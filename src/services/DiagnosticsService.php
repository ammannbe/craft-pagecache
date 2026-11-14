<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use suhype\pagecache\db\Table;
use suhype\pagecache\PageCache;

/**
 * Notices and improvement suggestions for the Page Cache utility
 *
 * @since 2.3.0
 */
class DiagnosticsService extends Component
{
    public const NOTICE_CSRF = 'csrf';
    public const NOTICE_TRANSFORMS = 'transforms';
    public const NOTICE_QUERIES = 'queries';
    public const NOTICE_RANDOM = 'random';

    /**
     * Element queries per page from which eager loading is suggested
     */
    public const QUERY_COUNT_THRESHOLD = 100;

    private const NOTICES_CACHE_KEY = 'pagecache:notices';
    private const REWRITE_CACHE_KEY = 'pagecache:rewriteCheck';
    private const BADGE_CACHE_KEY = 'pagecache:badge';
    private const DISMISSED_CACHE_KEY = 'pagecache:dismissedNotices';

    public const README_URL = 'https://github.com/ammannbe/craft-pagecache/blob/main/README.md';
    private const MAX_URLS = 20;

    /**
     * Remember a page with a problem (e.g. a static CSRF token), shown in the utility
     */
    public function addNotice(string $type, ?string $url = null, mixed $value = null): void
    {
        $url ??= $this->currentUrl();
        $cache = Craft::$app->getCache();
        $notices = $cache->get(self::NOTICES_CACHE_KEY) ?: [];

        $urls = $notices[$type] ?? [];
        unset($urls[$url]);
        $urls[$url] = $value ?? time();
        $notices[$type] = array_slice($urls, -self::MAX_URLS, null, true);

        $cache->set(self::NOTICES_CACHE_KEY, $notices, 30 * 86400);
    }

