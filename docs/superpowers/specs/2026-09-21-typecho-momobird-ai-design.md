# Typecho MomoBird AI 插件设计

## 目标

为 Typecho 1.2.x 提供一个服务端知识库同步插件，将当前站点的公开普通文章同步到 MomoBird 指定 collection。插件支持文章发布时同步、文章转为非公开或删除时清理远端数据、后台一键全量同步，以及失败后的人工重试。

首版只同步 `type=post` 且 `status=publish` 的文章。独立页面、草稿、待审核、隐藏、私密和密码保护内容不进入知识库。

## 非目标

- 不同步 Typecho 独立页面、评论、附件或媒体文件。
- 不在 Typecho 前台提供问答组件。
- 不创建或管理 MomoBird collection 和 API Key。
- 不依赖系统 cron、常驻进程或额外 PHP 框架。
- 不因 MomoBird 故障阻止文章发布。

## 运行环境与依赖

- Typecho 1.2.x。
- PHP 7.2 或更高版本，启用 cURL。
- 首要支持 MySQL，并保持数据访问和建表逻辑可兼容 SQLite。
- MomoBird Base URL 默认 `https://api.valley.atlaspaces.com`。
- 新集成统一使用 `/momobird/api/v1` 命名空间。
- 使用仅限目标 collection 的读写 API Key；禁止使用管理员 Key。

## 总体架构

插件由以下独立组件组成：

1. `Plugin.php`：插件元数据、激活/停用、Typecho 钩子、配置表单和后台面板注册。
2. MomoBird HTTP 客户端：构建端点、认证、超时、响应体限制、JSON 解码与结构化错误。
3. 文章映射器：读取 Typecho 文章、归一化正文、生成标签和 metadata。
4. 语义分块器：按标题、段落和安全长度切分正文。
5. 同步服务：执行 upsert、删除旧分块、删除文章、失败状态记录和全量校准。
6. 同步状态仓储：维护本地文章/分块与 MomoBird entry UUID 的映射。
7. 后台 Action 与管理页：连接测试、全量同步、失败重试、状态展示和孤儿清理。

组件之间通过窄接口通信。HTTP 客户端不读取 Typecho 数据；映射器不发网络请求；后台页面不直接持有同步算法。

## Typecho 生命周期

插件使用 Typecho 1.2 的文章编辑组件钩子：

- `finishPublish`：文章完成发布后同步。钩子触发后重新从数据库读取最终文章，只有公开普通文章才 upsert。
- `finishMark`：文章状态改变后检查最终状态。变为公开则 upsert；变为待审核、隐藏或私密则删除远端分块。
- `finishDelete`：文章删除后按本地状态表保存的 entry UUID 删除所有远端分块。

不使用独立页面组件钩子，不使用 `finishSave`，因此保存草稿和自动保存不会泄露未公开内容。

所有生命周期同步均为当前请求内的直接同步。单篇失败不回滚 Typecho 已完成的写入；插件在后台 Notice 中显示脱敏错误，并把状态记为失败供人工重试。

## 知识条目映射

### 站点标识

首次激活时生成并持久化随机 `site_id`。它不依赖域名，因此站点换域名后仍保持条目身份稳定。站点标识用于 external ID、标签和 metadata，防止多个 Typecho 站点共用 collection 时相互覆盖或误删。

### Stable external ID

每个文章分块使用：

```text
typecho:<site_id>:post:<cid>:<chunk_key>
```

`chunk_key` 优先由规范化标题路径和局部内容摘要生成；无标题段落使用稳定内容摘要。不得只使用位置序号，否则在文章开头插入一段会重编号所有后续分块。

### Entry 字段

- `title`：文章标题；多分块时追加当前章节标题。
- `content`：自包含的正文块，最大 7,500 个 Unicode 字符，为 MomoBird 的 8,000 字限制保留余量。
- `tags`：包含固定来源标签、站点标签，以及经过裁剪的 Typecho 标签和分类；最多 32 个，每个最多 64 字符。
- `source_ref`：文章最终永久链接。
- `metadata`：包含 `source=typecho`、`site_id`、`post_id`、`chunk_key`、`language`、`visibility=public` 和文章更新时间。

文章内容先去除 `script`、`style`、危险标签和 Typecho 标记噪声。Markdown 保留标题与列表语义；HTML 转为带合理换行的纯文本。图片可保留非空替代文本，但不上传图片二进制。

## 分块策略

1. 以 Markdown/HTML 标题作为首选边界。
2. 在同一章节内按空行和段落切分。
3. 过长段落按句子边界切分。
4. 仍超限时按 Unicode 字符安全截分。
5. 每块带上文章标题和章节上下文，使检索结果可独立理解。

同步新版本时先 upsert 当前全部分块，再删除该文章不再存在的旧分块。只有所有 upsert 成功后才执行旧块删除，避免部分失败造成知识缺失。

## MomoBird API 使用

所有请求仅从 PHP 服务端发出，使用：

```http
Authorization: Bearer <collection-scoped-key>
```

主要端点：

