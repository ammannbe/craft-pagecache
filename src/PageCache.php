<?php

/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache;

use Craft;
use craft\web\View;
use yii\base\Event;
use craft\base\Plugin;
use craft\base\Element;
use craft\elements\Entry;
use craft\web\Application;
use craft\services\Plugins;
use craft\services\Elements;
use craft\elements\GlobalSet;
use craft\events\PluginEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\utilities\ClearCaches;
use suhype\pagecache\models\Settings;
use craft\events\RegisterCacheOptionsEvent;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterElementActionsEvent;
use suhype\pagecache\services\PageCacheService;
use craft\console\Application as ConsoleApplication;
use craft\events\DefineHtmlEvent;
use suhype\pagecache\elements\actions\PageCacheAction;
use suhype\pagecache\services\CreateCacheService;
use suhype\pagecache\services\DeleteCacheService;
use suhype\pagecache\services\RefreshCacheService;
use suhype\pagecache\services\ServeCacheService;
use suhype\pagecache\variables\PageCacheVariable;

/**
 * Class PageCache
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 *
 * @property  PageCacheService $pageCacheService
 * @property  DeleteCacheService $deleteCacheService
 * @property  CreateCacheService $createCacheService
 * @property  RefreshCacheService $refreshCacheService
 * @property  ServeCacheService $serveCacheService
 */
class PageCache extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var PageCache
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.2.3';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    /**
     * @deprecated since 2.0.0 - use PageCacheService::GLOBAL_ACTION_REFRESH
     */
    public const GLOBAL_ACTION_RECREATE = 'recreate';

    public const GLOBAL_ACTION_REFRESH = 'refresh';
    public const GLOBAL_ACTION_DELETE = 'delete';

    // Protected Methods
    // =========================================================================

    private function _registerInstallEvents(): void
    {
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    $this->pageCacheService->createCacheFolder();
                }
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_BEFORE_UNINSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    $this->pageCacheService->deleteCacheFolder();
                }
            }
        );
    }

    private function _registerVariableEvent()
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pagecache', PageCacheVariable::class);
            }
        );
    }

    private function _registerElementEvents(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Element $element */
                $entry = $event->sender;

                if (!$entry->uri || ElementHelper::isDraftOrRevision($entry)) {
                    return;
                }

                $event->html .= Craft::$app->view->renderTemplate(
                    'pagecache/_sidebar/element',
                    [
                        'entry' => $entry,
                        'isCached' => $this->pageCacheService->isCached($entry),
                    ]
                );
            },
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri || ElementHelper::isDraftOrRevision($event->element)) {
                    return;
                }

                // Check if the the old slug is not equal the new one
                $element = Entry::find()->id($event->element->id)->one();
                if ($element && $event->element->slug !== $element->slug) {
                    $elements = $this->pageCacheService->getRelatedElements($element);
                    $this->deleteCacheService->deleteForElementWithQuery([$element, ...$elements]);
                }
            }
        );

        Event::on(
            GlobalSet::class,
            GlobalSet::EVENT_AFTER_SAVE,
            function (Event $event) {
                switch (PageCache::$plugin->settings->globalSaveAction) {
                    case PageCache::GLOBAL_ACTION_DELETE:
                        $this->deleteCacheService->delete($event->sender->siteId);
                        break;

                    default:
                        $this->refreshCacheService->refresh($event->sender->siteId);
                        break;
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (Event $event) {
                $activeStates = [
                    Entry::STATUS_LIVE,
                    Element::STATUS_ENABLED,
                ];

                $elements = $this->pageCacheService->getRelatedElements($event->element);
                $elements[$event->element->id] = $event->element;

                $toDelete = [];
                $toRefresh = [];

                foreach ($elements as $element) {
                    if (!$element->uri || ElementHelper::isDraftOrRevision($element) || $element->propagating || $element->resaving) {
                        continue;
                    }

                    if (!in_array($element->getStatus(), $activeStates)) {
                        $toDelete[$element->id] = $element;
                        continue;
                    }

                    $toRefresh[$element->id] = $element;
                }

                $this->deleteCacheService->deleteForElementWithQuery($toDelete);
                $this->refreshCacheService->refreshForElements($toRefresh);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri) {
                    return;
                }

                $this->deleteCacheService->deleteForElementWithQuery($event->element);
            }
        );
    }

    private function _registerActionEvents(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_ACTIONS,
            function (RegisterElementActionsEvent $event) {
                [$type, $uid] = explode(':', $event->source) + [null, null];

                if ($type == 'singles') {
                    $event->actions[] = PageCacheAction::class;
                    return;
                } elseif ($type == 'section') {
                    /** @var \craft\models\Section $section */
                    $section = Craft::$app->getEntries()->getSectionByUid($uid);
                    $settings = $section->getSiteSettings();
                    $setting = reset($settings);

                    if ($setting->hasUrls) {
                        $event->actions[] = PageCacheAction::class;
                    }

                    return;
                }
            }
        );
    }

    private function _registerClearCaches(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'pagecache',
                    'label' => Craft::t('pagecache', 'Page Cache'),
                    'action' => [PageCache::$plugin->deleteCacheService, 'delete'],
                ];
            }
        );
    }

    // Protected Methods
    // =========================================================================

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'pagecache/settings',
            ['settings' => $this->getSettings()]
        );
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'pageCacheService' => PageCacheService::class,
            'createCacheService' => CreateCacheService::class,
            'refreshCacheService' => RefreshCacheService::class,
            'deleteCacheService' => DeleteCacheService::class,
            'serveCacheService' => ServeCacheService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'suhype\pagecache\console\controllers';
        }

        if (Craft::$app->request->getIsSiteRequest()) {
            Event::on(
                Application::class,
                Application::EVENT_INIT,
                function () {
                    $element = Craft::$app->getUrlManager()->getMatchedElement();
                    if ($element && $element->uri) {
                        $this->serveCacheService->serve($element, Craft::$app->request->getQueryStringWithoutPath());
                    }
                }
            );
        }

        $this->_registerInstallEvents();
        $this->_registerVariableEvent();
        $this->_registerElementEvents();
        $this->_registerActionEvents();
        $this->_registerClearCaches();

        if (Craft::$app->request->getIsSiteRequest() && Craft::$app->request->getIsGet() && Craft::$app->user->isGuest) {
            Event::on(
                View::class,
                View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    if (!Craft::$app->getResponse()->getIsOk()) {
                        return;
                    }

                    $element = Craft::$app->getUrlManager()->getMatchedElement();

                    if (!$element) {
                        return;
                    }

                    $this->createCacheService->createFromSiteRequest($element, Craft::$app->request->getQueryStringWithoutPath(), $event->output);
                }
            );
        }

        Craft::info(
            Craft::t(
                'pagecache',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }
}
