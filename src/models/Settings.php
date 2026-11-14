<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\models;

use craft\base\Model;
use craft\helpers\App;
use suhype\pagecache\PageCache;

class Settings extends Model
{
    /**
     * Delete the cache right away, then generate it again in the queue
     */
    public const REFRESH_MODE_CLEAR_AND_GENERATE = 'clearAndGenerate';

    /**
     * Keep serving the old cache until the queue generated the new one
     */
    public const REFRESH_MODE_EXPIRE_AND_GENERATE = 'expireAndGenerate';

    /**
     * Delete the cache right away, the next visitor generates it again
     */
    public const REFRESH_MODE_CLEAR = 'clear';

    public bool $enabled = false;
    public bool $gzip = true;
    public bool $brotli = true;
    public string $globalSaveAction = PageCache::GLOBAL_ACTION_REFRESH;

    /**
     * What happens with the cache of a page when its content changes
     */
    public string $refreshMode = self::REFRESH_MODE_CLEAR_AND_GENERATE;

    /**
     * Track which elements and element queries a page uses, so the page is refreshed
     * when one of them changes (e.g. a news listing when a news entry is saved)
     */
    public bool $trackDependencies = true;

    /**
     * Cache pages with a query string. Every new query string creates a new cache file,
     * so only enable this together with excluded/included URL's.
     */
    public bool $cacheQueryStrings = false;
    public array $excludedUrls = [];
    public array $includedUrls = [];
    public string $cacheFolderPath = '@webroot/pagecache';

    /**
     * Number of pages the queue job requests at the same time
     */
    public int $generateConcurrency = 5;

    /**
     * Timeout in seconds per page request of the queue job
     */
    public int $generateTimeout = 30;

    /**
     * Basic auth for the page requests of the queue job (e.g. staging), environment variables allowed
     */
    public string $generateUsername = '';
    public string $generatePassword = '';

    /**
     * Show the Page Cache utility in the main navigation
     */
    public bool $showInCpNav = false;

    /**
     * Show a "Refresh cache" item in the main navigation, which refreshes all cached pages
     */
    public bool $showRefreshInCpNav = false;

    public function rules(): array
    {
        return [
            ['excludedUrls', 'default', 'value' => []],
            ['includedUrls', 'default', 'value' => []],
            ['cacheFolderPath', 'default', 'value' => '@webroot/pagecache'],
            ['globalSaveAction', 'default', 'value' => PageCache::GLOBAL_ACTION_REFRESH],
            ['refreshMode', 'default', 'value' => self::REFRESH_MODE_CLEAR_AND_GENERATE],
            ['refreshMode', 'in', 'range' => [
                self::REFRESH_MODE_CLEAR_AND_GENERATE,
                self::REFRESH_MODE_EXPIRE_AND_GENERATE,
                self::REFRESH_MODE_CLEAR,
            ]],
            [['generateConcurrency', 'generateTimeout'], 'integer', 'min' => 1],
            // A literal password would end up in the project config (and in git)
            ['generatePassword', 'match', 'pattern' => '/^(\$[A-Za-z0-9_]+)?$/', 'message' => \Craft::t('pagecache', 'Use an environment variable (e.g. `$BASIC_AUTH_PASSWORD`), so the password is not stored in the project config.')],
        ];
    }

    public function shouldGenerate(): bool
    {
        return $this->refreshMode !== self::REFRESH_MODE_CLEAR;
    }

    public function shouldClearImmediately(): bool
    {
        return $this->refreshMode !== self::REFRESH_MODE_EXPIRE_AND_GENERATE;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public function getGenerateAuth(): ?array
    {
        $username = (string) App::parseEnv($this->generateUsername);

        if ($username === '') {
            return null;
        }

        return [$username, (string) App::parseEnv($this->generatePassword)];
    }
}
