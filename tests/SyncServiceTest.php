<?php

class MomoBirdAI_TestClient
{
    public $upserts = array();
    public $deletes = array();
    public $results = array();
    public $failUpsert = null;
    public $failDelete = null;
    public $tooLargeIds = array();

    public function upsertPosts(array $posts)
    {
        $this->upserts[] = $posts;
        if ($this->failUpsert !== null) {
            throw $this->failUpsert;
        }
        foreach ($posts as $post) {
            if (isset($this->tooLargeIds[$post['external_id']])) {
                throw new MomoBirdAI_HttpException('too large', 413, 'PAYLOAD_TOO_LARGE');
            }
        }
        $out = array();
        foreach ($posts as $post) {
            $id = $post['external_id'];
            $out[] = isset($this->results[$id])
                ? array_merge(array('external_id' => $id, 'document_id' => null, 'error_code' => null), $this->results[$id])
                : array('external_id' => $id, 'document_id' => 'doc-' . $id, 'status' => 'created', 'error_code' => null);
        }
        return $out;
    }

    public function deletePosts(array $ids)
    {
        $this->deletes[] = $ids;
        if ($this->failDelete !== null) {
            throw $this->failDelete;
        }
        $out = array();
        foreach ($ids as $id) {
            $out[] = array('external_id' => $id, 'status' => 'deleted');
        }
        return $out;
    }
}

class MomoBirdAI_TestStateRepository
{
    public $rows = array();
    private $nextId = 1;

    public function forPost($postId)
    {
        return isset($this->rows[$postId]) ? $this->rows[$postId] : null;
    }

    public function saveSynced($postId, $documentId, $hash)
    {
        $this->put($postId, array('document_id' => $documentId, 'content_hash' => $hash, 'sync_status' => 'synced', 'last_error_code' => null));
    }

    public function markFailure($postId, $status, $code, $message)
    {
        $this->put($postId, array('sync_status' => $status, 'last_error_code' => $code, 'last_error_message' => $message));
    }

    public function remove($postId)
    {
        unset($this->rows[$postId]);
    }

    public function failed($afterId = 0, $limit = 200)
    {
        $out = array();
        foreach ($this->rows as $row) {
            if (!in_array($row['sync_status'], array('synced', 'skipped_empty'), true) && $row['id'] > $afterId) {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return $a['id'] - $b['id'];
        });
        return array_slice($out, 0, $limit);
    }

    private function put($postId, array $values)
    {
        $existing = isset($this->rows[$postId]) ? $this->rows[$postId] : array('id' => $this->nextId++, 'post_id' => $postId, 'document_id' => null, 'content_hash' => '');
        $this->rows[$postId] = array_merge($existing, $values);
    }
}

function mb_sync_service($client, $state)
{
    return new MomoBirdAI_SyncService($client, $state, 'https://example.com');
}

mb_test('unchanged post is not sent again', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $service = mb_sync_service($client, $state);
    mb_assert($service->syncPost(mb_post('<p>body</p>', 1))['ok'], 'first sync failed');
    mb_assert_same('doc-1', $state->rows[1]['document_id'], 'document id not stored');
    $second = $service->syncPost(mb_post('<p>body</p>', 1));
    mb_assert($second['ok'], 'second sync failed');
    mb_assert_same(1, count($client->upserts), 'unchanged post was sent again');
    $service->syncPost(mb_post('<p>edited</p>', 1));
    mb_assert_same(2, count($client->upserts), 'changed post was not sent');
});

mb_test('rejected post is recorded with its error code', function () {
    $client = new MomoBirdAI_TestClient();
    $client->results['2'] = array('status' => 'rejected', 'error_code' => 'PAYLOAD_TOO_LARGE');
    $state = new MomoBirdAI_TestStateRepository();
    $summary = mb_sync_service($client, $state)->syncPosts(array(mb_post('<p>a</p>', 1), mb_post('<p>b</p>', 2), mb_post('<p>c</p>', 3)));
    mb_assert(!$summary['ok'], 'batch with a rejected post reported ok');
    mb_assert_same(2, $summary['created'], 'created count');
    mb_assert_same(1, $summary['rejected'], 'rejected count');
    mb_assert_same('failed_upsert', $state->rows[2]['sync_status'], 'rejected post not marked failed');
    mb_assert_same('PAYLOAD_TOO_LARGE', $state->rows[2]['last_error_code'], 'rejection code lost');
    mb_assert_same('synced', $state->rows[1]['sync_status'], 'sibling post not synced');
    mb_assert_same('synced', $state->rows[3]['sync_status'], 'sibling post not synced');
});

