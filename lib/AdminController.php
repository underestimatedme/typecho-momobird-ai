<?php

final class MomoBirdAI_AdminController
{
    private $client;
    private $posts;
    private $state;
    private $sync;
    private $fullRun;

    public function __construct($client, $posts, $state, $sync, $fullRun)
    {
        $this->client = $client;
        $this->posts = $posts;
        $this->state = $state;
        $this->sync = $sync;
        $this->fullRun = $fullRun;
    }

    public function dispatch($operation, array $input)
    {
        switch ((string) $operation) {
            case 'status':
                return array('ok' => true, 'counts' => $this->state->counts());

            case 'test-connection':
                $collection = $this->client->testConnection();
                return array(
                    'ok' => true,
                    'collection' => array(
                        'slug' => isset($collection['slug']) ? $collection['slug'] : '',
                        'status' => isset($collection['status']) ? $collection['status'] : ''
                    )
                );

            case 'start-sync':
                return array('ok' => true, 'run_token' => $this->fullRun->start());

            case 'sync-page':
                $token = $this->token($input);
                $cursor = isset($input['cursor']) ? $this->nonNegativeInteger($input['cursor'], 'cursor') : 0;
                $limit = isset($input['limit']) ? $this->nonNegativeInteger($input['limit'], 'limit') : 10;
                if ($limit < 1) {
                    throw new InvalidArgumentException('Limit must be positive');
                }
                return $this->fullRun->syncPage($token, $cursor, min(20, $limit));

            case 'cleanup-page':
                $token = $this->token($input);
                $cursor = isset($input['cursor']) && $input['cursor'] !== '' ? (string) $input['cursor'] : null;
                return $this->fullRun->cleanupPage($token, $cursor);

            case 'retry-failures':
                return $this->sync->retryFailures(array($this->posts, 'find'));

            default:
                throw new InvalidArgumentException('Unknown MomoBird admin operation');
        }
    }

    private function token(array $input)
    {
        $token = isset($input['run_token']) ? (string) $input['run_token'] : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new InvalidArgumentException('Full sync token is invalid');
        }
        return $token;
    }

    private function nonNegativeInteger($value, $name)
    {
        if (!is_numeric($value) || (int) $value < 0 || (string) (int) $value !== (string) $value && !is_int($value)) {
            throw new InvalidArgumentException(ucfirst($name) . ' must be a non-negative integer');
        }
        return (int) $value;
    }
}
