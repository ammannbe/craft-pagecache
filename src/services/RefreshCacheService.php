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

use Craft;
use craft\base\Element;
use craft\base\NestedElementInterface;
use craft\elements\GlobalSet;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use suhype\pagecache\PageCache;
use suhype\pagecache\records\PageCacheRecord;

/**
 * Collects changed elements during a request and refreshes the affected pages once at the end,
 * depending on the refresh mode.
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class RefreshCacheService extends PageCacheService
{
    /**
     * Flush early in long running processes (imports), so memory stays low
     */
    private const MAX_CHANGED_ELEMENTS = 500;

    private const EXPIRED_CACHE_KEY = 'pagecache:expiredCheckedAt';

    /**
     * Seconds between automatic checks for entries whose post or expiry date has passed
     */
    private const EXPIRED_INTERVAL = 300;

    /**
     * @var array<string, Element> Changed elements, keyed by "id:siteId"
     */
    private array $changedElements = [];

    /**
     * @var array<int, int> Cached pages which showed a deleted element (resolved before its tracking rows are gone)
     */
    private array $changedCacheIds = [];

    /**
     * @var array<string, Element> Deleted elements, keyed by "id:siteId"
     */
    private array $deletedElements = [];

    // Public Methods
    // =========================================================================

    /**
     * Remember a saved or deleted element, the affected pages are refreshed at the end of the request
     */
    public function elementChanged(Element $element, bool $deleted = false): void
    {
        if (!PageCache::$plugin->settings->enabled) {
            return;
        }

        // Globals are handled by the "When globals are saved" setting
        if ($element instanceof GlobalSet) {
            return;
        }

        if ($deleted) {
            // Hard deletes remove the tracking rows, so resolve the pages right away
            foreach (PageCache::$plugin->trackingService->getCacheIdsForElementIds([$element->id]) as $cacheId) {
                $this->changedCacheIds[$cacheId] = $cacheId;
            }

            // Listings and counts that contained the element
            $this->deletedElements["{$element->id}:{$element->siteId}"] = $element;
        } else {
            $this->changedElements["{$element->id}:{$element->siteId}"] = $element;
        }

        // Flush early in imports, but not inside a transaction (e.g. nested entries saved with their owner):
        // a failed insert would abort the transaction on PostgreSQL
        if (count($this->changedElements) >= self::MAX_CHANGED_ELEMENTS && !Craft::$app->getDb()->getTransaction()) {
            $this->flush();
        }
    }

    /**
     * Refresh the pages affected by the changed elements
     */
    public function flush(): void
    {
        $elements = $this->changedElements;
        $deleted = $this->deletedElements;
        $cacheIds = $this->changedCacheIds;
        $this->changedElements = [];
        $this->deletedElements = [];
        $this->changedCacheIds = [];

        if (empty($elements) && empty($deleted) && empty($cacheIds)) {
            return;
        }

        $settings = PageCache::$plugin->settings;
        $tracking = PageCache::$plugin->trackingService;
        $pages = [];
        $checks = [];

        foreach ($elements as $element) {
            foreach ($this->getRelatedElements($element) + [$element->id => $element] as $page) {
                // The owner of a nested element can be in a bulk resave as well
                if (!$page->uri || ElementHelper::isDraftOrRevision($page) || $page->resaving) {
                    continue;
                }

                $pages = array_merge($pages, $this->getPagesForElement($page, !$this->isLive($page)));
            }
        }

        if ($settings->trackDependencies) {
            foreach ($elements + $deleted as $element) {
                // Nested elements (e.g. Matrix entries) are only queried through their owner, which is tracked
                if ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId()) {
                    continue;
                }

                if ($tracking->hasQueriesForType(get_class($element))) {
                    $checks[$element->id] = $element;
                }
            }
        }

        // Pages that showed the elements, also the page of an element on other sites (propagated saves
        // only trigger an event for the saved site)
        $elementIds = array_map(fn(Element $element) => $element->id, $elements);
        if (!empty($elementIds)) {
            $ownCacheIds = PageCacheRecord::find()->select('id')->where(['elementId' => $elementIds])->column();
            foreach ($ownCacheIds as $cacheId) {
                $cacheIds[(int) $cacheId] = (int) $cacheId;
            }
        }

        if ($settings->trackDependencies) {
            foreach ($tracking->getCacheIdsForElementIds($elementIds) as $cacheId) {
                $cacheIds[$cacheId] = $cacheId;
            }
        }

        if (!empty($cacheIds)) {
            // Pages of the elements themselves keep their delete flag
            $pages = array_merge($this->getPagesForRecords(PageCacheRecord::find()->where(['id' => array_values($cacheIds)])->all()), $pages);
        }

        $this->processPages($pages, false);

        // Matching element queries (e.g. listings) is too slow for the save request, the queue job does it
        $this->queueElementChecks(array_values($checks), false);

        if (!empty($pages) || !empty($checks)) {
            $this->ensureQueueJob();
        }
    }

    /**
     * Clear and/or queue pages, depending on the refresh mode.
     * Pages to delete (disabled, expired, deleted) are always removed right away.
     *
     * @param array<array{siteId: int, url: string, elementId: int|null, delete: bool}> $pages
     */
    public function processPages(array $pages, bool $ensureJob = true): void
    {
        if (empty($pages)) {
            return;
        }

        $settings = PageCache::$plugin->settings;
        $toDelete = [];
        $toQueue = [];

        foreach ($pages as $key => $page) {
            if ($page['delete'] || $settings->shouldClearImmediately()) {
                $toDelete[] = $page;
            }

            if (!$page['delete'] && $settings->shouldGenerate()) {
                $toQueue[$key] = $page;
            }
        }

        PageCache::$plugin->deleteCacheService->deleteUrls($toDelete);
        $this->queuePages($toQueue, $ensureJob);
    }

    /**
     * Refresh cache of element(s) (e.g. element action, sidebar)
     *
     * @param array<Element>|Element $element
     * @param bool $queue
     *
     * @return ?bool
     */
    public function refreshForElements($elements, bool $queue = true): ?bool
    {
        if (empty($elements)) {
            return null;
        }

        if (!is_array($elements)) {
            $elements = [$elements];
        }

        $pages = [];
        foreach (array_filter($elements) as $element) {
            if (!$element->uri) {
                continue;
            }

            $pages = array_merge($pages, $this->getPagesForElement($element, !$this->isLive($element)));
        }

        $this->processPages($pages);

        return true;
    }

    /**
     * Refresh complete cache
     *
     * @param int|null $siteId
     *
     * @return ?bool
     */
    public function refresh($siteId = null, ?array $tags = null, bool $queue = true): ?bool
    {
        $query = PageCacheRecord::find();

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        if (!empty($tags)) {
            $tagsQuery = [];
            foreach ($tags as $tag) {
                $tagsQuery[] = ['like', 'tags', '"' . $tag . '"'];
            }

            $query->andWhere(['or', ...$tagsQuery]);
        }

        // The queue job deletes the cache of elements that don't exist anymore
        $this->processPages($this->getPagesForRecords($query->each()));

        return true;
    }

    /**
     * Refresh the pages of entries whose post date or expiry date passed since the last check
     * (they change status without being saved)
     *
     * @return int Number of entries
     */
    public function refreshExpired(): int
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::EXPIRED_CACHE_KEY)) {
            return 0;
        }

        try {
            $cache = Craft::$app->getCache();
            $now = new \DateTime();
            $lastCheck = $cache->get(self::EXPIRED_CACHE_KEY);
            $since = $lastCheck ? (new \DateTime())->setTimestamp((int) $lastCheck) : (new \DateTime())->modify('-1 day');

            $from = Db::prepareDateForDb($since);
            $to = Db::prepareDateForDb($now);

            $entries = Entry::find()
                ->site('*')
                ->unique(false)
                ->status(null)
                ->andWhere(['or',
                    ['and', ['>', 'entries.postDate', $from], ['<=', 'entries.postDate', $to]],
                    ['and', ['>', 'entries.expiryDate', $from], ['<=', 'entries.expiryDate', $to]],
                ])
                ->all();

            foreach ($entries as $entry) {
                $this->elementChanged($entry);
            }

            $cache->set(self::EXPIRED_CACHE_KEY, $now->getTimestamp(), 0);
            $this->flush();

            return count($entries);
        } finally {
            $mutex->release(self::EXPIRED_CACHE_KEY);
        }
    }

    /**
     * Check for expired entries at most every few minutes, so it also works without a cron job
     */
    public function refreshExpiredIfDue(): void
    {
        if (!PageCache::$plugin->settings->enabled) {
            return;
        }

        $lastCheck = Craft::$app->getCache()->get(self::EXPIRED_CACHE_KEY);
        if ($lastCheck && $lastCheck > time() - self::EXPIRED_INTERVAL) {
            return;
        }

        try {
            $this->refreshExpired();
        } catch (\Throwable $e) {
            Craft::warning('Page Cache could not check expired entries: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function hasCheckedExpiredRecently(): bool
    {
        $lastCheck = Craft::$app->getCache()->get(self::EXPIRED_CACHE_KEY);

        return $lastCheck && $lastCheck > time() - 2 * 86400;
    }

    /**
     * Refresh pages by their full URLs (e.g. "https://example.com/news")
     *
     * @param array<string> $urls
     * @return int Number of pages found
     */
    public function refreshUrls(array $urls): int
    {
        $sites = Craft::$app->getSites()->getAllSites(true);

        // Longest base URL first, so "example.com/de" wins over "example.com"
        usort($sites, fn($a, $b) => strlen((string) $b->getBaseUrl()) <=> strlen((string) $a->getBaseUrl()));

        $pages = [];

        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }

            foreach ($sites as $site) {
                $baseUrl = rtrim((string) $site->getBaseUrl(), '/');
                if ($baseUrl === '' || ($url !== $baseUrl && !str_starts_with($url, $baseUrl . '/') && !str_starts_with($url, $baseUrl . '?'))) {
                    continue;
                }

                $path = '/' . ltrim(substr($url, strlen($baseUrl)), '/');
                [$uri] = explode('?', trim($path, '/'), 2);

                $element = Craft::$app->getElements()->getElementByUri($uri === '' ? Element::HOMEPAGE_URI : $uri, $site->id, true);

                $pages["{$site->id}:{$path}"] = [
                    'siteId' => (int) $site->id,
                    'url' => $path,
                    'elementId' => $element?->id,
                    'delete' => false,
                ];
                break;
            }
        }

        $this->processPages($pages);

        return count($pages);
    }
}
