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
use craft\base\Element;
use craft\queue\BaseJob;

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
     * @var array
     */
    public array $elementIds;

    /**
     * @var bool
     */
    public bool $deleteQuery = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $client = new GuzzleHttp\Client();
        $total = 0;

        $elements = [];
        foreach ($this->elementIds as $elementId) {
            /** @var Element $element */
            $element = Craft::$app->elements->getElementById($elementId['id']);
            $element->siteId = $elementId['siteId'];
            $records = PageCache::$plugin->pageCacheService->getPageCacheQueryRecords($element);

            $elements[] = [
                'element' => $element,
                'records' => $records,
            ];

            $total += count($records) + 1;
        }

        $i = 1;
        foreach ($elements as $el) {
            /** @var Element $element */
            $element = $el['element'];
            $records = $el['records'];

            $this->setProgress(
                $queue,
                $i / $total,
                \Craft::t('app', '{step, number} of {total, number}', [
                    'step' => $i + 1,
                    'total' => $total,
                ])
            );
            $i++;

            PageCache::$plugin->pageCacheService->deleteAllPageCaches($element);
            $url = $element->getSite()->getBaseUrl() . Craft::$app->elements->getElementUriForSite($element->id, $element->siteId);
            $client->getAsync($url);

            if (!$this->deleteQuery) {
                foreach ($records as $record) {
                    $this->setProgress(
                        $queue,
                        $i / $total,
                        \Craft::t('app', '{step, number} of {total, number}', [
                            'step' => $i + 1,
                            'total' => $total,
                        ])
                    );
                    $i++;

                    $query = explode('?', $record->url)[1];
                    $client->getAsync($url . '?' . $query);
                }
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
