# MomoBird AI for Typecho

将 Typecho 公开文章同步到 MomoBird 知识库。插件在文章发布、更新、转为非公开或删除时直接同步，并提供后台一键全量同步和失败重试。

## 功能

- 同步公开普通文章（`post + publish`）。
- 发布和更新时直接写入 MomoBird。
- 私密、隐藏、待审核或删除时清理对应知识分块。
- 长文章按章节和段落自动切分，每块不超过 MomoBird 限制。
- 一键全量同步，无需 cron 或常驻任务。
- 全量同步成功后清理当前站点产生的远端孤儿条目。
- 记录同步状态和远端 UUID，失败后可安全重试。
- 多个 Typecho 站点可以共用 collection，不会相互覆盖或误删。

独立页面 `page`、草稿、附件、评论、私密文章、隐藏文章、待审核文章和密码保护文章不会同步。

## 环境要求

- Typecho 1.2.x
- PHP 7.2 或更高版本
- PHP cURL 与 mbstring 扩展
- MySQL；同步表同时提供 SQLite 兼容结构
- 可从 Typecho 服务器访问 MomoBird API

## 安装

1. 下载或复制本项目。
2. 将插件目录重命名为 `MomoBirdAI`。
3. 上传到 Typecho 的 `usr/plugins/MomoBirdAI/`。
4. 在 Typecho 后台“控制台 → 插件”中启用 **MomoBird AI**。
5. 打开插件设置，填写 collection 和 API Key。

启用插件会创建带 Typecho 数据表前缀的 `momobird_sync` 表。该表只保存同步映射、状态和脱敏错误，不保存文章正文或 API 请求体。

## 准备 MomoBird

在 Forge/MomoBird 管理端完成以下准备：

1. 创建或选择一个用于博客知识的 collection。
2. 为该 Typecho 站点创建独立 API Key。
3. Key 的 read scopes 和 write scopes 都只授予目标 collection。
4. 不要使用管理员 Key，也不要把 Key 放入主题、前台 JavaScript 或公开仓库。

生产 Base URL 默认为：

```text
https://api.valley.atlaspaces.com
```

插件使用新客户端命名空间：

```text
/momobird/api/v1
```

## 配置

| 配置 | 说明 |
| --- | --- |
| 自动同步 | 启用后，在文章生命周期钩子中直接同步 |
| Base URL | MomoBird 服务地址；生产环境必须使用 HTTPS |
| Collection Slug | 目标知识集合标识 |
| API Key | collection 级读写密钥；留空保存会保留旧密钥 |
| 请求超时 | 2–30 秒，默认 10 秒 |

插件不会在设置页回显已经保存的完整 API Key。密钥仍存储在 Typecho 服务端配置数据库中，因此数据库备份也应按敏感数据保护。

## 同步行为

### 发布和更新

文章正式发布或重新发布后，插件读取数据库中的最终版本并立即同步。同步失败不会撤销或阻止 Typecho 发布；后台会显示错误通知并保留失败状态。

每个远端条目使用稳定的站点、文章和章节标识。修改同一章节会更新原条目；删除章节会在新内容全部写入成功后删除旧分块。

### 转为非公开或删除

文章变为私密、隐藏、待审核或被删除时，插件按本地保存的 MomoBird UUID 软删除远端条目。即使 Typecho 原文章已经不存在，也能继续重试删除。

### 一键全量同步

进入“控制台 → MomoBird 同步”，点击“一键全量同步”。浏览器会逐批处理公开文章，因此不依赖 cron，也不会用一个超长 PHP 请求同步整个站点。

只有所有文章批次成功后才允许孤儿清理。孤儿清理仅处理当前站点标识下的条目，不影响同 collection 中其他站点或其他来源的数据。

请在全量同步过程中保持后台页面打开。

## 停用与数据保留

停用插件时：

- 不删除 MomoBird 远端知识；
- 不删除本地同步表；
- 将插件配置备份到独立的 Typecho 服务端选项，以便再次启用时恢复 collection、API Key 和稳定站点标识。

如需彻底清除数据，应先在启用状态下处理远端内容，再显式删除同步表和备份选项。插件不会在停用时执行破坏性清理。

## 故障排查

| 状态或现象 | 检查项 |
| --- | --- |
| 401 | API Key 缺失、过期、被撤销或复制错误 |
| 403 | Key 没有目标 collection 的 read/write scope |
| 404 | Base URL 或 Collection Slug 不正确 |
| 409 | collection 被禁用或远端发生冲突 |
| 502 / 504 | Embedding 或上游服务临时不可用；插件会有限重试一次 |
| timeout | Typecho 主机无法访问 MomoBird，或超时设置过短 |
| 发布成功但同步失败 | 前往“MomoBird 同步”查看失败计数并点击“重试失败项” |
| 全量同步中断 | 重新执行一键全量同步；stable external ID 会保证重复导入幂等 |

错误通知和状态表不会记录 API Key、文章正文或完整上游响应。

## 开发验证

运行全部测试：

```bash
php tests/run.php
```

检查所有 PHP 文件语法：

```bash
find . -name '*.php' -not -path './.git/*' -not -path './.superpowers/*' -print0 | xargs -0 -n1 php -l
```

检查后台 JavaScript：

```bash
node --check assets/admin.js
```

## 安全说明

- 所有 MomoBird 请求均从 PHP 服务端发出。
- 后台操作要求管理员权限和 Typecho CSRF 校验。
- 生产 Base URL 必须使用 HTTPS；只有 localhost 和回环地址可使用 HTTP。
- 客户端限制响应体大小并保持 TLS 证书校验。
- 项目中不应提交任何真实生产凭据。

## License

MIT
