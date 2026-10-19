<?php

function mb_valid_config()
{
    return array(
        'base_url' => 'https://api.valley.atlaspaces.com',
        'connection_key' => mb_key('k'),
        'timeout' => 10
    );
}

function mb_post_input($id = '1')
{
    return array(
        'external_id' => (string) $id,
        'title' => 'Title ' . $id,
        'content' => "# Title\n\nBody",
        'url' => 'https://example.com/archives/' . $id . '.html',
        'modified_at' => '2026-10-18T00:00:00Z'
    );
}

function mb_ok($data)
{
    return array('status' => 200, 'body' => json_encode(array('code' => 'OK', 'data' => $data)));
}

function mb_client($transport)
{
    return new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
}

mb_test('upsert sends bearer key to the connect endpoint', function () {
    $seen = array();
    $client = mb_client(function ($method, $url, array $headers, $body, $timeout) use (&$seen) {
        $seen = compact('method', 'url', 'headers', 'body', 'timeout');
        return mb_ok(array('results' => array(array('external_id' => '1', 'document_id' => 'd1', 'status' => 'created', 'error_code' => null))));
    });
    $results = $client->upsertPosts(array(mb_post_input('1')));

    mb_assert_same('PUT', $seen['method'], 'wrong method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts', $seen['url'], 'wrong endpoint');
    mb_assert(in_array('Authorization: Bearer ' . mb_key('k'), $seen['headers'], true), 'missing bearer key');
    $body = json_decode($seen['body'], true);
    mb_assert_same('1', $body['posts'][0]['external_id'], 'posts were not wrapped');
    mb_assert_same('created', $results[0]['status'], 'data envelope was not unwrapped');
});

mb_test('upsert rejects batches over twenty', function () {
    $calls = 0;
    $client = mb_client(function () use (&$calls) {
        $calls++;
        return mb_ok(array('results' => array()));
    });
    $posts = array();
    for ($i = 1; $i <= 21; $i++) {
        $posts[] = mb_post_input((string) $i);
    }
    mb_assert_throws(function () use ($client, $posts) {
        $client->upsertPosts($posts);
    }, 'InvalidArgumentException');
    mb_assert_throws(function () use ($client) {
        $client->upsertPosts(array());
    }, 'InvalidArgumentException');
    mb_assert_same(0, $calls, 'invalid batch reached the network');
});

mb_test('plan required raises a typed exception', function () {
    $client = mb_client(function () {
        return array('status' => 402, 'body' => '{"code":"PLAN_REQUIRED","message":"Typecho 同步需要专业版或试用"}');
    });
    $error = mb_assert_throws(function () use ($client) {
        $client->upsertPosts(array(mb_post_input()));
    }, 'MomoBirdAI_PlanRequiredException');
    mb_assert_same(402, $error->status(), 'status lost');
    mb_assert_same('PLAN_REQUIRED', $error->apiCode(), 'code lost');
});

mb_test('errors never include the connection key', function () {
    $client = mb_client(function () {
        return array('status' => 401, 'body' => '{"code":"UNAUTHORIZED","message":"bad key ' . mb_key('k') . '"}');
    });
    $error = mb_assert_throws(function () use ($client) {
        $client->status();
    }, 'MomoBirdAI_HttpException');
    mb_assert(strpos($error->getMessage(), mb_key('k')) === false, 'connection key leaked into the error');
    mb_assert_same(401, $error->status(), 'status lost');
    mb_assert_same('UNAUTHORIZED', $error->apiCode(), 'top-level error code not read');
});

mb_test('does not retry authentication failures but retries one transient failure', function () {
    $attempts = 0;
    $client = mb_client(function () use (&$attempts) {
        $attempts++;
        return array('status' => 401, 'body' => '{"code":"UNAUTHORIZED","message":"unauthorized"}');
    });
    mb_assert_throws(function () use ($client) {
        $client->status();
    }, 'MomoBirdAI_HttpException');
    mb_assert_same(1, $attempts, 'authentication failure was retried');

    $attempts = 0;
    $client = mb_client(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            return array('status' => 502, 'body' => '{"code":"UPSTREAM","message":"temporary"}');
        }
        return mb_ok(array('results' => array(array('external_id' => '1', 'status' => 'deleted'))));
    });
    $client->deletePosts(array('1'));
    mb_assert_same(2, $attempts, 'transient failure was not retried once');
});

