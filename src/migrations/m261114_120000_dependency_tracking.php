<?php

namespace suhype\pagecache\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use suhype\pagecache\db\Table;
use suhype\pagecache\PageCache;

/**
 * Queue rows with element references instead of serialized elements, dependency tracking tables
 */
class m261114_120000_dependency_tracking extends Migration
{
    public function safeUp(): bool
    {
        $this->updateQueueTable();
        Install::createTrackingTables($this);
        Install::createCacheIndexes($this);
        $this->regenerateCaches();

        return true;
    }

    /**
     * Existing caches have no tracking data yet. Generate them again, they are served until they are replaced.
     */
    private function regenerateCaches(): void
    {
        $plugin = PageCache::$plugin;
        if (!$plugin || !$plugin->settings->enabled || !$plugin->settings->trackDependencies) {
            return;
        }

        try {
            $pages = [];
            foreach ((new Query())->select(['siteId', 'url', 'elementId'])->from(Table::CACHES)->all($this->db) as $row) {
                $pages["{$row['siteId']}:{$row['url']}"] = [
                    'siteId' => (int) $row['siteId'],
                    'url' => $row['url'],
                    'elementId' => (int) $row['elementId'],
                    'delete' => false,
                ];
            }

            $plugin->pageCacheService->queuePages($pages);
        } catch (\Throwable $e) {
            Craft::warning('Page Cache could not queue the existing caches: ' . $e->getMessage(), __METHOD__);
        }
    }

    public function safeDown(): bool
    {
        echo "m261114_120000_dependency_tracking cannot be reverted.\n";
        return false;
    }

    private function updateQueueTable(): void
    {
        $rows = (new Query())->from(Table::QUEUE)->all($this->db);

        $this->dropTableIfExists(Table::QUEUE);
        Install::createQueueTable($this);

        // Keep pending pages, the queue job processes them with the new format
        $sites = Craft::$app->getSites()->getAllSites(true);
        $seen = [];

        foreach ($rows as $row) {
            // PostgreSQL returns binary columns as stream
            $element = is_resource($row['element']) ? stream_get_contents($row['element']) : (string) $row['element'];
            $reference = Json::decodeIfJson($element);
            $elementId = is_array($reference) ? ($reference['id'] ?? null) : null;
            $siteId = is_array($reference) ? ($reference['siteId'] ?? null) : null;
            $url = (string) $row['url'];

            if (!$elementId || !$siteId || $url === '') {
                continue;
            }

            $path = null;
            foreach ($sites as $site) {
                $baseUrl = rtrim((string) $site->getBaseUrl(), '/');
                if ($site->id == $siteId && $baseUrl !== '' && str_starts_with($url, $baseUrl)) {
                    $path = '/' . ltrim(substr($url, strlen($baseUrl)), '/');
                    break;
                }
            }

            if ($path === null) {
                continue;
            }

            $hash = md5("{$siteId}:{$path}");
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $this->insert(Table::QUEUE, [
                'elementId' => (int) $elementId,
                'siteId' => (int) $siteId,
                'url' => $path,
                'urlHash' => $hash,
                'delete' => (bool) $row['delete'],
                'check' => false,
                'claimed' => false,
            ]);
        }
    }
}
