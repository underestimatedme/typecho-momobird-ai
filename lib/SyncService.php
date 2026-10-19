<?php

final class MomoBirdAI_SyncService
{
    const MAX_BATCH_POSTS = 20;
    const MAX_BATCH_BYTES = 4194304;

    private $client;
    private $state;
    private $siteOrigin;

    public function __construct($client, $state, $siteOrigin)
    {
        $this->client = $client;
        $this->state = $state;
        $this->siteOrigin = (string) $siteOrigin;
    }

    public function syncPost(array $post)
    {
        $postId = (int) $post['cid'];
        $mapped = MomoBirdAI_PostMapper::mapPost($post, $this->siteOrigin);
        if ($mapped === null) {
            $this->state->markFailure($postId, 'failed_upsert', 'empty_content', 'Article content or permalink is empty');
            $summary = $this->summary(false, array('code' => 'empty_content', 'message' => 'Article content or permalink is empty'));
            $summary['skipped'] = 1;
            return $summary;
        }
        $existing = $this->state->forPost($postId);
        if ($existing && $existing['sync_status'] === 'synced' && $existing['content_hash'] === self::hash($mapped)) {
            $summary = $this->summary(true, null);
            $summary['unchanged'] = 1;
            return $summary;
        }
        return $this->sendBatch(array($postId => $mapped));
    }

