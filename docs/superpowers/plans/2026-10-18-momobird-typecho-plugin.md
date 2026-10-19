# MomoBird Typecho 同步 · 插件 v0.2.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `MomoBirdAI` 插件从旧的 collection 接口改为用连接密钥写入控制台项目，并能在博客前台挂出 MomoBird 问答助手。

**Architecture:** 保留现有骨架（生命周期钩子、`momobird_sync` 表、浏览器驱动的分批全量同步、失败重试、孤儿清理、后台面板）。换掉三处：配置（连接密钥替代 collection + API Key）、HTTP 客户端（新插件接口）、映射（一篇文章 = 一个请求项，不再在客户端切块）。新增前台 footer 钩子输出助手脚本，状态缓存 10 分钟。

**Tech Stack:** PHP 7.2+（本地测试用 PHP 8.5）、Typecho 1.2.x、cURL、mbstring；自带测试框架 `php tests/run.php`。

**Spec:** `Valley/docs/superpowers/specs/2026-10-18-momobird-typecho-sync-design.md`
**接口契约：** `Valley/docs/superpowers/plans/2026-10-18-momobird-typecho-backend.md` 的「插件接口」一节。

## Global Constraints

- 仓库 `MomoBird_plugins/typecho-momobird-ai`（独立 git 仓库，分支 `main`），新建分支 `feat/connect-v2`。
- 版本号 `0.2.0`（`Plugin.php` 头注释、README、打包文件名 `MomoBirdAI-0.2.0.zip`，zip 顶层目录 `MomoBirdAI/`）。
- v0.1.0 没有任何安装，**不写数据迁移**：直接改建表 SQL；旧的 collection 代码全部删除。
- 默认 Base URL：`https://api.valley.atlaspaces.com`；接口前缀 `/momobird/api/public/connect/v1`。
- 连接密钥格式：`mbk_` + 43 位 base64url；只在服务端 PHP 使用，不出现在前台 HTML/JS，设置页不回显（沿用 `mergeForSave` 的留空保留逻辑）。
- 同步范围不变：只同步 `type=post`、`status=publish`、无密码的文章（`PostRepository::isEligible`）。
- 每批最多 20 篇且请求体估算不超过 4 MiB（服务端上限 8 MiB，留余量）；删除每批最多 100 个。
- 前台脚本：`<script defer src="https://momobird.atlaspaces.com/widget/v1/loader.js" data-project="{public_id}"></script>`（`public_id` 只允许 `[A-Za-z0-9_-]`，否则不输出）。
- 收到 402 `PLAN_REQUIRED`：后台显示到期提示，不计入失败重试；删除类请求照常发送。
- 每个任务结束跑：`php tests/run.php` 全部通过，以及 `find . -name '*.php' -not -path './.git/*' -not -path './.worktrees/*' -print0 | xargs -0 -n1 php -l`。

## Review Focus

1. **文章转私密时权益已过期**：插件仍要把删除发出去，并且本地映射清掉。（Task 3 `delete is sent even when plan is required`）
2. **超大文章**：单篇超过 2 MiB 被服务端 `rejected` → 本地记为失败并显示原因，不阻塞同批其他文章、不无限重试。（Task 3 `rejected post is recorded with its error code`）
3. **前台状态接口不可达**：MomoBird 宕机时博客页面不能变慢或报错 → footer 钩子只读缓存，从不同步请求网络。（Task 5 `footer never calls the network`）
4. **全量同步中途关页面**：重新开始全量同步要幂等，不产生重复文档。（Task 4 `restarted full sync does not duplicate`）
5. **文章没有固定链接或是相对链接**：发送前转成博客 origin 下的绝对 URL；拿不到就跳过该篇并记录。（Task 2 `mapper builds absolute permalink`）

---

### Task 1: 配置改为连接密钥

**Files:**
- Modify: `lib/Config.php`、`Plugin.php`（`config()` 表单）
- Test: `tests/ConfigTest.php`

**Interfaces:**
- Produces（`MomoBirdAI_Config`）：
  - `fromArray(array $values)` 读取 `base_url`、`connection_key`、`timeout`（2–30，默认 10）、`auto_sync`（`'1'|'0'`）、`widget_enabled`（`'1'|'0'`，默认 `'0'`）
  - `connectionKey()`、`autoSync()`（bool）、`widgetEnabled()`（bool）；删除 `collection()`、`apiKey()`
  - `mergeForSave(array $stored, array $submitted)`：`connection_key` 留空时保留旧值
  - `toArray($includeSecret = false)`：不含密钥时 `connection_key` 输出为空串
- 设置表单字段：自动同步、Base URL、连接密钥（Password 输入）、请求超时、在前台显示问答助手

