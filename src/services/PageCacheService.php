<?php

/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\services;

use suhype\pagecache\PageCache;

use Craft;
use craft\base\Element;
use craft\db\Query;
use yii\db\IntegrityException;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\models\Site;
use craft\base\Component;
use craft\elements\Entry;
use craft\queue\Queue as CraftQueue;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use suhype\pagecache\db\Table;
use suhype\pagecache\jobs\PageCacheTask;
use suhype\pagecache\records\PageCacheRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheService extends Component
{
    /**
     * Query parameter with which the queue job requests a page to generate its cache
     */
    public const GENERATE_PARAM = 'pagecache-generate';

    /**
     * Header with the secret token of the queue job requests
     */
    public const GENERATE_HEADER = 'X-Page-Cache-Generate';

    /**
     * Cache key of the waiting queue job ID
     */
    public const JOB_CACHE_KEY = 'pagecache:jobId';

    /**
     * Shared by all service instances, flash messages are removed while rendering
     */
    private static ?bool $hasFlashMessages = null;

    public function __construct()
    {
        $this->enabled = PageCache::$plugin->settings->enabled;
        $this->gzip = PageCache::$plugin->settings->gzip;
        $this->brotli = PageCache::$plugin->settings->brotli && function_exists('brotli_compress');
        $this->cacheQueryStrings = PageCache::$plugin->settings->cacheQueryStrings;
        $this->excludedUrls = PageCache::$plugin->settings->excludedUrls;
        $this->includedUrls = PageCache::$plugin->settings->includedUrls;
        $this->cacheFolderPath = rtrim(Craft::getAlias(PageCache::$plugin->settings->cacheFolderPath), '/');
    }

    // Protected Properties
    // =========================================================================

    protected $enabled;
    protected $gzip;
    protected $brotli;
    protected $cacheQueryStrings;
    protected $excludedUrls;
    protected $includedUrls;
    protected $cacheFolderPath;

    // Protected Methods
    // =========================================================================

    protected function parseUrl(Element $element, ?string $query = null)
    {
        $url = trim(implode('?', [$element->uri, $query]), '?');

        $url = str_replace(Element::HOMEPAGE_URI, '', $url);

        return '/' . $url;
    }

    /**
     * Path of the cache file, or null if the URL would leave the cache folder
     */
    protected function parsePath(Element $element, ?string $query = null): ?string
    {
        return $this->parsePathForSite($element->getSite(), $this->parseUrl($element, $query));
    }

    protected function parsePathForSite(Site $site, string $url): ?string
    {
        $url = trim($url, '/');
        $url = str_replace('?', '/@', $url);
        $baseUrl = trim((string) $site->getBaseUrl(), '/');
        $baseUrl = str_replace('https://', '', $baseUrl);
        $baseUrl = str_replace('http://', '', $baseUrl);
        $baseUrl = explode(':', $baseUrl)[0];

        $path = urldecode("{$baseUrl}/{$url}");

        // Query strings like "?a=/../../" must not write outside of the cache folder
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return "{$this->cacheFolderPath}/{$path}/index.html";
    }

    protected function parsePageCacheMetaTag(string $key, string $html)
    {
        $pattern = '/<meta\b[^>]*\bname=["\']pagecache:' . preg_quote($key, '/') . '["\'][^>]*>/i';
        if (!preg_match($pattern, $html, $tag)) {
            return null;
        }

        $content = '';
        if (preg_match('/\bcontent=["\']([^"\']*)["\']/i', $tag[0], $match)) {
            $content = html_entity_decode($match[1], ENT_QUOTES);
        }

        switch ($key) {
            case 'exclude':
                return $content === 'true';

            case 'tags':
                return array_values(array_filter(array_map('trim', explode(',', $content)), 'strlen'));

            default:
                return $content;
        }
    }

    protected function shouldCachePage(Element $element, ?string $query = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->parsePath($element, $query) === null) {
            return false;
        }

        if ($this->isPathIncluded($element, $query)) {
            return true;
        }

        if ($query !== null && $query !== '' && !$this->cacheQueryStrings) {
            return false;
        }

        return !$this->isPathExcluded($element, $query);
    }

    protected function shouldCacheHtml(string $html): bool
    {
        // Also matches JSON encoded URLs ("assets\/generate-transform")
        if (str_contains($html, 'assets/generate-transform') || str_contains($html, 'assets\/generate-transform')) {
            PageCache::$plugin->diagnosticsService->addNotice(DiagnosticsService::NOTICE_TRANSFORMS);
            return false;
        }

        if ($this->parsePageCacheMetaTag('exclude', $html)) {
            return false;
        }

        // A static CSRF token belongs to the visitor who rendered the page, every other visitor's
        // form submission would fail. Use {{ csrfInput({ async: true }) }} instead.
        if ($this->containsStaticCsrfToken($html)) {
            $url = PageCache::$plugin->diagnosticsService->currentUrl();
            Craft::warning(
                "Page Cache did not cache \"{$url}\", it uses a CSRF token. Use `csrfInput({ async: true })`.",
                __METHOD__
            );
            PageCache::$plugin->diagnosticsService->addNotice(DiagnosticsService::NOTICE_CSRF, $url);

            return false;
        }

        return true;
    }

    protected function containsStaticCsrfToken(string $html): bool
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        if (!$generalConfig->enableCsrfProtection) {
            return false;
        }

        // The token was used while rendering (csrfInput() without async, craft.app.request.csrfToken in JS, ...)
        if ($this->csrfTokenWasGenerated()) {
            return true;
        }

        $name = preg_quote($generalConfig->csrfTokenName, '/');

        if (!preg_match_all('/<input\b[^>]*\bname=["\']' . $name . '["\'][^>]*>/i', $html, $inputs)) {
            return false;
        }

        foreach ($inputs[0] as $input) {
            if (preg_match('/\bvalue=["\'][^"\']+["\']/i', $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether Craft generated a CSRF token in the current request (it is only generated on use)
     */
    protected function csrfTokenWasGenerated(): bool
    {
        $request = Craft::$app->getRequest();

        try {
            $property = new \ReflectionProperty(\craft\web\Request::class, '_craftCsrfToken');

            return $property->getValue($request) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function shouldCache(Element $element, ?string $query, string $html): bool
    {
        return $this->shouldCachePage($element, $query) && $this->shouldCacheHtml($html);
    }

    protected function isPathExcluded(Element $element, ?string $query = null): bool
    {
        return $this->matchesUrlPatterns($this->excludedUrls, $element, $query);
    }

    protected function isPathIncluded(Element $element, ?string $query = null): bool
    {
        return $this->matchesUrlPatterns($this->includedUrls, $element, $query);
    }

    protected function matchesUrlPatterns(array $patterns, Element $element, ?string $query = null): bool
    {
        $url = ltrim($this->parseUrl($element, $query), '/');

        foreach ($patterns as $pattern) {
            $path = (string) ($pattern['path'] ?? '');

            // An empty row would match every URL
            if ($path === '') {
                continue;
            }

            if (!empty($pattern['siteId']) && $element->getSite()->id != $pattern['siteId']) {
                continue;
            }

            if ($url === $path) {
                return true;
            }

            // "#" as delimiter, so patterns like "news/archive" work without escaping "/"
            if (@preg_match('#' . str_replace('#', '\#', $path) . '#', urldecode($url)) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function pageCacheFileExists(Element $element, ?string $query = null)
    {
        $path = $this->parsePath($element, $query);

        return $path !== null && file_exists($path);
    }

    /**
     * Cache folder of a site (host plus base path), or null if it can't be resolved
     */
    protected function getSiteCacheDir(Site $site): ?string
    {
        $path = $this->parsePathForSite($site, '/');

        return $path === null ? null : rtrim(dirname($path), '/');
    }

    /**
     * Whether the cache folder is a dedicated folder that may be deleted recursively
     */
    protected function isSafeCacheFolder(): bool
    {
        $folder = realpath($this->cacheFolderPath);

        if ($folder === false || $folder === '/' || !is_dir($folder)) {
            return false;
        }

        // Never delete a system folder or one of its parents (e.g. when the path is set to @webroot)
        foreach (['@root', '@webroot', '@storage', '@vendor', '@config', '@templates'] as $alias) {
            $systemPath = Craft::getAlias($alias, false);
            $systemPath = $systemPath ? realpath($systemPath) : false;

            if ($systemPath !== false && ($systemPath === $folder || str_starts_with($systemPath . '/', $folder . '/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete a directory recursively, only if it is inside the cache folder
     */
    protected function deleteDirectory(string $dir): void
    {
        $folder = realpath($this->cacheFolderPath);
        $dir = realpath($dir);

        if ($folder === false || $dir === false || !is_dir($dir) || !str_starts_with($dir, $folder . '/') || !$this->isSafeCacheFolder()) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $fileinfo->isDir() && !$fileinfo->isLink() ? @rmdir($fileinfo->getPathname()) : @unlink($fileinfo->getPathname());
        }

        @rmdir($dir);
    }

    /**
     * Cached variants of the page of $element (query strings), without the page itself
     *
     * @return array<PageCacheRecord>
     */
    protected function getPageCacheQueryRecords(Element $element): array
    {
        return PageCacheRecord::find()
            ->where([
                'elementId' => $element->id,
                'siteId' => $element->getSite()->id,
            ])
            ->andWhere(['not', ['url' => $this->parseUrl($element)]])
            ->all();
    }

    /**
     * The page of $element and its cached variants
     *
     * @return array<string, array{siteId: int, url: string, elementId: int|null, delete: bool}>
     */
    protected function getPagesForElement(Element $element, bool $delete = false): array
    {
        $siteId = (int) $element->getSite()->id;
        $pages = [];

        $url = $this->parseUrl($element);
        $pages["{$siteId}:{$url}"] = [
            'siteId' => $siteId,
            'url' => $url,
            'elementId' => (int) $element->id,
            'delete' => $delete,
        ];

        foreach ($this->getPageCacheQueryRecords($element) as $record) {
            $pages["{$siteId}:{$record->url}"] = [
                'siteId' => $siteId,
                'url' => $record->url,
                'elementId' => (int) $element->id,
                'delete' => $delete,
            ];
        }

        return $pages;
    }

    /**
     * @param iterable<PageCacheRecord> $records
     * @return array<string, array{siteId: int, url: string, elementId: int|null, delete: bool}>
     */
    protected function getPagesForRecords(iterable $records): array
    {
        $pages = [];

        foreach ($records as $record) {
            $pages["{$record->siteId}:{$record->url}"] = [
                'siteId' => (int) $record->siteId,
                'url' => $record->url,
                'elementId' => $record->elementId ? (int) $record->elementId : null,
                'delete' => false,
            ];
        }

        return $pages;
    }

    /**
     * Push $elements to queue
     *
     * @deprecated since 2.3.0 - use RefreshCacheService::refreshForElements()
     * @param array<Element|null> $elements
     */
    protected function pushToQueue(array $elements): void
    {
        $pages = [];
        foreach (array_filter($elements) as $element) {
            $pages += $this->getPagesForElement($element);
        }

        $this->queuePages($pages);
    }

    public function queuePages(array $pages, bool $ensureJob = true): void
    {
        $rows = [];
        foreach ($pages as $page) {
            $hash = md5("{$page['siteId']}:{$page['url']}");
            $rows[$hash] = [
                'elementId' => $page['elementId'],
                'siteId' => $page['siteId'],
                'url' => $page['url'],
                'urlHash' => $hash,
                'delete' => (bool) $page['delete'],
                'check' => false,
                'claimed' => false,
            ];
        }

        $this->insertQueueRows($rows);

        if ($ensureJob && !empty($rows)) {
            $this->ensureQueueJob();
        }
    }

    /**
     * Let the queue job find the pages whose element queries match the elements (e.g. listings)
     *
     * @param array<Element> $elements
     */
    public function queueElementChecks(array $elements, bool $ensureJob = true): void
    {
        $rows = [];
        foreach ($elements as $element) {
            // The queries are site specific already, one check per element is enough
            $hash = md5("check:{$element->id}");
            $rows[$hash] = [
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
                'url' => null,
                'urlHash' => $hash,
                'delete' => false,
                'check' => true,
                'claimed' => false,
            ];
        }

        $this->insertQueueRows($rows);

        if ($ensureJob && !empty($rows)) {
            $this->ensureQueueJob();
        }
    }

    /**
     * Insert new rows, mark existing ones as unclaimed again: a job that is processing them right now
     * then keeps them and processes them once more, so a change during the job is never lost.
     *
     * @param array<string, array> $rows keyed by hash
     */
    private function insertQueueRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500, true) as $chunk) {
            $existing = (new Query())
                ->select(['urlHash'])
                ->from(Table::QUEUE)
                ->where(['urlHash' => array_keys($chunk)])
                ->column();

            if (!empty($existing)) {
                Db::update(Table::QUEUE, ['claimed' => false], ['urlHash' => $existing]);

                // Deleting wins over refreshing (e.g. an entry got disabled while it waited in the queue)
                $toDelete = array_keys(array_filter(array_intersect_key($chunk, array_flip($existing)), fn($row) => $row['delete']));
                if (!empty($toDelete)) {
                    Db::update(Table::QUEUE, ['delete' => true], ['urlHash' => $toDelete]);
                }
            }

            $chunk = array_diff_key($chunk, array_flip($existing));
            if (empty($chunk)) {
                continue;
            }

            $columns = array_keys(reset($chunk));

            try {
                Db::batchInsert(Table::QUEUE, $columns, array_map('array_values', array_values($chunk)));
            } catch (IntegrityException $e) {
                // Another request queued one of the pages in the meantime. Not possible on PostgreSQL inside
                // a transaction, so elements are only flushed early outside of transactions.
                foreach ($chunk as $row) {
                    try {
                        Db::insert(Table::QUEUE, $row);
                    } catch (IntegrityException $e) {
                        Db::update(Table::QUEUE, ['claimed' => false], ['urlHash' => $row['urlHash']]);
                    }
                }
            }
        }
    }

    /**
     * Push a queue job, unless one is waiting already. A running job also processes pages queued
     * while it runs, but it forgets its ID when it starts, so a page queued at its very end gets a new job.
     */
    public function ensureQueueJob(?int $delay = null): void
    {
        $queue = Craft::$app->getQueue();
        $cache = Craft::$app->getCache();
        $jobId = $cache->get(self::JOB_CACHE_KEY);

        if ($jobId) {
            try {
                if ($queue->status($jobId) === CraftQueue::STATUS_WAITING) {
                    return;
                }
            } catch (\Throwable $e) {
            }
        }

        $jobId = Queue::push(new PageCacheTask(), null, $delay);
        if ($jobId) {
            $cache->set(self::JOB_CACHE_KEY, $jobId, 86400);
        }
    }

    /**
     * Full URL the queue job requests to generate a page. The query parameter makes the rewrite rules
     * skip the cached file, the secret token is sent as header (see getGenerateHeaders()), so it never
     * shows up in the HTML (e.g. pagination links) or in logs.
     */
    public function getGenerateUrl(Site $site, string $url): ?string
    {
        $baseUrl = $site->getBaseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

        return $url . (str_contains($url, '?') ? '&' : '?') . self::GENERATE_PARAM . '=1';
    }

    /**
     * @return array<string, string>
     */
    public function getGenerateHeaders(): array
    {
        return [self::GENERATE_HEADER => $this->getGenerateToken()];
    }

    public function getGenerateToken(): string
    {
        return hash_hmac('sha256', 'pagecache-generate', Craft::$app->getConfig()->getGeneral()->securityKey);
    }

    /**
     * Whether the current request was sent by the queue job to generate the page
     */
    public function isGenerateRequest(): bool
    {
        $token = Craft::$app->getRequest()->getHeaders()->get(self::GENERATE_HEADER);

        return is_string($token) && hash_equals($this->getGenerateToken(), $token);
    }

    /**
     * Remove the generate parameter from the current request, so it never ends up in the rendered HTML
     * (e.g. pagination links or canonical URLs) nor in the cache path
     */
    public function stripGenerateParam(): void
    {
        $request = Craft::$app->getRequest();
        $params = $request->getQueryParams();

        if (!array_key_exists(self::GENERATE_PARAM, $params)) {
            return;
        }

        unset($params[self::GENERATE_PARAM], $_GET[self::GENERATE_PARAM]);
        $request->setQueryParams($params);

        // String based, so the order and encoding of the other parameters stay as they are
        $strip = fn(string $query) => implode('&', array_filter(
            explode('&', $query),
            fn(string $part) => $part !== '' && $part !== self::GENERATE_PARAM && !str_starts_with($part, self::GENERATE_PARAM . '=')
        ));

        if (isset($_SERVER['QUERY_STRING'])) {
            $_SERVER['QUERY_STRING'] = $strip((string) $_SERVER['QUERY_STRING']);
        }

        // request.url / request.absoluteUrl (e.g. og:url, canonical)
        [$path, $query] = explode('?', $request->getUrl(), 2) + [1 => ''];
        $query = $strip($query);
        $url = $path . ($query !== '' ? "?{$query}" : '');
        $_SERVER['REQUEST_URI'] = $url;
        $request->setUrl($url);
    }

    /**
     * Query string of the current request
     */
    public function getRequestQueryString(): ?string
    {
        return (string) Craft::$app->getRequest()->getQueryStringWithoutPath();
    }

    public function isLive(Element $element): bool
    {
        return in_array($element->getStatus(), [Entry::STATUS_LIVE, Element::STATUS_ENABLED], true);
    }

    /**
     * The element with a URL that shows $element: itself, or the owner of a nested element
     */
    protected function resolvePageElement(?Element $element): ?Element
    {
        for ($depth = 0; $element && !$element->uri && $depth < 10; $depth++) {
            if (!method_exists($element, 'getOwner')) {
                return null;
            }

            try {
                $element = $element->getOwner();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $element && $element->uri ? $element : null;
    }

    // Public Methods
    // =========================================================================

    /**
     * Whether the current request may be served from or written to the cache
     */
    /**
     * Whether the current request path is the URI of $element.
     *
     * Paginated URLs (e.g. "news/p2") and other paths that resolve to the same element
     * must not be served from or written to the cache of the element URI.
     */
    public function isRequestForElement(Element $element): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getPageNum() !== 1) {
            return false;
        }

        $uri = $element->uri === Element::HOMEPAGE_URI ? '' : (string) $element->uri;

        // getPathInfo() strips the site base path and the page trigger ("news/p1" is "news", page 1)
        return trim($request->getPathInfo(), '/') === trim($uri, '/');
    }

    /**
     * Whether the rendered response may be written to the cache
     */
    public function isCacheableResponse(?string $template = null): bool
    {
        $response = Craft::$app->getResponse();

        if (!$response->getIsOk()) {
            return false;
        }

        $headers = $response->getHeaders();

        // Content-Type set by the template ({% header %}), otherwise by the template extension (e.g. sitemap.xml.twig)
        $contentType = $headers->get('content-type');
        if ($contentType === null && $template !== null) {
            try {
                $templateFile = preg_replace('/\.twig$/i', '', strtolower((string) Craft::$app->getView()->resolveTemplate($template)));
                $contentType = \craft\helpers\FileHelper::getMimeTypeByExtension($templateFile);
            } catch (\Throwable $e) {
                $contentType = null;
            }
        }

        $contentType = strtolower(trim(explode(';', (string) ($contentType ?? 'text/html'))[0]));

        // Cache-Control is intentionally ignored: it controls browsers and proxies,
        // not the page cache. Use {% do craft.pagecache.exclude() %} to exclude a page.
        return $contentType === 'text/html';
    }

    public function isCacheableRequest(): bool
    {
        $request = Craft::$app->getRequest();

        if (
            !$request->getIsSiteRequest()
            || $request->getIsActionRequest()
            || $request->getIsLivePreview()
            || $request->getIsPreview()
            || $request->getHadToken()
            || !$request->getIsGet()
            || $request->getIsAjax()
        ) {
            return false;
        }

        // Logged-in users may see personalized content
        if (!Craft::$app->getUser()->getIsGuest()) {
            return false;
        }

        // A flash message (e.g. "Thank you for your message") must neither end up in the cache for everyone,
        // nor be hidden by a cached page
        return !$this->hasFlashMessages();
    }

    /**
     * Whether the visitor has flash messages. Checked once before rendering, which removes them from the session.
     */
    protected function hasFlashMessages(): bool
    {
        if (self::$hasFlashMessages !== null) {
            return self::$hasFlashMessages;
        }

        $session = Craft::$app->getSession();

        // Don't start a session for visitors without one
        if (!$session->getIsActive() && !isset($_COOKIE[$session->getName()])) {
            return self::$hasFlashMessages = false;
        }

        try {
            // Read the flash counters directly: getAllFlashes() would mark the messages for deletion
            $session->open();
            return self::$hasFlashMessages = !empty($_SESSION[$session->flashParam] ?? []);
        } catch (\Throwable $e) {
            return self::$hasFlashMessages = false;
        }
    }

    public function createCacheFolder()
    {
        if (!file_exists($this->cacheFolderPath)) {
            mkdir($this->cacheFolderPath, 0755, true);
        }
    }

    public function deleteCacheFolder()
    {
        if (!file_exists($this->cacheFolderPath)) {
            return;
        }

        if (!$this->isSafeCacheFolder()) {
            Craft::warning(
                "Page Cache did not delete the cache folder \"{$this->cacheFolderPath}\", it is not a dedicated folder.",
                __METHOD__
            );
            return;
        }

        $this->deleteCacheFolderContents();
        @rmdir($this->cacheFolderPath);
    }

    /**
     * Delete all cached files, but keep the cache folder
     */
    public function deleteCacheFolderContents(): void
    {
        if (!is_dir($this->cacheFolderPath) || !$this->isSafeCacheFolder()) {
            return;
        }

        foreach (new \DirectoryIterator($this->cacheFolderPath) as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }

            $fileinfo->isDir() && !$fileinfo->isLink()
                ? $this->deleteDirectory($fileinfo->getPathname())
                : @unlink($fileinfo->getPathname());
        }
    }

    public function getRelatedElements(Element $element)
    {
        $entries = [];
        $addEntry = function (?Element $relatedElement) use (&$entries) {
            $page = $this->resolvePageElement($relatedElement);
            if ($page) {
                $entries[$page->id] = $page;
            }
        };

        // Nested elements (e.g. Matrix entries) are shown on the page of their owner
        if (!$element->uri) {
            $addEntry($element);
        }

        // Dependency tracking knows every page that shows the element, also through relations
        if (PageCache::$plugin->settings->trackDependencies) {
            return $entries;
        }

        foreach (Entry::find()->relatedTo($element)->siteId($element->siteId)->all() as $relatedEntry) {
            $addEntry($relatedEntry);
        }

        if (class_exists('\benf\neo\elements\Block')) {
            foreach (\benf\neo\elements\Block::find()->relatedTo($element)->siteId($element->siteId)->all() as $block) {
                $addEntry($block);
            }
        }

        if (class_exists('\verbb\supertable\elements\SuperTableBlockElement')) {
            foreach (\verbb\supertable\elements\SuperTableBlockElement::find()->relatedTo($element)->siteId($element->siteId)->all() as $block) {
                $addEntry($block);
            }
        }

        return $entries;
    }

    /**
     * When the page of $element was cached, null if it is not cached
     */
    public function getCachedAt(Element $element): ?\DateTime
    {
        $path = $this->parsePath($element);
        $time = $path !== null && is_file($path) ? @filemtime($path) : false;

        return $time ? (new \DateTime())->setTimestamp($time) : null;
    }

    /**
     * Whether the page of $element waits in the queue
     */
    public function isQueued(Element $element): bool
    {
        return (new Query())
            ->from(Table::QUEUE)
            ->where(['urlHash' => md5($element->getSite()->id . ':' . $this->parseUrl($element))])
            ->exists();
    }

    public function isCached(Element $element)
    {
        // A record without file is not served, the file is the source of truth
        return $this->pageCacheFileExists($element);
    }
}
