<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\records;

use craft\db\ActiveRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     1.2.0
 *
 * @property  int $id
 * @property  int|null $elementId
 * @property  int|null $siteId
 * @property  string|null $url Path with query string (e.g. "/news?page=2"), null for an element check
 * @property  string $urlHash
 * @property  bool $delete Only delete the cache, don't generate it again
 * @property  bool $check Find the pages whose element queries match the element
 */
class PageCacheQueueRecord extends ActiveRecord
{
    // Public Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%pagecache_pagecachequeuerecord}}';
    }
}
