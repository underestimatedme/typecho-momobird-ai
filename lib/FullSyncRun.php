<?php

final class MomoBirdAI_FullSyncRun
{
    private $posts;
    private $sync;
    private $state;
    private $client;
    private $siteId;

    public function __construct($posts, $sync, $state, $client, $siteId)
    {
        $this->posts = $posts;
        $this->sync = $sync;
        $this->state = $state;
        $this->client = $client;
        $this->siteId = (string) $siteId;
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
        $processed = 0;
        $nextCursor = $afterCid;
        foreach ($posts as $post) {
            $result = $this->sync->syncPost($post);
            if (!$result['ok']) {
                return array(
                    'ok' => false,
                    'done' => false,
                    'cleanup_allowed' => false,
                    'processed' => $processed,
                    'next_cursor' => $nextCursor,
                    'failed_post_id' => (int) $post['cid'],
                    'error' => isset($result['error']) ? $result['error'] : array('code' => 'sync_failed', 'message' => 'Post sync failed')
                );
            }
            $processed++;
            $nextCursor = (int) $post['cid'];
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
            'processed' => $processed,
            'next_cursor' => $nextCursor
        );
    }

    public function cleanupPage($token, $cursor)
    {
        if (!$this->state->cleanupIsAllowed($token)) {
            throw new RuntimeException('Cleanup is not authorized for this run');
        }
        $tag = 'typecho-site-' . substr(hash('sha256', $this->siteId), 0, 12);
        $remote = $this->client->listEntries($cursor, $tag);
        $current = $this->state->allExternalIds();
        $prefix = 'typecho:' . $this->siteId . ':post:';
        $deleted = 0;
        foreach ($remote['items'] as $item) {
            if (!is_array($item) || empty($item['id']) || empty($item['external_id'])) {
                throw new UnexpectedValueException('MomoBird returned an invalid entry during cleanup');
            }
            $externalId = (string) $item['external_id'];
            if (strpos($externalId, $prefix) !== 0 || isset($current[$externalId])) {
                continue;
            }
            $this->client->deleteEntry((string) $item['id']);
            $this->state->removeFailedByExternalId($externalId);
            $deleted++;
        }

        $nextCursor = !empty($remote['next_cursor']) ? (string) $remote['next_cursor'] : null;
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
