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
        $total = 0;

        $urls = [];
        foreach ($this->elementIds as $elementId) {
            /** @var Element $element */
            $element = Craft::$app->elements->getElementById($elementId['id'], null, $elementId['siteId']);
            $queryRecords = PageCache::$plugin->pageCacheService->getPageCacheQueryRecords($element);

            $url = $element->getSite()->getBaseUrl() . Craft::$app->elements->getElementUriForSite($element->id, $element->siteId);
            $url = str_replace(Element::HOMEPAGE_URI, '', $url);
            $urls[] = [
                'element' => $element,
                'query' => null,
                'url' => $url,
                'cache' => true,
            ];

            foreach ($queryRecords as $queryRecord) {
                $query = explode('?', $queryRecord->url)[1];

                $urls[] = [
                    'element' => $element,
                    'query' => $query,
                    'url' => $url . '?' . $query,
                    'cache' => !$this->deleteQuery,
                ];
            }
        }

        $total = count($urls);
        $promises = [];
        $i = 0;
        foreach ($urls as $key => $url) {
            PageCache::$plugin->pageCacheService->deletePageCache($url['element'], $url['query']);

            if ($url['cache']) {
                $promises[] = $client->getAsync($url['url']);
            }

            if (count($promises) >= $this->concurrencies || $key === array_key_last($urls)) {
                GuzzleHttp\Promise\Utils::settle($promises)->wait();
                $promises = [];
            }

            $this->setProgress(
                $queue,
                $i / $total,
                \Craft::t('app', '{step, number} of {total, number}', [
                    'step' => $i + 1,
                    'total' => $total,
                ])
            );
            $i++;
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
