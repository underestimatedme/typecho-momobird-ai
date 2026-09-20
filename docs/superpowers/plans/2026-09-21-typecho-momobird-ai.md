# Typecho MomoBird AI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Typecho 1.2.x plugin that directly synchronizes published posts to a scoped MomoBird collection and reliably removes remote chunks when posts become non-public or are deleted.

**Architecture:** Typecho lifecycle hooks call a small synchronous sync service after local writes complete. Pure PHP mapping, chunking, and HTTP components remain independent from Typecho, while adapters handle Typecho posts, options, database state, admin actions, and notices. A browser-driven paginated admin action performs full synchronization without cron.

**Tech Stack:** PHP 7.2+, Typecho 1.2 plugin APIs, cURL, Typecho DB abstraction, vanilla JavaScript, dependency-free PHP test runner.

**Spec:** `docs/superpowers/specs/2026-09-21-typecho-momobird-ai-design.md`

## Global Constraints

- Sync only `type=post` and `status=publish`; never sync pages, drafts, waiting, hidden, private, or password-protected content.
- Use `https://api.valley.atlaspaces.com` by default and `/momobird/api/v1` for every business API request.
- Require a collection-scoped read-write key; never expose the complete key to browser JavaScript, logs, notices, or committed files.
- Keep every MomoBird entry at or below 7,500 Unicode characters and every batch at or below 100 entries.
- Direct sync failures never roll back or block a completed Typecho publish, mark, or delete operation.
- Require PHP 7.2+, cURL, and Typecho 1.2.x; do not add Composer or runtime framework dependencies.
- Support MySQL first and keep schema/query code compatible with SQLite.
- Do not require cron or a resident worker.

## Review Focus

- A post containing malformed HTML, multibyte text, or one paragraph longer than 7,500 characters must produce valid non-empty bounded chunks; Task 1 pins this behavior.
- A Base URL with credentials, query text, fragment, or a non-local HTTP host must be rejected before cURL runs; Task 2 pins this behavior.
- A changed post that produces fewer chunks must upsert all new chunks before deleting old UUIDs, and must retain old rows when upsert fails; Task 3 pins this behavior.
- A deleted Typecho row must remain remotely deletable using local UUID mappings even though its source content no longer exists; Task 3 pins this behavior.
- A full sync with one failed article must stop before orphan cleanup, while repeated AJAX requests must remain idempotent; Task 5 pins this behavior.

---

## File Structure

- `Plugin.php`: Typecho plugin lifecycle, hooks, configuration fields, panel/action registration, and notices.
- `Action.php`: authenticated, CSRF-protected admin action dispatcher returning bounded JSON responses.
- `panel.php`: administrator-only sync dashboard markup.
- `assets/admin.js`: connection-test, retry, and paginated full-sync client.
- `assets/admin.css`: minimal dashboard status styling.
- `lib/Bootstrap.php`: explicit `require_once` loader for all plugin classes.
- `lib/Config.php`: validated immutable runtime configuration and secret-preserving option merge.
- `lib/ContentTransformer.php`: HTML/Markdown normalization and semantic chunking.
- `lib/EntryMapper.php`: stable site/post/chunk identifiers and MomoBird payload mapping.
- `lib/HttpClient.php`: bounded cURL transport and MomoBird API contract.
- `lib/PostRepository.php`: published-post lookup and paginated enumeration from Typecho.
- `lib/SyncRepository.php`: schema install plus chunk mapping and failure state persistence.
- `lib/SyncService.php`: post upsert, stale-chunk deletion, post deletion, retry, and full-sync orchestration.
- `tests/bootstrap.php`: plugin loader and dependency-free assertions.
- `tests/run.php`: test discovery and result reporting.
- `tests/ContentTransformerTest.php`: normalization/chunk boundary tests.
- `tests/ConfigTest.php`: URL, slug, timeout, and secret merge tests.
- `tests/HttpClientTest.php`: endpoint, auth, retry, response limit, and structured error tests.
- `tests/SyncServiceTest.php`: ordering, idempotency, failure retention, and deletion tests.
- `tests/FullSyncTest.php`: pagination and cleanup safety tests.
- `README.md`: installation, least-privilege setup, configuration, operations, and troubleshooting.

### Task 1: Content transformation and stable entry mapping