- `GET /momobird/readyz`：连接准备状态检查。
- `GET /momobird/api/v1/collections/{collection}`：验证 Key 和 collection 权限。
- `POST /momobird/api/v1/collections/{collection}/entries/batch`：批量 upsert，单批最多 100 条。
- `GET /momobird/api/v1/collections/{collection}/entries`：分页列出条目，用于全量校准和孤儿检测。
- `DELETE /momobird/api/v1/collections/{collection}/entries/{uuid}`：软删除远端条目。

默认请求超时 10 秒，可配置。502、504 和传输超时最多重试一次，并使用短随机退避。401、403、404、409 和验证错误不自动重试。响应读取设置上限；错误日志不得包含 API Key、正文或完整远端响应。

Base URL 默认要求 HTTPS。仅当主机为 `localhost`、`127.0.0.1` 或 `::1` 时允许 HTTP，方便本地开发。

## 本地状态

插件创建一张使用 Typecho 表前缀的同步表。每行代表一个文章分块，核心字段包括：

- 自增主键；
- `site_id`；
- Typecho `post_id`；
- `chunk_key` 和 `external_id`；
- MomoBird `entry_id`；
- 本地内容哈希；
- 状态：`synced`、`failed_upsert`、`failed_delete`；
- 尝试次数；
- 脱敏后的最后错误代码和消息；
- 最后尝试与成功时间。

文章删除后，如果远端删除失败，映射行继续保留为 `failed_delete`，保证后台重试仍知道远端 UUID。远端删除成功后才移除映射行。

插件停用不删除状态表或配置。卸载/清理数据必须是后台显式操作，且不得顺带删除远端知识。

## 后台体验

### 配置

- 启用自动同步；
- Base URL；
- collection slug；
- API Key；
- 请求超时。

API Key 输入框留空表示保留已保存密钥。页面不能回显完整密钥，也不能将其注入浏览器 JavaScript。保存配置时验证 URL、collection slug 和超时范围。

### 管理页

管理页显示：

- 配置与连接状态；
- 已同步文章和分块数量；
- 失败 upsert/delete 数量；
- 最近一次全量同步时间；
- 最近的脱敏错误。

管理员可执行连接测试、重试失败项、一键全量同步和远端孤儿清理。所有操作要求管理员权限和 Typecho CSRF 保护。

全量同步通过后台 AJAX 分页处理，每批读取有限数量文章，避免单请求执行时间过长。流程先 upsert 全部当前公开文章；只有全部批次成功后，才列出带当前站点标签的远端条目并清理不属于任何当前分块的孤儿项。独立页面不进入任何批次。

## 成功与失败行为

- 单篇同步成功：文章正常发布，显示同步成功通知，更新本地映射。
- 单篇同步失败：文章仍正常发布，显示知识库同步失败通知，记录可重试状态。
- 转非公开或删除失败：Typecho 操作仍成功，保留 UUID 映射并显示清理失败。
- 配置无效或缺失：不发网络请求，显示明确配置错误。
- 部分批量 upsert 失败：不删除旧块，不将整篇标为成功。
- malformed JSON 或缺失必需响应字段：视为上游失败，不猜测成功。

## 安全与隐私

- API Key 只存储在 Typecho 服务端配置中，只发送至配置的 MomoBird 主机。
- 所有后台 Action 执行权限和 CSRF 检查。
- 对 Base URL 和 collection slug 做严格校验，避免任意路径拼接。
- 不把未发布、私密、隐藏、待审核或密码保护文章发送到 MomoBird。
- 日志和后台错误不包含密钥、正文、请求体或未经裁剪的上游响应。
- HTTP 客户端禁用不安全 TLS 选项，不接受关闭证书校验的配置。

## 测试策略

纯逻辑与 HTTP 客户端尽量不依赖运行中的 Typecho，以便用轻量 PHP 测试执行器验证。Typecho 适配层使用可替换仓储和模拟 HTTP 服务测试。

覆盖范围：

- Markdown/HTML 正文清理和 Unicode 安全分块；
- 稳定 external ID 与站点隔离；
- API 路径、Bearer 认证、请求体和响应解析；
- created、updated、unchanged 处理；
- 重复同步幂等性；
- 文章缩短后的旧分块删除；
- 删除失败映射保留及重试；
- 草稿、非公开文章和独立页面排除；
- 401/403、502/504、超时、超大响应和 malformed JSON；
- MomoBird 失败不影响 Typecho 文章发布；
- 全量同步未完全成功时不执行孤儿删除。

最终验证包括 PHP 语法检查、聚焦单元测试、完整测试套件，以及在 Typecho 1.2.x 测试安装中的激活、配置、发布、改状态、删除和全量同步冒烟测试。

## 交付结构

插件仓库交付可直接重命名为 `MomoBirdAI` 并上传至 Typecho `usr/plugins/` 的目录。README 包含安装步骤、MomoBird collection 与最小权限 Key 的准备方法、配置说明、同步行为、故障排查和卸载语义。仓库不包含任何真实凭据。
