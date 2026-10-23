<?php

class MomoBirdAI_AdminTestClient
{
    public function status()
    {
        return array(
            'project' => array('id' => 'p1', 'name' => '博客知识库'),
            'source' => array('id' => 's1', 'name' => '乔人尹', 'site_url' => 'https://underestimated.cn', 'document_count' => 10, 'ready_document_count' => 7),
            'entitlement' => array('status' => 'trial', 'active' => true, 'trial_started_at' => '2026-10-18T00:00:00Z', 'trial_ends_at' => '2026-10-25T00:00:00Z', 'pro_ends_at' => null),
            'widget' => array('public_id' => 'pub_1', 'available' => false, 'reason' => 'not_published')
        );
    }
}

class MomoBirdAI_AdminTestState
{
    public function counts()
    {
        return array('synced' => 4, 'failed_upsert' => 1, 'failed_delete' => 2, 'plan_required' => 0);
    }

    public function syncedPostCount()
    {
        return 3;
    }

    public function lastFullSyncAt()
    {
        return 1700000000;
    }

    public function recentError()
    {
        return array('code' => 'provider_error', 'message' => 'temporary');
    }
}

class MomoBirdAI_AdminTestSync
{
    public function retryFailures($finder)
    {
        return array('ok' => true, 'retried' => 3, 'failed' => 0);
    }
}

class MomoBirdAI_AdminTestPosts
{
    public function find($cid)
    {
        return mb_post('Body', $cid);
    }
}

class MomoBirdAI_AdminTestFullRun
{
    public function start()
    {
        return str_repeat('a', 48);
    }

    public function syncPage($token, $cursor, $limit)
    {
        return array('ok' => true, 'done' => true, 'cleanup_allowed' => true, 'processed' => 1, 'next_cursor' => 7);
    }

    public function cleanupPage($token, $cursor)
    {
        return array('ok' => true, 'done' => true, 'deleted' => 2, 'next_cursor' => null);
    }
}

mb_test('admin controller exposes bounded status without configuration secrets', function () {
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestSync(),
        new MomoBirdAI_AdminTestFullRun(),
        array('configured' => true, 'auto_sync_enabled' => false)
    );

    $status = $controller->dispatch('status', array());

    mb_assert_same(true, $status['ok'], 'status failed');
    mb_assert_same(4, $status['counts']['synced'], 'wrong status count');
    mb_assert_same(3, $status['synced_posts'], 'wrong synced article count');
    mb_assert_same(1700000000, $status['last_full_sync_at'], 'missing latest full sync');
    mb_assert_same('configured', $status['configuration_state'], 'wrong configuration state');
    mb_assert_same(false, $status['auto_sync_enabled'], 'manual-only mode was lost');
    mb_assert_same('provider_error', $status['recent_error']['code'], 'recent error missing');
    mb_assert(strpos(json_encode($status), 'api_key') === false, 'status exposed a config key');
});

mb_test('admin controller dispatches full-sync and retry operations', function () {
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestSync(),
        new MomoBirdAI_AdminTestFullRun(),
        array('configured' => true, 'auto_sync_enabled' => true)
    );

    $token = str_repeat('a', 48);
    mb_assert_same($token, $controller->dispatch('start-sync', array())['run_token'], 'run token missing');
    mb_assert($controller->dispatch('sync-page', array('run_token' => $token, 'cursor' => 0))['done'], 'sync page did not complete');
    mb_assert_same(2, $controller->dispatch('cleanup-page', array('run_token' => $token))['deleted'], 'cleanup count is wrong');
    mb_assert_same(3, $controller->dispatch('retry-failures', array())['retried'], 'retry count is wrong');
    mb_assert_throws(function () use ($controller) {
        $controller->dispatch('unknown', array());
    }, 'InvalidArgumentException');
});

mb_test('test connection shows plan status', function () {
    $controller = new MomoBirdAI_AdminController(new MomoBirdAI_AdminTestClient(), null, new MomoBirdAI_AdminTestState(), null, null, array('configured' => true));
    $result = $controller->dispatch('test-connection', array());
    mb_assert_same(true, $result['ok'], 'test connection failed');
    mb_assert_same('博客知识库', $result['project']['name'], 'project name missing');
    mb_assert_same('trial', $result['entitlement']['status'], 'entitlement missing');
    mb_assert_same('2026-10-25T00:00:00Z', $result['entitlement']['trial_ends_at'], 'trial end missing');
    mb_assert_same('not_published', $result['widget']['reason'], 'widget reason missing');
    mb_assert(strpos(json_encode($result), 'mbk_') === false, 'connection key leaked');
});

