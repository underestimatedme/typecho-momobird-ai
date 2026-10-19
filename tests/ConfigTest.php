<?php

mb_test('rejects unsafe remote base urls', function () {
    $urls = array(
        'http://example.com',
        'https://user:pass@example.com',
        'https://example.com/?x=1',
        'https://example.com/#x',
        'https://example.com/other/path'
    );
    foreach ($urls as $url) {
        mb_assert_throws(function () use ($url) {
            MomoBirdAI_Config::fromArray(array(
                'base_url' => $url,
                'connection_key' => mb_key(),
                'timeout' => 10
            ));
        }, 'InvalidArgumentException');
    }
});

mb_test('config accepts a connection key', function () {
    $config = MomoBirdAI_Config::fromArray(array(
        'base_url' => 'http://127.0.0.1:8080/',
        'connection_key' => mb_key('b'),
        'timeout' => 2
    ));
    mb_assert_same('http://127.0.0.1:8080', $config->baseUrl(), 'local base URL was not normalized');
    mb_assert_same(mb_key('b'), $config->connectionKey(), 'connection key was altered');
    mb_assert_same(2, $config->timeout(), 'timeout was not kept');
});

mb_test('config rejects malformed connection keys and limits', function () {
    foreach (array(
        array('base_url' => 'https://example.com', 'connection_key' => '', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'mbk_short', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'sk_' . str_repeat('A', 44), 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'mbk_' . str_repeat('+', 43), 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 31)
    ) as $values) {
        mb_assert_throws(function () use ($values) {
            MomoBirdAI_Config::fromArray($values);
        }, 'InvalidArgumentException');
    }
});

mb_test('config keeps the stored key when the field is blank', function () {
    $config = MomoBirdAI_Config::mergeForSave(
        array('connection_key' => mb_key('s')),
        array('base_url' => 'https://example.com', 'connection_key' => '  ', 'timeout' => 10)
    );
    mb_assert_same(mb_key('s'), $config->connectionKey(), 'stored key was overwritten');
});

mb_test('config hides the key in toArray', function () {
    $config = MomoBirdAI_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10));
    $public = $config->toArray();
    mb_assert_same('', $public['connection_key'], 'key leaked from toArray');
    $private = $config->toArray(true);
    mb_assert_same(mb_key(), $private['connection_key'], 'secret toArray lost the key');
});

mb_test('widget is disabled by default and auto sync on', function () {
    $config = MomoBirdAI_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10));
    mb_assert(!$config->widgetEnabled(), 'widget should default to off');
    mb_assert($config->autoSync(), 'auto sync should default to on');
    $on = MomoBirdAI_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10, 'enabled' => '0', 'widget_enabled' => '1'));
    mb_assert($on->widgetEnabled(), 'widget flag ignored');
    mb_assert(!$on->autoSync(), 'auto sync flag ignored');
});

mb_test('only public unprotected posts are eligible', function () {
    mb_assert(MomoBirdAI_PostRepository::isEligible(array('type' => 'post', 'status' => 'publish', 'password' => '')), 'public post rejected');
    foreach (array(
        array('type' => 'page', 'status' => 'publish', 'password' => ''),
        array('type' => 'post', 'status' => 'private', 'password' => ''),
        array('type' => 'post', 'status' => 'publish', 'password' => 'secret'),
        array('type' => 'post_draft', 'status' => 'publish', 'password' => '')
    ) as $row) {
        mb_assert(!MomoBirdAI_PostRepository::isEligible($row), 'non-public input accepted');
    }
});

mb_test('builds prefixed mysql and sqlite state schemas with uniqueness', function () {
    foreach (array('Mysql', 'SQLite') as $adapter) {
        $sql = MomoBirdAI_SyncRepository::schemaSql($adapter, 'typecho_');
        mb_assert(strpos($sql, 'typecho_momobird_sync') !== false, 'table prefix missing');
        mb_assert(strpos($sql, 'site_id') !== false, 'site_id missing');
        mb_assert(strpos($sql, 'post_id') !== false, 'post_id missing');
        mb_assert(strpos($sql, 'document_id') !== false, 'document_id missing');
        mb_assert(strpos($sql, 'entry_id') === false, 'legacy entry_id column still present');
        mb_assert(stripos($sql, 'UNIQUE') !== false, 'unique mapping constraint missing');
    }
});

mb_test('rejects unsafe database prefixes before schema construction', function () {
    mb_assert_throws(function () {
        MomoBirdAI_SyncRepository::schemaSql('Mysql', 'typecho_; DROP TABLE contents;');
    }, 'InvalidArgumentException');
});

mb_test('sqlite installation creates status and post indexes', function () {
    $db = new class {
        public $queries = array();
        public function getAdapterName() { return 'Pdo_SQLite'; }
        public function getPrefix() { return 'typecho_'; }
        public function query($sql, $mode) { $this->queries[] = $sql; }
    };
    MomoBirdAI_SyncRepository::install($db);
    mb_assert_same(3, count($db->queries), 'sqlite table and indexes were not all created');
    mb_assert(strpos($db->queries[1], 'momobird_status_idx') !== false, 'status index missing');
    mb_assert(strpos($db->queries[2], 'momobird_post_idx') !== false, 'post index missing');
});

mb_test('scheduled posts are not eligible until their publish time', function () {
    $now = 1760000000;
    $row = array('type' => 'post', 'status' => 'publish', 'password' => '', 'created' => $now + 3600);
    mb_assert(!MomoBirdAI_PostRepository::isEligible($row, $now), 'future post treated as public');
    $row['created'] = $now - 60;
    mb_assert(MomoBirdAI_PostRepository::isEligible($row, $now), 'published post rejected');
});
