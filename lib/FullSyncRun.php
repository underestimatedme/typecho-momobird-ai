<?php

final class MomoBirdAI_FullSyncRun
{
    private $posts;
    private $sync;
    private $state;
    private $client;

    public function __construct($posts, $sync, $state, $client)
    {
        $this->posts = $posts;
        $this->sync = $sync;
        $this->state = $state;
        $this->client = $client;
    }

    public function start()
    {
        $token = bin2hex(random_bytes(24));
        $this->state->startRun($token);
        return $token;
    }

    public function syncPage($token, $afterCid, $limit)
    {
        if (!$this->state->runIsActive($token)) {
            throw new RuntimeException('Full sync run is invalid or expired');
        }
        $limit = min(20, max(1, (int) $limit));
        $afterCid = max(0, (int) $afterCid);
        if ($this->state->expectedRunCursor($token) !== $afterCid) {
            throw new RuntimeException('Full sync cursor is out of sequence');
        }
        $posts = $this->posts->page($afterCid, $limit);
        $summary = $posts ? $this->sync->syncPosts($posts) : null;
        // A rejected post is a permanent per-post problem already recorded locally; only
        // transport or plan failures stop the run, so orphan cleanup cannot run on a partial view.
        if ($summary !== null && ($summary['failed'] > 0 || $summary['plan_required'])) {
            return array(
                'ok' => false,
                'done' => false,
                'cleanup_allowed' => false,
                'processed' => 0,
                'rejected' => $summary['rejected'],
                'next_cursor' => $afterCid,
                'plan_required' => $summary['plan_required'],
                'error' => $summary['error'] !== null ? $summary['error'] : array('code' => 'sync_failed', 'message' => 'Post sync failed')
            );
        }
        $nextCursor = $afterCid;
        foreach ($posts as $post) {
            $nextCursor = max($nextCursor, (int) $post['cid']);
        }
        $done = count($posts) < $limit;
        $this->state->advanceRun($token, $afterCid, $nextCursor);
        if ($done) {
            $this->state->allowCleanup($token, $nextCursor);
        }
        return array(
            'ok' => true,
            'done' => $done,
            'cleanup_allowed' => $done,
            'processed' => count($posts),
            'rejected' => $summary !== null ? $summary['rejected'] : 0,
            'next_cursor' => $nextCursor
        );
    }

    public function cleanupPage($token, $cursor)
    {
        if (!$this->state->cleanupIsAllowed($token)) {
            throw new RuntimeException('Cleanup is not authorized for this run');
        }
        $remote = $this->client->listPosts($cursor);
        $orphans = array();
        foreach ($remote['items'] as $item) {
            $externalId = (string) $item['external_id'];
            $cid = ctype_digit($externalId) ? (int) $externalId : 0;
            if ($cid < 1 || $this->posts->find($cid) === null) {
                $orphans[] = $externalId;
            }
        }
        $deleted = 0;
        foreach (array_chunk($orphans, 100) as $batch) {
            $this->client->deletePosts($batch);
            foreach ($batch as $externalId) {
                if (ctype_digit($externalId)) {
                    $this->state->remove((int) $externalId);
                }
                $deleted++;
            }
        }

        $nextCursor = $remote['next_cursor'];
        $done = $nextCursor === null;
        if ($done) {
            $this->state->finishRun($token);
        }
        return array(
            'ok' => true,
            'done' => $done,
            'deleted' => $deleted,
            'next_cursor' => $nextCursor
        );
    }
}
