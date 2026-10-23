<?php

// Puts a hidden placeholder on single public posts; MomoBird's podcast.v1.js
// fills it only when the post has a generated podcast. Page views never call
// MomoBird from PHP.
final class MomoBirdAI_PodcastPlayer
{
    const SCRIPT_URL = 'https://momobird.atlaspaces.com/widget/podcast.v1.js';
    const RETRY_SECONDS = 600;
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    // The script tag follows a placeholder once per request.
    private static $placed = false;
    private static $scripted = false;

    private $load;
    private $save;

    public function __construct($load, $save)
    {
        $this->load = $load;
        $this->save = $save;
    }

    public static function resetRequest()
    {
        self::$placed = false;
        self::$scripted = false;
    }

    public function placeholder($cid, $isSinglePost, $isPublic, $enabled, $apiBase, $showTranscript = false)
    {
        $cid = (int) $cid;
        $sourceId = $this->sourceId();
        if (!$enabled || !$isSinglePost || !$isPublic || $cid < 1 || $sourceId === '') {
            return '';
        }
        self::$placed = true;
        return '<div class="momobird-podcast" data-source="' . $sourceId . '" data-post="' . $cid
            . '" data-api="' . htmlspecialchars(rtrim((string) $apiBase, '/'), ENT_QUOTES, 'UTF-8') . '"'
            . ($showTranscript ? ' data-transcript="1"' : '') . '></div>';
    }

    // A page cache (TypechoRedisCache and the like) serves the cached body,
    // placeholder included, without running the content hook again, so a single
    // public post loads the script even when nothing was placed this request.
    public function script($enabled, $onSinglePublicPost = false)
    {
        $wanted = self::$placed || ($onSinglePublicPost && $this->sourceId() !== '');
        if (!$enabled || !$wanted || self::$scripted) {
            return '';
        }
        self::$scripted = true;
        return '<script defer src="' . self::SCRIPT_URL . '"></script>';
    }

    public function rememberStatus(array $status, $now)
    {
        $source = isset($status['source']) && is_array($status['source']) ? $status['source'] : array();
        $id = isset($source['id']) ? strtolower((string) $source['id']) : '';
        if (!preg_match(self::UUID_PATTERN, $id)) {
            return;
        }
        call_user_func($this->save, array('source_id' => $id, 'checked_at' => (int) $now));
    }

    public function sourceId()
    {
        $state = $this->state();
        $id = isset($state['source_id']) ? (string) $state['source_id'] : '';
        return preg_match(self::UUID_PATTERN, $id) ? $id : '';
    }

    // Owners who upgraded without re-testing the connection have no source id yet:
    // look it up after a page is sent, at most every ten minutes.
    public function claimRefresh($now)
    {
        if ($this->sourceId() !== '') {
            return false;
        }
        $state = $this->state();
        if (!empty($state['checked_at']) && (int) $now - (int) $state['checked_at'] < self::RETRY_SECONDS) {
            return false;
        }
        call_user_func($this->save, array('source_id' => '', 'checked_at' => (int) $now));
        return true;
    }

    private function state()
    {
        $state = call_user_func($this->load);
        return is_array($state) ? $state : array();
    }
}