mb_test('plan required marks posts and skips retry', function () {
    $client = new MomoBirdAI_TestClient();
    $client->failUpsert = new MomoBirdAI_PlanRequiredException('plan', 402, 'PLAN_REQUIRED');
    $state = new MomoBirdAI_TestStateRepository();
    $service = mb_sync_service($client, $state);
    $result = $service->syncPost(mb_post('<p>a</p>', 5));
    mb_assert(!$result['ok'], 'plan failure reported ok');
    mb_assert($result['plan_required'], 'plan flag missing');
    mb_assert_same('plan_required', $state->rows[5]['sync_status'], 'post not marked plan_required');
    $client->failUpsert = null;
    $retry = $service->retryFailures(function ($cid) {
        return mb_post('<p>a</p>', $cid);
    });
    mb_assert_same(0, $retry['retried'], 'plan_required post was retried');
    mb_assert_same(1, count($client->upserts), 'plan_required post was re-sent');
});

mb_test('delete is sent even when plan is required', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $state->markFailure(6, 'plan_required', 'PLAN_REQUIRED', 'plan');
    $result = mb_sync_service($client, $state)->deletePost(6);
    mb_assert($result['ok'], 'delete failed');
    mb_assert_same(array(array('6')), $client->deletes, 'delete was not sent');
    mb_assert(!isset($state->rows[6]), 'local row not cleared');
});

mb_test('delete is sent for posts with no local mapping', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    mb_sync_service($client, $state)->deletePost(9);
    mb_assert_same(array(array('9')), $client->deletes, 'unmapped delete was skipped');
});

mb_test('delete failure is retried', function () {
    $client = new MomoBirdAI_TestClient();
    $client->failDelete = new MomoBirdAI_HttpException('temporary', 502, 'UPSTREAM');
    $state = new MomoBirdAI_TestStateRepository();
    $service = mb_sync_service($client, $state);
    $result = $service->deletePost(7);
    mb_assert(!$result['ok'], 'failed delete reported ok');
    mb_assert_same('failed_delete', $state->rows[7]['sync_status'], 'delete failure not recorded');
    $client->failDelete = null;
    $retry = $service->retryFailures(function () {
        return null;
    });
    mb_assert_same(1, $retry['retried'], 'delete was not retried');
    mb_assert(!isset($state->rows[7]), 'retried delete did not clear the row');
});

mb_test('batches split by count and size', function () {
    $client = new MomoBirdAI_TestClient();
    $posts = array();
    for ($i = 1; $i <= 45; $i++) {
        $posts[] = mb_post('<p>small ' . $i . '</p>', $i);
    }
    mb_sync_service($client, new MomoBirdAI_TestStateRepository())->syncPosts($posts);
    mb_assert_same(array(20, 20, 5), array_map('count', $client->upserts), 'count batching wrong');

    $client = new MomoBirdAI_TestClient();
    $big = array();
    for ($i = 1; $i <= 3; $i++) {
        $big[] = mb_post('<p>' . str_repeat('x', 1572864) . '</p>', $i);
    }
    mb_sync_service($client, new MomoBirdAI_TestStateRepository())->syncPosts($big);
    mb_assert_same(array(2, 1), array_map('count', $client->upserts), 'size batching wrong');
});

mb_test('empty post is skipped, not failed, without a request', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $result = mb_sync_service($client, $state)->syncPost(mb_post('<p> </p>', 8));
    mb_assert($result['ok'], 'an empty post is not a failure');
    mb_assert_same(1, $result['skipped'], 'empty post not counted as skipped');
    mb_assert_same('skipped_empty', $state->rows[8]['sync_status'], 'empty post not marked skipped');
    mb_assert_same('empty_content', $state->rows[8]['last_error_code'], 'skip reason not recorded');
    mb_assert_same(0, count($client->upserts), 'empty post was sent');
    mb_assert_same(0, count($client->deletes), 'a never-synced empty post was deleted remotely');
});

