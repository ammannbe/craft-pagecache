<?php

use suhype\pagecache\PageCache;

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
