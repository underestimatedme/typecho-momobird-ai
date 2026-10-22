<?php

final class MomoBirdAI_AdminController
{
    private $client;
    private $posts;
    private $state;
    private $sync;
    private $fullRun;
    private $statusContext;
    private $afterFullSync;

    public function __construct($client, $posts, $state, $sync, $fullRun, array $statusContext = array(), $afterFullSync = null)
    {
        $this->client = $client;
        $this->posts = $posts;
        $this->state = $state;
        $this->sync = $sync;
        $this->fullRun = $fullRun;
        $this->statusContext = $statusContext;
        $this->afterFullSync = $afterFullSync;
    }

    public function dispatch($operation, array $input)
    {
        switch ((string) $operation) {
            case 'status':
                $configured = !empty($this->statusContext['configured']);
                return array(
                    'ok' => true,
                    'counts' => $this->state->counts(),
                    'synced_posts' => $this->state->syncedPostCount(),
                    'last_full_sync_at' => $this->state->lastFullSyncAt(),
                    'recent_error' => $this->state->recentError(),
                    'configuration_state' => $configured ? 'configured' : 'unconfigured',
                    'connection_state' => $configured ? 'not_tested' : 'unconfigured',
                    'auto_sync_enabled' => !empty($this->statusContext['auto_sync_enabled'])
                );

            case 'test-connection':
                $status = $this->client->status();
                if (isset($this->statusContext['on_status']) && is_callable($this->statusContext['on_status'])) {
                    call_user_func($this->statusContext['on_status'], $status);
                }
                $pick = function ($key) use ($status) {
                    return isset($status[$key]) && is_array($status[$key]) ? $status[$key] : array();
                };
                $source = $pick('source');
                $blogOrigin = isset($this->statusContext['site_origin']) ? (string) $this->statusContext['site_origin'] : '';
                $sourceOrigin = isset($source['site_url']) ? (string) $source['site_url'] : '';
                return array(
                    'ok' => true,
                    'project' => $pick('project'),
                    'source' => $source,
                    'entitlement' => $pick('entitlement'),
                    'widget' => $pick('widget'),
                    'blog_origin' => $blogOrigin,
                    // Posts whose links are on another origin are rejected by MomoBird.
                    'origin_matches' => self::canonicalOrigin($blogOrigin) === self::canonicalOrigin($sourceOrigin)
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
                $page = $this->fullRun->cleanupPage($token, $cursor);
                if (!empty($page['done']) && $this->afterFullSync !== null) {
                    try {
                        $page['widget'] = call_user_func($this->afterFullSync);
                    } catch (Throwable $error) {
                        // The sync itself finished; the assistant is retried again from page views.
                    }
                }
                return $page;

            case 'retry-failures':
                return $this->sync->retryFailures(array($this->posts, 'find'));

            default:
                throw new InvalidArgumentException('Unknown MomoBird admin operation');
        }
    }

    private static function canonicalOrigin($origin)
    {
        $parts = parse_url(trim((string) $origin));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : 0;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = 0;
        }
        return $scheme . '://' . strtolower($parts['host']) . ($port ? ':' . $port : '');
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
