<?php
/**
 * Page Cache plugin for Craft CMS 3.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\utilities;

use suhype\pagecache\PageCache;
use suhype\pagecache\assetbundles\pagecacheutilityutility\PageCacheUtilityUtilityAsset;

use Craft;
use craft\base\Utility;

/**
 * Page Cache Utility
 *
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheUtility extends Utility
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('page-cache', 'PageCacheUtility');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'pagecache-page-cache-utility';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath()
    {
        return Craft::getAlias("@suhype/pagecache/assetbundles/pagecacheutilityutility/dist/img/PageCacheUtility-icon.svg");
    }

    /**
     * @inheritdoc
     */
    public static function badgeCount(): int
    {
        return 0;
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        Craft::$app->getView()->registerAssetBundle(PageCacheUtilityUtilityAsset::class);

        $someVar = 'Have a nice day!';
        return Craft::$app->getView()->renderTemplate(
            'page-cache/_components/utilities/PageCacheUtility_content',
            [
                'someVar' => $someVar
            ]
        );
    }
}
