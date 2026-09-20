<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$user->pass('administrator');
$securityWidget = class_exists('Widget_Security') ? Widget_Security::alloc() : call_user_func(array('Widget\\Security', 'alloc'));
$actionUrl = $securityWidget->getIndex('/action/momobird-ai');
$assetBase = rtrim($options->pluginUrl, '/') . '/MomoBirdAI/assets';

include 'header.php';
include 'menu.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/admin.css', ENT_QUOTES, 'UTF-8'); ?>">
<main class="main">
    <div class="body container">
        <div class="colgroup">
            <div class="typecho-page-main col-mb-12" id="momobird-admin"
                 data-action-url="<?php echo htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="typecho-list-operate clearfix">
                    <h1>MomoBird 知识库同步</h1>
                    <p>同步范围：仅公开普通文章。独立页面、草稿、私密及密码文章不会上传。</p>
                </div>

                <section class="momobird-card" aria-labelledby="momobird-status-title">
                    <h2 id="momobird-status-title">同步状态</h2>
                    <div class="momobird-stats">
                        <span>配置 <strong id="momobird-config-state">—</strong></span>
                        <span>连接 <strong id="momobird-connection-state">—</strong></span>
                        <span>自动同步 <strong id="momobird-auto-sync">—</strong></span>
                        <span>已同步文章 <strong id="momobird-synced-posts">—</strong></span>
                        <span>已同步分块 <strong id="momobird-synced">—</strong></span>
                        <span>上传失败 <strong id="momobird-failed-upsert">—</strong></span>
                        <span>删除失败 <strong id="momobird-failed-delete">—</strong></span>
                        <span>最近全量同步 <strong id="momobird-last-sync">—</strong></span>
                    </div>
                    <p id="momobird-recent-error" hidden></p>
                    <p id="momobird-message" role="status">正在读取状态…</p>
                </section>

                <section class="momobird-card" aria-labelledby="momobird-actions-title">
                    <h2 id="momobird-actions-title">操作</h2>
                    <div class="momobird-actions">
                        <button type="button" class="btn" data-momobird-action="test">测试连接</button>
                        <button type="button" class="btn primary" data-momobird-action="sync">一键全量同步</button>
                        <button type="button" class="btn" data-momobird-action="retry">重试失败项</button>
                    </div>
                    <progress id="momobird-progress" value="0" max="1" hidden></progress>
                </section>
            </div>
        </div>
    </div>
</main>
<script src="<?php echo htmlspecialchars($assetBase . '/admin.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php include 'footer.php'; ?>
