<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\jobs;

use suhype\pagecache\PageCache;

use Craft;
use GuzzleHttp;
use craft\base\Element;
use craft\queue\BaseJob;

/**
 * @author    Benjamin Ammann
 * @package   PageCache
 * @since     0.0.1
 */
class PageCacheTask extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $elementId;

    /**
     * @var bool
     */
    public bool $deleteOnly = false;

    /**
     * @var ?string
     */
    public ?string $query = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        /** @var Element $element */
        $element = Craft::$app->elements->getElementById($this->elementId);

        PageCache::$plugin->pageCacheService->deletePageCache($element, $this->query);

        if (!$this->deleteOnly) {
            $client = new GuzzleHttp\Client();
            $url = $element->getUrl();

            if ($this->query) {
                $url = $url . '?' . $this->query;
            }

            $client->get($url);
            
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('pagecache', 'Process page cache');
    }
}
