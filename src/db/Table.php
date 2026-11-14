<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\db;

/**
 * Database tables of the plugin
 */
abstract class Table
{
    public const CACHES = '{{%pagecache_pagecacherecords}}';
    public const QUEUE = '{{%pagecache_pagecachequeuerecord}}';

    /**
     * Elements rendered on a cached page
     */
    public const ELEMENTS = '{{%pagecache_elements}}';

    /**
     * Element queries executed on cached pages
     */
    public const QUERIES = '{{%pagecache_queries}}';

    /**
     * Which cached page executed which element query
     */
    public const CACHE_QUERIES = '{{%pagecache_cachequeries}}';
}
