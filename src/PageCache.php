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
use suhype\pagecache\services\TrackingService;
use suhype\pagecache\services\DiagnosticsService;
use suhype\pagecache\services\TemplateAuditService;
use suhype\pagecache\utilities\PageCacheUtility;
use suhype\pagecache\mcp\PageCacheCapabilities;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpNavItemsEvent;
use craft\services\Utilities;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use yii\base\Application as YiiApplication;
use yii\queue\Queue as YiiQueue;
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
 * @property  TrackingService $trackingService
 * @property  DiagnosticsService $diagnosticsService
 * @property  TemplateAuditService $templateAuditService
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
    public string $schemaVersion = '1.3.0';

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

    // Private Properties
    // =========================================================================

    /**
     * Elements before they are saved, keyed by "id:siteId"
     *
     * @var array<string, Element>
     */
    private array $previousElements = [];

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
                        'enabled' => $this->settings->enabled,
                        'isCached' => $this->pageCacheService->isCached($entry),
                        'cachedAt' => $this->pageCacheService->getCachedAt($entry),
                        'isQueued' => $this->pageCacheService->isQueued($entry),
                    ]
                );
            },
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function (Event $event) {
                $element = $event->element;

                // New elements have no id yet and no cached page
                if (!$element->id || !$element->uri || $element->resaving || ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                // Remember the saved version, its cache must go if the URI changes (slug or parent)
                $previous = Craft::$app->getElements()->getElementById($element->id, get_class($element), $element->siteId);
                if ($previous && $previous->uri) {
                    $this->previousElements["{$element->id}:{$element->siteId}"] = $previous;
                }
            }
        );

        Event::on(
            GlobalSet::class,
            GlobalSet::EVENT_AFTER_SAVE,
            function (Event $event) {
                if ($event->sender->resaving) {
                    return;
                }

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
                $savedElement = $event->element;

                // Drafts and revisions (including their nested blocks) never change a live page,
                // bulk resaves would push every page into the queue
                if (ElementHelper::isDraftOrRevision($savedElement) || $savedElement->resaving) {
                    return;
                }

                $key = "{$savedElement->id}:{$savedElement->siteId}";
                $previous = $this->previousElements[$key] ?? null;
                unset($this->previousElements[$key]);

                if ($previous && $previous->uri !== $savedElement->uri) {
                    $this->deleteCacheService->deleteForUriChange($previous);
                }

                // Processed once at the end of the request, saving an entry also saves all its nested entries
                $this->refreshCacheService->elementChanged($savedElement);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function (Event $event) {
                if (ElementHelper::isDraftOrRevision($event->element)) {
                    return;
                }

                // Pages that showed the element, before a hard delete removes the tracking rows
                $this->refreshCacheService->elementChanged($event->element, true);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri || ElementHelper::isDraftOrRevision($event->element)) {
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

    private function _registerFlushEvents(): void
    {
        $flush = function () {
            // The changes are saved already, a failing refresh must not show an error page to the editor
            try {
                $this->refreshCacheService->flush();
            } catch (\Throwable $e) {
                Craft::error('Page Cache could not refresh the changed pages: ' . $e->getMessage(), __METHOD__);
            }
        };

        // Entries change status without being saved when their post or expiry date passes
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Event::on(YiiApplication::class, YiiApplication::EVENT_AFTER_REQUEST, function () {
                try {
                    $this->refreshCacheService->refreshExpiredIfDue();
                } catch (\Throwable $e) {
                    Craft::error('Page Cache could not check expired entries: ' . $e->getMessage(), __METHOD__);
                }
            });
        }

        // Web and console requests
        Event::on(YiiApplication::class, YiiApplication::EVENT_AFTER_REQUEST, $flush);

        // Queue workers (queue/listen) never end their request, e.g. elements saved by an import job
        Event::on(YiiQueue::class, YiiQueue::EVENT_AFTER_EXEC, $flush);
        Event::on(YiiQueue::class, YiiQueue::EVENT_AFTER_ERROR, $flush);
    }

    private function _registerUtility(): void
    {
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PageCacheUtility::class;
            }
        );

        // Own page for the main navigation, with the same content as the utility
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pagecache'] = 'pagecache/utility/index';
                // Fallback of the "Refresh cache" navigation item without JavaScript
                $event->rules['pagecache/refresh'] = 'pagecache/utility/index';
            }
        );

        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            function (RegisterCpNavItemsEvent $event) {
                if (!Craft::$app->getUser()->checkPermission('utility:' . PageCacheUtility::id())) {
                    return;
                }

                if ($this->settings->showInCpNav) {
                    $event->navItems[] = [
                        'url' => 'pagecache',
                        'label' => Craft::t('pagecache', 'Page Cache'),
                        'icon' => '@suhype/pagecache/icon-mask.svg',
                        'badgeCount' => PageCacheUtility::badgeCount(),
                    ];
                }

                if ($this->settings->showRefreshInCpNav) {
                    $event->navItems[] = [
                        'url' => 'pagecache/refresh',
                        'label' => Craft::t('pagecache', 'Refresh cache'),
                        'icon' => 'arrows-rotate',
                    ];

                    // Navigation items are links (Craft adds ?site=…), the refresh is a POST request
                    Craft::$app->getView()->registerJs(<<<JS
document.addEventListener('click', (event) => {
    const link = event.target.closest('#nav-pagecache-refresh-link, a[href*="/pagecache/refresh"]');
    if (!link) {
        return;
    }
    event.preventDefault();
    if (link.classList.contains('loading')) {
        return;
    }
    link.classList.add('loading');
    Craft.sendActionRequest('POST', 'pagecache/utility/refresh')
        .then(({ data }) => Craft.cp.displayNotice(data.message))
        .catch(({ response }) => Craft.cp.displayError(response?.data?.message))
        .finally(() => link.classList.remove('loading'));
});
JS);
                }
            }
        );
    }

    /**
     * Tools for Craft's MCP server (Craft 5.12+)
     */
    private function _registerMcp(): void
    {
        if (!class_exists(\craft\services\Mcp::class) || !class_exists(\Mcp\Capability\Attribute\McpTool::class)) {
            return;
        }

        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PageCacheCapabilities::class;
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
            [
                'settings' => $this->getSettings(),
                'overrides' => $this->getConfigOverrides(),
            ]
        );
    }

    /**
     * Settings set in config/pagecache.php, they can't be changed in the control panel
     *
     * @return array<string>
     */
    public function getConfigOverrides(): array
    {
        return array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle));
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return $this->renderSettingsPage(false);
    }

    /**
     * @inheritdoc
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->renderSettingsPage(true);
    }

    /**
     * Like the default settings page, with tabs
     */
    private function renderSettingsPage(bool $readOnly): mixed
    {
        $view = Craft::$app->getView();
        $settingsHtml = $view->namespaceInputs(function () use ($readOnly) {
            return $readOnly
                ? (string) \craft\helpers\Html::disableInputs(fn() => $this->settingsHtml())
                : (string) $this->settingsHtml();
        }, 'settings');

        /** @var \craft\web\Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('pagecache/_settings/page', [
            'plugin' => $this,
            'settingsHtml' => $settingsHtml,
            'readOnly' => $readOnly,
        ]);
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
            'trackingService' => TrackingService::class,
            'diagnosticsService' => DiagnosticsService::class,
            'templateAuditService' => TemplateAuditService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'suhype\pagecache\console\controllers';
        }

        if (Craft::$app->request->getIsSiteRequest()) {
            // Before anything is rendered, so the parameter never ends up in links or in the cache path
            $this->pageCacheService->stripGenerateParam();

            Event::on(
                Application::class,
                Application::EVENT_INIT,
                function () {
                    $element = Craft::$app->getUrlManager()->getMatchedElement();
                    if (!$element || !$element->uri) {
                        return;
                    }

                    // The queue job generates the page, it must not get the old cache
                    if (!$this->pageCacheService->isGenerateRequest()) {
                        $this->serveCacheService->serve($element, $this->pageCacheService->getRequestQueryString());
                    }

                    if ($this->settings->enabled && $this->pageCacheService->isCacheableRequest()) {
                        $this->trackingService->start();
                    }
                }
            );
        }

        $this->_registerInstallEvents();
        $this->_registerVariableEvent();
        $this->_registerElementEvents();
        $this->_registerActionEvents();
        $this->_registerClearCaches();
        $this->_registerFlushEvents();
        $this->_registerUtility();
        $this->_registerMcp();

        if (Craft::$app->request->getIsSiteRequest()) {
            Event::on(
                View::class,
                View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    if (!$this->pageCacheService->isCacheableRequest()) {
                        return;
                    }

                    if (!$this->pageCacheService->isCacheableResponse($event->template)) {
                        return;
                    }

                    $element = Craft::$app->getUrlManager()->getMatchedElement();

                    // e.g. "news/p2" also matches the element, but must not overwrite the cache of "news"
                    if (!$element || !$this->pageCacheService->isRequestForElement($element)) {
                        return;
                    }

                    $this->createCacheService->createFromSiteRequest($element, $this->pageCacheService->getRequestQueryString(), $event->output);
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
