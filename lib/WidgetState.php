<?php

final class MomoBirdAI_WidgetState
{
    const TTL_SECONDS = 600;
    const MAX_AGE_SECONDS = 86400;
    const LOADER_URL = 'https://momobird.atlaspaces.com/widget/v1/loader.js';

    private $load;
    private $save;

    public function __construct($load, $save)
    {
        $this->load = $load;
        $this->save = $save;
    }

    // Called from the front-end footer: reads the cache only, never the network.
    public function snippet($now)
    {
        $state = $this->state();
        if (empty($state['available']) || $this->age($state, $now) > self::MAX_AGE_SECONDS) {
            return '';
        }
        $publicId = isset($state['public_id']) ? (string) $state['public_id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $publicId)) {
            return '';
        }
        return '<script defer src="' . self::LOADER_URL . '" data-project="' . $publicId . '"></script>';
    }

    public function needsRefresh($now)
    {
        return $this->isStale($this->state(), $now);
    }

    public function refresh($client, $now)
    {
        $previous = $this->state();
        try {
            $status = $client->status();
            $widget = isset($status['widget']) && is_array($status['widget']) ? $status['widget'] : array();
            $next = array(
                'public_id' => isset($widget['public_id']) ? (string) $widget['public_id'] : '',
                'available' => !empty($widget['available']),
                'reason' => isset($widget['reason']) ? (string) $widget['reason'] : 'unknown',
                'checked_at' => (int) $now
            );
        } catch (Throwable $error) {
            $next = array(
                'public_id' => isset($previous['public_id']) ? (string) $previous['public_id'] : '',
                'available' => false,
                'reason' => 'unreachable',
                'checked_at' => (int) $now
            );
        }
        call_user_func($this->save, $next);
        return $next;
    }

    public function enable($client, $now)
    {
        try {
            $client->enableWidget();
        } catch (MomoBirdAI_HttpException $error) {
            if ($error->apiCode() !== 'WIDGET_NOT_READY') {
                throw $error;
            }
            $state = array('public_id' => '', 'available' => false, 'reason' => 'not_published', 'checked_at' => (int) $now);
            call_user_func($this->save, $state);
            $state['error_code'] = 'WIDGET_NOT_READY';
            return $state;
        }
        return $this->refresh($client, $now);
    }

    public function clear()
    {
        call_user_func($this->save, array());
    }

    private function state()
    {
        $state = call_user_func($this->load);
        return is_array($state) ? $state : array();
    }

    private function isStale(array $state, $now)
    {
        return $this->age($state, $now) > self::TTL_SECONDS;
    }

    private function age(array $state, $now)
    {
        return empty($state['checked_at']) ? PHP_INT_MAX : (int) $now - (int) $state['checked_at'];
    }
}
