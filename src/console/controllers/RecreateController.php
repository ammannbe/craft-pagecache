<?php
/**
 * Page Cache plugin for Craft CMS 4.x
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
 * Recreate page cache
 * 
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     1.2.1
 */
class RecreateController extends Controller
{
    /**
     * @var int|null The site ID or NULL for all sites.
     */
    public ?int $siteId = null;

    /**
     * @var bool Delete URLs with query strings or not.
     */
    public bool $deleteQuery = false;

    // Public Methods
    // =========================================================================

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'index':
                $options[] = 'siteId';
                $options[] = 'deleteQuery';
                break;
        }

        return $options;
    }

    /**
     * Recreate page cache
     *
     * @return int
     */
    public function actionIndex()
    {
        Console::output('Start the recreate page cache job...');

        $siteId = $this->siteId ?? null;
        $deleteQuery = $this->deleteQuery ?? false;

        PageCache::$plugin->pageCacheService->recreateAllPageCaches($deleteQuery, $siteId);

        Console::output('Job successfully queued and started.');
        return ExitCode::OK;
    }
}
