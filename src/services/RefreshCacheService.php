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
    public function refresh($siteId = null, bool $queue = true): ?bool
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

        // TODO: delete cache beforehand

        return $this->pushToQueue($elements);
    }
}
