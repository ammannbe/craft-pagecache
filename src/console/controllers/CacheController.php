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
 * Cache page cache
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
     * Create page cache
     *
     * @return int
     */
    public function actionIndex()
    {
        Console::output('Start the create cache job...');

        $siteId = $this->siteId ?? null;
        $tags = explode(',', $this->tags) ?? null;

        PageCache::$plugin->refreshCacheService->refresh($siteId, $tags);

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

        $siteId = $this->siteId ?? null;
        $tags = explode(',', $this->tags) ?? null;

        PageCache::$plugin->deleteCacheService->delete($siteId, $tags);

        Console::output('Done');
        return ExitCode::OK;
    }
}