mb_test('status shows serving progress', function () {
    $controller = new MomoBirdAI_AdminController(new MomoBirdAI_AdminTestClient(), null, new MomoBirdAI_AdminTestState(), null, null, array('configured' => true));
    $result = $controller->dispatch('test-connection', array());
    mb_assert_same(7, $result['source']['ready_document_count'], 'ready count missing');
    mb_assert_same(10, $result['source']['document_count'], 'document count missing');
});

mb_test('test connection flags a blog address that differs from the console source', function () {
    $state = new MomoBirdAI_AdminTestState();
    $same = new MomoBirdAI_AdminController(new MomoBirdAI_AdminTestClient(), null, $state, null, null, array('configured' => true, 'site_origin' => 'https://Underestimated.cn:443'));
    mb_assert_same(true, $same->dispatch('test-connection', array())['origin_matches'], 'equivalent origins reported as different');
    $other = new MomoBirdAI_AdminController(new MomoBirdAI_AdminTestClient(), null, $state, null, null, array('configured' => true, 'site_origin' => 'https://www.underestimated.cn'));
    $result = $other->dispatch('test-connection', array());
    mb_assert_same(false, $result['origin_matches'], 'mismatched origin not flagged');
    mb_assert_same('https://www.underestimated.cn', $result['blog_origin'], 'blog origin not reported');
});

mb_test('finishing a full sync retries the assistant once, after cleanup', function () {
    $calls = array();
    $hook = function () use (&$calls) {
        $calls[] = 'widget';
        return array('available' => false, 'reason' => 'not_published');
    };
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestSync(),
        new MomoBirdAI_AdminTestFullRun(),
        array('configured' => true),
        $hook
    );
    $token = str_repeat('a', 48);
    $controller->dispatch('sync-page', array('run_token' => $token, 'cursor' => 0));
    mb_assert_same(array(), $calls, 'assistant retried before cleanup finished');
    $page = $controller->dispatch('cleanup-page', array('run_token' => $token));
    mb_assert_same(array('widget'), $calls, 'assistant not retried after the run finished');
    mb_assert_same('not_published', $page['widget']['reason'], 'cleanup page does not report the assistant');
});

mb_test('a failing assistant retry does not fail the finished sync', function () {
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestSync(),
        new MomoBirdAI_AdminTestFullRun(),
        array('configured' => true),
        function () {
            throw new RuntimeException('down');
        }
    );
    $page = $controller->dispatch('cleanup-page', array('run_token' => str_repeat('a', 48)));
    mb_assert_same(true, $page['done'], 'finished sync reported as failed');
    mb_assert(!isset($page['widget']), 'failed retry reported a widget state');
});

mb_test('test connection hands the status to the podcast player', function () {
    $seen = array();
    $controller = new MomoBirdAI_AdminController(new MomoBirdAI_AdminTestClient(), null, new MomoBirdAI_AdminTestState(), null, null, array(
        'configured' => true,
        'on_status' => function (array $status) use (&$seen) {
            $seen[] = $status;
        }
    ));
    $controller->dispatch('test-connection', array());
    mb_assert_same(1, count($seen), 'status not handed over');
    mb_assert(isset($seen[0]['source']), 'source missing from handed status');
});

class MomoBirdAI_AdminTestStillFailingSync
{
    public function retryFailures($finder)
    {
        return array('ok' => false, 'retried' => 8, 'failed' => 8);
    }
}

mb_test('a retry that leaves posts failing is reported as a completed request with counts', function () {
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestStillFailingSync(),
        new MomoBirdAI_AdminTestFullRun(),
        array('configured' => true)
    );
    $result = $controller->dispatch('retry-failures', array());
    // ok describes the request; the panel reads still-failing posts from the counts.
    mb_assert_same(true, $result['ok'], 'retry with remaining failures reported as a failed request');
    mb_assert_same(8, $result['retried'], 'retried count lost');
    mb_assert_same(8, $result['failed'], 'still-failing count lost');
});
