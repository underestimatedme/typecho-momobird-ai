<?php

class MomoBirdAI_FullTestPosts
{
    private $count;
    public $ineligible = array();

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
        if (isset($this->ineligible[(int) $cid])) {
            return null;
        }
        return $cid > 0 && $cid <= $this->count ? mb_post('Body ' . $cid, $cid) : null;
    }
}

class MomoBirdAI_FullTestService
{
    public $seen = array();
    public $calls = 0;
    private $failAt;
    private $rejectAt;

    public $planAt = 0;

    public function __construct($failAt = 0, $rejectAt = 0)
    {
        $this->failAt = (int) $failAt;
        $this->rejectAt = (int) $rejectAt;
    }

    public function syncPosts(array $posts)
    {
        $this->calls++;
        $summary = array('ok' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0, 'plan_required' => false, 'error' => null);
        foreach ($posts as $post) {
            $cid = (int) $post['cid'];
            $this->seen[] = $cid;
            if ($cid === $this->planAt) {
                $summary['ok'] = false;
                $summary['plan_required'] = true;
                $summary['error'] = array('code' => 'PLAN_REQUIRED', 'message' => 'plan');
            } elseif ($cid === $this->failAt) {
                $summary['ok'] = false;
                $summary['failed']++;
                $summary['error'] = array('code' => 'UPSTREAM', 'message' => 'temporary');
            } elseif ($cid === $this->rejectAt) {
                $summary['ok'] = false;
                $summary['rejected']++;
                $summary['error'] = array('code' => 'PAYLOAD_TOO_LARGE', 'message' => 'too large');
            } else {
                $summary['created']++;
            }
        }
        return $summary;
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

    public function remove($postId)
    {
        $this->removedExternalIds[] = (string) $postId;
    }
}

class MomoBirdAI_FullTestClient
{
    public $pages = array();
    public $cursors = array();
    public $deleted = array();

    public function listPosts($cursor = null)
    {
        $this->cursors[] = $cursor;
        $key = $cursor === null ? '' : $cursor;
        return isset($this->pages[$key]) ? $this->pages[$key] : array('items' => array(), 'next_cursor' => null);
    }

    public function deletePosts(array $ids)
    {
        $this->deleted[] = $ids;
        $out = array();
        foreach ($ids as $id) {
            $out[] = array('external_id' => $id, 'status' => 'deleted');
        }
        return $out;
    }
}

mb_test('failed page never authorizes cleanup', function () {
    $posts = new MomoBirdAI_FullTestPosts(25);
    $service = new MomoBirdAI_FullTestService(13);
    $state = new MomoBirdAI_FullTestState();
    $client = new MomoBirdAI_FullTestClient();
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, $client);
    $token = $run->start();

    $first = $run->syncPage($token, 0, 10);
    $second = $run->syncPage($token, $first['next_cursor'], 10);

    mb_assert($first['ok'] && !$first['done'], 'first page should continue');
    mb_assert(!$second['ok'], 'second page should fail');
    mb_assert(!$second['cleanup_allowed'], 'failed run authorized cleanup');
    mb_assert(!$state->cleanupAllowed, 'server-side cleanup flag was enabled');
    mb_assert_same('UPSTREAM', $second['error']['code'], 'failure reason lost');
    mb_assert_same($first['next_cursor'], $second['next_cursor'], 'failed page advanced the cursor');
});

