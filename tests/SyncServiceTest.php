<?php

function mb_post($body, $cid = 1)
{
    return array(
        'cid' => $cid,
        'title' => 'Post ' . $cid,
        'text' => $body,
        'permalink' => 'https://example.com/' . $cid,
        'modified' => 1720000000,
        'tags' => array('docs'),
        'categories' => array('help')
    );
}

class MomoBirdAI_TestClient
{
    public $events;
    public $imports = array();
    public $deleted = array();
    public $failImport = false;
    public $failDelete = array();

    public function __construct(array &$events)
    {
        $this->events =& $events;
    }

    public function import(array $entries)
    {
        $this->events[] = 'import';
        if ($this->failImport) {
            throw new MomoBirdAI_HttpException('temporary', 502, 'provider_error');
        }
        $this->imports[] = $entries;
        $results = array();
        foreach ($entries as $index => $entry) {
            $suffix = str_pad((string) (count($this->imports) * 100 + $index), 12, '0', STR_PAD_LEFT);
            $results[] = array(
                'external_id' => $entry['external_id'],
                'entry_id' => '11111111-1111-4111-8111-' . $suffix,
                'status' => 'created',
                'version' => 1
            );
        }
        return array('created' => count($entries), 'updated' => 0, 'unchanged' => 0, 'results' => $results);
    }

    public function deleteEntry($uuid)
    {
        $this->events[] = 'delete:' . $uuid;
        if (in_array($uuid, $this->failDelete, true)) {
            throw new MomoBirdAI_HttpException('delete failed', 502, 'provider_error');
        }
        $this->deleted[] = $uuid;
    }
}

class MomoBirdAI_TestStateRepository
{
    public $events;
    public $mappings = array();
    public $postFailures = array();

    public function __construct(array $initial, array &$events)
    {
        $this->events =& $events;
        foreach ($initial as $chunkKey => $uuid) {
            $this->mappings[1][$chunkKey] = array(
                'post_id' => 1,
                'chunk_key' => $chunkKey,
                'external_id' => 'old:' . $chunkKey,
                'entry_id' => $uuid,
                'sync_status' => 'synced'
            );
        }
    }

    public function forPost($postId)
    {
        return isset($this->mappings[$postId]) ? array_values($this->mappings[$postId]) : array();
    }

    public function replacePost($postId, array $next)
    {
        $this->events[] = 'replace';
        foreach ($next as $mapping) {
            $this->mappings[$postId][$mapping['chunk_key']] = $mapping;
        }
        unset($this->postFailures[$postId]);
    }

    public function markFailure($postId, $mapping, $status, $code, $message)
    {
        if ($mapping === null) {
            $this->postFailures[$postId] = compact('status', 'code', 'message');
            return;
        }
        $mapping['sync_status'] = $status;
        $mapping['last_error_code'] = $code;
        $this->mappings[$postId][$mapping['chunk_key']] = $mapping;
    }

    public function remove($postId, $chunkKey)
    {
        unset($this->mappings[$postId][$chunkKey]);
    }

    public function failed()
    {
        $failed = array();
        foreach ($this->mappings as $rows) {
            foreach ($rows as $row) {
                if ($row['sync_status'] !== 'synced') {
                    $failed[] = $row;
                }
            }
        }
        return $failed;
    }

    public function hasEntry($uuid)
    {
        foreach ($this->mappings as $rows) {
            foreach ($rows as $row) {
                if ($row['entry_id'] === $uuid) {
                    return true;
                }
            }
        }
        return false;
    }
}

mb_test('upserts before deleting stale chunks', function () {
    $events = array();
    $client = new MomoBirdAI_TestClient($events);
    $state = new MomoBirdAI_TestStateRepository(array('old-key' => '22222222-2222-4222-8222-222222222222'), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    $result = $service->syncPost(mb_post('new body'));

    mb_assert($result['ok'], 'sync should succeed');
    mb_assert_same('import', $events[0], 'must import first');
    mb_assert(in_array('delete:22222222-2222-4222-8222-222222222222', $events, true), 'stale UUID not deleted');
    mb_assert(!$state->hasEntry('22222222-2222-4222-8222-222222222222'), 'stale mapping remains after successful delete');
});

mb_test('failed import retains old mappings', function () {
    $events = array();
    $client = new MomoBirdAI_TestClient($events);
    $client->failImport = true;
    $state = new MomoBirdAI_TestStateRepository(array('old-key' => '33333333-3333-4333-8333-333333333333'), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    $result = $service->syncPost(mb_post('changed'));

    mb_assert(!$result['ok'], 'expected failure');
    mb_assert($state->hasEntry('33333333-3333-4333-8333-333333333333'), 'old mapping was removed');
    mb_assert_same(array('import'), $events, 'delete occurred after failed import');
});

mb_test('repeated sync keeps one mapping and does not delete it', function () {
    $events = array();
    $client = new MomoBirdAI_TestClient($events);
    $state = new MomoBirdAI_TestStateRepository(array(), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    mb_assert($service->syncPost(mb_post("## Stable\n\nBody"))['ok'], 'first sync failed');
    mb_assert($service->syncPost(mb_post("## Stable\n\nBody"))['ok'], 'second sync failed');

    mb_assert_same(1, count($state->forPost(1)), 'repeated sync duplicated state rows');
    mb_assert_same(array(), $client->deleted, 'unchanged chunk was deleted');
});

mb_test('deletes a removed source using only stored UUID mappings', function () {
    $events = array();
    $uuid = '44444444-4444-4444-8444-444444444444';
    $client = new MomoBirdAI_TestClient($events);
    $state = new MomoBirdAI_TestStateRepository(array('gone' => $uuid), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    $result = $service->deletePost(1);

    mb_assert($result['ok'], 'delete failed');
    mb_assert_same(1, $result['deleted'], 'wrong delete count');
    mb_assert(!$state->hasEntry($uuid), 'deleted source mapping remains');
});

mb_test('partial delete retains only the failed UUID for retry', function () {
    $events = array();
    $okUuid = '55555555-5555-4555-8555-555555555555';
    $badUuid = '66666666-6666-4666-8666-666666666666';
    $client = new MomoBirdAI_TestClient($events);
    $client->failDelete = array($badUuid);
    $state = new MomoBirdAI_TestStateRepository(array('ok' => $okUuid, 'bad' => $badUuid), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    $result = $service->deletePost(1);

    mb_assert(!$result['ok'], 'partial delete was reported as success');
    mb_assert(!$state->hasEntry($okUuid), 'successful delete mapping remains');
    mb_assert($state->hasEntry($badUuid), 'failed delete mapping was lost');
    mb_assert_same('failed_delete', $state->forPost(1)[0]['sync_status'], 'failed mapping status was not retained');
});

mb_test('imports more than one hundred chunks in bounded batches', function () {
    $sections = array();
    for ($i = 0; $i < 101; $i++) {
        $sections[] = '## Section ' . $i . "\n\nBody " . $i;
    }
    $events = array();
    $client = new MomoBirdAI_TestClient($events);
    $state = new MomoBirdAI_TestStateRepository(array(), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');

    $result = $service->syncPost(mb_post(implode("\n\n", $sections)));

    mb_assert($result['ok'], 'batched sync failed');
    mb_assert_same(2, count($client->imports), 'wrong batch count');
    mb_assert_same(100, count($client->imports[0]), 'first batch exceeded contract');
    mb_assert_same(1, count($client->imports[1]), 'second batch size is wrong');
    mb_assert_same(101, count($state->forPost(1)), 'not every mapping was saved');
});
