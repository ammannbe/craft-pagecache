<?php

namespace suhype\pagecache\migrations;

use Craft;
use craft\db\Migration;
use suhype\pagecache\records\PageCacheRecord;

/**
 * m250502_170758_add_columns_to_pagecacherecords_table migration.
 */
class m250502_170758_add_columns_to_pagecacherecords_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn(PageCacheRecord::tableName(), 'tags', $this->json());

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropColumn(PageCacheRecord::tableName(), 'tags');

        return true;
    }
}
