<?php
/**
 * Page Cache plugin for Craft CMS 3.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache;

use suhype\pagecache\services\PageCacheService as PageCacheServiceService;
use suhype\pagecache\utilities\PageCacheUtility as PageCacheUtilityUtility;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\console\Application as ConsoleApplication;
use craft\services\Utilities;
use craft\events\RegisterComponentTypesEvent;

use yii\base\Event;

/**
 * Class PageCache
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 *
 * @property  PageCacheServiceService $pageCacheService
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
    public $schemaVersion = '0.0.1';

    /**
     * @var bool
     */
    public $hasCpSettings = false;

    /**
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'suhype\pagecache\console\controllers';
        }

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PageCacheUtilityUtility::class;
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
        );

        Craft::info(
            Craft::t(
                'page-cache',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
