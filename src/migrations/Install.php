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
use suhype\pagecache\records\PageCacheQueueRecord;
use suhype\pagecache\records\PageCacheRecord;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class Install extends Migration
{
    // Public Properties
    // =========================================================================

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        if ($this->createTables()) {
            $this->addForeignKeys();
            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

   /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->removeTables();

        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @return bool
     */
    protected function createTables()
    {
        if (!$this->db->tableExists(PageCacheRecord::tableName())) {
            $this->createTable(
                PageCacheRecord::tableName(),
                [
                    'id' => $this->primaryKey(),
                    'uid' => $this->uid(),
                    'elementId' => $this->integer()->notNull(),
                    'siteId' => $this->integer()->notNull(),
                    'url' => $this->text()->notNull(),
                    'tags' => $this->json(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                ]
            );
        }

        if (!$this->db->tableExists(PageCacheQueueRecord::tableName())) {
            $this->createTable(
                PageCacheQueueRecord::tableName(),
                [
                    'id' => $this->primaryKey(),
                    'uid' => $this->uid(),
                    'element' => $this->binary(),
                    'url' => $this->string(2048),
                    'delete' => $this->boolean()->notNull(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                ]
            );
        }

        return true;
    }

    /**
     * @return void
     */
    protected function addForeignKeys()
    {
        $this->addForeignKey(
            $this->db->getForeignKeyName(PageCacheRecord::tableName(), 'siteId'),
            PageCacheRecord::tableName(),
            'siteId',
            '{{%sites}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * @return void
     */
    protected function removeTables()
    {
        $this->dropTableIfExists(PageCacheRecord::tableName());
    }
}
