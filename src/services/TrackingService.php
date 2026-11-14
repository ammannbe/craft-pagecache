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
use craft\base\ElementInterface;
use craft\base\Model;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\GlobalSet;
use craft\events\CancelableEvent;
use craft\events\PopulateElementsEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeInterface;
use Illuminate\Support\Collection;
use suhype\pagecache\db\Table;
use suhype\pagecache\PageCache;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\UnknownMethodException;
use yii\base\UnknownPropertyException;
use yii\db\IntegrityException;

/**
 * Tracks which elements and element queries a page uses while it is rendered, so it can be
 * refreshed when one of them changes:
 *
 * - Elements: every element shown on the page (entries, nested entries, assets, categories, ...)
 * - Element queries: e.g. `craft.entries.section('news')`, so a new news entry refreshes the listing
 *
 * @since 2.3.0
 */
class TrackingService extends Component
{
    /**
     * Criteria that don't change which elements a query can return
     */
    private const IGNORED_CRITERIA = ['limit', 'offset', 'orderBy', 'fixedOrder', 'inReverse', 'asArray', 'with', 'eagerly', 'withCustomFields'];

    private bool $tracking = false;

    private int $queryCount = 0;

    private bool $hasRandomOrder = false;

    /**
     * @var array<int, int>
     */
    private array $elementIds = [];

    /**
     * @var array<string, array{type: string, params: string}> keyed by hash
     */
    private array $queries = [];

    /**
     * @var array<string, bool>
     */
    private array $typesWithQueries = [];

    /**
     * @var array<int>
     */
    private array $obsoleteQueryIds = [];

    // Tracking while rendering
    // =========================================================================

    /**
     * Start tracking the current (cacheable) site request
     */
    public function start(): void
    {
        if ($this->tracking || !PageCache::$plugin->settings->trackDependencies) {
            return;
        }

        $this->tracking = true;

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS, function (PopulateElementsEvent $event) {
            // Globals are handled by the "When globals are saved" setting
            if (is_a((string) $event->sender->elementType, GlobalSet::class, true)) {
                return;
            }

            foreach ($event->elements as $element) {
                $id = $element instanceof ElementInterface ? $element->id : ($element['id'] ?? null);
                if ($id) {
                    $this->elementIds[(int) $id] = (int) $id;
                }
            }
        });

