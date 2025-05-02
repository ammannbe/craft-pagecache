<?php

/**
 * Page Cache plugin for Craft CMS 4.x
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
use craft\helpers\Queue;
use craft\base\Component;
use craft\elements\Entry;
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
        $this->excludedUrls = PageCache::$plugin->settings->excludedUrls;
        $this->includedUrls = PageCache::$plugin->settings->includedUrls;
        $this->cacheFolderPath = Craft::getAlias(PageCache::$plugin->settings->cacheFolderPath);
    }

    // Protected Properties
    // =========================================================================

    protected $enabled;
    protected $gzip;
    protected $brotli;
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

    protected function parsePath(Element $element, ?string $query = null)
    {
        $url = trim($this->parseUrl($element, $query), '/');
        $url = str_replace('?', '/@', $url);
        $baseUrl = trim($element->getSite()->getBaseUrl(), '/');
        $baseUrl = str_replace('https://', '', $baseUrl);
        $baseUrl = str_replace('http://', '', $baseUrl);
        $baseUrl = explode(':', $baseUrl)[0];

        if ($url === Element::HOMEPAGE_URI) {
            $url = 'index';
        }

        return urldecode("{$this->cacheFolderPath}/{$baseUrl}/{$url}/index.html");
    }

    protected function parsePageCacheMetaTag(string $key, string $html)
    {
        // Suppress parsing warnings for malformed HTML
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if ($meta->getAttribute('name') !== "pagecache:{$key}") {
                continue;
            }

            switch ($key) {
                case 'exclude':
                    return $meta->getAttribute('content') === 'true';
                    break;

                case 'tags':
                    return explode(',', $meta->getAttribute('content'));
                    break;

                default:
                    return $meta->getAttribute('content');
                    break;
            }

        }
    
        return null;
    }

    protected function shouldCachePage(Element $element, ?string $query = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->isPathExcluded($element, $query) && !$this->isPathIncluded($element, $query)) {
            return false;
        }

        return true;
    }

    protected function shouldCacheHtml(string $html): bool
    {
        $html = stripslashes($html);

        if (str_contains($html, 'assets/generate-transform')) {
            return false;
        }

        if ($this->parsePageCacheMetaTag('exclude', $html)) {
            return false;
        }

        return true;
    }

    protected function shouldCache(Element $element, ?string $query = null, string $html): bool
    {
        return $this->shouldCachePage($element, $query) && $this->shouldCacheHtml($html);
    }

    protected function isPathExcluded(Element $element, ?string $query = null): bool
    {
        foreach ($this->excludedUrls as $excludedUrl) {
            if (
                isset($excludedUrl['siteId'])
                && (!is_null($excludedUrl['siteId']) && !empty($excludedUrl['siteId']))
                && $element->getSite()->id != $excludedUrl['siteId']
            ) {
                continue;
            }

            $url = ltrim($this->parseUrl($element, $query), '/');

            if ($url == $excludedUrl['path']) {
                return true;
            }

            if (preg_match("/{$excludedUrl['path']}/", urldecode($url))) {
                return true;
            }
        }

        return false;
    }

    protected function isPathIncluded(Element $element, ?string $query = null): bool
    {
        foreach ($this->includedUrls as $includedUrl) {
            if (
                isset($includedUrl['siteId'])
                && (!is_null($includedUrl['siteId']) && !empty($includedUrl['siteId']))
                && $element->getSite()->id != $includedUrl['siteId']
            ) {
                continue;
            }

            $url = ltrim($this->parseUrl($element, $query), '/');

            if ($url == $includedUrl['path']) {
                return true;
            }

            if (preg_match("/{$includedUrl['path']}/", urldecode($url))) {
                return true;
            }
        }

        return false;
    }

    protected function pageCacheFileExists(Element $element, ?string $query = null)
    {
        $path = $this->parsePath($element, $query);

        return file_exists($path);
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
            ->where('`url` LIKE :query', ['query' => $url . '?%'])
            ->andWhere('`url` != :url', ['url' => $url])
            ->andWhere(['siteId' => $element->getSite()->id])
            ->all();
    }

    /**
     * Push $elements to queue
     * 
     * @param array<Element> $elements
     */
    protected function pushToQueue(array $elements)
    {
        $queue = Craft::$app->getQueue();
        if (!$queue || !($queue instanceof QueueInterface)) {
            return;
        }

        foreach ($elements as $element) {
            $url = $element->getSite()->getBaseUrl() . Craft::$app->elements->getElementUriForSite($element->id, $element->siteId);
            $url = str_replace(Element::HOMEPAGE_URI, '', $url);

            if (PageCacheQueueRecord::find()->where(['url' => $url])->exists()) {
                continue;
            }

            try {
                $serializedElement = serialize($element);
            } catch (\Throwable $th) {
                continue;
            }

            foreach ($this->getPageCacheQueryRecords($element) as $queryRecord) {
                if (PageCacheQueueRecord::find()->where(['url' => $queryRecord->url])->exists()) {
                    continue;
                }

                $pageCacheQueueRecord = new PageCacheQueueRecord([
                    'element' => $serializedElement,
                    'url'     => $queryRecord->url,
                    'delete'  => true,
                ]);
                $pageCacheQueueRecord->save();
            }

            $pageCacheQueueRecord = new PageCacheQueueRecord([
                'element' => $serializedElement,
                'url'     => $url,
                'delete'  => false,
            ]);
            $pageCacheQueueRecord->save();
        }

        foreach ($queue->getJobInfo() as $job) {
            $jobDetails = $queue->getJobDetails($job['id']);

            if ($jobDetails['job'] instanceof PageCacheTask) {
                // Job is already running, abort.
                return;
            }
        }

        Queue::push(new PageCacheTask());
    }

    // Public Methods
    // =========================================================================

    public function createCacheFolder()
    {
        mkdir($this->cacheFolderPath, 0755, true);
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
        foreach (Entry::find()->relatedTo($element)->all() as $element) {
            $entries[$element->id] = $element;
        }

        if (class_exists('\benf\neo\elements\Block')) {
            $neo = \benf\neo\elements\Block::find()->relatedTo($element)->all();
            foreach ($neo as $el) {
                $owner = $el->getOwner();
                if (!$owner->uri) {
                    continue;
                }

                $entries[$owner->id] = $owner;
            }
        }

        if (class_exists('\verbb\supertable\elements\SuperTableBlockElement')) {
            $supertable = \verbb\supertable\elements\SuperTableBlockElement::find()->relatedTo($element)->all();
            foreach ($supertable as $el) {
                $owner = $el->getOwner();
                if (!$owner->uri) {
                    continue;
                }

                $entries[$owner->id] = $owner;
            }
        }

        $matrix = \craft\elements\MatrixBlock::find()->relatedTo($element)->all();
        foreach ($matrix as $el) {
            $owner = null;
            try {
                $owner = $el->getOwner();
                if (!$owner->uri && $owner->getOwner()?->uri) {
                    $owner = $owner->owner;
                }
            } catch (\Exception $e) {
            }

            if (!$owner || !$owner->uri) {
                continue;
            }

            $entries[$owner->id] = $owner;
        }

        return $entries;
    }

    public function isCached(Element $element)
    {
        return $this->pageCacheFileExists($element) || $this->pageCacheRecordExists($element);
    }
}
