<?php

class MomoBirdAI_FullTestPosts
{
    private $count;

    public function __construct($count)
    {
        $this->count = (int) $count;
    }

    public function page($afterCid, $limit)
    {
        $posts = array();
        for ($cid = (int) $afterCid + 1; $cid <= $this->count && count($posts) < $limit; $cid++) {
            $posts[] = mb_post('Body ' . $cid, $cid);
        }
        return $posts;
    }

    public function find($cid)
    {
        return $cid > 0 && $cid <= $this->count ? mb_post('Body ' . $cid, $cid) : null;
    }
}

class MomoBirdAI_FullTestService
{
    public $seen = array();
    private $failAt;

    public function __construct($failAt = 0)
    {
        $this->failAt = (int) $failAt;
    }

    public function syncPost(array $post)
    {
        $cid = (int) $post['cid'];
        $this->seen[] = $cid;
        return array(
            'ok' => $cid !== $this->failAt,
            'created' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'error' => $cid === $this->failAt ? array('code' => 'failed', 'message' => 'failed') : null
        );
    }
}

class MomoBirdAI_FullTestState
{
    public $token = null;
    public $cleanupAllowed = false;
    public $finished = false;
    public $externalIds = array();
    public $cursor = 0;
    public $removedExternalIds = array();

    public function startRun($token)
    {
        $this->token = $token;
        $this->cleanupAllowed = false;
        $this->finished = false;
        $this->cursor = 0;
    }

    public function runIsActive($token)
    {
        return !$this->finished && hash_equals((string) $this->token, (string) $token);
    }

    public function expectedRunCursor($token)
    {
        if (!$this->runIsActive($token)) {
            throw new RuntimeException('invalid run');
        }
        return $this->cursor;
    }

    public function advanceRun($token, $expected, $next)
    {
        if (!$this->runIsActive($token) || $this->cursor !== (int) $expected) {
            throw new RuntimeException('invalid cursor');
        }
        $this->cursor = (int) $next;
    }

    public function allowCleanup($token, $expected)
    {
        if (!$this->runIsActive($token) || $this->cursor !== (int) $expected) {
            throw new RuntimeException('invalid run');
        }
        $this->cleanupAllowed = true;
    }

    public function cleanupIsAllowed($token)
    {
        return $this->runIsActive($token) && $this->cleanupAllowed;
    }

    public function finishRun($token)
    {
        if (!$this->cleanupIsAllowed($token)) {
            throw new RuntimeException('cleanup not allowed');
        }
        $this->finished = true;
    }

    public function allExternalIds()
    {
        return $this->externalIds;
    }

    public function removeFailedByExternalId($externalId)
    {
        $this->removedExternalIds[] = $externalId;
        unset($this->externalIds[$externalId]);
    }
}

class MomoBirdAI_FullTestClient
{
    public $pages = array();
    public $tags = array();
    public $deleted = array();

    public function listEntries($cursor, $tag)
    {
        $this->tags[] = $tag;
        $key = $cursor === null ? '' : $cursor;
        return isset($this->pages[$key]) ? $this->pages[$key] : array('items' => array());
    }

    public function deleteEntry($uuid)
    {
        $this->deleted[] = $uuid;
    }
}

mb_test('failed page never authorizes cleanup', function () {
    $posts = new MomoBirdAI_FullTestPosts(25);
    $service = new MomoBirdAI_FullTestService(13);
    $state = new MomoBirdAI_FullTestState();
    $client = new MomoBirdAI_FullTestClient();
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, $client, 'site-a');
    $token = $run->start();

    $first = $run->syncPage($token, 0, 10);
    $second = $run->syncPage($token, $first['next_cursor'], 10);

    mb_assert($first['ok'] && !$first['done'], 'first page should continue');
    mb_assert(!$second['ok'], 'second page should fail');
    mb_assert(!$second['cleanup_allowed'], 'failed run authorized cleanup');
    mb_assert(!$state->cleanupAllowed, 'server-side cleanup flag was enabled');
    mb_assert_same(13, $second['failed_post_id'], 'wrong failed post');
});

