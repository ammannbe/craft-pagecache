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

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class ServeCacheService extends PageCacheService
{
    private function shouldCacheRequest(): bool
    {
        if (Craft::$app->request->getIsCpRequest()) {
            return false;
        }

        if (Craft::$app->request->getIsActionRequest()) {
            return false;
        }

        if (Craft::$app->request->getIsLivePreview()) {
            return false;
        }

        if (!Craft::$app->request->getIsGet()) {
            return false;
        }

        if (Craft::$app->request->getIsAjax()) {
            return false;
        }

        return true;
    }

    private function getPageCacheFileContents(Element $element, ?string $query = null)
    {
        $path = $this->parsePath($element, $query);

        return file_get_contents($path);
    }

    /**
     * Serve cache if exists
     *
     * @return ?bool
     */
    public function serve(Element $element, ?string $query = null): ?bool
    {
        if (!$this->shouldCacheRequest()) {
            return false;
        }

        if (!$this->shouldCachePage($element, $query)) {
            return false;
        }

        if (!$this->pageCacheFileExists($element, $query)) {
            PageCache::$plugin->deleteCacheService->deleteForElement($element, $query);
            return false;
        }

        Craft::$app->response->data = $this->getPageCacheFileContents($element, $query);
        Craft::$app->end();
    }
}
