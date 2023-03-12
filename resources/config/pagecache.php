<?php

use craft\helpers\App;
use suhype\pagecache\PageCache;

$isDev = App::env('CRAFT_ENVIRONMENT') === 'dev';
$isProd = App::env('CRAFT_ENVIRONMENT') === 'production';

return [
    'enabled' => true,
    'optimize' => true,
    'gzip' => true,
    'brotli' => true, // NOTE: the brotli extension must be installed
    'globalSaveAction' => PageCache::GLOBAL_ACTION_RECREATE,
    'excludedUrls' => [
        // ['siteId' => null, 'path' => '^\/mysite$'],
    ],
    'cacheFolderPath' => '@webroot/pagecache',
];
