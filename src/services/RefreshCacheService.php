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
use suhype\pagecache\PageCache;
use suhype\pagecache\records\PageCacheRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class RefreshCacheService extends PageCacheService
{
    /**
     * Refresh cache of element(s)
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

        $this->pushToQueue($elements);

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

        /** @var array<string, Element> $elements */
        $elements = [];
        foreach ($query->all() as $record) {
            $key = "{$record->elementId}:{$record->siteId}";
            if (isset($elements[$key])) {
                continue;
            }

            $element = Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);

            if (!$element) {
                PageCache::$plugin->deleteCacheService->deleteRecord($record);
                continue;
            }

            $elements[$key] = $element;
        }

        $this->pushToQueue(array_values($elements));

        return true;
    }
}