- [ ] **Step 1: 写失败的测试**
  - `config accepts a connection key`：`mbk_` + 43 位 → 通过，`connectionKey()` 原样返回。
  - `config rejects malformed connection keys`：空串、`mbk_short`、旧格式 API Key → `InvalidArgumentException`。
  - `config keeps the stored key when the field is blank`。
  - `config hides the key in toArray`。
  - `widget is disabled by default`。
- [ ] **Step 2: 跑 `php tests/run.php ConfigTest`，确认失败**
- [ ] **Step 3: 实现**
- [ ] **Step 4: 跑通过**
- [ ] **Step 5: Commit** `feat: configure the plugin with a MomoBird connection key`

---

### Task 2: 新接口 HTTP 客户端与文章映射

**Files:**
- Modify: `lib/HttpClient.php`
- Create: `lib/PostMapper.php`（替代 `lib/EntryMapper.php`，删除旧文件）
- Modify: `lib/ContentTransformer.php`（新增整篇转换，删除 `chunks`）
- Test: `tests/HttpClientTest.php`、`tests/ContentTransformerTest.php`、新增 `tests/PostMapperTest.php`

**Interfaces:**
- Produces（`MomoBirdAI_HttpClient`，构造函数签名不变，`$transport` 可注入）：
  - `status()` → `GET /status`，返回契约中的 `ConnectStatus` 数组
  - `upsertPosts(array $posts)` → `PUT /posts`，1–20 篇，返回 `results` 数组
  - `deletePosts(array $externalIds)` → `POST /posts/delete`，1–100 个，返回 `results`
  - `listPosts($cursor = null, $limit = 500)` → `GET /posts`，返回 `array('items' => ..., 'next_cursor' => ...)`
  - `enableWidget()` → `POST /widget/enable`，返回 `Widget`
  - 所有请求头 `Authorization: Bearer {connectionKey}`、`Accept: application/json`；解包 `{code, data}` 的 `data`
  - 新异常类 `MomoBirdAI_PlanRequiredException extends MomoBirdAI_HttpException`（402 且 `code=PLAN_REQUIRED` 时抛出）；502/504 仍有限重试一次（沿用现有逻辑）
- `MomoBirdAI_ContentTransformer::document($title, $html)`：返回 `"# {title}\n\n{正文纯文本}"`，复用现有 `normalize`
- `MomoBirdAI_PostMapper::mapPost(array $post, $siteOrigin)`：返回 `array('external_id' => (string) cid, 'title', 'content', 'url', 'modified_at')`，`modified_at` 为 `gmdate('Y-m-d\TH:i:s\Z', $post['modified'])`；固定链接为相对路径时拼上 `$siteOrigin`；拿不到可用 URL 或正文为空时返回 `null`

- [ ] **Step 1: 写失败的测试**
  - `HttpClientTest`：
    - `upsert sends bearer key to the connect endpoint`：断言方法 `PUT`、URL `https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts`、头含 `Authorization: Bearer mbk_...`、body `{"posts":[...]}`。
    - `upsert rejects batches over twenty`：21 篇 → `InvalidArgumentException`，不发请求。
    - `plan required raises a typed exception`：transport 返回 402 `{"code":"PLAN_REQUIRED"}` → `MomoBirdAI_PlanRequiredException`。
    - `errors never include the connection key`：401 时异常消息不含密钥。
  - `ContentTransformerTest`：`document('标题', '<h2>小节</h2><p>正文</p>')` === `"# 标题\n\n## 小节\n\n正文"`；`<script>` 内容被去掉。
  - `PostMapperTest`：
    - `mapper builds absolute permalink`：`/archives/1.html` + `https://underestimated.cn` → `https://underestimated.cn/archives/1.html`。
    - `mapper uses cid as external id`：`external_id === '42'`。
    - `mapper skips posts without content`：返回 `null`。
- [ ] **Step 2: 跑 `php tests/run.php HttpClientTest ContentTransformerTest PostMapperTest`，确认失败**
- [ ] **Step 3: 实现**
- [ ] **Step 4: 跑通过**
- [ ] **Step 5: Commit** `feat: talk to the MomoBird connect API`

---

### Task 3: 单篇同步、删除、重试

**Files:**
- Modify: `lib/SyncService.php`、`lib/SyncRepository.php`（建表 SQL 与方法）
- Test: `tests/SyncServiceTest.php`（测试替身 `MomoBirdAI_TestClient` 改为新方法名）

