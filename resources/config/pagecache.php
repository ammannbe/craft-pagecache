<?php

use suhype\pagecache\PageCache;

return [
    'enabled' => true,
    'gzip' => true,
    'brotli' => true, // NOTE: the brotli extension must be installed
    'globalSaveAction' => PageCache::GLOBAL_ACTION_REFRESH,
    'excludedUrls' => [
        ['siteId' => null, 'path' => '\?(.*)'], // exclude all query strings
        // ['siteId' => null, 'path' => '^\/mysite$'],
    ],
    'includedUrls' => [
        // ['siteId' => null, 'path' => '\?something=(.*)'],
    ],
    'cacheFolderPath' => '@webroot/pagecache',
];
