<?php

namespace suhype\pagecache\controllers;

use Craft;
use craft\web\Controller;
use suhype\pagecache\PageCache;
use yii\web\Response;

/**
 * Cache controller
 */
class CacheController extends Controller
{
    public $defaultAction = 'refresh';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * pagecache/cache action
     */
    public function actionRefresh()
    {
        $entryId = Craft::$app->request->getBodyParam('entryId');
        if (!$entryId) {
            Craft::$app->end();
        }

        $element = Craft::$app->elements->getElementById($entryId, null);

        PageCache::$plugin->refreshCacheService->refreshForElements($element);
    }

    /**
     * pagecache/cache action
     */
    public function actionDelete()
    {
        $entryId = Craft::$app->request->getBodyParam('entryId');
        if (!$entryId) {
            Craft::$app->end();
        }

        $element = Craft::$app->elements->getElementById($entryId, null);

        PageCache::$plugin->deleteCacheService->deleteForElementWithQuery($element);
    }
}
