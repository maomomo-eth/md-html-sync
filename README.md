# Markdown HTML Sync

这是一个轻量 WordPress 插件：在文章、页面等支持编辑器的内容类型里增加「Markdown 同步」编辑框。启用同步后，保存文章时会把 Markdown 源内容渲染为 HTML，并写入 WordPress 正文内容；后续修改 Markdown 后再次保存，会自动同步 HTML。

## 安装

1. 将 `md-html-sync` 目录放到 WordPress 的 `wp-content/plugins/` 目录。
2. 进入 WordPress 后台「插件」页面，启用 `Markdown HTML Sync`。
3. 编辑文章或页面，在「Markdown 同步」区域勾选「启用 Markdown 同步」。
4. 在文本框中编写 Markdown，保存文章即可生成 HTML 正文。

## 支持的常用语法

- 标题：`#` 到 `######`
- 段落
- 无序列表和有序列表
- 引用块
- 分隔线
- fenced code block：三个反引号
- 行内代码
- 链接和图片
- 粗体、斜体、删除线

## TinyPNG WebP 集成

如果同时启用了 `MaoMoMo TinyPNG Media`，当 TinyPNG 后台队列生成 WebP 附件后，本插件会把已导入文章中的原图 URL 回写为 WebP URL，并重新生成 HTML 正文。

## 注意事项

- 启用同步后，文章正文会以 Markdown 生成结果为准；直接修改正文编辑器里的 HTML 后，下次保存会被 Markdown 重新覆盖。
- Markdown 源内容保存在文章 meta 中，HTML 正文保存在 WordPress 原生 `post_content` 字段中。
- 插件内置的是轻量 Markdown 渲染器，适合常见文章写作；如果需要完整 CommonMark 兼容，可以后续接入 `league/commonmark`。
