<?php

final class MomoBirdAI_WidgetState
{
    const TTL_SECONDS = 600;
    const MAX_AGE_SECONDS = 86400;
    const LOADER_URL = 'https://momobird.atlaspaces.com/widget/loader.v1.js';

    private $load;
    private $save;

    public function __construct($load, $save)
    {
        $this->load = $load;
        $this->save = $save;
    }

    // Called from the front-end footer: reads the cache only, never the network.
    // The loader defaults to the bottom-right corner, so only left is written out.
    public function snippet($now, $position = 'right')
    {
        $state = $this->state();
        if (empty($state['available']) || $this->age($state, $now) > self::MAX_AGE_SECONDS) {
            return '';
        }
        $publicId = isset($state['public_id']) ? (string) $state['public_id'] : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $publicId)) {
            return '';
        }
        $side = $position === 'left' ? ' data-position="left"' : '';
        return '<script defer src="' . self::LOADER_URL . '" data-project="' . $publicId . '"' . $side . '></script>';
    }

    public function needsRefresh($now)
    {
        return $this->isStale($this->state(), $now);
    }

    // Record the attempt before any network call so concurrent page views do not all refresh.
    public function claimRefresh($now)
    {
        $state = $this->state();
        if (!$this->isStale($state, $now)) {
            return false;
        }
        $state['checked_at'] = (int) $now;
        call_user_func($this->save, $state);
        return true;
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

    // Refresh, and publish the assistant if it has never been published (the owner chose 显示
    // before any post was indexed). Other reasons, such as the owner turning it off, are respected.
    public function ensureEnabled($client, $now)
    {
        $state = $this->refresh($client, $now);
        if ($state['reason'] !== 'not_published') {
            return $state;
        }
        try {
            return $this->enable($client, $now);
        } catch (Throwable $error) {
            call_user_func($this->save, $state);
            return $state;
        }
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
