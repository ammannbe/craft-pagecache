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
use craft\web\View;
use craft\helpers\Html;

class PageCacheVariable
{
    public function csrfInput()
    {
        $rand = rand();

        Craft::$app->getView()->registerJs(<<<JS
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
JS, View::POS_END);

        $tokenName = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
        return Html::hiddenInput($tokenName, null, [
            'data-pagecache' => $rand,
        ]);
    }
}
