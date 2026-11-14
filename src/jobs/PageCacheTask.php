<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\jobs;

use suhype\pagecache\PageCache;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\queue\BaseJob;
use suhype\pagecache\db\Table;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use suhype\pagecache\records\PageCacheQueueRecord;
use suhype\pagecache\records\PageCacheRecord;
use suhype\pagecache\services\PageCacheService;
use yii\queue\RetryableJobInterface;

/**
 * Processes the queued pages: finds the pages of changed elements (element queries),
 * deletes pages that are not live anymore and generates the cache of the others.
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheTask extends BaseJob implements RetryableJobInterface
{
    private const MUTEX_NAME = 'pagecache:task';
    private const BATCH_SIZE = 100;
    private const CHECK_BATCH_SIZE = 200;

    /**
     * Seconds after which the job hands over to a new job
     */
    private const MAX_RUNTIME = 600;

    // Public Properties
    // =========================================================================

    /**
     * @deprecated since 2.3.0 - use the `generateConcurrency` setting
     */
    public int $concurrencies = 5;

    /**
     * @deprecated since 2.3.0 - use the `generateTimeout` setting
     */
    public int $timeout = 30;

    // Private Properties
    // =========================================================================

    private int $processed = 0;

    /**
     * @var array<string, Element|null>
     */
    private array $elements = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Pages queued from now on need a new job, this one might be done with the table already
        Craft::$app->getCache()->delete(PageCacheService::JOB_CACHE_KEY);

        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire(self::MUTEX_NAME)) {
            // Another job is running, it processes the queued pages as well. Check again later,
            // in case it finished before the latest pages were queued.
            PageCache::$plugin->pageCacheService->ensureQueueJob(60);
            return;
        }

        $continue = false;

        try {
            // Rows claimed by a job that crashed
            Db::update(Table::QUEUE, ['claimed' => false], ['claimed' => true]);

            $client = $this->createClient();
            $startedAt = time();

            while (true) {
                $this->processChecks($queue);

                $rows = $this->claimRows(false, self::BATCH_SIZE);
                if (empty($rows)) {
                    break;
                }

                $this->processPages($client, $rows, $queue);
                $this->elements = [];

                // Stay far below the TTR, so the queue never releases the job while it runs; a new job continues
                if (time() - $startedAt > self::MAX_RUNTIME) {
                    $continue = true;
                    break;
                }
            }

            if (!$continue && PageCache::$plugin->settings->trackDependencies) {
                PageCache::$plugin->trackingService->cleanup();
            }
        } finally {
            $mutex->release(self::MUTEX_NAME);
        }

        if ($continue) {
            PageCache::$plugin->pageCacheService->ensureQueueJob();
        }
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // The remaining pages are still in the queue table, a retry continues there
        return $attempt < 3;
    }

    // Private Methods
    // =========================================================================

    private function createClient(): Client
    {
        $settings = PageCache::$plugin->settings;

        $config = [
            'timeout' => $settings->generateTimeout,
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => 'Craft Page Cache',
            ],
        ];

        if ($auth = $settings->getGenerateAuth()) {
            $config['auth'] = $auth;
        }

        // Respects config/guzzle.php (e.g. proxy, verify)
        return Craft::createGuzzleClient($config);
    }

    /**
     * Mark rows as being processed. A row queued again meanwhile is unclaimed and stays for the next batch.
     *
     * @return PageCacheQueueRecord[]
     */
    private function claimRows(bool $check, int $limit): array
    {
        /** @var PageCacheQueueRecord[] $rows */
        $rows = PageCacheQueueRecord::find()
            ->where(['check' => $check, 'claimed' => false])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        if (!empty($rows)) {
            Db::update(Table::QUEUE, ['claimed' => true], ['id' => array_map(fn($row) => $row->id, $rows)]);
        }

        return $rows;
    }

    /**
     * Delete processed rows, unless they were queued again while being processed
     *
     * @param PageCacheQueueRecord[] $rows
     */
    private function releaseRows(array $rows): void
    {
        Db::delete(Table::QUEUE, ['id' => array_map(fn($row) => $row->id, $rows), 'claimed' => true]);
    }

    /**
     * Turn element checks into pages: the pages with an element query that returns the element
     */
    private function processChecks($queue): void
    {
        $tracking = PageCache::$plugin->trackingService;
        $refresh = PageCache::$plugin->refreshCacheService;
        $elements = Craft::$app->getElements();

        while (!empty($rows = $this->claimRows(true, self::CHECK_BATCH_SIZE))) {
            $this->setProgress($queue, 0, Craft::t('pagecache', 'Finding affected pages'));

            // One query per element type and stored query, for all elements of the batch
            $idsByType = [];
            foreach ($rows as $row) {
                // Also finds trashed elements
                $type = $row->elementId ? $elements->getElementTypeById($row->elementId) : null;
                if ($type) {
                    $idsByType[$type][] = (int) $row->elementId;
                }
            }

            $cacheIds = [];
            foreach ($idsByType as $type => $ids) {
                foreach ($tracking->getMatchingCacheIds($type, $ids) as $cacheId) {
                    $cacheIds[$cacheId] = $cacheId;
                }
            }

            $this->releaseRows($rows);

            if (!empty($cacheIds)) {
                $pages = [];
                foreach (PageCacheRecord::find()->where(['id' => array_values($cacheIds)])->each() as $record) {
                    $pages["{$record->siteId}:{$record->url}"] = [
                        'siteId' => (int) $record->siteId,
                        'url' => $record->url,
                        'elementId' => (int) $record->elementId,
                        'delete' => false,
                    ];
                }

                // Clears the pages right away (depending on the refresh mode) and queues them for this job
                $refresh->processPages($pages, false);
            }
        }
    }

    /**
     * @param PageCacheQueueRecord[] $rows
     */
    private function processPages(Client $client, array $rows, $queue): void
    {
        $settings = PageCache::$plugin->settings;
        $pageCacheService = PageCache::$plugin->pageCacheService;
        $deleteService = PageCache::$plugin->deleteCacheService;
        $sites = Craft::$app->getSites();

        $total = $this->processed + count($rows) + (int) PageCacheQueueRecord::find()->where(['check' => false, 'claimed' => false])->count();
        $urls = [];
        $pages = [];

        foreach ($rows as $row) {
            $element = $row->elementId ? $this->getElement($row->elementId, $row->siteId) : null;
            $site = $sites->getSiteById((int) $row->siteId, true);

            // Deleted, disabled or expired pages are only removed from the cache
            if (
                !$site
                || $row->delete
                || !$settings->shouldGenerate()
                || ($row->elementId && (!$element || !$pageCacheService->isLive($element)))
            ) {
                $deleteService->deleteUrl((int) $row->siteId, (string) $row->url);
                continue;
            }

            if ($url = $pageCacheService->getGenerateUrl($site, (string) $row->url)) {
                $urls[] = $url;
                $pages[] = ['siteId' => (int) $row->siteId, 'url' => (string) $row->url];
            }
        }

        $headers = $pageCacheService->getGenerateHeaders();
        $requests = function () use ($client, $urls, $headers) {
            foreach ($urls as $url) {
                yield fn() => $client->getAsync($url, ['headers' => $headers]);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $settings->generateConcurrency),
            'fulfilled' => function () use ($queue, $total) {
                $this->updateProgress($queue, $total);
            },
            'rejected' => function ($reason, $index) use ($urls, $pages, $queue, $total, $deleteService) {
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                Craft::warning("Page Cache could not generate \"{$urls[$index]}\": {$message}", 'pagecache');

                // Don't keep serving an outdated page (refresh mode "keep until generated again")
                $deleteService->deleteUrl($pages[$index]['siteId'], $pages[$index]['url']);
                $this->updateProgress($queue, $total);
            },
        ]);

        $pool->promise()->wait();

        $this->releaseRows($rows);
    }

    private function getElement(?int $id, ?int $siteId): ?Element
    {
        if (!$id) {
            return null;
        }

        $key = "{$id}:{$siteId}";
        if (!array_key_exists($key, $this->elements)) {
            $this->elements[$key] = Craft::$app->getElements()->getElementById($id, null, $siteId);
        }

        return $this->elements[$key];
    }

    private function updateProgress($queue, int $total): void
    {
        $this->processed++;

        $this->setProgress(
            $queue,
            $total ? min(1, $this->processed / $total) : 1,
            Craft::t('app', '{step, number} of {total, number}', [
                'step' => $this->processed,
                'total' => $total,
            ])
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('pagecache', 'Process page cache');
    }
}
