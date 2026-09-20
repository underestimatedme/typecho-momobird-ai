(function () {
  'use strict';

  var root = document.getElementById('momobird-admin');
  if (!root) return;

  var actionUrl = root.getAttribute('data-action-url');
  var message = document.getElementById('momobird-message');
  var progress = document.getElementById('momobird-progress');
  var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-momobird-action]'));

  function setBusy(busy) {
    buttons.forEach(function (button) { button.disabled = busy; });
    progress.hidden = !busy;
  }

  function setMessage(text, kind) {
    message.textContent = text;
    message.setAttribute('data-kind', kind || '');
  }

  function errorText(payload) {
    if (payload && payload.error && payload.error.message) return payload.error.message;
    return '请求失败，请稍后重试。';
  }

  function postAction(operation, values) {
    var body = new URLSearchParams();
    body.set('op', operation);
    Object.keys(values || {}).forEach(function (key) {
      if (values[key] !== null && typeof values[key] !== 'undefined') body.set(key, values[key]);
    });
    return fetch(actionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (!response.ok || !payload || payload.ok === false) throw new Error(errorText(payload));
        return payload;
      });
    });
  }

  function refreshStatus() {
    return postAction('status', {}).then(function (payload) {
      document.getElementById('momobird-config-state').textContent = payload.configuration_state === 'configured' ? '已配置' : '未配置';
      document.getElementById('momobird-connection-state').textContent = payload.connection_state === 'unconfigured' ? '不可用' : '待检测';
      document.getElementById('momobird-auto-sync').textContent = payload.auto_sync_enabled ? '已开启' : '已关闭（可手动同步）';
      document.getElementById('momobird-synced-posts').textContent = String(payload.synced_posts || 0);
      document.getElementById('momobird-synced').textContent = String(payload.counts.synced || 0);
      document.getElementById('momobird-failed-upsert').textContent = String(payload.counts.failed_upsert || 0);
      document.getElementById('momobird-failed-delete').textContent = String(payload.counts.failed_delete || 0);
      document.getElementById('momobird-last-sync').textContent = payload.last_full_sync_at
        ? new Date(payload.last_full_sync_at * 1000).toLocaleString()
        : '尚未执行';
      var recent = document.getElementById('momobird-recent-error');
      if (payload.recent_error) {
        recent.textContent = '最近错误：' + payload.recent_error.code + ' — ' + payload.recent_error.message;
        recent.hidden = false;
      } else {
        recent.hidden = true;
        recent.textContent = '';
      }
    });
  }

  function cleanupAll(token) {
    var cursor = null;
    var deleted = 0;
    function next() {
      return postAction('cleanup-page', {run_token: token, cursor: cursor}).then(function (page) {
        deleted += page.deleted || 0;
        if (page.done) return deleted;
        cursor = page.next_cursor;
        return next();
      });
    }
    return next();
  }

  function runFullSync() {
    setBusy(true);
    progress.removeAttribute('value');
    setMessage('正在准备全量同步…');
    return postAction('start-sync', {}).then(function (run) {
      var cursor = 0;
      var processed = 0;
      function next() {
        return postAction('sync-page', {run_token: run.run_token, cursor: cursor, limit: 10}).then(function (page) {
          processed += page.processed || 0;
          setMessage('已同步 ' + processed + ' 篇文章…');
          if (page.done) return cleanupAll(run.run_token);
          cursor = page.next_cursor;
          return next();
        });
      }
      return next().then(function (deleted) {
        setMessage('全量同步完成，清理了 ' + deleted + ' 个孤儿分块。', 'success');
        return refreshStatus();
      });
    }).catch(function (error) {
      setMessage(error.message, 'error');
    }).then(function () {
      setBusy(false);
      progress.hidden = true;
    });
  }

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var operation = button.getAttribute('data-momobird-action');
      if (operation === 'sync') {
        runFullSync();
        return;
      }
      setBusy(true);
      var request = operation === 'test' ? postAction('test-connection', {}) : postAction('retry-failures', {});
      request.then(function (payload) {
        if (operation === 'test') document.getElementById('momobird-connection-state').textContent = '已连接';
        setMessage(operation === 'test' ? '连接成功。' : '失败项重试完成，共处理 ' + (payload.retried || 0) + ' 项。', 'success');
        return refreshStatus();
      }).catch(function (error) {
        setMessage(error.message, 'error');
      }).then(function () {
        setBusy(false);
        progress.hidden = true;
      });
    });
  });

  refreshStatus().then(function () {
    setMessage('状态已更新。');
  }).catch(function (error) {
    setMessage(error.message, 'error');
  });
}());
