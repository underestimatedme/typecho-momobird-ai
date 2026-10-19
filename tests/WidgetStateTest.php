<?php

class MomoBirdAI_WidgetTestClient
{
    public $calls = 0;
    public $status = array('widget' => array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok'));
    public $enableError = null;
    public $fail = false;

    public function status()
    {
        $this->calls++;
        if ($this->fail) {
            throw new MomoBirdAI_TransportException('down');
        }
        return $this->status;
    }

    public function enableWidget()
    {
        $this->calls++;
        if ($this->enableError !== null) {
            throw $this->enableError;
        }
        return $this->status['widget'];
    }
}

function mb_widget_state(array &$store)
{
    return new MomoBirdAI_WidgetState(
        function () use (&$store) {
            return $store;
        },
        function (array $values) use (&$store) {
            $store = $values;
        }
    );
}

mb_test('footer never calls the network', function () {
    $store = array();
    $state = mb_widget_state($store);
    $now = 1760000000;
    mb_assert_same('', $state->snippet($now), 'missing cache produced a snippet');
    $store = array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok', 'checked_at' => $now - 90000);
    mb_assert_same('', $state->snippet($now), 'day-old cache produced a snippet');
    $store['checked_at'] = $now - 10;
    mb_assert_same('<script defer src="https://momobird.atlaspaces.com/widget/v1/loader.js" data-project="pub_1"></script>', $state->snippet($now), 'fresh cache did not produce the loader');
});

mb_test('snippet requires available state', function () {
    foreach (array('plan_required', 'disabled_in_console', 'not_published', 'origin_not_registered') as $reason) {
        $store = array('public_id' => 'pub_1', 'available' => false, 'reason' => $reason, 'checked_at' => 1760000000);
        mb_assert_same('', mb_widget_state($store)->snippet(1760000000), 'snippet shown for ' . $reason);
    }
});

mb_test('snippet escapes public id', function () {
    $store = array('public_id' => 'abc"><script>', 'available' => true, 'reason' => 'ok', 'checked_at' => 1760000000);
    mb_assert_same('', mb_widget_state($store)->snippet(1760000000), 'unsafe public id was rendered');
});

mb_test('cache needs refresh after ten minutes but keeps serving for a day', function () {
    $store = array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok', 'checked_at' => 1760000000 - 601);
    $state = mb_widget_state($store);
    mb_assert($state->needsRefresh(1760000000), 'ten-minute-old cache not flagged for refresh');
    mb_assert(strpos($state->snippet(1760000000), 'pub_1') !== false, 'quiet blog lost its assistant after ten minutes');
    $store['checked_at'] = 1760000000 - 86401;
    mb_assert_same('', $state->snippet(1760000000), 'cache older than a day rendered');
    $store['checked_at'] = 1760000000 - 30;
    mb_assert(!$state->needsRefresh(1760000000), 'fresh cache flagged for refresh');
});

mb_test('unreachable status hides the widget', function () {
    $store = array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok', 'checked_at' => 1760000000);
    $state = mb_widget_state($store);
    $client = new MomoBirdAI_WidgetTestClient();
    $client->fail = true;
    $state->refresh($client, 1760000100);
    mb_assert_same('', $state->snippet(1760000100), 'widget stayed visible while MomoBird was unreachable');
    mb_assert_same('unreachable', $store['reason'], 'unreachable reason not stored');
});

mb_test('enable publishes then caches the widget', function () {
    $store = array();
    $state = mb_widget_state($store);
    $client = new MomoBirdAI_WidgetTestClient();
    $result = $state->enable($client, 1760000000);
    mb_assert_same('ok', $result['reason'], 'enable result');
    mb_assert_same(2, $client->calls, 'enable must call enableWidget then status');
    mb_assert(strpos($state->snippet(1760000000), 'pub_1') !== false, 'enabled widget not cached');
});

mb_test('enable reports widget not ready', function () {
    $store = array();
    $state = mb_widget_state($store);
    $client = new MomoBirdAI_WidgetTestClient();
    $client->enableError = new MomoBirdAI_HttpException('not ready', 409, 'WIDGET_NOT_READY');
    $result = $state->enable($client, 1760000000);
    mb_assert_same('not_published', $result['reason'], 'not-ready reason');
    mb_assert_same('WIDGET_NOT_READY', $result['error_code'], 'not-ready code lost');
    mb_assert_same('', $state->snippet(1760000000), 'not-ready widget rendered');
});
