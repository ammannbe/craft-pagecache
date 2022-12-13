<?php

use craft\helpers\App;

$isDev = App::env('CRAFT_ENVIRONMENT') === 'dev';
$isProd = App::env('CRAFT_ENVIRONMENT') === 'production';

return [
    'enabled' => true,
    'optimize' => true,
    'gzip' => true,
    'brotli' => true, // NOTE: the brotli extension must be installed
    'excludedUrls' => [
        // ['siteId' => null, 'path' => '^\/mysite$'],
    ],
    'cacheFolderPath' => '@webroot/pagecache',
];