**Interfaces:**
- Consumes: Task 2 的 `upsertPosts`、`deletePosts`、`PostMapper`、`MomoBirdAI_PlanRequiredException`
- Produces:
  - `momobird_sync` 表改为每篇文章一行：`post_id`（唯一）、`document_id`、`content_hash`（本地 sha256，用于跳过未改动文章）、`sync_status`（`synced`/`failed`/`plan_required`/`delete_failed`）、`attempts`、`last_error_code`、`last_error_message`、`last_attempt_at`、`last_success_at`；去掉 `site_id`、`chunk_key`、`external_id`、`entry_id`（MySQL 与 SQLite 两份 SQL 都改）
  - `SyncRepository`：`forPost($postId)`、`saveSynced($postId, $documentId, $hash)`、`markFailure($postId, $status, $code, $message)`、`remove($postId)`、`failed($afterId, $limit)`、`counts()`、`allPostIds()`；全量同步运行状态相关方法保持不变
  - `SyncService::syncPost(array $post)`：本地哈希未变且状态 `synced` → 不发请求；否则 `upsertPosts([$mapped])`，按结果 `created/updated/unchanged` 记 `synced`，`rejected` 记 `failed` + `error_code`
  - `SyncService::syncPosts(array $posts)`：批量版本，供全量同步用，按 20 篇 / 4 MiB 切批
  - `SyncService::deletePost($postId)`：发 `deletePosts([(string) $postId])`，`deleted`/`not_found` 都清本地行；失败记 `delete_failed`
  - `SyncService::retryFailures($postFinder = null)`：`plan_required` 状态不重试

- [ ] **Step 1: 写失败的测试**
  - `unchanged post is not sent again`：同一篇同步两次，第二次 transport 无调用。
  - `rejected post is recorded with its error code`：服务端返回该篇 `rejected`/`PAYLOAD_TOO_LARGE` → 本地 `failed` + `PAYLOAD_TOO_LARGE`；同批另一篇 `synced`。
  - `plan required marks posts and skips retry`：抛 `PlanRequiredException` → 状态 `plan_required`，`retryFailures` 不重发。
  - `delete is sent even when plan is required`：之前是 `plan_required` 的文章被删除 → 仍调用 `deletePosts`，本地行被清除。
  - `delete failure is retried`：`deletePosts` 抛 502 → 状态 `delete_failed`，`retryFailures` 再发一次。
  - `batches split by count and size`：45 篇小文章 → 3 次请求（20/20/5）；3 篇各 1.5 MiB → 2 次请求。
- [ ] **Step 2: 跑 `php tests/run.php SyncServiceTest`，确认失败**
- [ ] **Step 3: 实现**
- [ ] **Step 4: 跑通过**
- [ ] **Step 5: Commit** `feat: sync whole posts as MomoBird documents`

---

### Task 4: 全量同步与孤儿清理

**Files:**
- Modify: `lib/FullSyncRun.php`、`lib/AdminController.php`、`assets/admin.js`
- Test: `tests/FullSyncTest.php`、`tests/AdminControllerTest.php`

**Interfaces:**
- Consumes: Task 3 的 `syncPosts`、`deletePosts`；Task 2 的 `listPosts`
- Produces:
  - `FullSyncRun::syncPage($token, $afterCid, $limit)`：取一页合格文章交给 `syncPosts`，返回下一页游标（与现有前端轮询协议一致）
  - `FullSyncRun::cleanupPage($token, $cursor)`：调用 `listPosts($cursor)`，把服务端有、本站已不合格或已不存在的 `external_id` 用 `deletePosts` 删除；只有本轮所有同步页成功后才允许执行（沿用 `allowCleanup`）
  - `AdminController::dispatch('test-connection')` 改为调 `status()`，返回项目名、来源名、权益状态和到期时间、`widget.reason`
  - `admin.js`：状态卡显示权益（试用剩余天数 / 专业版到期日 / 已到期提示文案「试用已结束，自动同步和前台助手已暂停，请联系 MomoBird 开通专业版」），
    以及线上进度「已上线 {source.ready_document_count} / 已同步 {source.document_count}」（两者不等即表示还有文章在索引中），本地失败数沿用 `counts()`

- [ ] **Step 1: 写失败的测试**
  - `restarted full sync does not duplicate`：同步一半后重新 `start()` 再跑完，transport 收到的 `external_id` 集合等于合格文章集合，服务端替身里每个 ID 只对应一个文档。
  - `cleanup deletes only ids no longer eligible`：服务端列表 `1,2,3`，本站 2 已转私密、3 已删除 → 只删除 `2,3`。
  - `cleanup is refused after a failed page`：某页失败后 `cleanupPage` 抛出/返回拒绝，不调用 `deletePosts`。
  - `test connection shows plan status`（AdminControllerTest）：`status()` 返回 trial → 输出含 `trial_ends_at`，不含密钥。
  - `status shows serving progress`（AdminControllerTest）：`document_count=10, ready_document_count=7` → 输出含 `ready_document_count` 7 和 `document_count` 10。
