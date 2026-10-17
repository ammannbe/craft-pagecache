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

        return true;
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

    protected function pageCacheRecordExists(Element $element, ?string $query = null)
    {
        $condition = [
            'url' => $this->parseUrl($element, $query),
            'siteId' => $element->getSite()->id,
        ];

        return PageCacheRecord::find()
            ->where($condition)
            ->exists();
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
        $dir = $this->cacheFolderPath;

        if (!file_exists($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }

        rmdir($dir);
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
        return $this->pageCacheFileExists($element) || $this->pageCacheRecordExists($element);
    }
}
