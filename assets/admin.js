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
      document.getElementById('momobird-plan-required').textContent = String(payload.counts.plan_required || 0);
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

  var EXPIRED_TEXT = '试用已结束，自动同步和前台助手已暂停。请在 MomoBird 控制台知识页点「兑换码」开通专业版';

  function planText(entitlement) {
    if (!entitlement || !entitlement.status) return '—';
    if (entitlement.status === 'trial' && entitlement.trial_ends_at) {
      var days = Math.ceil((new Date(entitlement.trial_ends_at).getTime() - Date.now()) / 86400000);
      return days <= 1 ? '专业版试用 · 今天到期' : '专业版试用 · 剩 ' + days + ' 天';
    }
    if (entitlement.status === 'pro' && entitlement.pro_ends_at) {
      return '专业版 · 至 ' + entitlement.pro_ends_at.slice(0, 10);
    }
    if (entitlement.status === 'expired') return EXPIRED_TEXT;
    return '未开通';
  }

  function showConnection(payload) {
    document.getElementById('momobird-connection-state').textContent = '已连接 · ' + ((payload.project && payload.project.name) || '');
    document.getElementById('momobird-plan').textContent = planText(payload.entitlement);
    var source = payload.source || {};
    document.getElementById('momobird-serving').textContent =
      '已上线 ' + (source.ready_document_count || 0) + ' / 已同步 ' + (source.document_count || 0);
    // A mismatch is a standing problem, not an action result, so it gets its own line that
    // later messages such as 连接成功 or 状态已更新 cannot overwrite.
    var warning = document.getElementById('momobird-origin-warning');
    if (payload.origin_matches === false) {
      warning.textContent = '博客地址 ' + payload.blog_origin + ' 与这个连接密钥所属来源的地址 ' + (source.site_url || '') +
        ' 不一致，MomoBird 会拒绝上传的文章。请换成按博客实际地址创建的 Typecho 来源的密钥（可在控制台为它重新生成密钥）。';
      warning.hidden = false;
    } else {
      warning.textContent = '';
      warning.hidden = true;
    }
  }

  function cleanupAll(token) {
    var cursor = null;
    var deleted = 0;
    function next() {
      return postAction('cleanup-page', {run_token: token, cursor: cursor}).then(function (page) {
        deleted += page.deleted || 0;
        if (page.done) return {deleted: deleted, widget: page.widget || null};
        cursor = page.next_cursor;
        return next();
      });
    }
    return next();
  }

  // The assistant is only shown when 显示 is selected; null means it was not requested.
  function widgetText(widget) {
    if (!widget) return '';
    if (widget.available) return '前台问答助手已开启。';
    if (widget.reason === 'not_published') return '文章索引完成后，前台问答助手会自动开启（有人访问博客时每 10 分钟检查一次）。';
    return '';
  }

  function runFullSync() {
    setBusy(true);
    progress.removeAttribute('value');
    setMessage('正在准备全量同步…');
    return postAction('start-sync', {}).then(function (run) {
      var cursor = 0;
      var processed = 0;
      var rejected = 0;
      var planRequired = false;
      function next() {
        return postAction('sync-page', {run_token: run.run_token, cursor: cursor, limit: 10}).then(function (page) {
          processed += page.processed || 0;
          rejected += page.rejected || 0;
          if (page.plan_required) planRequired = true;
          setMessage('已同步 ' + processed + ' 篇文章…');
          if (page.done) return cleanupAll(run.run_token);
          cursor = page.next_cursor;
          return next();
        });
      }
      return next().then(function (result) {
        var deleted = result.deleted;
        var widget = widgetText(result.widget);
        if (planRequired) {
          setMessage(EXPIRED_TEXT + '。已下线 ' + deleted + ' 篇不再公开的文章。', 'error');
        } else if (rejected > 0) {
          setMessage('全量同步完成，但有 ' + rejected + ' 篇文章被拒绝（见失败计数和最近错误）；已下线 ' + deleted + ' 篇不再公开的文章。' + widget, 'error');
        } else {
          setMessage('全量同步完成，已下线 ' + deleted + ' 篇不再公开的文章。文章索引完成后即可在问答中使用。' + widget, 'success');
        }
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
        if (operation === 'test') showConnection(payload);
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
    if (document.getElementById('momobird-config-state').textContent === '已配置') {
      return postAction('test-connection', {}).then(showConnection).catch(function () {});
    }
  }).then(function () {
    setMessage('状态已更新。');
  }).catch(function (error) {
    setMessage(error.message, 'error');
  });
}());
