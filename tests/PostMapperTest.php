<?php

mb_test('mapper builds absolute permalink', function () {
    $post = mb_post('<p>正文</p>', 1);
    $post['permalink'] = '/archives/1.html';
    $mapped = MomoBirdAI_PostMapper::mapPost($post, 'https://underestimated.cn');
    mb_assert_same('https://underestimated.cn/archives/1.html', $mapped['url'], 'relative permalink not absolutized');
});

mb_test('mapper uses cid as external id', function () {
    $post = mb_post('<p>正文</p>', 42);
    $mapped = MomoBirdAI_PostMapper::mapPost($post, 'https://example.com');
    mb_assert_same('42', $mapped['external_id'], 'external id is not the cid');
    mb_assert_same('Post 42', $mapped['title'], 'title not mapped');
    mb_assert_same("# Post 42\n\n正文", $mapped['content'], 'content is not the whole document');
    mb_assert_same('https://example.com/42', $mapped['url'], 'absolute permalink altered');
    mb_assert_same('2024-07-03T09:46:40Z', $mapped['modified_at'], 'modified_at not RFC3339 UTC');
});

mb_test('mapper skips posts without content or url', function () {
    mb_assert_same(null, MomoBirdAI_PostMapper::mapPost(mb_post('  <p> </p> ', 3), 'https://example.com'), 'empty post mapped');
    $post = mb_post('<p>正文</p>', 4);
    $post['permalink'] = '';
    mb_assert_same(null, MomoBirdAI_PostMapper::mapPost($post, ''), 'post without url mapped');
});
