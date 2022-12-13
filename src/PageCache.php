<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache;

use suhype\pagecache\services\PageCacheService;
use suhype\pagecache\models\Settings;

use Craft;
use craft\web\View;
use yii\base\Event;
use craft\base\Plugin;
use craft\base\Element;
use craft\elements\Entry;
use craft\web\Application;
use craft\services\Plugins;
use craft\services\Elements;
use craft\events\PluginEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\events\RegisterElementActionsEvent;
use craft\console\Application as ConsoleApplication;
use suhype\pagecache\elements\actions\PageCacheAction;

/**
 * Class PageCache
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 *
 * @property  PageCacheService $pageCacheService
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
    public string $schemaVersion = '0.0.1';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

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

    private function _registerElementEvents(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri || ElementHelper::isDraftOrRevision($event->element)) {
                    return;
                }

                // Check if the the old slug is not equal the new one
                $element = Entry::find()->id($event->element->id)->one();
                if ($event->element->slug !== $element->slug) {
                    $this->pageCacheService->deleteAllPageCaches($element);
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri || ElementHelper::isDraftOrRevision($event->element)) {
                    return;
                }

                $activeStates = [
                    Entry::STATUS_LIVE,
                    Element::STATUS_ENABLED,
                ];

                if (!in_array($event->element->getStatus(), $activeStates)) {
                    $this->pageCacheService->deleteAllPageCaches($event->element);
                    return;
                }

                $this->pageCacheService->recreatePageCaches($event->element);
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (Event $event) {
                if (!$event->element->uri) {
                    return;
                }

                $this->pageCacheService->deletePageCache($event->element);
            }
        );
    }

    private function _registerActionEvents(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                [$type, $uid] = explode(':', $event->source) + [null, null];

                if ($type == 'singles') {
                    $event->actions[] = PageCacheAction::class;
                    return;
                } elseif ($type == 'section') {
                    /** @var \craft\models\Section $section */
                    $section = Craft::$app->sections->getSectionByUid($uid);
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
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'suhype\pagecache\console\controllers';
        }

        if (Craft::$app->request->getIsSiteRequest()) {
            Event::on(
                Application::class,
                Application::EVENT_INIT,
                function() {
                    $element = Craft::$app->getUrlManager()->getMatchedElement();
                    if ($element && $element->uri) {
                        $this->pageCacheService->servePageCacheIfExists($element, Craft::$app->request->getQueryStringWithoutPath());
                    }
                }
            );
        }

        $this->_registerInstallEvents();
        $this->_registerElementEvents();
        $this->_registerActionEvents();

        if (Craft::$app->request->getIsSiteRequest()) {
            Event::on(View::class, View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
                function(TemplateEvent $event) {
                    if (!Craft::$app->getResponse()->getIsOk()) {
                        return;
                    }

                    $element = Craft::$app->getUrlManager()->getMatchedElement();

                    $this->pageCacheService->createPageCache($element, Craft::$app->request->getQueryStringWithoutPath(), $event->output);
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
