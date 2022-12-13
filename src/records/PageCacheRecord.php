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

use suhype\pagecache\PageCache;

use Craft;
use craft\db\ActiveRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 *
 * @property  int $id
 * @property  int $elementId
 * @property  int $siteId
 * @property  string $uri
 */
class PageCacheRecord extends ActiveRecord
{
    // Public Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%pagecache_pagecacherecords}}';
    }
}
