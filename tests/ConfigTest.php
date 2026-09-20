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
                'collection' => 'blog-kb',
                'api_key' => 'secret',
                'timeout' => 10
            ));
        }, 'InvalidArgumentException');
    }
});

mb_test('allows local http but rejects invalid credentials and limits', function () {
    $config = MomoBirdAI_Config::fromArray(array(
        'base_url' => 'http://127.0.0.1:8080/',
        'collection' => 'blog-kb',
        'api_key' => 'test-key',
        'timeout' => 2
    ));
    mb_assert_same('http://127.0.0.1:8080', $config->baseUrl(), 'local base URL was not normalized');

    foreach (array(
        array('base_url' => 'https://example.com', 'collection' => 'Bad Slug', 'api_key' => 'x', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'collection' => 'blog-kb', 'api_key' => '', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'collection' => 'blog-kb', 'api_key' => 'x', 'timeout' => 31)
    ) as $values) {
        mb_assert_throws(function () use ($values) {
            MomoBirdAI_Config::fromArray($values);
        }, 'InvalidArgumentException');
    }
});

mb_test('blank submitted secret preserves the stored key', function () {
    $config = MomoBirdAI_Config::mergeForSave(
        array('api_key' => 'stored-key'),
        array('base_url' => 'https://example.com', 'collection' => 'blog-kb', 'api_key' => '  ', 'timeout' => 10)
    );
    mb_assert_same('stored-key', $config->apiKey(), 'stored key was overwritten');
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
        mb_assert(strpos($sql, 'chunk_key') !== false, 'chunk_key missing');
        mb_assert(stripos($sql, 'UNIQUE') !== false, 'unique mapping constraint missing');
    }
});

mb_test('rejects unsafe database prefixes before schema construction', function () {
    mb_assert_throws(function () {
        MomoBirdAI_SyncRepository::schemaSql('Mysql', 'typecho_; DROP TABLE contents;');
    }, 'InvalidArgumentException');
});
