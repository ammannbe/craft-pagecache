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
        $this->optimize = PageCache::$plugin->settings->optimize;
        $this->excludedUrls = PageCache::$plugin->settings->excludedUrls;
        $this->includedUrls = PageCache::$plugin->settings->includedUrls;
        $this->cacheFolderPath = Craft::getAlias(PageCache::$plugin->settings->cacheFolderPath);
    }

    // Private Properties
    // =========================================================================

    private $enabled;
    private $gzip;
    private $brotli;
    private $optimize;
    private $excludedUrls;
    private $includedUrls;
    private $cacheFolderPath;

    // Private Methods
    // =========================================================================

    private function parseUrl(Element $element, string $query = null)
    {
        $url = trim(implode('?', [$element->uri, $query]), '?');

        $url = str_replace(Element::HOMEPAGE_URI, '', $url);

        return '/' . $url;
    }

    private function parsePath(Element $element, string $query = null)
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

    private function shouldCachePage(Element $element, string $query = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->isPathExcluded($element, $query) && !$this->isPathIncluded($element, $query)) {
            return false;
        }

        return true;
    }

    public function shouldCacheHtml(string $html): bool
    {
        $html = stripslashes($html);

        if (str_contains($html, 'assets/generate-transform')) {
            return false;
        }

        return true;
    }

    private function isPathExcluded(Element $element, string $query = null): bool
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

            if (preg_match("/{$excludedUrl['path']}/", $url)) {
                return true;
            }
        }

        return false;
    }

    private function isPathIncluded(Element $element, string $query = null): bool
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

            if (preg_match("/{$includedUrl['path']}/", $url)) {
                return true;
            }
        }

        return false;
    }

    private function pageCacheFileExists(Element $element, string $query = null)
    {
        $path = $this->parsePath($element, $query);

        return file_exists($path);
    }

    private function pageCacheRecordExists(Element $element, string $query = null)
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
     * Thanks to @Rakesh Sankar https://stackoverflow.com/a/6225706
     */
    private function optimizeHtml($html): string
    {
        $search = [
            '/\>[^\S ]+/s',      // strip whitespaces after tags, except space
            '/[^\S ]+\</s',      // strip whitespaces before tags, except space
            '/(\s)+/s',          // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/', // Remove HTML comments
        ];

        $replace = [
            '>',
            '<',
            '\\1',
            '',
        ];

        return preg_replace($search, $replace, $html);
    }

    /**
     * Push $elements to queue
     * 
     * @param array<Element> $elements
     */
    private function pushToQueue(array $elements)
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

    /**
     * @return array<PageCacheRecord>
     */
    public function getPageCacheQueryRecords(Element $element): array
    {
        $url = $this->parseUrl($element);

        return PageCacheRecord::find()
            ->where('`url` LIKE :query', ['query' => $url . '?%'])
            ->andWhere('`url` != :url', ['url' => $url])
            ->andWhere(['siteId' => $element->getSite()->id])
            ->all();
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

    public function createPageCache(Element $element, string $query = null, string $html)
    {
        if (!$this->shouldCachePage($element, $query, $html) || !$this->shouldCacheHtml($html)) {
            $this->deletePageCache($element, $query);
            return false;
        }

        $this->createPageCacheFile($element, $query, $html);

        if (!$this->pageCacheRecordExists($element, $query)) {
            $pageCacheRecord = new PageCacheRecord([
                'elementId' => $element->id,
                'siteId'    => $element->getSite()->id,
                'url'       => $this->parseUrl($element, $query),
            ]);
            $pageCacheRecord->save();
        }
    }

    /**
     * Recreate page caches of element(s)
     * 
     * @param array<Element>|Element $element
     */
    public function recreatePageCaches($element)
    {
        if (empty($element)) {
            return;
        }

        $elements = [$element];
        if (is_array($element)) {
            $elements = $element;
        }

        unset($element);

        $this->pushToQueue($elements);
    }

    /**
     * Recreate all existing page caches
     */
    public function recreateAllPageCaches($siteId = null)
    {
        if ($siteId !== null) {
            $records = PageCacheRecord::find()->where(['siteId' => $siteId])->all();
        } else {
            $records = PageCacheRecord::find()->all();
        }

        /** @var array<Element> $elements */
        $elements = [];
        foreach ($records as $record) {
            $element = $elements[$record->elementId] ?? null;
            if ($element && $element->siteId === $record->siteId) {
                continue;
            }

            $element = Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
            $elements[] = $element;
        }

        $this->pushToQueue($elements);
    }

    public function createPageCacheFile(Element $element, string $query = null, string $html): void
    {
        if ($this->optimize) {
            $html = $this->optimizeHtml($html);
        }

        $path = $this->parsePath($element, $query);

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $hasWritten = file_put_contents($path, $html) !== false;

        if (!$hasWritten) {
            Craft::error(
                Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                __METHOD__
            );
        }

        if ($this->gzip) {
            $hasWritten = file_put_contents("{$path}.gz", gzencode($html, 6)) !== false;

            if (!$hasWritten) {
                Craft::error(
                    Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                    __METHOD__
                );
            }
        }

        if ($this->brotli) {
            $hasWritten = file_put_contents("{$path}.br", brotli_compress($html)) !== false;

            if (!$hasWritten) {
                Craft::error(
                    Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                    __METHOD__
                );
            }
        }
    }

    public function getPageCacheFileContents(Element $element, string $query = null)
    {
        $path = $this->parsePath($element, $query);

        return file_get_contents($path);
    }

    private function deletePageCacheFile(Element $element, string $query = null)
    {
        $path = $this->parsePath($element, $query);

        if (file_exists($path)) {
            unlink($path);
        }

        if (file_exists("{$path}.gz")) {
            unlink("{$path}.gz");
        }

        if (file_exists("{$path}.br")) {
            unlink("{$path}.br");
        }

        if (is_readable(dirname($path))) {
            if (count(scandir(dirname($path))) == 2) {
                rmdir(dirname($path));
            }
        }
    }

    private function deletePageCacheRecord(Element $element, string $query = null)
    {
        $condition = [
            'url' => $this->parseUrl($element, $query),
            'siteId' => $element->getSite()->id,
        ];

        return PageCacheRecord::find()
            ->where($condition)
            ->one()->delete();
    }

    public function deletePageCache(Element $element, string $query = null)
    {
        if ($this->pageCacheFileExists($element, $query)) {
            $this->deletePageCacheFile($element, $query);
        }

        if ($this->pageCacheRecordExists($element, $query)) {
            $this->deletePageCacheRecord($element, $query);
        }
    }

    /**
     * Delete the page and query cache of the element(s)
     *
     * @param array<Element>|Element $element
     */
    public function deletePageCacheWithQuery($element)
    {
        $elements = [$element];
        if (is_array($element)) {
            $elements = $element;
        }

        unset($element);

        foreach ($elements as $element) {
            $this->deletePageCache($element);

            $records = $this->getPageCacheQueryRecords($element);
            foreach ($records as $record) {
                $query = explode('?', $record->url)[1];
                $this->deletePageCache($element, $query);
            }
        }
    }

    /**
     * Delete all existing page caches
     */
    public function deleteAllPageCaches(int $siteId = null)
    {
        if ($siteId !== null) {
            $records = PageCacheRecord::find()->where(['siteId' => $siteId])->all();
        } else {
            $records = PageCacheRecord::find()->all();
        }

        $elements = [];
        foreach ($records as $record) {
            $element = Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
            if ($element) {
                $elements[] = $element;
            }
        }

        $this->deletePageCacheWithQuery($elements);
    }

    public function renamePageCache(Element $oldElement, Element $newElement, string $query = null)
    {
        $condition = [
            'url' => $this->parseUrl($oldElement, $query),
            'siteId' => $oldElement->getSite()->id,
        ];

        $record = PageCacheRecord::find()
            ->where($condition)
            ->one();

        if ($record) {
            $record->url = $this->parseUrl($newElement, $query);
            $record->update(true, ['url']);
        }
    }

    /*
     * @return void|false
     */
    public function servePageCacheIfExists(Element $element, string $query = null)
    {
        if (Craft::$app->request->getIsCpRequest()) {
            return false;
        }

        if (Craft::$app->request->getIsActionRequest()) {
            return false;
        }

        if (Craft::$app->request->getIsLivePreview()) {
            return false;
        }

        if (!Craft::$app->request->getIsGet()) {
            return false;
        }

        if (Craft::$app->request->getIsAjax()) {
            return false;
        }

        if (!$this->shouldCachePage($element, $query)) {
            return false;
        }

        if (!$this->pageCacheFileExists($element, $query)) {
            $this->deletePageCache($element, $query);
            return false;
        }

        Craft::$app->response->data = $this->getPageCacheFileContents($element, $query);
        Craft::$app->end();
    }
}
