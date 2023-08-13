<?php
/**
 * Page Cache plugin for Craft CMS 4.x
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
 * @property  string $element serialized craft\base\Element
 * @property  string $url
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
