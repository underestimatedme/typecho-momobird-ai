<?php

final class MomoBirdAI_SyncService
{
    private $client;
    private $state;
    private $siteId;

    public function __construct($client, $state, $siteId)
    {
        $this->client = $client;
        $this->state = $state;
        $this->siteId = (string) $siteId;
    }

    public function syncPost(array $post)
    {
        $postId = (int) $post['cid'];
        $previous = $this->state->forPost($postId);
        $entries = MomoBirdAI_EntryMapper::mapPost($post, $this->siteId);
        if (!$entries) {
            $this->state->markFailure($postId, null, 'failed_upsert', 'empty_content', 'Article content is empty');
            return $this->failure('empty_content', 'Article content is empty');
        }

        $summary = array('ok' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'error' => null);
        $results = array();
        try {
            foreach (array_chunk($entries, 100) as $batch) {
                $remote = $this->client->import($batch);
                $summary['created'] += (int) $remote['created'];
                $summary['updated'] += (int) $remote['updated'];
                $summary['unchanged'] += (int) $remote['unchanged'];
                $results = array_merge($results, $remote['results']);
            }
            $next = $this->combineMappings($postId, $entries, $results);
        } catch (Throwable $error) {
            $safe = $this->safeError($error);
            $this->state->markFailure($postId, null, 'failed_upsert', $safe['code'], $safe['message']);
            return $this->failure($safe['code'], $safe['message']);
        }

        $this->state->replacePost($postId, $next);
        $currentExternalIds = array();
        foreach ($next as $mapping) {
            $currentExternalIds[$mapping['external_id']] = true;
        }

        foreach ($previous as $mapping) {
            if (isset($currentExternalIds[$mapping['external_id']])) {
                continue;
            }
            $deleted = $this->deleteMapping($postId, $mapping);
            if ($deleted['ok']) {
                $summary['deleted']++;
            } else {
                $summary['ok'] = false;
                $summary['error'] = $deleted['error'];
            }
        }

        return $summary;
    }

    public function deletePost($postId)
    {
        $postId = (int) $postId;
        $summary = array('ok' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'error' => null);
        foreach ($this->state->forPost($postId) as $mapping) {
            $deleted = $this->deleteMapping($postId, $mapping);
            if ($deleted['ok']) {
                $summary['deleted']++;
            } else {
                $summary['ok'] = false;
                $summary['error'] = $deleted['error'];
            }
        }
        return $summary;
    }

    public function retryFailures($postFinder = null)
    {
        $postFinder = is_callable($postFinder) ? $postFinder : null;
        $seenPosts = array();
        $summary = array('ok' => true, 'retried' => 0, 'failed' => 0);
        foreach ($this->state->failed() as $mapping) {
            $postId = (int) $mapping['post_id'];
            if ($mapping['sync_status'] === 'failed_delete') {
                $result = $this->deleteMapping($postId, $mapping);
            } elseif ($postFinder !== null && !isset($seenPosts[$postId])) {
                $seenPosts[$postId] = true;
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
        return $summary;
    }

    private function combineMappings($postId, array $entries, array $results)
    {
        $byExternalId = array();
        foreach ($results as $result) {
            if (!is_array($result) || empty($result['external_id']) || isset($byExternalId[$result['external_id']])) {
                throw new UnexpectedValueException('Import response contains invalid or duplicate external IDs');
            }
            $byExternalId[$result['external_id']] = $result;
        }
        if (count($byExternalId) !== count($entries)) {
            throw new UnexpectedValueException('Import response did not cover every submitted entry');
        }

        $mappings = array();
        foreach ($entries as $entry) {
            if (!isset($byExternalId[$entry['external_id']])) {
                throw new UnexpectedValueException('Import response omitted a submitted entry');
            }
            $result = $byExternalId[$entry['external_id']];
            $mappings[] = array(
                'post_id' => $postId,
                'chunk_key' => $entry['metadata']['chunk_key'],
                'external_id' => $entry['external_id'],
                'entry_id' => $result['entry_id'],
                'content_hash' => $this->contentHash($entry),
                'sync_status' => 'synced',
                'version' => (int) $result['version']
            );
        }
        return $mappings;
    }

    private function deleteMapping($postId, array $mapping)
    {
        if (empty($mapping['entry_id'])) {
            $this->state->remove($postId, $mapping['chunk_key']);
            return array('ok' => true, 'error' => null);
        }
        try {
            $this->client->deleteEntry($mapping['entry_id']);
            $this->state->remove($postId, $mapping['chunk_key']);
            return array('ok' => true, 'error' => null);
        } catch (Throwable $error) {
            $safe = $this->safeError($error);
            $this->state->markFailure($postId, $mapping, 'failed_delete', $safe['code'], $safe['message']);
            return array('ok' => false, 'error' => $safe);
        }
    }

    private function contentHash(array $entry)
    {
        $tags = $entry['tags'];
        sort($tags, SORT_STRING);
        return hash('sha256', $entry['title'] . "\0" . $entry['content'] . "\0" . implode("\x1f", $tags));
    }

    private function safeError($error)
    {
        $code = $error instanceof MomoBirdAI_HttpException ? $error->apiCode() : 'sync_error';
        $message = mb_substr(strip_tags((string) $error->getMessage()), 0, 200, 'UTF-8');
        return array('code' => $code, 'message' => $message);
    }

    private function failure($code, $message)
    {
        return array(
            'ok' => false,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'error' => array('code' => $code, 'message' => $message)
        );
    }
}