    public function syncPosts(array $posts)
    {
        $total = $this->summary(true, null);
        $batch = array();
        $batchBytes = 0;
        foreach ($posts as $post) {
            $postId = (int) $post['cid'];
            $mapped = MomoBirdAI_PostMapper::mapPost($post, $this->siteOrigin);
            if ($mapped === null) {
                // A permanent per-post problem: record it and keep the rest of the run going.
                $this->state->markFailure($postId, 'failed_upsert', 'empty_content', 'Article content or permalink is empty');
                $total['ok'] = false;
                $total['skipped']++;
                continue;
            }
            $size = strlen((string) json_encode($mapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($batch && (count($batch) >= self::MAX_BATCH_POSTS || $batchBytes + $size > self::MAX_BATCH_BYTES)) {
                $total = $this->merge($total, $this->sendBatch($batch));
                $batch = array();
                $batchBytes = 0;
            }
            $batch[$postId] = $mapped;
            $batchBytes += $size;
        }
        if ($batch) {
            $total = $this->merge($total, $this->sendBatch($batch));
        }
        return $total;
    }

    public function deletePost($postId)
    {
        $postId = (int) $postId;
        try {
            // Always ask the server: a missing local row must never leave a private post answerable.
            $this->client->deletePosts(array((string) $postId));
        } catch (Throwable $error) {
            $safe = $this->safeError($error);
            $this->state->markFailure($postId, 'failed_delete', $safe['code'], $safe['message']);
            return $this->summary(false, $safe);
        }
        $this->state->remove($postId);
        $summary = $this->summary(true, null);
        $summary['deleted'] = 1;
        return $summary;
    }

    public function retryFailures($postFinder = null)
    {
        $postFinder = is_callable($postFinder) ? $postFinder : null;
        $summary = array('ok' => true, 'retried' => 0, 'failed' => 0);
        $afterId = 0;
        do {
            $batch = $this->state->failed($afterId, 200);
            foreach ($batch as $row) {
                $afterId = max($afterId, (int) $row['id']);
                if ($row['sync_status'] === 'plan_required') {
                    continue;
                }
                $postId = (int) $row['post_id'];
                if ($row['sync_status'] === 'failed_delete') {
                    $result = $this->deletePost($postId);
                } elseif ($postFinder !== null) {
                    $post = call_user_func($postFinder, $postId);
                    $result = $post ? $this->syncPost($post) : $this->deletePost($postId);
                } else {
                    continue;
                }
                $summary['retried']++;
                if (!$result['ok']) {
                    $summary['ok'] = false;
                    $summary['failed']++;
                }
            }
        } while (count($batch) === 200);
        return $summary;
    }

    private function sendBatch(array $mappedById)
    {
        try {
            $results = $this->client->upsertPosts(array_values($mappedById));
        } catch (MomoBirdAI_PlanRequiredException $error) {
            $safe = $this->safeError($error);
            foreach (array_keys($mappedById) as $postId) {
                $this->state->markFailure($postId, 'plan_required', $safe['code'], $safe['message']);
            }
            $summary = $this->summary(false, $safe);
            $summary['plan_required'] = true;
            return $summary;
        } catch (Throwable $error) {
            if ($error instanceof MomoBirdAI_HttpException && $error->status() === 413) {
                return $this->tooLarge($mappedById);
            }
            $safe = $this->safeError($error);
            foreach (array_keys($mappedById) as $postId) {
                $this->state->markFailure($postId, 'failed_upsert', $safe['code'], $safe['message']);
            }
            $summary = $this->summary(false, $safe);
            $summary['failed'] = count($mappedById);
            return $summary;
        }

        $summary = $this->summary(true, null);
        $byExternalId = array();
        foreach ($results as $result) {
            $byExternalId[$result['external_id']] = $result;
        }
        foreach ($mappedById as $postId => $mapped) {
            if (!isset($byExternalId[$mapped['external_id']])) {
                $this->state->markFailure($postId, 'failed_upsert', 'missing_result', 'MomoBird response omitted this post');
                $summary['ok'] = false;
                $summary['failed']++;
                continue;
            }
            $result = $byExternalId[$mapped['external_id']];
            if ($result['status'] === 'rejected') {
                $code = !empty($result['error_code']) ? (string) $result['error_code'] : 'rejected';
                $this->state->markFailure($postId, 'failed_upsert', $code, 'MomoBird rejected this post (' . $code . ')');
                $summary['ok'] = false;
                $summary['rejected']++;
                $summary['error'] = array('code' => $code, 'message' => 'MomoBird rejected a post (' . $code . ')');
                continue;
            }
            $this->state->saveSynced($postId, isset($result['document_id']) ? (string) $result['document_id'] : '', self::hash($mapped));
            $summary[$result['status']]++;
        }
        return $summary;
    }

    // The server rejects a request body over 8 MiB as a whole; resend post by post so only
    // the oversized post is rejected.
    private function tooLarge(array $mappedById)
    {
        if (count($mappedById) > 1) {
            $total = $this->summary(true, null);
            foreach ($mappedById as $postId => $mapped) {
                $total = $this->merge($total, $this->sendBatch(array($postId => $mapped)));
            }
            return $total;
        }
        $postId = key($mappedById);
        $this->state->markFailure($postId, 'failed_upsert', 'PAYLOAD_TOO_LARGE', 'MomoBird rejected this post (PAYLOAD_TOO_LARGE)');
        $summary = $this->summary(false, array('code' => 'PAYLOAD_TOO_LARGE', 'message' => 'MomoBird rejected a post (PAYLOAD_TOO_LARGE)'));
        $summary['rejected'] = 1;
        return $summary;
    }

    private static function hash(array $mapped)
    {
        return hash('sha256', $mapped['title'] . "\0" . $mapped['content'] . "\0" . $mapped['url']);
    }

    private function merge(array $total, array $part)
    {
        foreach (array('created', 'updated', 'unchanged', 'rejected', 'skipped', 'failed', 'deleted') as $key) {
            $total[$key] += $part[$key];
        }
        if (!$part['ok']) {
            $total['ok'] = false;
            $total['error'] = $part['error'];
        }
        $total['plan_required'] = $total['plan_required'] || $part['plan_required'];
        return $total;
    }

    private function safeError($error)
    {
        $code = $error instanceof MomoBirdAI_HttpException ? $error->apiCode() : 'sync_error';
        $rawMessage = strip_tags((string) $error->getMessage());
        $message = function_exists('mb_substr') ? mb_substr($rawMessage, 0, 200, 'UTF-8') : substr($rawMessage, 0, 200);
        return array('code' => $code, 'message' => $message);
    }

    private function summary($ok, $error)
    {
        return array(
            'ok' => (bool) $ok,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'rejected' => 0,
            'skipped' => 0,
            'failed' => 0,
            'deleted' => 0,
            'plan_required' => false,
            'error' => $error
        );
    }
}