mb_test('successful pages advance monotonically and authorize cleanup only at the end', function () {
    $posts = new MomoBirdAI_FullTestPosts(21);
    $service = new MomoBirdAI_FullTestService();
    $state = new MomoBirdAI_FullTestState();
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, new MomoBirdAI_FullTestClient(), 'site-a');
    $token = $run->start();

    $one = $run->syncPage($token, 0, 10);
    $two = $run->syncPage($token, $one['next_cursor'], 10);
    $three = $run->syncPage($token, $two['next_cursor'], 10);

    mb_assert_same(10, $one['next_cursor'], 'first cursor is wrong');
    mb_assert_same(20, $two['next_cursor'], 'second cursor is wrong');
    mb_assert_same(21, $three['next_cursor'], 'final cursor is wrong');
    mb_assert(!$one['cleanup_allowed'] && !$two['cleanup_allowed'], 'cleanup enabled early');
    mb_assert($three['done'] && $three['cleanup_allowed'], 'cleanup not enabled after final page');
});

mb_test('full sync rejects skipped and replayed cursors before cleanup authorization', function () {
    $state = new MomoBirdAI_FullTestState();
    $run = new MomoBirdAI_FullSyncRun(
        new MomoBirdAI_FullTestPosts(21),
        new MomoBirdAI_FullTestService(),
        $state,
        new MomoBirdAI_FullTestClient(),
        'site-a'
    );
    $token = $run->start();

    mb_assert_throws(function () use ($run, $token) {
        $run->syncPage($token, 999, 10);
    }, 'RuntimeException');
    $first = $run->syncPage($token, 0, 10);
    mb_assert_throws(function () use ($run, $token) {
        $run->syncPage($token, 0, 10);
    }, 'RuntimeException');
    mb_assert(!$state->cleanupAllowed, 'invalid cursor authorized cleanup');
    mb_assert_same(10, $first['next_cursor'], 'valid cursor did not advance');
});

mb_test('cleanup deletes only current-site remote orphans across pages', function () {
    $state = new MomoBirdAI_FullTestState();
    $state->externalIds = array('typecho:site-a:post:1:keep' => true);
    $client = new MomoBirdAI_FullTestClient();
    $next = '77777777-7777-4777-8777-777777777777';
    $client->pages[''] = array(
        'items' => array(
            array('id' => '11111111-1111-4111-8111-111111111111', 'external_id' => 'typecho:site-a:post:1:keep'),
            array('id' => '22222222-2222-4222-8222-222222222222', 'external_id' => 'typecho:site-a:post:9:orphan'),
            array('id' => '33333333-3333-4333-8333-333333333333', 'external_id' => 'typecho:site-b:post:9:orphan')
        ),
        'next_cursor' => $next
    );
    $client->pages[$next] = array(
        'items' => array(
            array('id' => '44444444-4444-4444-8444-444444444444', 'external_id' => 'typecho:site-a:post:10:orphan')
        )
    );
    $run = new MomoBirdAI_FullSyncRun(new MomoBirdAI_FullTestPosts(0), new MomoBirdAI_FullTestService(), $state, $client, 'site-a');
    $token = $run->start();
    $run->syncPage($token, 0, 10);

    $first = $run->cleanupPage($token, null);
    $second = $run->cleanupPage($token, $first['next_cursor']);

    mb_assert_same(array(
        '22222222-2222-4222-8222-222222222222',
        '44444444-4444-4444-8444-444444444444'
    ), $client->deleted, 'cleanup deleted the wrong entries');
    mb_assert(!$first['done'] && $second['done'], 'remote pagination was not followed');
    mb_assert($state->finished, 'run was not finalized');
    mb_assert_same(array(
        'typecho:site-a:post:9:orphan',
        'typecho:site-a:post:10:orphan'
    ), $state->removedExternalIds, 'cleanup did not converge local stale mappings');
    $expectedTag = 'typecho-site-' . substr(hash('sha256', 'site-a'), 0, 12);
    mb_assert_same(array($expectedTag, $expectedTag), $client->tags, 'site tag filter is wrong');
});
