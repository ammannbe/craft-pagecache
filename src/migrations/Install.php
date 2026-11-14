<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use suhype\pagecache\db\Table;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        if (!$this->db->tableExists(Table::CACHES)) {
            $this->createTable(Table::CACHES, [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'elementId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'url' => $this->text()->notNull(),
                'tags' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->addForeignKey(null, Table::CACHES, 'siteId', CraftTable::SITES, 'id', 'CASCADE', 'CASCADE');
        }

        self::createCacheIndexes($this);
        self::createQueueTable($this);
        self::createTrackingTables($this);

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->dropTableIfExists(Table::CACHE_QUERIES);
        $this->dropTableIfExists(Table::QUERIES);
        $this->dropTableIfExists(Table::ELEMENTS);
        $this->dropTableIfExists(Table::QUEUE);
        $this->dropTableIfExists(Table::CACHES);

        return true;
    }

    // Shared with update migrations
    // =========================================================================

    public static function createCacheIndexes(Migration $migration): void
    {
        $migration->createIndex(null, Table::CACHES, ['elementId', 'siteId']);
    }

    public static function createQueueTable(Migration $migration): void
    {
        if ($migration->db->tableExists(Table::QUEUE)) {
            return;
        }

        $migration->createTable(Table::QUEUE, [
            'id' => $migration->primaryKey(),
            'uid' => $migration->uid(),
            'elementId' => $migration->integer(),
            'siteId' => $migration->integer(),
            'url' => $migration->string(2048),
            'urlHash' => $migration->char(32)->notNull(),
            'delete' => $migration->boolean()->notNull()->defaultValue(false),
            'check' => $migration->boolean()->notNull()->defaultValue(false),
            // Set by the queue job while it processes the row, reset when the page is queued again
            'claimed' => $migration->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
        ]);

        // The same page is only queued once
        $migration->createIndex(null, Table::QUEUE, ['urlHash'], true);
    }

    public static function createTrackingTables(Migration $migration): void
    {
        if (!$migration->db->tableExists(Table::ELEMENTS)) {
            $migration->createTable(Table::ELEMENTS, [
                'id' => $migration->primaryKey(),
                'cacheId' => $migration->integer()->notNull(),
                'elementId' => $migration->integer()->notNull(),
            ]);

            $migration->createIndex(null, Table::ELEMENTS, ['cacheId', 'elementId'], true);
            $migration->createIndex(null, Table::ELEMENTS, ['elementId']);
            $migration->addForeignKey(null, Table::ELEMENTS, 'cacheId', Table::CACHES, 'id', 'CASCADE', 'CASCADE');
            $migration->addForeignKey(null, Table::ELEMENTS, 'elementId', CraftTable::ELEMENTS, 'id', 'CASCADE', 'CASCADE');
        }

        if (!$migration->db->tableExists(Table::QUERIES)) {
            $migration->createTable(Table::QUERIES, [
                'id' => $migration->primaryKey(),
                'hash' => $migration->char(32)->notNull(),
                'type' => $migration->string()->notNull(),
                'params' => $migration->mediumText()->notNull(),
            ]);

            $migration->createIndex(null, Table::QUERIES, ['hash'], true);
            $migration->createIndex(null, Table::QUERIES, ['type']);
        }

        if (!$migration->db->tableExists(Table::CACHE_QUERIES)) {
            $migration->createTable(Table::CACHE_QUERIES, [
                'id' => $migration->primaryKey(),
                'cacheId' => $migration->integer()->notNull(),
                'queryId' => $migration->integer()->notNull(),
            ]);

            $migration->createIndex(null, Table::CACHE_QUERIES, ['cacheId', 'queryId'], true);
            $migration->createIndex(null, Table::CACHE_QUERIES, ['queryId']);
            $migration->addForeignKey(null, Table::CACHE_QUERIES, 'cacheId', Table::CACHES, 'id', 'CASCADE', 'CASCADE');
            $migration->addForeignKey(null, Table::CACHE_QUERIES, 'queryId', Table::QUERIES, 'id', 'CASCADE', 'CASCADE');
        }
    }
}
