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
use craft\elements\Entry;
use suhype\pagecache\PageCache;
use suhype\pagecache\records\PageCacheRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class CreateCacheService extends PageCacheService
{
    private function getFooter(): string
    {
        return '<!-- Cached by Page Cache at ' . gmdate('Y-m-d\TH:i:s') . ' -->';
    }

    /**
     * Write to a temporary file first, so a rewrite rule never serves a half written file
     */
    private function writeFile(string $path, string $contents): bool
    {
        $tmpPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmpPath, $contents) !== false && @rename($tmpPath, $path)) {
            return true;
        }

        @unlink($tmpPath);

        Craft::error(
            Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
            __METHOD__
        );

        return false;
    }

    private function createPageCacheFile(Element $element, ?string $query, string $html): bool
    {
        $path = $this->parsePath($element, $query);

        if ($path === null) {
            return false;
        }

        $html .= $this->getFooter();

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Craft::error(
                Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                __METHOD__
            );

            return false;
        }

        if (!$this->writeFile($path, $html)) {
            return false;
        }

        if ($this->gzip) {
            $this->writeFile("{$path}.gz", gzencode($html, 6));
        }

        if ($this->brotli) {
            $this->writeFile("{$path}.br", brotli_compress($html));
        }

        return true;
    }

    public function create($siteId = null, bool $queue = true): ?bool
    {
        $entries = Entry::find()
            ->siteId($siteId ?? '*')
            ->uri(':notempty:')
            ->collect()
            ->filter(fn (Entry $entry) => $entry->getUrl());

        $this->pushToQueue($entries->all());

        return true;
    }

    public function createFromSiteRequest(Element $element, ?string $query, string $html): bool
    {
        if (!$this->shouldCache($element, $query, $html)) {
            PageCache::$plugin->deleteCacheService->deleteForElement($element, $query);
            return false;
        }

        if (!$this->createPageCacheFile($element, $query, $html)) {
            return false;
        }

        $condition = [
            'url'    => $this->parseUrl($element, $query),
            'siteId' => $element->getSite()->id,
        ];

        $pageCacheRecord = PageCacheRecord::findOne($condition) ?? new PageCacheRecord($condition);
        $pageCacheRecord->elementId = $element->id;
        $pageCacheRecord->tags = $this->parsePageCacheMetaTag('tags', $html);

        return $pageCacheRecord->save();
    }
}
