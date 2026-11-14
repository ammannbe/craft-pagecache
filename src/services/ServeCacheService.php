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
use craft\web\Response;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class ServeCacheService extends PageCacheService
{
    /**
     * Send the cached page and end the request, if there is one
     */
    public function serve(Element $element, ?string $query = null): bool
    {
        if (!$this->isCacheableRequest()) {
            return false;
        }

        // e.g. "news/p2" also matches the element, but must not get the cache of "news"
        if (!$this->isRequestForElement($element)) {
            return false;
        }

        if (!$this->shouldCachePage($element, $query)) {
            return false;
        }

        $path = $this->parsePath($element, $query);
        $html = is_file($path) ? @file_get_contents($path) : false;

        if ($html === false) {
            return false;
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_HTML;
        $response->data = $html;
        Craft::$app->end();

        return true;
    }
}