- [ ] **Step 2: 跑 `php tests/run.php FullSyncTest AdminControllerTest`，确认失败**
- [ ] **Step 3: 实现**（`node --check assets/admin.js` 一并通过）
- [ ] **Step 4: 跑通过**
- [ ] **Step 5: Commit** `feat: full sync and cleanup against the connect API`

---

### Task 5: 前台问答助手

**Files:**
- Create: `lib/WidgetState.php`
- Modify: `Plugin.php`（注册 footer 钩子；保存设置时处理助手开关）、`Action.php`
- Test: 新增 `tests/WidgetStateTest.php`、`tests/PluginContractTest.php`

**Interfaces:**
- Consumes: Task 1 的 `widgetEnabled()`；Task 2 的 `status()`、`enableWidget()`
- Produces:
  - `MomoBirdAI_WidgetState`（用 Typecho options 存 `momobird_widget_state`：`public_id`、`available`、`reason`、`checked_at`）：
    - `refresh($client, $now)`：调用 `status()` 写缓存；异常时保留旧缓存但把 `available` 置 false、`reason` 置 `unreachable`
    - `snippet($now)`：缓存存在、`available===true`、`checked_at` 距今 ≤ 600 秒、`public_id` 合法 → 返回脚本字符串，否则返回空串；**从不发网络请求**
    - `needsRefresh($now)`：缓存缺失或超过 600 秒
  - 钩子 `Typecho_Plugin::factory('Widget_Archive')->footer = array('MomoBirdAI_Plugin', 'footer')`，`footer()` 只 `echo $state->snippet(time())`
  - 刷新时机：设置保存时（打开开关 → 先 `enableWidget()` 再 `refresh`；关闭 → 清缓存）、后台面板加载 `status` 时、每次文章同步成功后若 `needsRefresh` 则刷新
  - `enableWidget()` 返回 409 `WIDGET_NOT_READY` 时，设置页提示「请先完成一次全量同步，等文章索引完成后再打开前台助手」，开关保持打开，缓存记 `not_published`

- [ ] **Step 1: 写失败的测试**
  - `footer never calls the network`：transport 替身在被调用时抛异常；`snippet()` 在缓存过期、缺失、正常三种情况下都不触发它。
  - `snippet requires available state`：`reason` 为 `plan_required`/`disabled_in_console` 时返回空串。
  - `snippet escapes public id`：`public_id` 为 `abc"><script>` → 返回空串。
  - `snippet expires after ten minutes`：`checked_at = now-601` → 空串。
  - `unreachable status hides the widget`：`refresh` 时 transport 抛异常 → 之后 `snippet()` 为空。
  - `plugin registers footer hook`（PluginContractTest）：激活时注册了 `Widget_Archive->footer`。
- [ ] **Step 2: 跑 `php tests/run.php WidgetStateTest PluginContractTest`，确认失败**
- [ ] **Step 3: 实现**
- [ ] **Step 4: 跑通过**
- [ ] **Step 5: Commit** `feat: show the MomoBird assistant on the blog`

---

### Task 6: 文档、打包与真实站点验收

**Files:**
- Modify: `README.md`、`Plugin.php`（`@version 0.2.0`）
- Create: `scripts/package.sh`（生成 `dist/MomoBirdAI-0.2.0.zip`，顶层目录 `MomoBirdAI/`，排除 `tests/`、`docs/`、`.worktrees/`、`.git*`、旧 zip）
- Delete: `MomoBirdAI-v0.1.0.zip`

- [ ] **Step 1: 改 README**：安装步骤改为「控制台新增 Typecho 来源 → 复制连接密钥 → 填入插件」；删除 collection、API Key、scope 相关段落；故障排查表加 402 `PLAN_REQUIRED`、409 `WIDGET_NOT_READY`。
- [ ] **Step 2: 写打包脚本并运行**

Run: `bash scripts/package.sh && unzip -l dist/MomoBirdAI-0.2.0.zip | head`
Expected: 所有路径以 `MomoBirdAI/` 开头，不含 `tests/`。

- [ ] **Step 3: 全量自检**：`php tests/run.php`、`php -l` 全部文件、`node --check assets/admin.js` 全部通过。
- [ ] **Step 4: Commit** `chore: release MomoBirdAI 0.2.0`
- [ ] **Step 5: 交给控制台计划 Task 5**：把 `dist/MomoBirdAI-0.2.0.zip` 复制到 `MomoBird_fe/public/downloads/`。
- [ ] **Step 6: 真实站点验收（需求方在场，后端和控制台都已上线后）**：按设计文档 §9 在 https://underestimated.cn/ 逐条走一遍，记录每一条的结果。第 7 条（权益过期）用管理员接口把试用改成过去时间来模拟。
