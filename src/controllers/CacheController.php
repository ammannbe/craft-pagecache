<?php

namespace suhype\pagecache\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use suhype\pagecache\PageCache;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Cache controller
 */
class CacheController extends Controller
{
    public $defaultAction = 'refresh';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        return parent::beforeAction($action);
    }

    /**
     * pagecache/cache/refresh action
     */
    public function actionRefresh(): Response
    {
        PageCache::$plugin->refreshCacheService->refreshForElements($this->getElement());

        return $this->asSuccess();
    }

    /**
     * pagecache/cache/delete action
     */
    public function actionDelete(): Response
    {
        PageCache::$plugin->deleteCacheService->deleteForElementWithQuery($this->getElement());

        return $this->asSuccess();
    }

    private function getElement(): ElementInterface
    {
        $elementId = (int) $this->request->getRequiredBodyParam('entryId');
        $siteId = $this->request->getBodyParam('siteId');

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId ? (int) $siteId : null);

        if (!$element) {
            throw new NotFoundHttpException('Element not found');
        }

        // Only users who may edit the element can change its cache
        if (!Craft::$app->getElements()->canSave($element)) {
            throw new ForbiddenHttpException('User not authorized to change the cache of this element.');
        }

        return $element;
    }
}