    /**
     * URL of the current request, based on the site URL (the Host header is controlled by the client)
     */
    public function currentUrl(): string
    {
        return rtrim((string) Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), '/') . '/' . Craft::$app->getRequest()->getFullPath();
    }

    /**
     * Pages found while rendering, by notice type
     *
     * @return array<string, array<string, mixed>> type => [url => value]
     */
    public function getStoredNotices(): array
    {
        return Craft::$app->getCache()->get(self::NOTICES_CACHE_KEY) ?: [];
    }

    public function clearNotices(): void
    {
        Craft::$app->getCache()->delete(self::NOTICES_CACHE_KEY);
        Craft::$app->getCache()->delete(self::REWRITE_CACHE_KEY);
        Craft::$app->getCache()->delete(self::BADGE_CACHE_KEY);
        Craft::$app->getCache()->delete(self::DISMISSED_CACHE_KEY);
    }

    /**
     * Number of warnings, without slow checks (shown as badge)
     */
    public function getWarningCount(): int
    {
        // Shown on every control panel page (navigation badge), so only computed every few minutes
        return (int) Craft::$app->getCache()->getOrSet(self::BADGE_CACHE_KEY, function () {
            return count(array_filter($this->getNotices(false), fn($notice) => $notice['level'] === 'warning'));
        }, 300);
    }

    /**
     * @return array<array{id: string, level: string, message: string, urls?: array<string>, link?: array{url: string, label: string}}>
     */
    public function getNotices(bool $includeSlowChecks = true): array
    {
        $settings = PageCache::$plugin->settings;
        // Settings tab panes are namespaced with "settings"
        $settingsUrl = fn(string $tab) => UrlHelper::cpUrl('settings/plugins/pagecache') . "#settings-pagecache-{$tab}";
        $notices = [];

        if (!$settings->enabled) {
            $notices[] = [
                'id' => 'disabled',
                'level' => 'info',
                'message' => Craft::t('pagecache', 'Caching is disabled, pages are neither cached nor served from the cache.'),
                'link' => ['url' => $settingsUrl('general'), 'label' => Craft::t('pagecache', 'Settings')],
            ];

            return $this->withoutDismissed($notices);
        }

        $stored = Craft::$app->getCache()->get(self::NOTICES_CACHE_KEY) ?: [];

        $optimizations = PageCache::$plugin->templateAuditService->getFindings(false);
        if (!empty($optimizations)) {
            $warnings = count(array_filter($optimizations, fn($finding) => $finding['level'] === 'warning'));
            $notices[] = [
                'id' => 'templates',
                'level' => $warnings ? 'warning' : 'info',
                'message' => Craft::t('pagecache', 'Template optimizations: {warnings, plural, =0{no problems} =1{# problem} other{# problems}} and {suggestions, plural, =1{# suggestion} other{# suggestions}}.', [
                    'warnings' => $warnings,
                    'suggestions' => count($optimizations) - $warnings,
                ]),
                'keyData' => array_map(fn($finding) => $finding['key'], $optimizations),
                'tab' => 'templates',
                'link' => ['url' => '#templates', 'label' => Craft::t('pagecache', 'Show template optimizations')],
            ];
        }

        $oldestQueued = (new Query())->from(Table::QUEUE)->min('dateCreated');
        if ($oldestQueued && DateTimeHelper::toDateTime($oldestQueued)?->getTimestamp() < time() - 900) {
            $notices[] = [
                'id' => 'queue',
                'level' => 'warning',
                'message' => Craft::t('pagecache', 'Pages have been waiting in the queue for more than 15 minutes. Make sure the Craft queue is running (e.g. a `craft queue/listen` worker or `runQueueAutomatically`).'),
                'link' => ['url' => 'https://craftcms.com/docs/5.x/system/queue.html', 'label' => Craft::t('pagecache', 'Craft documentation')],
            ];
        }

        if ($this->hasScheduledEntries() && !PageCache::$plugin->refreshCacheService->hasCheckedExpiredRecently()) {
            $notices[] = [
                'id' => 'expired',
                'level' => 'warning',
                'message' => Craft::t('pagecache', 'There are entries with a future post or expiry date, but they were not checked recently. Run `craft pagecache/cache/refresh-expired` with a cron job (e.g. every 5 minutes) on sites with little traffic.'),
                'link' => ['url' => self::README_URL . '#dependency-tracking', 'label' => 'README'],
            ];
        }

        if ($settings->brotli && !function_exists('brotli_compress')) {
            $notices[] = [
                'id' => 'brotli',
                'level' => 'info',
                'message' => Craft::t('pagecache', 'Brotli compression is enabled, but the PHP brotli extension is not installed.'),
                'link' => ['url' => $settingsUrl('files'), 'label' => Craft::t('pagecache', 'Settings')],
            ];
        }

        if ($settings->cacheQueryStrings && empty($settings->includedUrls) && empty($settings->excludedUrls)) {
            $notices[] = [
                'id' => 'queryStrings',
                'level' => 'info',
                'message' => Craft::t('pagecache', 'Pages with query strings are cached without excluded URL’s. Every new query string (e.g. tracking parameters) creates a new cache file.'),
                'link' => ['url' => $settingsUrl('pages'), 'label' => Craft::t('pagecache', 'Settings')],
            ];
        }

        if (!$settings->trackDependencies) {
            $notices[] = [
                'id' => 'tracking',
                'level' => 'info',
                'message' => Craft::t('pagecache', 'Dependency tracking is disabled, pages like listings or navigations are not refreshed when an entry they show is saved.'),
                'link' => ['url' => self::README_URL . '#dependency-tracking', 'label' => 'README'],
            ];
        }

        if ($includeSlowChecks && $this->rewriteRulesActive() === false) {
            $notices[] = [
                'id' => 'rewrite',
                'level' => 'info',
                'message' => Craft::t('pagecache', 'Cached pages are served by PHP. Add the rewrite rules for your web server to serve them without starting Craft.'),
                'link' => ['url' => self::README_URL . '#add-rewrite-rules', 'label' => 'README'],
            ];
        }

        return $this->withoutDismissed($notices);
    }

    /**
     * Hide a notice until its content changes (e.g. a new URL with a CSRF token)
     */
    public function dismissNotice(string $key): void
    {
        $cache = Craft::$app->getCache();
        $dismissed = $cache->get(self::DISMISSED_CACHE_KEY) ?: [];
        $dismissed[$key] = time();
        $cache->set(self::DISMISSED_CACHE_KEY, $dismissed, 90 * 86400);
        $cache->delete(self::BADGE_CACHE_KEY);
    }

    /**
     * @param array<array> $notices
     * @return array<array>
     */
    private function withoutDismissed(array $notices): array
    {
        $dismissed = Craft::$app->getCache()->get(self::DISMISSED_CACHE_KEY) ?: [];
        $result = [];

        foreach ($notices as $notice) {
            $notice['key'] = $notice['id'] . ':' . md5(Json::encode($notice['keyData'] ?? $notice['urls'] ?? []));
            if (!isset($dismissed[$notice['key']])) {
                $result[] = $notice;
            }
        }

        return $result;
    }

    /**
     * @return array<array{site: string, pages: int}>
     */
    public function getStats(): array
    {
        $counts = (new Query())
            ->select(['siteId', 'count' => 'COUNT(*)'])
            ->from(Table::CACHES)
            ->groupBy('siteId')
            ->pairs();

        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $sites[] = ['id' => (int) $site->id, 'site' => $site->getName(), 'pages' => (int) ($counts[$site->id] ?? 0)];
        }

        return [
            'sites' => $sites,
            'queued' => (int) (new Query())->from(Table::QUEUE)->where(['check' => false])->count(),
            'checks' => (int) (new Query())->from(Table::QUEUE)->where(['check' => true])->count(),
            'tracking' => PageCache::$plugin->trackingService->getStats(),
        ];
    }

    private function hasScheduledEntries(): bool
    {
        $now = Db::prepareDateForDb(new \DateTime());

        return Entry::find()
            ->site('*')
            ->status(null)
            ->uri(':notempty:')
            ->andWhere(['or', ['>', 'entries.postDate', $now], ['>', 'entries.expiryDate', $now]])
            ->exists();
    }

    /**
     * Whether a cached page is served by the web server (true), by PHP (false) or unknown (null).
     * The plugin adds the "X-Page-Cache" header when it serves a page, the web server doesn't.
     */
    private function rewriteRulesActive(): ?bool
    {
        $cache = Craft::$app->getCache();
        $result = $cache->get(self::REWRITE_CACHE_KEY);
        if ($result !== false) {
            return $result === 'unknown' ? null : $result === 'yes';
        }

        $result = 'unknown';

        $record = (new Query())->select(['siteId', 'url'])->from(Table::CACHES)->where(['not like', 'url', '?'])->one();
        $site = $record ? Craft::$app->getSites()->getSiteById((int) $record['siteId']) : null;

        if ($site && $site->getBaseUrl()) {
            try {
                $client = Craft::createGuzzleClient(['timeout' => 5, 'http_errors' => false]);
                $options = [];
                if ($auth = PageCache::$plugin->settings->getGenerateAuth()) {
                    $options['auth'] = $auth;
                }

                $response = $client->get(rtrim($site->getBaseUrl(), '/') . $record['url'], $options);
                $body = (string) $response->getBody();

                // Only a response from the cache tells something about the rewrite rules
                if (str_contains($body, '<!-- Cached by Page Cache')) {
                    $result = $response->hasHeader(ServeCacheService::HEADER) ? 'no' : 'yes';
                }
            } catch (\Throwable $e) {
            }
        }

        $cache->set(self::REWRITE_CACHE_KEY, $result, 3600);

        return $result === 'unknown' ? null : $result === 'yes';
    }
}
