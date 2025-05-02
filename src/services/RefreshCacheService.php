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

        return $this->pushToQueue($elements);
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
            $query = $query->where(['siteId' => $siteId]);
        }

        if ($tags !== null) {
            $tagsQuery = [];
            foreach ($tags as $tag) {
                $tagsQuery[] = ['like', 'tags', '"' . $tag . '"'];
            }
        
            $query->andWhere(['or', ...$tagsQuery]);
        }

        $records = $query->all();

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

        return $this->pushToQueue($elements);
    }
}
