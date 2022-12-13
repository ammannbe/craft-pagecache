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

class Settings extends Model
{
    public bool $enabled = false;
    public bool $optimize = true;
    public bool $gzip = true;
    public bool $brotli = true;
    public array $excludedUrls = [];
    public string $cacheFolderPath = '@webroot/pagecache';

    public function rules(): array
    {
        return [
            [['excludedUrls', 'cacheFolderPath'], 'default', 'value' => []],
            [['enabled', 'optimize', 'gzip', 'brotli'], 'default', 'value' => true],
        ];
    }
}
