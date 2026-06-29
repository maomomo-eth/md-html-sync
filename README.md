# Markdown HTML Sync

`Markdown HTML Sync` 是一个轻量 WordPress 插件，用来把 Markdown 作为文章源内容保存，并自动生成 WordPress 正文 HTML。

适合两类场景：

- 在 WordPress 后台直接用 Markdown 编辑文章。
- 通过 REST API 导入 Markdown、图片和 front matter，自动创建或更新文章。

## 功能概览

- 在文章、页面等支持编辑器的内容类型中增加「Markdown 同步」编辑框。
- 勾选「启用 Markdown 同步」后，保存文章会用 Markdown 重新生成 `post_content`。
- Markdown 源内容保存在文章 meta：`_md_html_sync_markdown`。
- 同步开关保存在文章 meta：`_md_html_sync_enabled`。
- 支持 Markdown front matter 同步标题、slug、摘要、发布时间、状态、分类、标签、特色图。
- 提供 REST 导入接口：`POST /wp-json/md-html-sync/v1/import`。
- 支持上传 Markdown 文件、ZIP 发布包、正文图片和特色图。
- 如果同时启用了 `MaoMoMo TinyPNG Media`，TinyPNG 后台生成 WebP 后会自动回写文章中的图片 URL。

## 安装

1. 将 `md-html-sync` 目录放到 WordPress 的 `wp-content/plugins/` 目录。
2. 进入 WordPress 后台「插件」页面，启用 `Markdown HTML Sync`。
3. 编辑文章或页面，在「Markdown 同步」区域勾选「启用 Markdown 同步」。
4. 在文本框中编写 Markdown，保存文章即可生成 HTML 正文。

插件会自动作用于所有满足以下条件的内容类型：

- `show_ui` 为 true。
- 支持 WordPress 编辑器。
- 不是 `attachment`。

## 后台编辑用法

进入文章编辑页后：

1. 找到「Markdown 同步」编辑框。
2. 勾选「启用 Markdown 同步」。
3. 输入 Markdown。
4. 保存文章。

保存后插件会：

- 把 Markdown 保存到 `_md_html_sync_markdown`。
- 把同步开关保存到 `_md_html_sync_enabled`。
- 将 Markdown 渲染为 HTML，并写入文章正文 `post_content`。
- 如果 Markdown 顶部有 front matter，会同步对应文章字段。

注意：启用同步后，文章正文以 Markdown 渲染结果为准。直接修改正文编辑器里的 HTML 后，下次保存会被 Markdown 重新覆盖。

## Markdown 示例

```markdown
---
title: "REST 导入测试"
slug: "rest-import-demo"
published_at: "2026-06-28"
excerpt: "REST 导入摘要"
status: "publish"
featured_image: "assets/cover.png"
taxonomy_category:
  - "REST 分类"
taxonomy_post_tag:
  - "REST 标签"
---

# REST 正文标题

普通段落含 **加粗**、*斜体*、[链接](https://example.com) 和 `code`。

![正文配图](assets/body.png)

| 项目 | 内容 |
|---|---|
| 来源 | REST API |

- 项目 A
- 项目 B

> 引用内容
```

## 支持的 Markdown 语法

- 标题：`#` 到 `######`
- 段落
- 无序列表和有序列表
- 引用块
- 分隔线
- fenced code block：三个反引号
- 行内代码
- 链接和图片
- 粗体、斜体、删除线
- 表格
- Shortcode 保留在正文中，前台渲染时由 WordPress 执行

插件内置的是轻量 Markdown 渲染器，覆盖常见文章写作场景；它不是完整 CommonMark 实现。

## Front Matter 字段

front matter 必须放在 Markdown 文件开头，用 `---` 包裹。

支持字段：

| 字段 | 作用 |
|---|---|
| `title` | 写入文章标题 `post_title` |
| `slug` | 写入文章别名 `post_name`，也用于 upsert 查找已有文章 |
| `excerpt` | 写入文章摘要 `post_excerpt` |
| `published_at` | 写入发布时间，支持 `YYYY-MM-DD` 或 WordPress 可解析的时间字符串 |
| `status` | 写入文章状态 |
| `post_type` | 指定内容类型 |
| `update_mode` | 指定导入模式 |
| `featured_image` | 指定特色图路径 |
| `taxonomy_category` | 写入分类，数组格式 |
| `taxonomy_post_tag` | 写入标签，数组格式 |

`status` 支持：

- `draft`
- `publish`
- `pending`
- `private`
- `future`

`update_mode` 支持：

- `upsert`：默认值。优先按 `slug` 查找已有文章，找到则更新，找不到则创建。
- `create`：始终创建新文章。
- `update`：只更新已有文章；按 `post_id` 或 `slug` 找不到时返回错误。

## REST 导入接口

接口地址：

```text
POST /wp-json/md-html-sync/v1/import
```

权限要求：

- 当前用户必须有 `edit_posts` 权限。
- 当前用户必须有 `upload_files` 权限。

常用认证方式：

- WordPress Application Password。
- 已登录后台下的 REST nonce。
- 反向代理或脚本内调用时，也可以先设置当前用户。

### 请求参数