mb_test('a synced post that becomes empty is removed remotely before it is skipped', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $service = mb_sync_service($client, $state);
    $service->syncPost(mb_post('<p>body</p>', 5));
    $result = $service->syncPost(mb_post('', 5));
    mb_assert($result['ok'], 'emptied post reported as a failure');
    mb_assert_same(array(array('5')), $client->deletes, 'stale document of an emptied post was not deleted');
    mb_assert_same('skipped_empty', $state->rows[5]['sync_status'], 'emptied post not marked skipped');
});

mb_test('an emptied post whose remote delete fails stays a delete failure', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $service = mb_sync_service($client, $state);
    $service->syncPost(mb_post('<p>body</p>', 5));
    $client->failDelete = new MomoBirdAI_TransportException('down');
    $result = $service->syncPost(mb_post('', 5));
    mb_assert(!$result['ok'], 'failed delete of an emptied post reported ok');
    mb_assert_same('failed_delete', $state->rows[5]['sync_status'], 'stale document left without a retryable failure');
});

mb_test('retry turns old empty-content failures into skipped posts', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    // How 0.3.2 and earlier recorded an empty post.
    $state->markFailure(9, 'failed_upsert', 'empty_content', 'Article content or permalink is empty');
    $summary = mb_sync_service($client, $state)->retryFailures(function ($postId) {
        return mb_post('', $postId);
    });
    mb_assert_same(1, $summary['retried'], 'old empty failure not retried');
    mb_assert_same(0, $summary['failed'], 'empty post still counted as failed after retry');
    mb_assert_same('skipped_empty', $state->rows[9]['sync_status'], 'old empty failure not converted');
    mb_assert_same(array(), $state->failed(), 'skipped post would be retried again');
});

mb_test('unmappable post is skipped without failing the batch', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $summary = mb_sync_service($client, $state)->syncPosts(array(mb_post('<p>a</p>', 1), mb_post('<p><img src="x.png"></p>', 2), mb_post('<p>c</p>', 3)));
    mb_assert($summary['ok'], 'an empty post failed the batch');
    mb_assert_same(0, $summary['failed'], 'an unmappable post counted as a transport failure');
    mb_assert_same(1, $summary['skipped'], 'unmappable post not counted as skipped');
    mb_assert_same('skipped_empty', $state->rows[2]['sync_status'], 'unmappable post not marked skipped');
    mb_assert_same('synced', $state->rows[3]['sync_status'], 'sibling post blocked');
});

mb_test('invalid utf-8 in one post does not fail the batch', function () {
    $client = new MomoBirdAI_TestClient();
    $state = new MomoBirdAI_TestStateRepository();
    $bad = mb_post('<p>ok</p>', 2);
    $bad['title'] = "bad \xC3\x28 title";
    $summary = mb_sync_service($client, $state)->syncPosts(array(mb_post('<p>a</p>', 1), $bad));
    mb_assert_same(0, $summary['failed'], 'invalid utf-8 failed the whole batch');
    mb_assert_same('synced', $state->rows[1]['sync_status'], 'sibling post blocked by invalid utf-8');
});

mb_test('payload too large is retried post by post and rejects only the oversized post', function () {
    $client = new MomoBirdAI_TestClient();
    $client->tooLargeIds = array('2' => true);
    $state = new MomoBirdAI_TestStateRepository();
    $summary = mb_sync_service($client, $state)->syncPosts(array(mb_post('<p>a</p>', 1), mb_post('<p>b</p>', 2), mb_post('<p>c</p>', 3)));
    mb_assert_same(0, $summary['failed'], '413 counted as a transport failure');
    mb_assert_same(1, $summary['rejected'], 'oversized post not rejected');
    mb_assert_same('PAYLOAD_TOO_LARGE', $state->rows[2]['last_error_code'], 'oversized post code');
    mb_assert_same('synced', $state->rows[1]['sync_status'], 'post 1 not synced after split');
    mb_assert_same('synced', $state->rows[3]['sync_status'], 'post 3 not synced after split');
});
