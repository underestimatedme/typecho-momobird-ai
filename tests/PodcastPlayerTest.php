<?php

const MB_TEST_SOURCE_ID = '01a16017-e335-75b8-ae8c-bf4dbaba5f1d';

function mb_podcast_player(array &$store)
{
    MomoBirdAI_PodcastPlayer::resetRequest();
    return new MomoBirdAI_PodcastPlayer(
        function () use (&$store) {
            return $store;
        },
        function (array $values) use (&$store) {
            $store = $values;
        }
    );
}

class MomoBirdAI_TestArchive
{
    public $cid = 42;
    public $status = 'publish';
    public $password = '';
    public $type = 'post';
    public $single = true;

    public function is($type)
    {
        return $type === 'single' ? $this->single : ($type === 'post' && $this->single && $this->type === 'post');
    }
}

mb_test('podcast placeholder only on a single public post', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $player = mb_podcast_player($store);
    $html = $player->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com');
    mb_assert_same(
        '<div class="momobird-podcast" data-source="' . MB_TEST_SOURCE_ID . '" data-post="42" data-api="https://api.valley.atlaspaces.com"></div>',
        $html,
        'placeholder markup'
    );
    mb_assert_same('', $player->placeholder(42, false, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder on a list page');
    mb_assert_same('', $player->placeholder(42, true, false, true, 'https://api.valley.atlaspaces.com'), 'placeholder on a private post');
    mb_assert_same('', $player->placeholder(0, true, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder without a cid');
});

mb_test('no podcast placeholder without a source id', function () {
    $store = array();
    mb_assert_same('', mb_podcast_player($store)->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder without source');
});

mb_test('no podcast placeholder when disabled', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    mb_assert_same('', mb_podcast_player($store)->placeholder(42, true, true, false, 'https://api.valley.atlaspaces.com'), 'placeholder while disabled');
});

mb_test('podcast script only after a placeholder and only once', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $player = mb_podcast_player($store);
    mb_assert_same('', $player->script(true), 'script without placeholder');
    $player->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com');
    mb_assert_same('<script defer src="https://momobird.atlaspaces.com/widget/podcast.v1.js"></script>', $player->script(true), 'script after placeholder');
    mb_assert_same('', $player->script(true), 'script printed twice');
});

mb_test('remember status stores a valid source id', function () {
    $store = array();
    $player = mb_podcast_player($store);
    $player->rememberStatus(array('source' => array('id' => MB_TEST_SOURCE_ID)), 1760000000);
    mb_assert_same(MB_TEST_SOURCE_ID, $player->sourceId(), 'source id not remembered');
    mb_assert_same(1760000000, $store['checked_at'], 'checked_at not stored');
});

mb_test('remember status rejects a malformed source id', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1);
    $player = mb_podcast_player($store);
    $player->rememberStatus(array('source' => array('id' => '"><script>alert(1)</script>')), 1760000000);
    mb_assert_same(MB_TEST_SOURCE_ID, $player->sourceId(), 'malformed id replaced the stored one');
    $store = array('source_id' => '"><script>', 'checked_at' => 1);
    mb_assert_same('', mb_podcast_player($store)->sourceId(), 'malformed stored id used');
});

mb_test('podcast placeholder escapes the api base', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $html = mb_podcast_player($store)->placeholder(42, true, true, true, 'https://x.example.com/"><script>');
    mb_assert(strpos($html, '<script>') === false, 'api base not escaped: ' . $html);
});

mb_test('podcast refresh is claimed only when the source id is missing and stale', function () {
    $store = array();
    $player = mb_podcast_player($store);
    mb_assert($player->claimRefresh(1760000000), 'missing source id not claimed');
    mb_assert(!$player->claimRefresh(1760000100), 'claimed twice within ten minutes');
    mb_assert($player->claimRefresh(1760000700), 'not reclaimed after ten minutes');
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1);
    mb_assert(!mb_podcast_player($store)->claimRefresh(1760000000), 'claimed although source id is known');
});

mb_test('content hook prepends the player on a single public post', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAIPodcast'] = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => time());
    MomoBirdAI_PodcastPlayer::resetRequest();
    $archive = new MomoBirdAI_TestArchive();
    $html = MomoBirdAI_Plugin::podcastContent('<p>正文</p>', $archive, null);
    mb_assert(strpos($html, '<div class="momobird-podcast"') === 0, 'player not prepended: ' . $html);
    mb_assert(substr($html, -strlen('<p>正文</p>')) === '<p>正文</p>', 'content changed');
    MomoBirdAI_PodcastPlayer::resetRequest();
    $html = MomoBirdAI_Plugin::podcastContent('<p>正文</p>', $archive, '<p>上一个插件</p>');
    mb_assert(strpos($html, '<p>上一个插件</p>') !== false, 'previous filter result dropped');
    $archive->password = 'secret';
    MomoBirdAI_PodcastPlayer::resetRequest();
    mb_assert_same('<p>正文</p>', MomoBirdAI_Plugin::podcastContent('<p>正文</p>', $archive, null), 'player on a protected post');
});

mb_test('content hook never throws', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAIPodcast'] = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => time());
    MomoBirdAI_PodcastPlayer::resetRequest();
    mb_assert_same('<p>正文</p>', MomoBirdAI_Plugin::podcastContent('<p>正文</p>', new stdClass(), null), 'odd widget broke content');
});

mb_test('podcast script on a single public post even when a page cache skipped the content hook', function () {
    // TypechoRedisCache and similar plugins serve the cached body, placeholder
    // included, without running contentEx again.
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $player = mb_podcast_player($store);
    mb_assert_same('', $player->script(true, false), 'script on a page that is not a single public post');
    mb_assert_same('<script defer src="https://momobird.atlaspaces.com/widget/podcast.v1.js"></script>', $player->script(true, true), 'script missing on a cached single post');
    mb_assert_same('', $player->script(true, true), 'script printed twice');
    $empty = array();
    mb_assert_same('', mb_podcast_player($empty)->script(true, true), 'script without a source id');
    mb_assert_same('', mb_podcast_player($store)->script(false, true), 'script while disabled');
});

mb_test('footer loads the podcast script on a cached single post', function () {
    require_once dirname(__DIR__) . '/Plugin.php';
    Helper::$options = new MomoBirdAI_TestOptions();
    Helper::$options->plugins['MomoBirdAIPodcast'] = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => time());
    $archive = new MomoBirdAI_TestArchive();
    MomoBirdAI_PodcastPlayer::resetRequest();
    ob_start();
    MomoBirdAI_Plugin::footer($archive);
    $html = ob_get_clean();
    mb_assert(strpos($html, 'widget/podcast.v1.js') !== false, 'footer skipped the podcast script: ' . $html);
    $archive->password = 'secret';
    MomoBirdAI_PodcastPlayer::resetRequest();
    ob_start();
    MomoBirdAI_Plugin::footer($archive);
    mb_assert(strpos(ob_get_clean(), 'podcast.v1.js') === false, 'podcast script on a protected post');
});