**Files:**
- Create: `lib/Bootstrap.php`
- Create: `lib/ContentTransformer.php`
- Create: `lib/EntryMapper.php`
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/ContentTransformerTest.php`

**Interfaces:**
- Consumes: associative post rows containing `cid`, `title`, `text`, `permalink`, `modified`, `tags`, and `categories`.
- Produces: `MomoBirdAI_ContentTransformer::chunks($text, $limit = 7500): array` and `MomoBirdAI_EntryMapper::mapPost(array $post, $siteId): array`.

- [ ] **Step 1: Add a dependency-free test runner and failing content tests**

```php
function mb_assert($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

mb_test('chunks malformed multibyte html safely', function () {
    $chunks = MomoBirdAI_ContentTransformer::chunks('<h2>章节</h2><p>' . str_repeat('你', 7600), 7500);
    mb_assert(count($chunks) >= 2, 'expected multiple chunks');
    foreach ($chunks as $chunk) {
        mb_assert($chunk['content'] !== '', 'chunk must not be empty');
        mb_assert(mb_strlen($chunk['content'], 'UTF-8') <= 7500, 'chunk exceeds limit');
        mb_assert(strpos($chunk['content'], '<script') === false, 'unsafe html remains');
    }
});
```

- [ ] **Step 2: Run the content tests and verify the missing-class failure**

Run: `php tests/run.php ContentTransformerTest`

Expected: FAIL mentioning `MomoBirdAI_ContentTransformer` is not found.

- [ ] **Step 3: Implement normalization, semantic splitting, and stable chunk keys**

```php
final class MomoBirdAI_ContentTransformer
{
    public static function chunks($text, $limit = 7500)
    {
        $normalized = self::normalize($text);
        $sections = self::sections($normalized);
        return self::pack($sections, (int) $limit);
    }
}

final class MomoBirdAI_EntryMapper
{
    public static function mapPost(array $post, $siteId)
    {
        $entries = array();
        foreach (MomoBirdAI_ContentTransformer::chunks($post['text']) as $chunk) {
            $key = substr(hash('sha256', $chunk['heading'] . "\n" . substr($chunk['content'], 0, 512)), 0, 20);
            $entries[] = array(
                'external_id' => 'typecho:' . $siteId . ':post:' . (int) $post['cid'] . ':' . $key,
                'title' => self::boundedTitle($post['title'], $chunk['heading']),
                'content' => $chunk['content'],
                'tags' => self::tags($post, $siteId),
                'source_ref' => $post['permalink'],
                'metadata' => array('source' => 'typecho', 'site_id' => $siteId, 'post_id' => (string) $post['cid'], 'chunk_key' => $key, 'visibility' => 'public')
            );
        }
        return $entries;
    }
}
```

Implement `normalize` with DOMDocument when available and a conservative tag-strip fallback. Preserve heading and paragraph breaks, remove scripts/styles/comments, strip the `<!--markdown-->` marker, decode entities, normalize whitespace, and split overlong paragraphs with multibyte-safe sentence then hard boundaries. Cap title at 300 characters, tags at 32 items and 64 characters each.

- [ ] **Step 4: Add stable-ID and page-exclusion input tests, then run the suite**

```php
$first = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');
$again = MomoBirdAI_EntryMapper::mapPost($post, 'site-a');
mb_assert($first === $again, 'mapping must be deterministic');
mb_assert($first[0]['external_id'] !== MomoBirdAI_EntryMapper::mapPost($post, 'site-b')[0]['external_id'], 'sites must be isolated');
```

Run: `php tests/run.php`

Expected: PASS with all content and mapper cases reported.

- [ ] **Step 5: Commit the transformation slice**

```bash
git add lib/Bootstrap.php lib/ContentTransformer.php lib/EntryMapper.php tests
git commit -m "feat: map Typecho posts to MomoBird entries"
```

### Task 2: Validated configuration and bounded MomoBird client

**Files:**
- Create: `lib/Config.php`
- Create: `lib/HttpClient.php`
- Create: `tests/ConfigTest.php`
- Create: `tests/HttpClientTest.php`
- Modify: `lib/Bootstrap.php`

**Interfaces:**
- Consumes: saved Typecho plugin option arrays and an optional callable HTTP transport.
- Produces: `MomoBirdAI_Config::fromArray(array $values): MomoBirdAI_Config`; `MomoBirdAI_HttpClient::import(array $entries): array`; `listEntries($cursor = null, $tag = null): array`; `deleteEntry($uuid): void`; `testConnection(): array`.

- [ ] **Step 1: Write failing config and transport-contract tests**

```php
mb_test('rejects unsafe remote base urls', function () {
    foreach (array('http://example.com', 'https://user:pass@example.com', 'https://example.com/?x=1', 'https://example.com/#x') as $url) {
        mb_assert_throws(function () use ($url) {
            MomoBirdAI_Config::fromArray(array('base_url' => $url, 'collection' => 'blog-kb', 'api_key' => 'secret', 'timeout' => 10));
        }, 'InvalidArgumentException');
    }
});

mb_test('uses namespaced endpoint and bearer auth', function () {
    $seen = array();
    $transport = function ($method, $url, array $headers, $body, $timeout) use (&$seen) {
        $seen = compact('method', 'url', 'headers', 'body', 'timeout');
        return array('status' => 200, 'body' => '{"created":1,"updated":0,"unchanged":0,"results":[{"entry_id":"11111111-1111-4111-8111-111111111111","status":"created","version":1}]}');
    };
    $client = new MomoBirdAI_HttpClient(MomoBirdAI_Config::fromArray(mb_valid_config()), $transport);
    $client->import(array(mb_entry()));
    mb_assert(strpos($seen['url'], '/momobird/api/v1/collections/blog-kb/entries/batch') !== false, 'wrong endpoint');
    mb_assert(in_array('Authorization: Bearer test-key', $seen['headers'], true), 'missing auth');
});
```

- [ ] **Step 2: Run focused tests and verify they fail**

Run: `php tests/run.php ConfigTest HttpClientTest`

Expected: FAIL for missing `MomoBirdAI_Config` and `MomoBirdAI_HttpClient`.

- [ ] **Step 3: Implement validation, secret-preserving merge, client methods, and cURL transport**

```php
final class MomoBirdAI_Config
{
    public static function mergeForSave(array $stored, array $submitted)
    {
        if (!isset($submitted['api_key']) || trim($submitted['api_key']) === '') {
            $submitted['api_key'] = isset($stored['api_key']) ? $stored['api_key'] : '';
        }
        return self::fromArray($submitted);
    }
}

final class MomoBirdAI_HttpClient
{
    const MAX_RESPONSE_BYTES = 1048576;

    public function import(array $entries)
    {
        if (count($entries) < 1 || count($entries) > 100) {
            throw new InvalidArgumentException('Import batch must contain 1 to 100 entries');
        }
        return $this->request('POST', '/collections/' . rawurlencode($this->config->collection()) . '/entries/batch', array('entries' => $entries), true);
    }
}
```

Accept only `https`, except local loopback hosts over `http`; reject userinfo, query, fragment, non-empty path outside an optional trailing slash, invalid collection slugs, blank keys, and timeout outside 2–30 seconds. cURL must verify TLS, stop response collection above 1 MiB, use JSON headers, and return secret-safe exceptions. Retry only transport timeout/connection errors and HTTP 502/504 once.

- [ ] **Step 4: Add error-path tests and run the full suite**

Test 401/403 non-retry, one 502 retry, malformed JSON, oversized response, missing response fields, percent-encoded collection, and blank-key rejection.

Run: `php tests/run.php`

Expected: PASS.

- [ ] **Step 5: Commit the client slice**

```bash
git add lib/Bootstrap.php lib/Config.php lib/HttpClient.php tests/ConfigTest.php tests/HttpClientTest.php
git commit -m "feat: add secure MomoBird API client"
```

### Task 3: Stateful post synchronization service

**Files:**
- Create: `lib/SyncService.php`
- Create: `tests/SyncServiceTest.php`
- Modify: `lib/Bootstrap.php`

**Interfaces:**
- Consumes: mapped post arrays, an HTTP client exposing Task 2 methods, and a state repository exposing `forPost`, `replacePost`, `markFailure`, `remove`, and `failed`.
- Produces: `syncPost(array $post): array`, `deletePost($postId): array`, and `retryFailures(): array` result summaries with `ok`, `created`, `updated`, `unchanged`, `deleted`, and `error` fields.

- [ ] **Step 1: Write failing service ordering and failure-retention tests using fakes**

```php
mb_test('upserts before deleting stale chunks', function () {
    $events = array();
    $client = new FakeClient($events);
    $state = new FakeStateRepository(array('old-key' => 'old-uuid'), $events);
    $service = new MomoBirdAI_SyncService($client, $state, 'site-a');
    $result = $service->syncPost(mb_post('new body'));
    mb_assert($result['ok'], 'sync should succeed');
    mb_assert($events[0] === 'import', 'must import first');
    mb_assert(in_array('delete:old-uuid', $events, true), 'stale UUID not deleted');
});

mb_test('failed import retains old mappings', function () {
    $state = new FakeStateRepository(array('old-key' => 'old-uuid'));
    $service = new MomoBirdAI_SyncService(new FailingImportClient(), $state, 'site-a');
    mb_assert(!$service->syncPost(mb_post('changed'))['ok'], 'expected failure');
    mb_assert($state->hasEntry('old-uuid'), 'old mapping was removed');
});
```

- [ ] **Step 2: Run focused tests and verify the missing-service failure**

Run: `php tests/run.php SyncServiceTest`

Expected: FAIL mentioning `MomoBirdAI_SyncService`.

- [ ] **Step 3: Implement import-first reconciliation and durable delete failures**

```php
public function syncPost(array $post)
{
    $entries = MomoBirdAI_EntryMapper::mapPost($post, $this->siteId);
    $remote = $this->client->import($entries);
    $next = $this->combineMappings($entries, $remote['results']);
    $previous = $this->state->forPost((int) $post['cid']);
    $this->state->replacePost((int) $post['cid'], $next);
    foreach ($this->staleMappings($previous, $next) as $mapping) {
        $this->deleteMapping($mapping);
    }
    return $this->summary($remote);
}
```

Validate that each result maps to the submitted external ID order or explicit external ID supplied by the API contract. If any result is incomplete, record `failed_upsert` and perform no stale deletion. `deletePost` must operate only from stored mappings, mark each failed remote deletion as `failed_delete`, and remove a row only after HTTP 204 success.

- [ ] **Step 4: Add repeated-sync, deleted-source, partial-delete, and 100-entry batching tests**

Run: `php tests/run.php`

Expected: PASS; repeated content returns unchanged without duplicate state rows, and a post with no remaining Typecho row can still delete every saved UUID.

- [ ] **Step 5: Commit the service slice**

```bash
git add lib/Bootstrap.php lib/SyncService.php tests/SyncServiceTest.php
git commit -m "feat: reconcile MomoBird post chunks"
```

### Task 4: Typecho persistence, lifecycle hooks, and plugin configuration

**Files:**
- Create: `Plugin.php`
- Create: `lib/PostRepository.php`
- Create: `lib/SyncRepository.php`
- Create: `tests/ConfigTest.php` additions
- Modify: `lib/Bootstrap.php`

**Interfaces:**
- Consumes: Typecho DB/options/widgets and Task 1–3 services.
- Produces: activated plugin schema, validated settings, `finishPublish`, `finishMark`, and `finishDelete` hook handlers.

- [ ] **Step 1: Add failing adapter-level tests for eligibility and schema SQL**

```php
mb_test('only public unprotected posts are eligible', function () {
    mb_assert(MomoBirdAI_PostRepository::isEligible(array('type' => 'post', 'status' => 'publish', 'password' => '')), 'public post rejected');
    foreach (array(
        array('type' => 'page', 'status' => 'publish', 'password' => ''),
        array('type' => 'post', 'status' => 'private', 'password' => ''),
        array('type' => 'post', 'status' => 'publish', 'password' => 'secret')
    ) as $row) {
        mb_assert(!MomoBirdAI_PostRepository::isEligible($row), 'private input accepted');
    }
});
```

Assert generated table names use the Typecho prefix, contain a unique `(site_id, post_id, chunk_key)` constraint, and never interpolate request values into SQL.

- [ ] **Step 2: Run focused tests and verify missing adapters fail**

Run: `php tests/run.php ConfigTest`

Expected: FAIL for missing Typecho adapter classes.

- [ ] **Step 3: Implement schema installer and repositories**

Create a table with `id`, `site_id`, `post_id`, `chunk_key`, `external_id`, `entry_id`, `content_hash`, `sync_status`, `attempts`, `last_error_code`, `last_error_message`, `last_attempt_at`, and `last_success_at`. Use adapter-specific `CREATE TABLE IF NOT EXISTS` statements for MySQL and SQLite, with indexed post/status columns and the unique tuple from Step 1.

`PostRepository::find($cid)` must query the final stored row, load tags/categories, build a Typecho permalink, and return null unless eligible. `page($afterCid, $limit)` must filter `type=post`, `status=publish`, empty password, order by CID, and never return pages.

- [ ] **Step 4: Implement Typecho plugin entry and direct hooks**

```php
public static function activate()
{
    require_once __DIR__ . '/lib/Bootstrap.php';
    MomoBirdAI_SyncRepository::install(Typecho_Db::get());
    Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'finishPublish');
    Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishMark = array(__CLASS__, 'finishMark');
    Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = array(__CLASS__, 'finishDelete');
    Helper::addPanel(3, 'MomoBirdAI/panel.php', _t('MomoBird 同步'), _t('MomoBird 同步'), 'administrator');
    Helper::addAction('momobird-ai', 'MomoBirdAI_Action');
    return _t('MomoBird AI 已启用');
}
```

Hook handlers catch every exception, write only a sanitized failure row/notice, and return control to Typecho. `finishDelete` uses the supplied CID and local mapping without reading the deleted content. `finishMark` reloads final state: eligible means sync, every other post status means delete.

- [ ] **Step 5: Add plugin metadata/config form and secret-preserving save path**

Expose `enabled`, `base_url`, `collection`, password-style `api_key`, and integer `timeout`. On save, blank API Key preserves the old value. Generate `site_id` once with cryptographic randomness and never regenerate during ordinary config saves.

- [ ] **Step 6: Run syntax and unit verification**

Run: `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`

Run: `php tests/run.php`

Expected: every PHP file reports no syntax errors and every test passes.

- [ ] **Step 7: Commit the Typecho integration slice**

```bash
git add Plugin.php lib tests/ConfigTest.php
git commit -m "feat: synchronize Typecho post lifecycle"
```

### Task 5: Admin actions and browser-driven full synchronization

**Files:**
- Create: `Action.php`
- Create: `panel.php`
- Create: `assets/admin.js`
- Create: `assets/admin.css`
- Create: `tests/FullSyncTest.php`
- Modify: `lib/SyncService.php`
- Modify: `lib/Bootstrap.php`

**Interfaces:**
- Consumes: authenticated Typecho admin requests, `PostRepository::page`, `SyncService`, and opaque cursor/state values.
- Produces: JSON actions `status`, `test-connection`, `sync-page`, `cleanup-page`, and `retry-failures` plus an administrator dashboard.

- [ ] **Step 1: Write failing full-sync safety and cursor tests**

```php
mb_test('failed page never authorizes cleanup', function () {
    $run = new MomoBirdAI_FullSyncRun(new FakePostRepository(25), new FailingAtPostService(13));
    $first = $run->syncPage(0, 10);
    $second = $run->syncPage($first['next_cursor'], 10);
    mb_assert(!$second['ok'], 'page should fail');
    mb_assert(!$second['cleanup_allowed'], 'cleanup must remain disabled');
});
```

Test page sizes, monotonically increasing CID cursors, repeated page idempotency, site-tag filtering, remote pagination, and orphan deletion restricted to the current `site_id`.

- [ ] **Step 2: Run focused tests and verify the missing full-sync coordinator failure**

Run: `php tests/run.php FullSyncTest`

Expected: FAIL mentioning `MomoBirdAI_FullSyncRun`.

- [ ] **Step 3: Implement paginated full-sync and cleanup coordination**

```php
public function syncPage($afterCid, $limit)
{
    $posts = $this->posts->page((int) $afterCid, min(20, max(1, (int) $limit)));
    foreach ($posts as $post) {
        $result = $this->sync->syncPost($post);
        if (!$result['ok']) {
            return array('ok' => false, 'cleanup_allowed' => false, 'failed_post_id' => (int) $post['cid']);
        }
    }
    return array('ok' => true, 'done' => count($posts) < $limit, 'next_cursor' => $posts ? (int) end($posts)['cid'] : (int) $afterCid);
}
```

Issue a server-side random run token stored with `cleanup_allowed=false`. Set it true only after the final sync page succeeds. Cleanup actions require that token, page through MomoBird entries tagged for the current site, compare against current local mappings, and delete only proven orphans.

- [ ] **Step 4: Implement administrator-only Action endpoints**

`MomoBirdAI_Action` must require administrator access and Typecho security protection for every mutation. Return JSON with `ok`, bounded counts, `next_cursor`, `done`, and a sanitized `error`. Never return config or API Key. Reject unknown operations and invalid cursors with HTTP 400.

- [ ] **Step 5: Implement the dashboard and sequential AJAX controller**

```javascript
async function runFullSync() {
  const run = await postAction('start-sync', {});
  let cursor = 0;
  while (true) {
    const page = await postAction('sync-page', {run_token: run.run_token, cursor});
    renderProgress(page);
    if (!page.ok) throw new Error(page.error || '同步失败');
    if (page.done) break;
    cursor = page.next_cursor;
  }
  await cleanupAll(run.run_token);
}
```

Render connection status, synced/failed counts, last sanitized error, and buttons for connection test, full sync, and retry failures. Disable controls during an active run, show progress, and recover controls after an error. All text and dynamic values must be escaped or assigned through `textContent`.

- [ ] **Step 6: Run tests and syntax checks**

Run: `php tests/run.php`

Run: `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`

Expected: PASS.

- [ ] **Step 7: Commit the admin/full-sync slice**

```bash
git add Action.php panel.php assets lib/SyncService.php tests/FullSyncTest.php
git commit -m "feat: add Typecho knowledge sync dashboard"
```

### Task 6: Documentation, packaging, and end-to-end verification

**Files:**
- Modify: `README.md`
- Create: `tests/PluginContractTest.php`
- Create: `.gitignore`

**Interfaces:**
- Consumes: complete plugin behavior from Tasks 1–5.
- Produces: installable `MomoBirdAI` directory with reproducible local verification instructions.

- [ ] **Step 1: Add failing static plugin contract tests**

Assert that `Plugin.php` declares `MomoBirdAI_Plugin implements Typecho_Plugin_Interface`, registers only post hooks, registers/removes its action and panel symmetrically, contains no production-looking `mb_live_` secret, and references the namespaced MomoBird path through the client.

Run: `php tests/run.php PluginContractTest`

Expected: any missing deactivation cleanup or packaging contract fails clearly.

- [ ] **Step 2: Complete deactivation symmetry and packaging exclusions**

Ensure `deactivate()` removes the panel and action but preserves settings and the sync table. Add only editor files, OS metadata, test output, and packaged ZIP files to `.gitignore`; do not ignore source, tests, specs, or plans.

- [ ] **Step 3: Replace the README with operator documentation**

Document folder rename to `MomoBirdAI`, Typecho/PHP/cURL requirements, installation, default production URL, collection preparation, collection-scoped read-write key, configuration, direct-sync semantics, excluded content, full sync, retry behavior, safe deactivation, data cleanup policy, and troubleshooting for 401/403/404/502/504/timeouts. Include commands `php tests/run.php` and the PHP lint command.

- [ ] **Step 4: Run all automated verification from a clean process**

Run: `php tests/run.php`

Run: `find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l`

Run: `git diff --check`

Expected: all tests pass, all PHP files lint, and no whitespace errors exist.

- [ ] **Step 5: Inspect the package contents and secret scan**

Run: `rg -n "mb_live_|Authorization: Bearer [^<'\" ]|api_key.*=" . --glob '!docs/**' --glob '!tests/**'`

Expected: no real credential or hard-coded bearer token.

Run: `find . -maxdepth 3 -type f -not -path './.git/*' | sort`

Expected: only plugin runtime files, tests, docs, README, and repository metadata.

- [ ] **Step 6: Commit the release-ready plugin**

```bash
git add README.md .gitignore tests/PluginContractTest.php Plugin.php
git commit -m "docs: prepare MomoBirdAI plugin delivery"
```

- [ ] **Step 7: Record manual Typecho smoke-test checklist in the handoff**

Verify on Typecho 1.2.x: activation creates the table; blank Key save preserves the Key; connection test succeeds; a public post creates entries; republish updates them; draft/page creates none; private/hidden transition deletes them; post deletion deletes them; a forced upstream failure still publishes locally and appears as retryable; full sync completes and cleans only current-site orphans.
