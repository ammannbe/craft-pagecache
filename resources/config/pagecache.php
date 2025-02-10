<?php

use suhype\pagecache\PageCache;

return [
    'enabled' => true,
    'optimize' => false,
    'gzip' => true,
    'brotli' => true, // NOTE: the brotli extension must be installed
    'globalSaveAction' => PageCache::GLOBAL_ACTION_RECREATE,
    'excludedUrls' => [
        ['siteId' => null, 'path' => '\?(.*)'], // exclude all query strings
        // ['siteId' => null, 'path' => '^\/mysite$'],
    ],
    'includedUrls' => [
        // ['siteId' => null, 'path' => '\?something=(.*)'],
    ],
    'cacheFolderPath' => '@webroot/pagecache',
];