| 参数 | 类型 | 说明 |
|---|---|---|
| `markdown` | string | Markdown 正文。也可以上传名为 `markdown` 的 `.md` 文件 |
| `package` | file | ZIP 发布包。传了 `package` 时优先使用 ZIP |
| `post_id` | integer | 指定更新某篇文章 |
| `post_type` | string | 默认 `post`，可被 front matter 覆盖 |
| `status` | string | 可被 front matter 覆盖 |
| `update_mode` | string | 默认 `upsert`，可被 front matter 覆盖 |

返回内容包括：

- `post_id`
- `status`
- `post_type`
- `permalink`
- `edit_url`
- `markdown_url`
- `attachments`

## 推荐导入方式：ZIP 发布包

如果文章包含图片，推荐用 ZIP。ZIP 能完整保留 `assets/xxx.png` 这类相对路径，最稳定。

推荐结构：

```text
article.md
assets/
  cover.png
  body.png
```

规则：

- 插件优先寻找 ZIP 根目录或子目录中的 `article.md`。
- 如果没有 `article.md`，会选择第一个 `.md` 或 `.markdown` 文件。
- 只会自动导入 `assets/` 目录下的文件。
- 支持图片扩展名：`jpg`、`jpeg`、`png`、`gif`、`webp`、`svg`。
- Markdown 中的 `assets/body.png` 会被重写为上传后的 WordPress 媒体 URL。
- `featured_image: "assets/cover.png"` 会把对应附件设为特色图。

curl 示例：

```bash
curl -X POST "https://example.com/wp-json/md-html-sync/v1/import" \
  -u "用户名:应用密码" \
  -F "package=@/path/to/article-package.zip"
```

## 直接上传 Markdown

不含图片时，可以直接传 `markdown` 字符串：

```bash
curl -X POST "https://example.com/wp-json/md-html-sync/v1/import" \
  -u "用户名:应用密码" \
  --form-string "markdown=$(cat article.md)"
```

也可以上传 Markdown 文件：

```bash
curl -X POST "https://example.com/wp-json/md-html-sync/v1/import" \
  -u "用户名:应用密码" \
  -F "markdown=@/path/to/article.md"
```

如果要同时传图片，建议仍然使用 ZIP。普通 multipart 上传不一定能稳定保留 `assets/` 目录层级，图片路径容易和 Markdown 中的引用对不上。

## 图片路径重写规则

导入时插件会先上传附件，再重写 Markdown 图片 URL。

匹配规则：

- 优先匹配完整相对路径，例如 `assets/body.png`。
- 也会匹配文件名，例如 `body.png`。
- 匹配成功后，Markdown 中的本地路径会被替换成 WordPress 上传后的 URL。
- 渲染 HTML 时使用重写后的 Markdown。

示例：

```markdown
![正文配图](assets/body.png)
```

导入后会变成类似：

```markdown
![正文配图](https://example.com/wp-content/uploads/2026/06/body.png)
```

## TinyPNG WebP 集成

如果同时启用了 `MaoMoMo TinyPNG Media`：

1. 本插件导入文章时先写入原始图片 URL，确保文章能立即发布。
2. TinyPNG 插件后台队列生成 WebP 附件后，会触发 `maomomo_tinypng_attachment_processed`。
3. 本插件监听该事件，并把文章 Markdown 与 HTML 正文中的原图 URL 替换为 WebP URL。
4. 如果 WebP 事件触发时文章还没写完，本插件会通过 WP-Cron 延迟重试。

注意：

- 只有 `webp` 或 `both` 模式会触发 WebP URL 回写。
- 回写只处理已经启用 Markdown 同步的文章。
- TinyPNG 后台队列依赖 WP-Cron。生产环境建议配置系统 cron 定时触发 `wp-cron.php`。

## 常见工作流

### 新建文章

front matter：

```yaml
---
title: "新文章标题"
slug: "new-post-slug"
status: "draft"
update_mode: "create"
---
```

导入后会创建新文章。

### 按 slug 更新文章

front matter：

```yaml
---
title: "更新后的标题"
slug: "existing-post-slug"
status: "publish"
update_mode: "upsert"
---
```

`upsert` 会先查找同 `slug` 的文章，找到则更新，找不到则创建。

### 指定 post_id 更新文章

```bash
curl -X POST "https://example.com/wp-json/md-html-sync/v1/import" \
  -u "用户名:应用密码" \
  -F "post_id=123" \
  -F "update_mode=update" \
  -F "markdown=@/path/to/article.md"
```

`post_id` 优先级高于 `slug`。

## 本地测试

项目测试环境中可以运行：

```bash
docker compose exec -T php php /srv/scripts/test-md-html-sync.php
docker compose exec -T php php /srv/scripts/test-rest-import.php
```

语法检查：

```bash
docker compose exec -T php php -l /srv/public/wp-content/plugins/md-html-sync/md-html-sync.php
```

## 注意事项

- 启用同步后，文章正文会以 Markdown 生成结果为准。
- Markdown 源内容保存在文章 meta 中，不会直接暴露到 REST。
- ZIP 导入会拒绝绝对路径、协议路径、`../` 和空字节路径。
- 单个上传文件大小上限为 50MB。
- 如果需要完整 CommonMark 兼容，可以后续接入 `league/commonmark`。
