<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\controllers;

use Craft;
use craft\web\Controller;
use suhype\pagecache\PageCache;
use suhype\pagecache\utilities\PageCacheUtility;
use yii\web\Response;

/**
 * Actions of the Page Cache utility
 *
 * @since 2.3.0
 */
class UtilityController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!in_array($action->id, ['index', 'stats'], true)) {
            $this->requirePostRequest();
        }

        $this->requireCpRequest();
        $this->requirePermission('utility:' . PageCacheUtility::id());

        return parent::beforeAction($action);
    }

    /**
     * Own control panel page (main navigation), same content as the utility
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pagecache/_page/index', PageCacheUtility::templateVariables());
    }

    /**
     * Numbers of the overview, polled by the utility
     */
    public function actionStats(): Response
    {
        return $this->asJson(PageCache::$plugin->diagnosticsService->getStats());
    }

    /**
     * Hide a notice until its content changes
     */
    public function actionDismissNotice(): Response
    {
        PageCache::$plugin->diagnosticsService->dismissNotice((string) $this->request->getRequiredBodyParam('key'));

        return $this->asSuccess();
    }

    /**
     * Delete the whole cache (or of one site)
     */
    public function actionClear(): Response
    {
        PageCache::$plugin->deleteCacheService->delete($this->getSiteId());

        return $this->asSuccess(Craft::t('pagecache', 'The page cache has been deleted.'));
    }

    /**
     * Refresh all cached pages, depending on the refresh mode
     */
    public function actionRefresh(): Response
    {
        PageCache::$plugin->refreshCacheService->refresh($this->getSiteId(), $this->getTags());

        return $this->asSuccess(Craft::t('pagecache', 'The page cache will be refreshed.'));
    }

    /**
     * Generate the cache of all pages with a URL
     */
    public function actionWarm(): Response
    {
        PageCache::$plugin->createCacheService->create($this->getSiteId());

        return $this->asSuccess(Craft::t('pagecache', 'All pages will be cached.'));
    }

    /**
     * Refresh pages by URL
     */
    public function actionRefreshUrls(): Response
    {
        $urls = preg_split('/\R/', (string) $this->request->getBodyParam('urls', '')) ?: [];
        $count = PageCache::$plugin->refreshCacheService->refreshUrls($urls);

        return $this->asSuccess(Craft::t('pagecache', '{count, plural, =1{# page} other{# pages}} will be refreshed.', ['count' => $count]));
    }

    /**
     * Scan the templates again and forget the pages found while rendering (e.g. after fixing templates)
     */
    public function actionRescanTemplates(): Response
    {
        PageCache::$plugin->templateAuditService->rescan();
        PageCache::$plugin->diagnosticsService->clearNotices();

        return $this->asSuccess(Craft::t('pagecache', 'The templates will be checked again.'));
    }

    /**
     * Forget the stored notices (e.g. after fixing a template)
     */
    public function actionClearNotices(): Response
    {
        PageCache::$plugin->diagnosticsService->clearNotices();

        return $this->asSuccess(Craft::t('pagecache', 'Notices cleared.'));
    }

    private function getSiteId(): ?int
    {
        $siteId = $this->request->getBodyParam('siteId');

        return $siteId ? (int) $siteId : null;
    }

    private function getTags(): ?array
    {
        $tags = array_values(array_filter(array_map('trim', explode(',', (string) $this->request->getBodyParam('tags', ''))), 'strlen'));

        return $tags ?: null;
    }
}
