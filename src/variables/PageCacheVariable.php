<?php
/**
 * Page Cache plugin for Craft CMS 4.x
 *
 * Simple HTML Page Cache Plugin
 *
 * @link      https://github.com/ammannbe
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\variables;

use Craft;
use craft\helpers\Html;

class PageCacheVariable
{
    public function csrfInput()
    {
        $rand = rand();

        Craft::$app->getView()->registerJsWithVars(fn ($type) => <<<JS
(() => {
    fetch('/actions/users/session-info', { headers: { 'Accept': 'application/json' } })
        .then((resp) => resp.json())
        .then(function(data) {
            const input = document.querySelector('[data-pagecache="{$rand}"]');

            if (input) {
                input.value = data.csrfTokenValue;
            }
        });
})();
JS, [static::class]);

        $tokenName = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
        return Html::hiddenInput($tokenName, null, [
            'data-pagecache' => $rand,
        ]);
    }

    /**
     * Get the resulting posts.
     *
     * This method is deprecated and will be removed in the next release.
     * Use [[SocialFeedVariable::posts()]] instead.
     *
     * @param array $conditions Some additional query conditions.
     * @param ?int $limit If not provided, use the limit from the settings.
     * @param ?array $orderBy If not provided, order by the feed date created.
     * @return array<ElementInterface>
     */
    public function all($conditions = [], $limit = null, $orderBy = ['homm_socialfeeds.feedDateCreated' => SORT_DESC]): array
    {
        return $this->posts($conditions, $limit, $orderBy)->all();
    }

    /**
     * Get the social feeds query
     *
     * @param array $conditions Some additional query conditions.
     * @param ?int $limit If not provided, use the limit from the settings.
     * @param ?array $orderBy If not provided, order by the feed date created.
     * @return ElementQueryInterface
     */
    public function posts($conditions = [], $limit = null, $orderBy = ['homm_socialfeeds.feedDateCreated' => SORT_DESC]): ElementQueryInterface
    {
        $firstKey = array_key_first($conditions);
        $query = SocialFeed::find();
        $limit = $limit ?? HOMMSocialFeed::$plugin->getSettings()->numberOfFeeds;

        foreach ($conditions as $key => $condition) {
            if ($key == $firstKey) {
                $query = $query->where($condition);
                continue;
            }
            $query = $query->andWhere($condition);
        }

        $query = $query->limit($limit);

        if ($orderBy !== null) {
            $query = $query->orderBy($orderBy);
        }

        return $query;
    }
}
