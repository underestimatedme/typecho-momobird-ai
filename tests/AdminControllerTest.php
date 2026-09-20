<?php

class MomoBirdAI_AdminTestClient
{
    public function testConnection()
    {
        return array('slug' => 'blog-kb', 'status' => 'active');
    }
}

class MomoBirdAI_AdminTestState
{
    public function counts()
    {
        return array('synced' => 4, 'failed_upsert' => 1, 'failed_delete' => 2);
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
        new MomoBirdAI_AdminTestFullRun()
    );

    $status = $controller->dispatch('status', array());

    mb_assert_same(true, $status['ok'], 'status failed');
    mb_assert_same(4, $status['counts']['synced'], 'wrong status count');
    mb_assert(strpos(json_encode($status), 'api_key') === false, 'status exposed a config key');
});

mb_test('admin controller dispatches full-sync and retry operations', function () {
    $controller = new MomoBirdAI_AdminController(
        new MomoBirdAI_AdminTestClient(),
        new MomoBirdAI_AdminTestPosts(),
        new MomoBirdAI_AdminTestState(),
        new MomoBirdAI_AdminTestSync(),
        new MomoBirdAI_AdminTestFullRun()
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