mb_test('successful pages advance monotonically and authorize cleanup only at the end', function () {
    $posts = new MomoBirdAI_FullTestPosts(21);
    $service = new MomoBirdAI_FullTestService();
    $state = new MomoBirdAI_FullTestState();
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, new MomoBirdAI_FullTestClient());
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
        new MomoBirdAI_FullTestClient()
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

mb_test('cleanup deletes only ids no longer eligible', function () {
    $state = new MomoBirdAI_FullTestState();
    $posts = new MomoBirdAI_FullTestPosts(3);
    $posts->ineligible[2] = true;
    $client = new MomoBirdAI_FullTestClient();
    $client->pages[''] = array('items' => array(array('external_id' => '1'), array('external_id' => '2')), 'next_cursor' => 'n2');
    $client->pages['n2'] = array('items' => array(array('external_id' => '3'), array('external_id' => '44')), 'next_cursor' => null);
    $run = new MomoBirdAI_FullSyncRun($posts, new MomoBirdAI_FullTestService(), $state, $client);
    $token = $run->start();
    $run->syncPage($token, 0, 10);

    $first = $run->cleanupPage($token, null);
    $second = $run->cleanupPage($token, $first['next_cursor']);

    mb_assert_same(array(array('2'), array('44')), $client->deleted, 'cleanup deleted the wrong posts');
    mb_assert_same(array(null, 'n2'), $client->cursors, 'remote pagination was not followed');
    mb_assert(!$first['done'] && $second['done'], 'cleanup did not finish on the last page');
    mb_assert($state->finished, 'run was not finalized');
    mb_assert_same(array('2', '44'), $state->removedExternalIds, 'local rows for deleted posts not cleared');
});

mb_test('cleanup is refused after a failed page', function () {
    $state = new MomoBirdAI_FullTestState();
    $client = new MomoBirdAI_FullTestClient();
    $run = new MomoBirdAI_FullSyncRun(new MomoBirdAI_FullTestPosts(5), new MomoBirdAI_FullTestService(3), $state, $client);
    $token = $run->start();
    $page = $run->syncPage($token, 0, 10);
    mb_assert(!$page['ok'] && !$page['cleanup_allowed'], 'failed page authorized cleanup');
    mb_assert_throws(function () use ($run, $token) {
        $run->cleanupPage($token, null);
    }, 'RuntimeException');
    mb_assert_same(array(), $client->deleted, 'cleanup deleted after a failed page');
});

mb_test('a rejected post does not block the run', function () {
    $state = new MomoBirdAI_FullTestState();
    $run = new MomoBirdAI_FullSyncRun(new MomoBirdAI_FullTestPosts(5), new MomoBirdAI_FullTestService(0, 3), $state, new MomoBirdAI_FullTestClient());
    $token = $run->start();
    $page = $run->syncPage($token, 0, 10);
    mb_assert($page['ok'] && $page['done'] && $page['cleanup_allowed'], 'rejected post blocked the run');
    mb_assert_same(1, $page['rejected'], 'rejected count not reported');
});

mb_test('restarted full sync does not duplicate', function () {
    $service = new MomoBirdAI_FullTestService();
    $posts = new MomoBirdAI_FullTestPosts(15);
    $state = new MomoBirdAI_FullTestState();
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, new MomoBirdAI_FullTestClient());
    $token = $run->start();
    $run->syncPage($token, 0, 10);
    $token = $run->start();
    $one = $run->syncPage($token, 0, 10);
    $run->syncPage($token, $one['next_cursor'], 10);
    $unique = array_values(array_unique($service->seen));
    sort($unique);
    mb_assert_same(range(1, 15), $unique, 'restarted run did not cover every eligible post');
});

mb_test('plan expiry still allows orphan cleanup', function () {
    $state = new MomoBirdAI_FullTestState();
    $posts = new MomoBirdAI_FullTestPosts(3);
    $posts->ineligible[2] = true;
    $service = new MomoBirdAI_FullTestService();
    $service->planAt = 1;
    $client = new MomoBirdAI_FullTestClient();
    $client->pages[''] = array('items' => array(array('external_id' => '1'), array('external_id' => '2')), 'next_cursor' => null);
    $run = new MomoBirdAI_FullSyncRun($posts, $service, $state, $client);
    $token = $run->start();
    $page = $run->syncPage($token, 0, 10);
    mb_assert($page['plan_required'], 'plan expiry not reported');
    mb_assert($page['done'] && $page['cleanup_allowed'], 'plan expiry blocked cleanup of private posts');
    $cleanup = $run->cleanupPage($token, null);
    mb_assert_same(array(array('2')), $client->deleted, 'private post not cleaned up after plan expiry');
    mb_assert($cleanup['done'], 'cleanup did not finish');
});

mb_test('one unmappable post does not wedge a real full sync', function () {
    $state = new MomoBirdAI_FullTestState();
    $posts = new MomoBirdAI_FullTestPosts(3);
    $client = new MomoBirdAI_TestClient();
    $sync = new MomoBirdAI_SyncService($client, new MomoBirdAI_TestStateRepository(), 'https://example.com');
    $postsWithImage = new class($posts) {
        private $inner;
        public function __construct($inner) { $this->inner = $inner; }
        public function page($after, $limit) {
            $page = $this->inner->page($after, $limit);
            foreach ($page as &$post) {
                if ((int) $post['cid'] === 2) { $post['text'] = '<p><img src="a.png"></p>'; }
            }
            return $page;
        }
        public function find($cid) { return $this->inner->find($cid); }
    };
    $run = new MomoBirdAI_FullSyncRun($postsWithImage, $sync, $state, new MomoBirdAI_FullTestClient());
    $token = $run->start();
    $page = $run->syncPage($token, 0, 10);
    mb_assert($page['ok'] && $page['done'] && $page['cleanup_allowed'], 'image-only post wedged the full sync');
    mb_assert_same(3, $page['next_cursor'], 'cursor did not advance past the skipped post');
});
