<?php

namespace suhype\pagecache\migrations;

use Craft;
use craft\db\Migration;
use suhype\pagecache\records\PageCacheQueueRecord;

/**
 * m230723_161746_create_pagecachequeuerecord migration.
 */
class m230723_161746_create_pagecachequeuerecord extends Migration
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The database driver to use
     */
    public $driver;

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
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
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(PageCacheQueueRecord::tableName());

        return true;
    }
}
