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
    private function deletePageCacheFile(?string $path)
    {
        if ($path === null) {
            return;
        }

        foreach ([$path, "{$path}.gz", "{$path}.br"] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $dir = dirname($path);
        if (is_dir($dir) && count(scandir($dir)) == 2) {
            @rmdir($dir);
        }
    }

    public function deleteForElement(Element $element, ?string $query = null)
    {
        $this->deletePageCacheFile($this->parsePath($element, $query));

        PageCacheRecord::deleteAll([
            'url' => $this->parseUrl($element, $query),
            'siteId' => $element->getSite()->id,
        ]);
    }

    /**
     * Delete the cache file and record, also if the element does not exist anymore
     */
    public function deleteRecord(PageCacheRecord $record)
    {
        $site = Craft::$app->getSites()->getSiteById($record->siteId, true);

        if ($site) {
            $this->deletePageCacheFile($this->parsePathForSite($site, $record->url));
        }

        $record->delete();
    }

    /**
     * Delete the page and query cache of the element(s)
     *
     * @param array<Element>|Element $element
     */
    public function deleteForElementWithQuery($element)
    {
        $elements = array_filter(is_array($element) ? $element : [$element]);

        unset($element);

        foreach ($elements as $element) {
            $this->deleteForElement($element);

            foreach ($this->getPageCacheQueryRecords($element) as $record) {
                $this->deleteRecord($record);
            }
        }
    }

    /**
     * Delete the cache of an element whose URI changed (slug or parent), including the pages below it.
     *
     * Craft updates the URIs of descendants without saving them, so their cache would stay
     * at the old URLs.
     */
    public function deleteForUriChange(Element $previous): void
    {
        $this->deleteForElementWithQuery($previous);

        if (!$previous->uri || $previous->uri === Element::HOMEPAGE_URI) {
            return;
        }

        $url = $this->parseUrl($previous);
        $records = PageCacheRecord::find()
            ->where(['like', 'url', addcslashes($url, '%_\\') . '/%', false])
            ->andWhere(['siteId' => $previous->getSite()->id])
            ->all();

        foreach ($records as $record) {
            $this->deleteRecord($record);
        }

        // Also files without record (e.g. after a database import)
        $path = $this->parsePath($previous);
        if ($path !== null) {
            $this->deleteDirectory(dirname($path));
        }
    }

    /**
     * Delete complete cache
     */
    public function delete(?int $siteId = null, ?array $tags = null)
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

        foreach ($query->each() as $record) {
            $this->deleteRecord($record);
        }

        if (!empty($tags)) {
            return;
        }

        // Files without record (e.g. after a database import) would be served by the rewrite rules forever
        if ($siteId === null) {
            $this->deleteCacheFolderContents();
            return;
        }

        $site = Craft::$app->getSites()->getSiteById($siteId, true);
        $siteDir = $site ? $this->getSiteCacheDir($site) : null;
        if ($siteDir === null) {
            return;
        }

        // Don't delete the folder if another site lives below it (e.g. "example.com" and "example.com/de")
        foreach (Craft::$app->getSites()->getAllSites(true) as $otherSite) {
            if ($otherSite->id == $siteId) {
                continue;
            }

            $otherDir = $this->getSiteCacheDir($otherSite);
            if ($otherDir !== null && ($otherDir === $siteDir || str_starts_with($otherDir . '/', $siteDir . '/'))) {
                return;
            }
        }

        $this->deleteDirectory($siteDir);
    }
}
