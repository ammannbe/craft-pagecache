<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\utilities;

use Craft;
use craft\base\Utility;
use suhype\pagecache\PageCache;

/**
 * Page Cache utility: overview, notices and actions for the whole cache
 *
 * @since 2.3.0
 */
class PageCacheUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('pagecache', 'Page Cache');
    }

    public static function id(): string
    {
        return 'pagecache';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@suhype/pagecache/icon-mask.svg');
    }

    public static function badgeCount(): int
    {
        try {
            return PageCache::$plugin->diagnosticsService->getWarningCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('pagecache/_utility/index', self::templateVariables());
    }

    /**
     * @return array<string, mixed>
     */
    public static function templateVariables(): array
    {
        $plugin = PageCache::$plugin;

        // Scan first, so the notice about template optimizations includes the scan result
        $findings = $plugin->templateAuditService->getFindings();

        $warnings = count(array_filter($findings, fn($finding) => $finding['level'] === 'warning'));
        $label = Craft::t('pagecache', 'Template optimizations');
        if (!empty($findings)) {
            $label .= ' (' . count($findings) . ')';
        }

        return [
            'pagecacheTabs' => [
                'pagecache-overview' => ['label' => Craft::t('pagecache', 'Overview'), 'url' => '#pagecache-overview'],
                'pagecache-templates' => ['label' => $label, 'url' => '#pagecache-templates', 'class' => $warnings ? ['error'] : []],
            ],
            'findings' => $findings,
            'combinedPrompt' => $plugin->templateAuditService->getCombinedPrompt(),
            'settings' => $plugin->settings,
            'notices' => $plugin->diagnosticsService->getNotices(),
            'stats' => $plugin->diagnosticsService->getStats(),
            'sites' => Craft::$app->getSites()->getAllSites(true),
        ];
    }
}
