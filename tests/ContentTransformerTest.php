<?php

mb_test('chunks malformed multibyte html safely', function () {
    $html = '<h2>章节</h2><p>' . str_repeat('你', 7600) . '<script>alert(1)</script>';
    $chunks = MomoBirdAI_ContentTransformer::chunks($html, 7500);

    mb_assert(count($chunks) >= 2, 'expected multiple chunks');
    foreach ($chunks as $chunk) {
        mb_assert($chunk['content'] !== '', 'chunk must not be empty');
        mb_assert(mb_strlen($chunk['content'], 'UTF-8') <= 7500, 'chunk exceeds limit');
        mb_assert(strpos($chunk['content'], '<script') === false, 'unsafe html remains');
        mb_assert(strpos($chunk['content'], 'alert(1)') === false, 'script content remains');
    }
});

mb_test('maps the same post deterministically and isolates sites', function () {
    $post = array(
        'cid' => 42,
        'title' => '安装说明',
        'text' => "## 准备\n\n安装 PHP。",
        'permalink' => 'https://example.com/archives/42',
        'modified' => 1720000000,
        'tags' => array('帮助'),
        'categories' => array('文档')
    );

    $first = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');
    $again = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');
    $otherSite = MomoBirdAI_EntryMapper::mapPost($post, 'site-b');

    mb_assert_same($first, $again, 'mapping must be deterministic');
    mb_assert($first[0]['external_id'] !== $otherSite[0]['external_id'], 'sites must be isolated');
    mb_assert_same('https://example.com/archives/42', $first[0]['source_ref'], 'source URL changed');
});

mb_test('keeps a headed chunk identity when only its body changes', function () {
    $post = array(
        'cid' => 7,
        'title' => '指南',
        'text' => "## 安装\n\n旧内容",
        'permalink' => 'https://example.com/7',
        'modified' => 1,
        'tags' => array(),
        'categories' => array()
    );
    $before = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');
    $post['text'] = "## 安装\n\n更新后的内容";
    $post['modified'] = 2;
    $after = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');

    mb_assert_same($before[0]['external_id'], $after[0]['external_id'], 'body edit changed stable section identity');
    mb_assert($before[0]['content'] !== $after[0]['content'], 'fixture must change content');
});

mb_test('bounds mapped titles and tags to the API contract', function () {
    $tags = array();
    for ($i = 0; $i < 50; $i++) {
        $tags[] = str_repeat('标', 70) . $i;
    }
    $post = array(
        'cid' => 9,
        'title' => str_repeat('题', 310),
        'text' => '正文',
        'permalink' => 'https://example.com/9',
        'modified' => 1,
        'tags' => $tags,
        'categories' => array()
    );
    $entry = MomoBirdAI_EntryMapper::mapPost($post, 'site-a')[0];

    mb_assert(mb_strlen($entry['title'], 'UTF-8') <= 300, 'title exceeds API limit');
    mb_assert(count($entry['tags']) <= 32, 'too many tags');
    foreach ($entry['tags'] as $tag) {
        mb_assert(mb_strlen($tag, 'UTF-8') <= 64, 'tag exceeds API limit');
    }
});
