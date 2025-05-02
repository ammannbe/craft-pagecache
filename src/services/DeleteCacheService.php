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
class DeleteCacheService extends PageCacheService
{
    private function deletePageCacheFile(Element $element, ?string $query = null)
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

    protected function deletePageCacheRecord(Element $element, ?string $query = null)
    {
        $condition = [
            'url' => $this->parseUrl($element, $query),
            'siteId' => $element->getSite()->id,
        ];

        return PageCacheRecord::find()
            ->where($condition)
            ->one()->delete();
    }

    public function deleteForElement(Element $element, ?string $query = null)
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
    public function deleteForElementWithQuery($element)
    {
        $elements = [$element];
        if (is_array($element)) {
            $elements = $element;
        }

        unset($element);

        foreach ($elements as $element) {
            $this->deleteForElement($element);

            $records = $this->getPageCacheQueryRecords($element);
            foreach ($records as $record) {
                $query = explode('?', $record->url)[1];
                $this->deleteForElement($element, $query);
            }
        }
    }

    /**
     * Delete complete cache
     */
    public function delete(?int $siteId = null, ?array $tags = null)
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

        $elements = [];
        foreach ($records as $record) {
            $element = Craft::$app->elements->getElementById($record->elementId, null, $record->siteId);
            if ($element) {
                $elements[] = $element;
            }
        }

        $this->deleteForElementWithQuery($elements);
    }
}
