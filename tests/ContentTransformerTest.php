<?php

mb_test('document keeps the title and section headings', function () {
    $document = MomoBirdAI_ContentTransformer::document('标题', '<h2>小节</h2><p>正文</p>');
    mb_assert_same("# 标题\n\n## 小节\n\n正文", $document, 'document shape changed');
});

mb_test('document strips scripts and unsafe html', function () {
    $document = MomoBirdAI_ContentTransformer::document('T', '<p>a</p><script>alert(1)</script><style>x{}</style>');
    mb_assert(strpos($document, 'alert(1)') === false, 'script content remains');
    mb_assert(strpos($document, '<') === false, 'html tags remain');
});

mb_test('document of an empty body is empty', function () {
    mb_assert_same('', MomoBirdAI_ContentTransformer::document('T', '<p> </p><!--markdown-->'), 'empty body should produce no document');
});
