// Run with: node --test tests/admin-panel.test.mjs
// Loads assets/admin.js against a minimal fake panel DOM (no dependencies).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = readFileSync(path.join(root, 'assets', 'admin.js'), 'utf8');
const panel = readFileSync(path.join(root, 'panel.php'), 'utf8');

function element(id) {
  const attributes = {};
  const listeners = {};
  return {
    id,
    textContent: '—',
    hidden: /hidden/.test((panel.match(new RegExp(`id="${id}"[^>]*>`)) || [''])[0]),
    disabled: false,
    setAttribute(name, value) { attributes[name] = String(value); },
    getAttribute(name) { return attributes[name] ?? null; },
    removeAttribute(name) { delete attributes[name]; },
    addEventListener(type, fn) { listeners[type] = fn; },
    click() { listeners.click?.(); },
  };
}

function loadPanel(responses) {
  const ids = [...panel.matchAll(/id="(momobird-[a-z-]+)"/g)].map((m) => m[1]);
  const elements = Object.fromEntries(ids.map((id) => [id, element(id)]));
  elements['momobird-admin'].setAttribute('data-action-url', '/action/momobird-ai?_=t');
  const buttons = [...panel.matchAll(/data-momobird-action="([a-z]+)"/g)].map((m) => {
    const button = element(`button-${m[1]}`);
    button.setAttribute('data-momobird-action', m[1]);
    return button;
  });
  elements['momobird-admin'].querySelectorAll = () => buttons;
  const document = { getElementById: (id) => elements[id] ?? null };
  const fetch = async (_url, init) => {
    const op = new URLSearchParams(init.body).get('op');
    const payload = responses[op];
    return { ok: true, json: async () => payload };
  };
  vm.runInNewContext(script, { document, fetch, URLSearchParams, Promise, Date, Math, String, Array, Object });
  return { elements, buttons };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const status = {
  ok: true, counts: { synced: 227, failed_upsert: 8, failed_delete: 0, plan_required: 0 }, synced_posts: 227,
  last_full_sync_at: 1761046319, recent_error: null, configuration_state: 'configured',
  connection_state: 'not_tested', auto_sync_enabled: true,
};
const mismatched = {
  ok: true, project: { name: '测试项目' }, entitlement: { status: 'pro', pro_ends_at: '2026-11-20T00:00:00Z' },
  source: { site_url: 'https://underestimatd.cn', document_count: 0, ready_document_count: 0 },
  blog_origin: 'https://underestimated.cn', origin_matches: false,
};

test('an address mismatch stays visible after the page finishes loading', async () => {
  const { elements } = loadPanel({ status, 'test-connection': mismatched });
  await settle();
  const warning = elements['momobird-origin-warning'];
  assert.ok(warning, 'panel has no dedicated element for the address warning');
  assert.equal(warning.hidden, false, 'address warning is hidden');
  assert.match(warning.textContent, /https:\/\/underestimated\.cn/);
  assert.match(warning.textContent, /https:\/\/underestimatd\.cn/);
});

test('clicking 测试连接 does not replace the address warning with 连接成功', async () => {
  const { elements, buttons } = loadPanel({ status, 'test-connection': mismatched });
  await settle();
  buttons.find((b) => b.getAttribute('data-momobird-action') === 'test').click();
  await settle();
  assert.equal(elements['momobird-origin-warning'].hidden, false);
  assert.match(elements['momobird-origin-warning'].textContent, /不一致/);
});

test('a matching address shows no warning', async () => {
  const { elements } = loadPanel({ status, 'test-connection': { ...mismatched, origin_matches: true, source: { ...mismatched.source, site_url: 'https://underestimated.cn' } } });
  await settle();
  assert.equal(elements['momobird-origin-warning'].hidden, true);
});

test('retrying failed posts reports how many were retried and how many still fail', async () => {
  const { elements, buttons } = loadPanel({
    status, 'test-connection': mismatched,
    'retry-failures': { ok: true, retried: 8, failed: 8 },
  });
  await settle();
  buttons.find((b) => b.getAttribute('data-momobird-action') === 'retry').click();
  await settle();
  const message = elements['momobird-message'];
  assert.doesNotMatch(message.textContent, /请求失败/);
  assert.match(message.textContent, /处理 8 项/);
  assert.match(message.textContent, /8 项仍然失败/);
  assert.equal(message.getAttribute('data-kind'), 'error');
});

test('a clean retry is reported as success', async () => {
  const { elements, buttons } = loadPanel({
    status, 'test-connection': mismatched,
    'retry-failures': { ok: true, retried: 3, failed: 0 },
  });
  await settle();
  buttons.find((b) => b.getAttribute('data-momobird-action') === 'retry').click();
  await settle();
  assert.match(elements['momobird-message'].textContent, /处理 3 项/);
  assert.equal(elements['momobird-message'].getAttribute('data-kind'), 'success');
});
