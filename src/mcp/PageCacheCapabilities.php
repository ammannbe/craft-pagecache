<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe/craft-pagecache
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\mcp;

use Craft;
use craft\base\Element;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use suhype\pagecache\PageCache;

/**
 * Page Cache tools for Craft's MCP server (Craft 5.12+). Same permission as the Page Cache utility.
 *
 * @since 2.3.0
 */
class PageCacheCapabilities
{
    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'pagecache_status',
        description: 'Page Cache overview: settings (enabled, refresh mode, dependency tracking), cached pages per site, pages waiting in the queue, tracked elements and queries, and notices with improvement suggestions.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('utility:pagecache')]
    public function status(): array
    {
        $plugin = PageCache::$plugin;
        $settings = $plugin->settings;

        return [
            'enabled' => $settings->enabled,
            'refreshMode' => $settings->refreshMode,
            'trackDependencies' => $settings->trackDependencies,
            'stats' => $plugin->diagnosticsService->getStats(),
            'notices' => array_map(fn(array $notice) => [
                'level' => $notice['level'],
                'message' => $notice['message'],
                'urls' => $notice['urls'] ?? [],
                'link' => $notice['link']['url'] ?? null,
            ], $plugin->diagnosticsService->getNotices()),
        ];
    }

    /**
     * @return array{cached: bool, cachedAt: string|null, queued: bool}
     */
    #[McpTool(
        name: 'pagecache_page_status',
        description: 'Whether the page of an element (e.g. an entry) is cached, since when, and whether it waits in the queue.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('utility:pagecache')]
    public function pageStatus(int $elementId, ?int $siteId = null): array
    {
        $element = $this->element($elementId, $siteId);
        $service = PageCache::$plugin->pageCacheService;

        return [
            'cached' => (bool) $service->isCached($element),
            'cachedAt' => $service->getCachedAt($element)?->format(DATE_ATOM),
            'queued' => $service->isQueued($element),
        ];
    }

    /**
     * @param list<string>|null $urls Full URLs of pages to refresh
     * @param list<int>|null $elementIds Elements whose pages to refresh
     * @param list<string>|null $tags Refresh pages with one of these tags
     * @return array{refreshed: string}
     */
    #[McpTool(
        name: 'pagecache_refresh',
        description: 'Refreshes cached pages depending on the refresh mode (clear right away and/or regenerate in the queue). Pass full URLs, element IDs or tags; with none of them, all cached pages (optionally of one site) are refreshed.',
        annotations: new ToolAnnotations(idempotentHint: true),
    )]
    #[RequiresPermission('utility:pagecache')]
    public function refresh(
        #[Schema(items: ['type' => 'string'])] ?array $urls = null,
        #[Schema(items: ['type' => 'integer'])] ?array $elementIds = null,
        #[Schema(items: ['type' => 'string'])] ?array $tags = null,
        ?int $siteId = null,
    ): array {
        $refresh = PageCache::$plugin->refreshCacheService;

        if (!empty($urls)) {
            $count = $refresh->refreshUrls($urls);
            return ['refreshed' => "{$count} page(s) by URL"];
        }

        if (!empty($elementIds)) {
            $elements = array_map(fn(int $id) => $this->element($id, $siteId), $elementIds);
            $refresh->refreshForElements($elements);
            return ['refreshed' => count($elements) . ' element page(s)'];
        }

        $refresh->refresh($siteId, $tags ?: null);

        return ['refreshed' => !empty($tags) ? 'pages tagged ' . implode(', ', $tags) : ($siteId ? "all pages of site {$siteId}" : 'all pages')];
    }

    /**
     * @param list<string>|null $tags Only delete pages with one of these tags
     * @return array{deleted: string}
     */
    #[McpTool(
        name: 'pagecache_clear',
        description: 'Deletes cached pages right away: all pages, the pages of one site, or the pages with one of the given tags. Visitors get the live pages until they are cached again.',
        annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true),
    )]
    #[RequiresPermission('utility:pagecache')]
    public function clear(#[Schema(items: ['type' => 'string'])] ?array $tags = null, ?int $siteId = null): array
    {
        PageCache::$plugin->deleteCacheService->delete($siteId, $tags ?: null);

        return ['deleted' => !empty($tags) ? 'pages tagged ' . implode(', ', $tags) : ($siteId ? "all pages of site {$siteId}" : 'all pages')];
    }

    /**
     * @return array{queued: bool}
     */
    #[McpTool(
        name: 'pagecache_warm',
        description: 'Caches every entry page with a URL in the queue (also pages nobody visited yet). Existing caches stay until they are replaced.',
        annotations: new ToolAnnotations(idempotentHint: true),
    )]
    #[RequiresPermission('utility:pagecache')]
    public function warm(?int $siteId = null): array
    {
        PageCache::$plugin->createCacheService->create($siteId);

        return ['queued' => true];
    }

    private function element(int $elementId, ?int $siteId): Element
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element instanceof Element || !$element->uri) {
            throw new ToolCallException("Element {$elementId} doesn’t exist or has no URL.");
        }

        if (!Craft::$app->getElements()->canView($element)) {
            throw new ToolCallException("You aren’t allowed to view element {$elementId}.");
        }

        return $element;
    }
}