        // Before prepare, so count(), ids() and exists() are tracked as well
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, function (CancelableEvent $event) {
            /** @var ElementQuery $query */
            $query = $event->sender;
            $this->queryCount++;

            // Random order is frozen in the cached page
            if (!$this->hasRandomOrder && preg_match('/\bRANDOM?\s*\(/i', is_array($query->orderBy) ? implode(',', array_keys($query->orderBy)) . implode(',', array_filter($query->orderBy, 'is_string')) : (string) $query->orderBy)) {
                $this->hasRandomOrder = true;
            }

            $this->trackQuery($query);
        });
    }

    public function isTracking(): bool
    {
        return $this->tracking;
    }

    /**
     * Store the tracked elements and queries of the rendered page
     */
    public function save(int $cacheId): void
    {
        if (!$this->tracking) {
            return;
        }

        $diagnostics = PageCache::$plugin->diagnosticsService;
        if ($this->queryCount > DiagnosticsService::QUERY_COUNT_THRESHOLD) {
            $diagnostics->addNotice(DiagnosticsService::NOTICE_QUERIES, null, $this->queryCount);
        }
        if ($this->hasRandomOrder) {
            $diagnostics->addNotice(DiagnosticsService::NOTICE_RANDOM);
        }

        try {
            // Outside the transaction: a parallel insert of the same query only fails this insert
            $queryIds = empty($this->queries) ? [] : $this->getQueryIds($this->queries);
            $elementIds = $this->getExistingElementIds();
        } catch (\Throwable $e) {
            Craft::warning("Page Cache could not store the dependencies of cache {$cacheId}: {$e->getMessage()}", __METHOD__);
            return;
        }

        // Pages are rendered in parallel by the queue job, retry on deadlocks
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                Craft::$app->getDb()->transaction(function () use ($cacheId, $queryIds, $elementIds) {
                    // Only delete if there is something: deleting non-existing rows takes gap locks (MySQL)
                    if ((new Query())->from(Table::ELEMENTS)->where(['cacheId' => $cacheId])->exists()) {
                        Db::delete(Table::ELEMENTS, ['cacheId' => $cacheId]);
                    }
                    if ((new Query())->from(Table::CACHE_QUERIES)->where(['cacheId' => $cacheId])->exists()) {
                        Db::delete(Table::CACHE_QUERIES, ['cacheId' => $cacheId]);
                    }

                    foreach (array_chunk($elementIds, 1000) as $chunk) {
                        Db::batchInsert(Table::ELEMENTS, ['cacheId', 'elementId'], array_map(fn($id) => [$cacheId, $id], $chunk));
                    }

                    if (!empty($queryIds)) {
                        Db::batchInsert(Table::CACHE_QUERIES, ['cacheId', 'queryId'], array_map(fn($id) => [$cacheId, $id], $queryIds));
                    }
                });

                return;
            } catch (\Throwable $e) {
                if ($attempt < 3 && str_contains($e->getMessage(), 'eadlock')) {
                    usleep(random_int(50000, 200000));
                    continue;
                }

                // e.g. the cache got deleted meanwhile. The visitor still gets the page; without tracking data,
                // the page is only refreshed by its own element.
                Craft::warning("Page Cache could not store the dependencies of cache {$cacheId}: {$e->getMessage()}", __METHOD__);
                return;
            }
        }
    }

    /**
     * Tracked element IDs that still exist (deleted elements would violate the foreign key)
     *
     * @return array<int>
     */
    private function getExistingElementIds(): array
    {
        $ids = [];
        foreach (array_chunk(array_values($this->elementIds), 1000) as $chunk) {
            $ids = array_merge($ids, (new Query())->select('id')->from(\craft\db\Table::ELEMENTS)->where(['id' => $chunk])->column());
        }

        return array_map('intval', $ids);
    }

    private function trackQuery(ElementQuery $query): void
    {
        $type = $query->elementType;

        // Globals are handled by the "When globals are saved" setting
        if (!$type || is_a($type, GlobalSet::class, true)) {
            return;
        }

        // Relation fields, eager loading and custom conditions restrict the query with where/join, which
        // getCriteria() doesn't contain. Stored without them, the query would match every element of the type.
        // The elements they load are covered by the element tracking.
        if (!empty($query->where) || !empty($query->join) || !empty($query->having) || !empty($query->union)) {
            return;
        }

        try {
            $criteria = $query->getCriteria();
        } catch (\Throwable $e) {
            return;
        }

        // Queries for specific elements are covered by element tracking, nested elements by their owner,
        // drafts, revisions and trashed elements never show up on a live page
        // Eager loading and author lookups belong to one element, which is tracked as well
        foreach (['id', 'uid', 'ownerId', 'primaryOwnerId', 'draftId', 'revisionId', 'draftOf', 'revisionOf', 'uri', 'slug', 'eagerLoadSourceElement', 'authorOf'] as $key) {
            if (!empty($criteria[$key])) {
                return;
            }
        }

        foreach (['drafts', 'revisions', 'trashed', 'provisionalDrafts'] as $key) {
            if (!empty($criteria[$key])) {
                return;
            }
        }

        foreach (self::IGNORED_CRITERIA as $key) {
            unset($criteria[$key]);
        }

        // The site of the request, the check runs in the queue
        if (($criteria['siteId'] ?? null) === null) {
            $criteria['siteId'] = Craft::$app->getSites()->getCurrentSite()->id;
        }

        $ok = true;
        $criteria = $this->normalize($criteria, $ok);
        if (!$ok) {
            return;
        }

        $criteria = array_filter($criteria, fn($value) => $value !== null);
        ksort($criteria);

        $params = Json::encode($criteria);
        $hash = md5($type . ':' . $params);

        $this->queries[$hash] = ['type' => $type, 'params' => $params];
    }

    /**
     * Turn criteria into JSON compatible values, $ok is false if that's not possible (e.g. a sub query)
     */
    private function normalize(mixed $value, bool &$ok): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof ElementInterface) {
            return $value->id;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($value instanceof ElementQueryInterface) {
            $ok = false;
            return null;
        }

        if ($value instanceof Model && isset($value->id)) {
            return $value->id;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item, $ok);
            }

            return $value;
        }

        $ok = false;
        return null;
    }

    /**
     * @param array<string, array{type: string, params: string}> $queries keyed by hash
     * @return array<int>
     */
    private function getQueryIds(array $queries): array
    {
        $existing = (new Query())
            ->select(['hash', 'id'])
            ->from(Table::QUERIES)
            ->where(['hash' => array_keys($queries)])
            ->pairs();

        $missing = array_diff_key($queries, $existing);

        foreach ($missing as $hash => $query) {
            try {
                Db::insert(Table::QUERIES, ['hash' => $hash, 'type' => $query['type'], 'params' => $query['params']]);
                $existing[$hash] = (int) Craft::$app->getDb()->getLastInsertID();
            } catch (IntegrityException $e) {
                // Stored by a parallel request (not inside a transaction, so this works on PostgreSQL as well)
                $existing[$hash] = (int) (new Query())->select('id')->from(Table::QUERIES)->where(['hash' => $hash])->scalar();
            }
        }

        return array_values(array_filter(array_map('intval', $existing)));
    }

    // Lookups
    // =========================================================================

    /**
     * IDs of the cached pages that showed one of the elements
     *
     * @param array<int> $elementIds
     * @return array<int>
     */
    public function getCacheIdsForElementIds(array $elementIds): array
    {
        $elementIds = array_values(array_filter($elementIds));
        if (empty($elementIds)) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select('cacheId')
            ->distinct()
            ->from(Table::ELEMENTS)
            ->where(['elementId' => $elementIds])
            ->column());
    }

    public function hasQueriesForType(string $type): bool
    {
        // Only remember true: in long running processes (queue workers) queries can be stored meanwhile
        if (!empty($this->typesWithQueries[$type])) {
            return true;
        }

        return $this->typesWithQueries[$type] = (new Query())
            ->from(Table::QUERIES)
            ->where(['type' => $type])
            ->exists();
    }

    /**
     * IDs of the cached pages with an element query that returns one of the elements.
     *
     * The status of the stored query is ignored, so disabled, expired and deleted elements match as
     * well: the page showed them (or counted them) before. This refreshes a few pages too many, but none too few.
     *
     * @param array<int> $elementIds
     * @return array<int>
     */
    public function getMatchingCacheIds(string $type, array $elementIds): array
    {
        $elementIds = array_values(array_unique(array_filter(array_map('intval', $elementIds))));
        if (empty($elementIds) || !class_exists($type)) {
            return [];
        }

        $matched = [];

        $rows = (new Query())
            ->select(['id', 'params'])
            ->from(Table::QUERIES)
            ->where(['type' => $type])
            ->all();

        foreach ($rows as $row) {
            try {
                /** @var ElementQuery $query */
                $query = $type::find();
                Craft::configure($query, Json::decode($row['params']));

                if ($query->id($elementIds)->status(null)->trashed(null)->exists()) {
                    $matched[] = (int) $row['id'];
                }
            } catch (UnknownPropertyException|UnknownMethodException|InvalidArgumentException|InvalidConfigException $e) {
                // e.g. a field or section that doesn't exist anymore. Refresh its pages, they are tracked again on render.
                Craft::warning("Page Cache removed an element query that can't be executed anymore: {$e->getMessage()}", __METHOD__);
                $matched[] = (int) $row['id'];
                $this->obsoleteQueryIds[] = (int) $row['id'];
            } catch (\Throwable $e) {
                // e.g. a deadlock: refresh the pages to be safe, keep the query
                Craft::warning("Page Cache could not check an element query: {$e->getMessage()}", __METHOD__);
                $matched[] = (int) $row['id'];
            }
        }

        if (empty($matched)) {
            return [];
        }

        $cacheIds = array_map('intval', (new Query())
            ->select('cacheId')
            ->distinct()
            ->from(Table::CACHE_QUERIES)
            ->where(['queryId' => $matched])
            ->column());

        if (!empty($this->obsoleteQueryIds)) {
            Db::delete(Table::QUERIES, ['id' => $this->obsoleteQueryIds]);
            $this->obsoleteQueryIds = [];
        }

        return $cacheIds;
    }

    /**
     * Remove queries no cached page uses anymore
     */
    public function cleanup(): void
    {
        $used = (new Query())->select('queryId')->from(Table::CACHE_QUERIES);

        Db::delete(Table::QUERIES, ['not in', 'id', $used]);
    }

    /**
     * @return array{elements: int, queries: int}
     */
    public function getStats(): array
    {
        return [
            'elements' => (int) (new Query())->from(Table::ELEMENTS)->count(),
            'queries' => (int) (new Query())->from(Table::QUERIES)->count(),
        ];
    }
}
