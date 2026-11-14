<?php

/**
 * Page Cache config
 *
 * Copy this file to config/pagecache.php. Every setting set here is locked in the control panel
 * (Settings → Page Cache). Remove a setting to manage it in the control panel (project config) again.
 * Multi-environment configs are supported, e.g. ['*' => [...], 'dev' => [...]].
 */

use craft\helpers\App;
use suhype\pagecache\models\Settings;
use suhype\pagecache\PageCache;

return [
    // Cache pages and serve them from the cache
    'enabled' => App::parseBooleanEnv('$PAGECACHE_ENABLED') ?? false,

    // What happens with the cache of a page when its content changes:
    // Settings::REFRESH_MODE_CLEAR_AND_GENERATE  delete right away, the queue job caches the page again (default)
    // Settings::REFRESH_MODE_EXPIRE_AND_GENERATE keep serving the old page until the queue job cached the new one
    // Settings::REFRESH_MODE_CLEAR               delete right away, the next visitor caches the page again
    'refreshMode' => Settings::REFRESH_MODE_CLEAR_AND_GENERATE,

    // Remember which elements and element queries a page uses, to refresh it when one of them changes
    'trackDependencies' => true,

    // What happens when globals are saved: PageCache::GLOBAL_ACTION_REFRESH or PageCache::GLOBAL_ACTION_DELETE
    'globalSaveAction' => PageCache::GLOBAL_ACTION_REFRESH,

    // Show the Page Cache utility in the main navigation
    'showInCpNav' => false,

    // Show "Refresh cache" in the main navigation, refreshes all cached pages with one click
    'showRefreshInCpNav' => false,

    // Cache pages with a query string, every query string creates its own cache file
    'cacheQueryStrings' => false,

    // Never cached (regular expressions possible, "#" is the delimiter), siteId null = all sites
    'excludedUrls' => [
        // ['siteId' => null, 'path' => '^news/archive'],
    ],

    // Always cached, also with a query string; overrides excludedUrls and cacheQueryStrings
    'includedUrls' => [
        // ['siteId' => null, 'path' => '\?category=(.*)'],
    ],

    // Dedicated folder for the cache files (the rewrite rules expect @webroot/pagecache)
    'cacheFolderPath' => '@webroot/pagecache',

    // Also store .gz/.br files for the rewrite rules (brotli requires the PHP brotli extension)
    'gzip' => true,
    'brotli' => true,

    // Queue job: pages requested at the same time, timeout per page in seconds
    'generateConcurrency' => 5,
    'generateTimeout' => 30,

    // Queue job: basic auth (e.g. staging), environment variables
    'generateUsername' => '',
    'generatePassword' => '',
    // 'generateUsername' => '$BASIC_AUTH_USER',
    // 'generatePassword' => '$BASIC_AUTH_PASSWORD',
];
