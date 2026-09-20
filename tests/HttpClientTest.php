<?php

function mb_valid_config()
{
    return array(
        'base_url' => 'https://api.valley.atlaspaces.com',
        'collection' => 'blog-kb',
        'api_key' => 'test-key',
        'timeout' => 10
    );
}

function mb_entry()
{
    return array(
        'external_id' => 'typecho:site:post:1:chunk',
        'title' => 'Title',
        'content' => 'Content',
        'tags' => array('typecho'),
        'source_ref' => 'https://example.com/1',
        'metadata' => array('source' => 'typecho')
    );
}

mb_test('uses namespaced endpoint and bearer auth', function () {
    $seen = array();
    $transport = function ($method, $url, array $headers, $body, $timeout) use (&$seen) {
        $seen = compact('method', 'url', 'headers', 'body', 'timeout');
        return array(
            'status' => 200,
            'body' => '{"created":1,"updated":0,"unchanged":0,"results":[{"external_id":"typecho:site:post:1:chunk","entry_id":"11111111-1111-4111-8111-111111111111","status":"created","version":1}]}'
        );
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $result = $client->import(array(mb_entry()));

    mb_assert_same('POST', $seen['method'], 'wrong method');
    mb_assert(strpos($seen['url'], '/momobird/api/v1/collections/blog-kb/entries/batch') !== false, 'wrong endpoint');
    mb_assert(in_array('Authorization: Bearer test-key', $seen['headers'], true), 'missing auth');
    mb_assert_same(1, $result['created'], 'response was not decoded');
});

mb_test('retries one transient upstream failure and then succeeds', function () {
    $attempts = 0;
    $transport = function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            return array('status' => 502, 'body' => '{"error":{"code":"provider_error","message":"temporary"}}');
        }
        return array(
            'status' => 200,
            'body' => '{"created":0,"updated":0,"unchanged":1,"results":[{"external_id":"typecho:site:post:1:chunk","entry_id":"11111111-1111-4111-8111-111111111111","status":"unchanged","version":1}]}'
        );
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $client->import(array(mb_entry()));
    mb_assert_same(2, $attempts, 'transient failure was not retried once');
});

mb_test('does not retry authentication failures', function () {
    $attempts = 0;
    $transport = function () use (&$attempts) {
        $attempts++;
        return array('status' => 401, 'body' => '{"error":{"code":"unauthorized","message":"bad key"}}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $error = mb_assert_throws(function () use ($client) {
        $client->import(array(mb_entry()));
    }, 'MomoBirdAI_HttpException');
    mb_assert_same(1, $attempts, 'authentication failure was retried');
    mb_assert_same(401, $error->status(), 'HTTP status was lost');
});

mb_test('rejects malformed oversized and incomplete success responses', function () {
    $bodies = array(
        'not-json',
        str_repeat('x', MomoBirdAI_HttpClient::MAX_RESPONSE_BYTES + 1),
        '{"created":1,"updated":0,"unchanged":0}',
        '{"created":"1","updated":0,"unchanged":0,"results":[]}'
    );
    foreach ($bodies as $body) {
        $transport = function () use ($body) {
            return array('status' => 200, 'body' => $body);
        };
        $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
        mb_assert_throws(function () use ($client) {
            $client->import(array(mb_entry()));
        }, 'MomoBirdAI_HttpException');
    }
});

mb_test('never includes the configured API key in upstream errors', function () {
    $transport = function () {
        return array('status' => 403, 'body' => '{"error":{"code":"forbidden","message":"test-key has no scope"}}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $error = mb_assert_throws(function () use ($client) {
        $client->import(array(mb_entry()));
    }, 'MomoBirdAI_HttpException');
    mb_assert(strpos($error->getMessage(), 'test-key') === false, 'API key leaked into exception');
});

mb_test('lists and deletes entries with validated identifiers', function () {
    $seen = array();
    $transport = function ($method, $url) use (&$seen) {
        $seen[] = array($method, $url);
        if ($method === 'DELETE') {
            return array('status' => 204, 'body' => '');
        }
        return array('status' => 200, 'body' => '{"items":[],"next_cursor":""}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $client->listEntries('11111111-1111-4111-8111-111111111111', 'typecho-site-a');
    $client->deleteEntry('22222222-2222-4222-8222-222222222222');

    mb_assert(strpos($seen[0][1], 'cursor=11111111-1111-4111-8111-111111111111') !== false, 'cursor missing');
    mb_assert(strpos($seen[0][1], 'tag=typecho-site-a') !== false, 'tag missing');
    mb_assert_same('DELETE', $seen[1][0], 'delete used wrong method');
});

mb_test('treats an already deleted remote entry as successful convergence', function () {
    $transport = function () {
        return array('status' => 404, 'body' => '{"error":{"code":"not_found","message":"gone"}}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $client->deleteEntry('22222222-2222-4222-8222-222222222222');
    mb_assert(true, 'delete 404 should not throw');
});

mb_test('retries one transient list failure', function () {
    $attempts = 0;
    $transport = function () use (&$attempts) {
        $attempts++;
        return $attempts === 1
            ? array('status' => 504, 'body' => '{"error":{"code":"timeout","message":"temporary"}}')
            : array('status' => 200, 'body' => '{"items":[]}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $client->listEntries();
    mb_assert_same(2, $attempts, 'transient list failure was not retried');
});

mb_test('rejects path-like collection values before endpoint construction', function () {
    $values = mb_valid_config();
    $values['collection'] = 'blog-kb/entries';
    mb_assert_throws(function () use ($values) {
        MomoBirdAI_Config::fromArray($values);
    }, 'InvalidArgumentException');
});
