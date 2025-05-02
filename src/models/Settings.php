<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\models;

use craft\base\Model;
use suhype\pagecache\PageCache;

class Settings extends Model
{
    public bool $enabled = false;
    public bool $gzip = true;
    public bool $brotli = true;
    public string $globalSaveAction = PageCache::GLOBAL_ACTION_REFRESH;
    public array $excludedUrls = [];
    public array $includedUrls = [];
    public string $cacheFolderPath = '@webroot/pagecache';

    public function rules(): array
    {
        return [
            ['excludedUrls', 'default', 'value' => []],
            ['includedUrls', 'default', 'value' => []],
            ['cacheFolderPath', 'default', 'value' => '@webroot/pagecache'],
            ['globalSaveAction', 'default', 'value' => PageCache::GLOBAL_ACTION_REFRESH],
        ];
    }
}
