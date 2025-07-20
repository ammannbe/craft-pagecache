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

    private function createPageCacheFile(Element $element, ?string $query = null, string $html): void
    {
        $path = $this->parsePath($element, $query);

        $html .= $this->getFooter();


        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $hasWritten = file_put_contents($path, $html) !== false;

        if (!$hasWritten) {
            Craft::error(
                Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                __METHOD__
            );
        }

        if ($this->gzip) {
            $hasWritten = file_put_contents("{$path}.gz", gzencode($html, 6)) !== false;

            if (!$hasWritten) {
                Craft::error(
                    Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                    __METHOD__
                );
            }
        }

        if ($this->brotli) {
            $hasWritten = file_put_contents("{$path}.br", brotli_compress($html)) !== false;

            if (!$hasWritten) {
                Craft::error(
                    Craft::t('pagecache', 'Page Cache could not be written to "{path}"', ['path' => $path]),
                    __METHOD__
                );
            }
        }
    }

    /**
     * Delete and warm complete cache
     * 
     * @param int|null $siteId
     * 
     * @return ?bool
     */
    public function create($siteId = null, bool $queue = true): ?bool
    {
        $entries = Entry::find()
            ->siteId($siteId ?? '*')
            ->collect()
            ->filter(fn (Entry $entry) => $entry->getUrl());

        return $this->pushToQueue($entries->toArray());
    }

    public function createFromSiteRequest(Element $element, ?string $query = null, string $html): bool
    {
        if (!$this->shouldCache($element, $query, $html)) {
            PageCache::$plugin->deleteCacheService->deleteForElement($element, $query);
            return false;
        }

        $this->createPageCacheFile($element, $query, $html);

        if (!$this->pageCacheRecordExists($element, $query)) {
            $pageCacheRecord = new PageCacheRecord([
                'elementId' => $element->id,
                'siteId'    => $element->getSite()->id,
                'url'       => $this->parseUrl($element, $query),
                'tags'      => $this->parsePageCacheMetaTag('tags', $html),
            ]);
            return $pageCacheRecord->save();
        }

        return true;
    }
}
