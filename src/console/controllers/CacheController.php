<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\console\controllers;

use suhype\pagecache\PageCache;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manage page cache
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     1.2.1
 */
class CacheController extends Controller
{
    /**
     * @var int|null The site ID or NULL for all sites.
     */
    public ?int $siteId = null;

    /**
     * @var string|null The tags comma separated or NULL for all tags.
     */
    public ?string $tags = null;

    // Public Methods
    // =========================================================================

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'index':
                $options[] = 'siteId';
                break;

            case 'refresh':
                $options[] = 'siteId';
                $options[] = 'tags';
                break;

            case 'delete':
                $options[] = 'siteId';
                $options[] = 'tags';
                break;
        }

        return $options;
    }

    /**
     * Delete existing page cache and warm all pages
     *
     * @return int
     */
    public function actionIndex()
    {
        Console::output('Start the warm cache job...');

        $siteId = $this->siteId ?? null;

        PageCache::$plugin->createCacheService->create($siteId);

        Console::output('Job successfully queued and started.');
        return ExitCode::OK;
    }

    /**
     * Refresh already existing page cache
     *
     * @return int
     */
    public function actionRefresh()
    {
        Console::output('Start the refresh cache job...');

        PageCache::$plugin->refreshCacheService->refresh($this->siteId, $this->parseTags());

        Console::output('Job successfully queued and started.');
        return ExitCode::OK;
    }

    /**
     * Delete page cache
     *
     * @return int
     */
    public function actionDelete()
    {
        Console::output('Delete cache...');

        PageCache::$plugin->deleteCacheService->delete($this->siteId, $this->parseTags());

        Console::output('Done');
        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Tags from --tags, or null for all pages
     */
    private function parseTags(): ?array
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->tags)), 'strlen'));

        return $tags ?: null;
    }
}
