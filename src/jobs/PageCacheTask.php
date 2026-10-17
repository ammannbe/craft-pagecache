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
use craft\elements\Entry;
use GuzzleHttp;
use craft\queue\BaseJob;
use suhype\pagecache\records\PageCacheQueueRecord;
use yii\queue\RetryableJobInterface;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheTask extends BaseJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================

    /**
     * @var int
     */
    public int $concurrencies = 5;

    /**
     * @var int Timeout in seconds per page request
     */
    public int $timeout = 30;

    // Private Properties
    // =========================================================================

    /**
     * @var int
     */
    private int $total = 0;

    /**
     * @var int
     */
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
        $client = new GuzzleHttp\Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ]);

        while (!empty($queueRecords = PageCacheQueueRecord::find()->orderBy(['id' => SORT_ASC])->all())) {
            $this->total = count($queueRecords);
            $this->processed = 0;
            $this->elements = [];
            $promises = [];

            /** @var PageCacheQueueRecord $queueRecord */
            foreach ($queueRecords as $queueRecord) {
                $element = $this->getElement($queueRecord);
                $query = explode('?', (string) $queueRecord->url, 2)[1] ?? null;

                if ($element) {
                    PageCache::$plugin->deleteCacheService->deleteForElement($element, $query);

                    // Disabled or expired pages are only removed from the cache
                    if (!$queueRecord->delete && $this->isLive($element)) {
                        $promises[] = $client->getAsync($queueRecord->url);
                    }
                }

                $queueRecord->delete();

                if (count($promises) >= $this->concurrencies) {
                    $this->settle($promises);
                    $promises = [];
                }

                $this->updateProgress($queue);
            }

            $this->settle($promises);
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

    private function getElement(PageCacheQueueRecord $queueRecord): ?Element
    {
        $reference = json_decode((string) $queueRecord->element);
        $id = $reference->id ?? null;
        $siteId = $reference->siteId ?? null;

        if (!$id) {
            return null;
        }

        $key = "{$id}:{$siteId}";
        if (!array_key_exists($key, $this->elements)) {
            $this->elements[$key] = Craft::$app->getElements()->getElementById((int) $id, null, $siteId ? (int) $siteId : null);
        }

        return $this->elements[$key];
    }

    private function isLive(Element $element): bool
    {
        return in_array($element->getStatus(), [Entry::STATUS_LIVE, Element::STATUS_ENABLED], true);
    }

    private function settle(array $promises): void
    {
        if (empty($promises)) {
            return;
        }

        $responses = GuzzleHttp\Promise\Utils::settle($promises)->wait();
        foreach ($responses as $response) {
            if ($response['state'] === 'rejected') {
                $reason = $response['reason'];
                Craft::error($reason instanceof \Throwable ? $reason->getMessage() : (string) $reason, 'pagecache');
            }
        }
    }

    private function updateProgress($queue): void
    {
        $this->processed++;

        $this->setProgress(
            $queue,
            $this->total ? $this->processed / $this->total : 1,
            \Craft::t('app', '{step, number} of {total, number}', [
                'step' => $this->processed,
                'total' => $this->total,
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