mb_test('delete and list use the connect endpoints', function () {
    $seen = array();
    $client = mb_client(function ($method, $url, array $headers, $body) use (&$seen) {
        $seen[] = array($method, $url, $body);
        if ($method === 'GET') {
            return mb_ok(array('items' => array(array('external_id' => '7', 'document_id' => 'd7', 'updated_at' => '2026-10-18T00:00:00Z')), 'next_cursor' => 'abc'));
        }
        return mb_ok(array('results' => array(array('external_id' => '7', 'status' => 'deleted'))));
    });
    $deleted = $client->deletePosts(array('7'));
    $page = $client->listPosts('cur sor');

    mb_assert_same('POST', $seen[0][0], 'delete method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts/delete', $seen[0][1], 'delete endpoint');
    mb_assert_same(array('external_ids' => array('7')), json_decode($seen[0][2], true), 'delete body');
    mb_assert_same('deleted', $deleted[0]['status'], 'delete results');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts?limit=500&cursor=cur%20sor', $seen[1][1], 'list endpoint');
    mb_assert_same('7', $page['items'][0]['external_id'], 'list items');
    mb_assert_same('abc', $page['next_cursor'], 'list cursor');
    mb_assert_throws(function () use ($client) {
        $client->deletePosts(array());
    }, 'InvalidArgumentException');
});

mb_test('status and widget enable unwrap data', function () {
    $client = mb_client(function ($method, $url) {
        if (substr($url, -14) === '/widget/enable') {
            return mb_ok(array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok'));
        }
        return mb_ok(array('project' => array('id' => 'p', 'name' => 'Blog'), 'widget' => array('public_id' => 'pub_1', 'available' => false, 'reason' => 'not_published')));
    });
    $status = $client->status();
    mb_assert_same('Blog', $status['project']['name'], 'status not unwrapped');
    $widget = $client->enableWidget();
    mb_assert_same('ok', $widget['reason'], 'widget not unwrapped');
});

mb_test('rejects malformed oversized and envelope-less success responses', function () {
    $bodies = array(
        'not-json',
        str_repeat('x', MomoBirdAI_HttpClient::MAX_RESPONSE_BYTES + 1),
        '{"results":[]}',
        '{"code":"OK","data":{"results":"nope"}}'
    );
    foreach ($bodies as $body) {
        $client = mb_client(function () use ($body) {
            return array('status' => 200, 'body' => $body);
        });
        mb_assert_throws(function () use ($client) {
            $client->upsertPosts(array(mb_post_input()));
        }, 'MomoBirdAI_HttpException');
    }
});

mb_test('invalid utf-8 in a post is substituted instead of failing the request', function () {
    $sent = null;
    $client = mb_client(function ($method, $url, array $headers, $body) use (&$sent) {
        $sent = $body;
        return mb_ok(array('results' => array(array('external_id' => '1', 'status' => 'created'))));
    });
    $post = mb_post_input('1');
    $post['title'] = "bad \xC3\x28 title";
    $client->upsertPosts(array($post));
    mb_assert(is_string($sent) && $sent !== '', 'request body was not encoded');
});

mb_test('a no-retry client gives up after one transient failure', function () {
    $attempts = 0;
    $client = mb_client(function () use (&$attempts) {
        $attempts++;
        return array('status' => 502, 'body' => '{"code":"UPSTREAM","message":"slow"}');
    })->withoutRetry();
    mb_assert_throws(function () use ($client) {
        $client->status();
    }, 'MomoBirdAI_HttpException');
    mb_assert_same(1, $attempts, 'no-retry client retried');
});
