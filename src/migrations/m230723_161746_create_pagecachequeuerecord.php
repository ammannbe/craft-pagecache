<?php

namespace suhype\pagecache\migrations;

use Craft;
use craft\db\Migration;

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
        $this->driver = Craft::$app->getConfig()->getDb()->driver;

        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%pagecache_pagecachequeuerecord}}');
        if ($tableSchema !== null) {
            return false;
        }

        $this->createTable(
            '{{%pagecache_pagecachequeuerecord}}',
            [
                'id' => $this->primaryKey(),
                'element' => $this->binary(),
                'url' => $this->string(2048),
                'delete' => $this->boolean()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]
        );

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->driver = Craft::$app->getConfig()->getDb()->driver;
        $this->dropTableIfExists('{{%pagecache_pagecachequeuerecord}}');

        return true;
    }
}
