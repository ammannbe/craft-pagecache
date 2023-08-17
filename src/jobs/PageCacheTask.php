<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\jobs;

use suhype\pagecache\PageCache;

use Craft;
use GuzzleHttp;
use craft\queue\BaseJob;
use suhype\pagecache\records\PageCacheQueueRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheTask extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int
     */
    public int $concurrencies = 5;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $client = new GuzzleHttp\Client();

        while (!empty($queueRecords = PageCacheQueueRecord::find()->all())) {
            $total = PageCacheQueueRecord::find()->count();
            $processed = 0;
            $promises = [];

            /** @var PageCacheQueueRecord $queueRecord */
            foreach ($queueRecords as $key => $queueRecord) {
                $element = unserialize($queueRecord->element);
                $query = explode('?', $queueRecord->url)[1] ?? null;
                PageCache::$plugin->pageCacheService->deletePageCache($element, $query);

                if (!$queueRecord->delete) {
                    $promises[] = $client->getAsync($queueRecord->url);

                    if (count($promises) >= $this->concurrencies || $key === array_key_last($queueRecords)) {
                        $responses = GuzzleHttp\Promise\Utils::settle($promises)->wait();
                        foreach ($responses as $response) {
                            if ($response['state'] === 'rejected') {
                                \Craft::error($response['reason'], 'pagecache');
                            }
                        }
                        $promises = [];
                    }
                }

                $queueRecord->delete();

                $this->setProgress(
                    $queue,
                    $processed / $total,
                    \Craft::t('app', '{step, number} of {total, number}', [
                        'step' => $processed + 1,
                        'total' => $total,
                    ])
                );
                $processed++;
            }
        }
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
