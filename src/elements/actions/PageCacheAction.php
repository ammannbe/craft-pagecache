<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\elements\actions;

use Craft;
use craft\web\View;
use craft\base\ElementAction;
use suhype\pagecache\PageCache;
use craft\elements\db\ElementQueryInterface;

/**
 * DeleteRedirects represents a Delete Redirect element action.
 *
 */
class PageCacheAction extends ElementAction
{
    private const ACTION_RECREATE = 'recreate';
    private const ACTION_RECREATE_AND_DELETE_QUERY = 'recreateAndDeleteQuery';
    private const ACTION_DELETE = 'delete';

    /**
     * @var null|string Change/Delete the page cache
     */
    public $cache = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        \Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_CP);

        // Only enable for elements with url's
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        validateSelection: \$selectedItems => {
            for (let i = 0; i < \$selectedItems.length; i++) {
                if (!Garnish.hasAttr(\$selectedItems.eq(i).find('.element'), 'data-url')) {
                    return false;
                }
            }
            return true;
        },
    });
})();
JS, [static::class]);

        return \Craft::$app->view->renderTemplate('pagecache/_actions/pagecache');
    }

    /**
     * Performs the action on any elements that match the given criteria.
     *
     * @param ElementQueryInterface $query The element query defining which elements the action should affect.
     *
     * @return bool Whether the action was performed successfully.
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        foreach ($query->all() as $element) {
            if (!$element->uri) {
                continue;
            }

            if ($this->cache == self::ACTION_DELETE) {
                PageCache::$plugin->pageCacheService->deleteAllPageCaches($element);
            } elseif ($this->cache == self::ACTION_RECREATE_AND_DELETE_QUERY) {
                PageCache::$plugin->pageCacheService->recreatePageCaches($element, true);
            } else {
                PageCache::$plugin->pageCacheService->recreatePageCaches($element);
            }

        }
        
        $this->setMessage(\Craft::t('pagecache', 'Process page cache'));
        return true;
    }
}
