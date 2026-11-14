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
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\models\Site;
use craft\base\Component;
use craft\elements\Entry;
use craft\queue\Queue as CraftQueue;
use craft\queue\QueueInterface;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use suhype\pagecache\jobs\PageCacheTask;
use suhype\pagecache\records\PageCacheQueueRecord;
use suhype\pagecache\records\PageCacheRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheService extends Component
{
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
            return false;
        }

        if ($this->parsePageCacheMetaTag('exclude', $html)) {
            return false;
        }

        // A static CSRF token belongs to the visitor who rendered the page, every other visitor's
        // form submission would fail. Use {{ csrfInput({ async: true }) }} instead.
        if ($this->containsStaticCsrfToken($html)) {
            Craft::warning(
                'Page Cache did not cache "' . Craft::$app->getRequest()->getFullPath() . '", it contains a static CSRF token. Use `csrfInput({ async: true })`.',
                __METHOD__
            );

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
     * @return array<PageCacheRecord>
     */
    protected function getPageCacheQueryRecords(Element $element): array
    {
        $url = $this->parseUrl($element);

        return PageCacheRecord::find()
            ->where(['like', 'url', addcslashes($url, '%_\\') . '?%', false])
            ->andWhere(['siteId' => $element->getSite()->id])
            ->all();
    }

    protected function getQueueUrl(Element $element): ?string
    {
        $baseUrl = $element->getSite()->getBaseUrl();
        $uri = Craft::$app->elements->getElementUriForSite($element->id, $element->siteId);

        if ($baseUrl === null || !is_string($uri)) {
            return null;
        }

        return str_replace(Element::HOMEPAGE_URI, '', $baseUrl . $uri);
    }

    /**
     * Push $elements to queue
     *
     * @param array<Element|null> $elements
     */
    protected function pushToQueue(array $elements): void
    {
        $elements = array_filter($elements);
        if (empty($elements)) {
            return;
        }

        $queue = Craft::$app->getQueue();
        if (!$queue || !($queue instanceof QueueInterface)) {
            return;
        }

        $queuedUrls = array_flip(PageCacheQueueRecord::find()->select('url')->column());

        foreach ($elements as $element) {
            $url = $this->getQueueUrl($element);
            if ($url === null || isset($queuedUrls[$url])) {
                continue;
            }

            // Only store the reference: serializing the whole element walks all nested
            // Matrix/Neo field values recursively and can hang the save request.
            $elementReference = Json::encode([
                'id' => $element->id,
                'siteId' => $element->siteId,
            ]);

            foreach ($this->getPageCacheQueryRecords($element) as $queryRecord) {
                if (isset($queuedUrls[$queryRecord->url])) {
                    continue;
                }

                $pageCacheQueueRecord = new PageCacheQueueRecord([
                    'element' => $elementReference,
                    'url'     => $queryRecord->url,
                    'delete'  => true,
                ]);
                $pageCacheQueueRecord->save();
                $queuedUrls[$queryRecord->url] = true;
            }

            $pageCacheQueueRecord = new PageCacheQueueRecord([
                'element' => $elementReference,
                'url'     => $url,
                'delete'  => false,
            ]);
            $pageCacheQueueRecord->save();
            $queuedUrls[$url] = true;
        }

        if (!$this->hasPendingPageCacheTask($queue)) {
            Queue::push(new PageCacheTask());
        }
    }

    protected function hasPendingPageCacheTask(QueueInterface $queue): bool
    {
        foreach ($queue->getJobInfo() as $job) {
            // A failed task never runs again, so it must not block a new one
            if (($job['status'] ?? null) === CraftQueue::STATUS_FAILED) {
                continue;
            }

            try {
                $jobDetails = $queue->getJobDetails($job['id']);
            } catch (\Throwable $e) {
                // The job finished in the meantime
                continue;
            }

            if (($jobDetails['job'] ?? null) instanceof PageCacheTask) {
                return true;
            }
        }

        return false;
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
        return Craft::$app->getUser()->getIsGuest();
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

        // Nested elements (e.g. Matrix entries) are shown on the page of their owner
        if (!$element->uri) {
            $addEntry($element);
        }

        return $entries;
    }

    public function isCached(Element $element)
    {
        // A record without file is not served, the file is the source of truth
        return $this->pageCacheFileExists($element);
    }
}
