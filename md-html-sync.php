<?php
/**
 * Plugin Name: Markdown HTML Sync
 * Description: 在文章编辑页维护 Markdown 源内容，保存时自动生成并同步 HTML 正文。
 * Version:     1.0.0
 * Author:      Codex
 * License:     GPL-2.0-or-later
 * Text Domain: md-html-sync
 * Requires PHP: 7.2
 */

if (! defined('ABSPATH')) {
    exit;
}

final class MD_HTML_Sync_Plugin
{
    private const VERSION = '1.0.0';
    private const META_MARKDOWN = '_md_html_sync_markdown';
    private const META_ENABLED = '_md_html_sync_enabled';
    private const NONCE_ACTION = 'md_html_sync_save';
    private const NONCE_NAME = 'md_html_sync_nonce';
    private const FIELD_MARKDOWN = 'md_html_sync_markdown';
    private const FIELD_ENABLED = 'md_html_sync_enabled';
    private const REST_NAMESPACE = 'md-html-sync/v1';
    private const MAX_IMPORT_BYTES = 52428800;

    private static $is_syncing = false;

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_post_meta']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post', [__CLASS__, 'save_post'], 20, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_styles']);
    }

    public static function register_rest_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/import', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'rest_import'],
            'permission_callback' => [__CLASS__, 'rest_import_permissions'],
            'args' => [
                'markdown' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'post_id' => [
                    'type' => 'integer',
                    'required' => false,
                ],
                'post_type' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'post',
                ],
                'status' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'update_mode' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => 'upsert',
                ],
            ],
        ]);
    }

    public static function rest_import_permissions()
    {
        if (! current_user_can('edit_posts') || ! current_user_can('upload_files')) {
            return new WP_Error(
                'md_html_sync_forbidden',
                '当前用户没有导入 Markdown 文章所需的权限。',
                ['status' => rest_authorization_required_code()]
            );
        }

        return true;
    }

    public static function rest_import(WP_REST_Request $request)
    {
        $package = self::read_import_package($request);
        if (is_wp_error($package)) {
            return $package;
        }

        try {
            $result = self::import_markdown_package($request, $package);
        } finally {
            self::cleanup_import_package($package);
        }

        return $result;
    }

    public static function register_post_meta(): void
    {
        foreach (self::get_supported_post_types() as $post_type) {
            register_post_meta($post_type, self::META_MARKDOWN, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => [__CLASS__, 'sanitize_markdown_meta'],
                'auth_callback' => static function ($allowed, $meta_key, $post_id): bool {
                    return current_user_can('edit_post', (int) $post_id);
                },
            ]);

            register_post_meta($post_type, self::META_ENABLED, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => static function ($value): string {
                    return '1' === (string) $value ? '1' : '0';
                },
                'auth_callback' => static function ($allowed, $meta_key, $post_id): bool {
                    return current_user_can('edit_post', (int) $post_id);
                },
            ]);
        }
    }

    public static function add_meta_boxes(string $post_type): void
    {
        if (! in_array($post_type, self::get_supported_post_types(), true)) {
            return;
        }

        add_meta_box(
            'md-html-sync-editor',
            'Markdown 同步',
            [__CLASS__, 'render_meta_box'],
            $post_type,
            'normal',
            'high'
        );
    }

    public static function enqueue_admin_styles(string $hook_suffix): void
    {
        if (! in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_register_style('md-html-sync-admin', false, [], self::VERSION);
        wp_enqueue_style('md-html-sync-admin');
        wp_add_inline_style('md-html-sync-admin', '
            .md-html-sync-box {
                display: grid;
                gap: 10px;
            }
            .md-html-sync-toggle {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-weight: 600;
            }
            .md-html-sync-textarea {
                width: 100%;
                min-height: 420px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
                font-size: 13px;
                line-height: 1.6;
                resize: vertical;
            }
            .md-html-sync-help {
                margin: 0;
                color: #646970;
            }
        ');
    }

    public static function render_meta_box(WP_Post $post): void
    {
        $markdown = (string) get_post_meta($post->ID, self::META_MARKDOWN, true);
        $enabled = '1' === (string) get_post_meta($post->ID, self::META_ENABLED, true);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="md-html-sync-box">
            <label class="md-html-sync-toggle">
                <input
                    type="checkbox"
                    name="<?php echo esc_attr(self::FIELD_ENABLED); ?>"
                    value="1"
                    <?php checked($enabled); ?>
                />
                启用 Markdown 同步
            </label>
            <textarea
                class="md-html-sync-textarea"
                name="<?php echo esc_attr(self::FIELD_MARKDOWN); ?>"
                spellcheck="false"
                placeholder="# 标题&#10;&#10;这里编写 Markdown 内容。保存文章后，正文会同步为 HTML。"
            ><?php echo esc_textarea($markdown); ?></textarea>
            <p class="md-html-sync-help">
                启用后，每次保存都会用上面的 Markdown 重新生成正文 HTML；未启用时不会改写正文。
            </p>
        </div>
        <?php
    }

    public static function save_post(int $post_id, WP_Post $post): void
    {
        if (self::$is_syncing) {
            return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (! in_array($post->post_type, self::get_supported_post_types(), true)) {
            return;
        }

        if (! isset($_POST[self::NONCE_NAME])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
        if (! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        if (! array_key_exists(self::FIELD_MARKDOWN, $_POST)) {
            return;
        }

        $enabled = isset($_POST[self::FIELD_ENABLED]) && '1' === (string) wp_unslash($_POST[self::FIELD_ENABLED]);
        $markdown = self::sanitize_markdown_meta(wp_unslash($_POST[self::FIELD_MARKDOWN]));

        update_post_meta($post_id, self::META_MARKDOWN, $markdown);
        update_post_meta($post_id, self::META_ENABLED, $enabled ? '1' : '0');

        if (! $enabled) {
            return;
        }

        $front_matter = self::parse_front_matter($markdown);
        $html = self::render_markdown($markdown);
        $current_content = (string) get_post_field('post_content', $post_id, 'raw');
        $post_update = self::build_post_update_from_front_matter($post_id, $html, $front_matter);

        if ($current_content === $html) {
            unset($post_update['post_content']);
        }

        if (1 === count($post_update)) {
            self::sync_front_matter_terms($post_id, $front_matter);
            return;
        }

        self::$is_syncing = true;
        try {
            wp_update_post($post_update);
            self::sync_front_matter_terms($post_id, $front_matter);
        } finally {
            self::$is_syncing = false;
        }
    }

    private static function read_import_package(WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        $package_file = self::get_uploaded_file($files, 'package');

        if (null !== $package_file) {
            return self::read_zip_import_package($package_file);
        }

        $markdown = (string) $request->get_param('markdown');
        if ('' === trim($markdown)) {
            $markdown_file = self::get_uploaded_file($files, 'markdown');
            if (null !== $markdown_file) {
                $markdown = self::read_uploaded_text_file($markdown_file);
                if (is_wp_error($markdown)) {
                    return $markdown;
                }
            }
        }

        if ('' === trim($markdown)) {
            return new WP_Error(
                'md_html_sync_missing_markdown',
                '请提供 markdown 字段、markdown 文件或 package ZIP。',
                ['status' => 400]
            );
        }

        $assets = [];
        foreach (self::flatten_uploaded_files($files) as $field => $file) {
            if (in_array($field, ['package', 'markdown'], true)) {
                continue;
            }

            $path = self::normalize_relative_path((string) ($file['full_path'] ?? $file['name'] ?? ''));
            if ('' === $path) {
                $path = sanitize_file_name((string) ($file['name'] ?? 'asset'));
            }

            $assets[$path] = $file;
        }

        return [
            'markdown' => self::sanitize_markdown_meta($markdown),
            'assets' => $assets,
            'cleanup_dirs' => [],
        ];
    }

    private static function read_zip_import_package(array $package_file)
    {
        $valid = self::validate_uploaded_file($package_file, ['zip']);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $tmp_dir = trailingslashit(get_temp_dir()) . 'md-html-sync-import-' . wp_generate_password(12, false, false);
        if (! wp_mkdir_p($tmp_dir)) {
            return new WP_Error('md_html_sync_tmp_dir_failed', '无法创建导入临时目录。', ['status' => 500]);
        }

        $unzipped = self::extract_zip_file($package_file['tmp_name'], $tmp_dir);
        if (is_wp_error($unzipped)) {
            self::delete_directory($tmp_dir);

            return new WP_Error(
                'md_html_sync_unzip_failed',
                'ZIP 解压失败：' . $unzipped->get_error_message(),
                ['status' => 400]
            );
        }

        $markdown_file = self::find_import_markdown_file($tmp_dir);
        if ('' === $markdown_file) {
            self::delete_directory($tmp_dir);

            return new WP_Error('md_html_sync_zip_missing_markdown', 'ZIP 中没有找到 article.md 或其他 Markdown 文件。', ['status' => 400]);
        }

        $markdown = file_get_contents($markdown_file);
        if (! is_string($markdown)) {
            self::delete_directory($tmp_dir);

            return new WP_Error('md_html_sync_read_markdown_failed', '读取 ZIP 中的 Markdown 文件失败。', ['status' => 400]);
        }

        $assets = self::collect_zip_assets($tmp_dir, $markdown_file);

        return [
            'markdown' => self::sanitize_markdown_meta($markdown),
            'assets' => $assets,
            'cleanup_dirs' => [$tmp_dir],
        ];
    }

    private static function extract_zip_file(string $zip_file, string $destination)
    {
        if (! class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $archive = new PclZip($zip_file);
        $files = $archive->listContent();
        if (! is_array($files)) {
            return new WP_Error('md_html_sync_zip_list_failed', $archive->errorInfo(true), ['status' => 400]);
        }

        foreach ($files as $file) {
            $filename = (string) ($file['filename'] ?? '');
            if ('' === $filename) {
                continue;
            }

            if (
                0 === strpos($filename, '/')
                || preg_match('/^(?:[A-Za-z][A-Za-z0-9+.-]*:|\/\/)/', $filename)
                || false !== strpos(str_replace('\\', '/', $filename), '../')
                || false !== strpos($filename, "\0")
            ) {
                return new WP_Error('md_html_sync_zip_unsafe_path', 'ZIP 中包含不安全路径：' . $filename, ['status' => 400]);
            }
        }

        $extracted = $archive->extract(PCLZIP_OPT_PATH, $destination);
        if (0 === $extracted) {
            return new WP_Error('md_html_sync_zip_extract_failed', $archive->errorInfo(true), ['status' => 400]);
        }

        return true;
    }

    private static function import_markdown_package(WP_REST_Request $request, array $package)
    {
        $markdown = self::sanitize_markdown_meta((string) $package['markdown']);
        $front_matter = self::parse_front_matter($markdown);
        $post_type = self::get_import_post_type($request, $front_matter);
        if (is_wp_error($post_type)) {
            return $post_type;
        }

        $status = self::get_import_post_status($request, $front_matter);
        $update_mode = self::get_import_update_mode($request, $front_matter);
        $post_id = self::resolve_import_post_id($request, $front_matter, $post_type, $update_mode);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($post_id > 0 && ! current_user_can('edit_post', $post_id)) {
            return new WP_Error('md_html_sync_cannot_edit_post', '当前用户不能编辑目标文章。', ['status' => 403]);
        }

        $initial_fields = self::build_post_fields_from_front_matter('', $front_matter);
        $initial_fields['post_type'] = $post_type;
        $initial_fields['post_status'] = $status;
        unset($initial_fields['post_content']);

        if ($post_id <= 0) {
            $initial_fields['post_author'] = get_current_user_id();
            $created = wp_insert_post($initial_fields, true);
            if (is_wp_error($created)) {
                return $created;
            }
            $post_id = (int) $created;
        }

        $attachments = self::import_assets((array) $package['assets'], $post_id);
        if (is_wp_error($attachments)) {
            return $attachments;
        }

        $rewritten_markdown = self::rewrite_markdown_asset_urls($markdown, $attachments['url_map']);
        $rewritten_front_matter = self::parse_front_matter($rewritten_markdown);
        $html = self::render_markdown($rewritten_markdown);
        $post_update = self::build_post_update_from_front_matter($post_id, $html, $rewritten_front_matter);
        $post_update['post_status'] = $status;

        self::$is_syncing = true;
        try {
            $updated = wp_update_post($post_update, true);
            if (is_wp_error($updated)) {
                return $updated;
            }

            update_post_meta($post_id, self::META_MARKDOWN, $rewritten_markdown);
            update_post_meta($post_id, self::META_ENABLED, '1');
            self::sync_front_matter_terms($post_id, $rewritten_front_matter);
            self::sync_featured_image($post_id, $rewritten_front_matter, $attachments['id_map']);
        } finally {
            self::$is_syncing = false;
        }

        return rest_ensure_response([
            'post_id' => $post_id,
            'status' => get_post_status($post_id),
            'post_type' => get_post_type($post_id),
            'permalink' => get_permalink($post_id),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'markdown_url' => function_exists('maomomo_markdown_build_url') ? maomomo_markdown_build_url(get_permalink($post_id)) : '',
            'attachments' => array_values($attachments['items']),
        ]);
    }

    public static function sanitize_markdown_meta($value): string
    {
        $markdown = is_string($value) ? $value : '';
        $markdown = wp_check_invalid_utf8($markdown);

        return str_replace(["\r\n", "\r"], "\n", $markdown);
    }

    private static function get_uploaded_file(array $files, string $field): ?array
    {
        if (! isset($files[$field]) || ! is_array($files[$field])) {
            return null;
        }

        $file = $files[$field];
        if (isset($file['name']) && is_array($file['name'])) {
            return null;
        }

        return $file;
    }

    private static function flatten_uploaded_files(array $files): array
    {
        $flattened = [];

        foreach ($files as $field => $file) {
            if (! is_array($file) || ! isset($file['name'])) {
                continue;
            }

            if (! is_array($file['name'])) {
                $flattened[$field] = $file;
                continue;
            }

            foreach ($file['name'] as $index => $name) {
                $flattened[$field . '_' . $index] = [
                    'name' => $name,
                    'full_path' => $file['full_path'][$index] ?? $name,
                    'type' => $file['type'][$index] ?? '',
                    'tmp_name' => $file['tmp_name'][$index] ?? '',
                    'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'][$index] ?? 0,
                ];
            }
        }

        return $flattened;
    }

    private static function validate_uploaded_file(array $file, array $allowed_extensions)
    {
        if (! isset($file['error']) || UPLOAD_ERR_OK !== (int) $file['error']) {
            return new WP_Error('md_html_sync_upload_failed', '文件上传失败。', ['status' => 400]);
        }

        if (empty($file['tmp_name']) || ! is_readable((string) $file['tmp_name'])) {
            return new WP_Error('md_html_sync_upload_unreadable', '上传文件不可读取。', ['status' => 400]);
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_IMPORT_BYTES) {
            return new WP_Error('md_html_sync_upload_too_large', '上传文件超过大小限制。', ['status' => 413]);
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (! in_array($extension, $allowed_extensions, true)) {
            return new WP_Error('md_html_sync_upload_type_not_allowed', '不支持的文件类型。', ['status' => 400]);
        }

        return true;
    }

    private static function read_uploaded_text_file(array $file)
    {
        $valid = self::validate_uploaded_file($file, ['md', 'markdown', 'txt']);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $content = file_get_contents((string) $file['tmp_name']);
        if (! is_string($content)) {
            return new WP_Error('md_html_sync_read_upload_failed', '读取上传 Markdown 文件失败。', ['status' => 400]);
        }

        return $content;
    }

    private static function find_import_markdown_file(string $dir): string
    {
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relative = self::normalize_relative_path(substr($file->getPathname(), strlen(trailingslashit($dir))));
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! in_array($extension, ['md', 'markdown'], true)) {
                continue;
            }

            if ('article.md' === strtolower(basename($relative))) {
                return $file->getPathname();
            }

            $candidates[] = $file->getPathname();
        }

        sort($candidates);

        return $candidates[0] ?? '';
    }

    private static function collect_zip_assets(string $dir, string $markdown_file): array
    {
        $assets = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($file->getPathname() === $markdown_file) {
                continue;
            }

            $relative = self::normalize_relative_path(substr($file->getPathname(), strlen(trailingslashit($dir))));
            if ('' === $relative || 0 !== strpos($relative, 'assets/')) {
                continue;
            }

            $assets[$relative] = [
                'name' => basename($relative),
                'full_path' => $relative,
                'type' => wp_check_filetype($relative)['type'] ?? '',
                'tmp_name' => $file->getPathname(),
                'error' => UPLOAD_ERR_OK,
                'size' => $file->getSize(),
            ];
        }

        return $assets;
    }

    private static function import_assets(array $assets, int $post_id)
    {
        if ([] === $assets) {
            return [
                'items' => [],
                'url_map' => [],
                'id_map' => [],
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        self::ensure_filesystem_chmod_constants();

        $items = [];
        $url_map = [];
        $id_map = [];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

        foreach ($assets as $path => $file) {
            $normalized_path = self::normalize_relative_path((string) $path);
            if ('' === $normalized_path) {
                continue;
            }

            $valid = self::validate_uploaded_file($file, $allowed_extensions);
            if (is_wp_error($valid)) {
                return $valid;
            }

            $sideload = [
                'name' => sanitize_file_name((string) ($file['name'] ?? basename($normalized_path))),
                'type' => (string) ($file['type'] ?? ''),
                'tmp_name' => (string) $file['tmp_name'],
                'error' => UPLOAD_ERR_OK,
                'size' => (int) ($file['size'] ?? 0),
            ];

            $uploaded = wp_handle_sideload($sideload, ['test_form' => false]);
            if (! empty($uploaded['error'])) {
                return new WP_Error('md_html_sync_asset_upload_failed', '附件上传失败：' . $uploaded['error'], ['status' => 400]);
            }

            $attachment = [
                'post_mime_type' => $uploaded['type'],
                'post_title' => sanitize_text_field(pathinfo($sideload['name'], PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            $attachment_id = wp_insert_attachment($attachment, $uploaded['file'], $post_id, true);
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            $metadata = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
            if (! is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata($attachment_id, $metadata);
            }

            $url = wp_get_attachment_url($attachment_id);
            if (! is_string($url) || '' === $url) {
                continue;
            }

            $content_url = self::get_attachment_content_url((int) $attachment_id, $url);
            $webp_id = absint(get_post_meta((int) $attachment_id, '_maomomo_tinypng_webp_id', true));
            $webp_url = $webp_id > 0 ? wp_get_attachment_url($webp_id) : '';
            if (! is_string($webp_url)) {
                $webp_url = '';
            }

            $items[$normalized_path] = [
                'id' => (int) $attachment_id,
                'path' => $normalized_path,
                'url' => $url,
                'content_url' => $content_url,
                'webp_id' => $webp_id,
                'webp_url' => $webp_url,
            ];
            $url_map[$normalized_path] = $content_url;
            $url_map[basename($normalized_path)] = $content_url;
            $id_map[$normalized_path] = (int) $attachment_id;
            $id_map[basename($normalized_path)] = (int) $attachment_id;
        }

        return [
            'items' => $items,
            'url_map' => $url_map,
            'id_map' => $id_map,
        ];
    }

    private static function get_attachment_content_url(int $attachment_id, string $fallback_url): string
    {
        $webp_id = absint(get_post_meta($attachment_id, '_maomomo_tinypng_webp_id', true));
        if ($webp_id <= 0 || 'attachment' !== get_post_type($webp_id)) {
            return $fallback_url;
        }

        if ('image/webp' !== get_post_mime_type($webp_id)) {
            return $fallback_url;
        }

        $webp_url = wp_get_attachment_url($webp_id);
        if (! is_string($webp_url) || '' === $webp_url) {
            return $fallback_url;
        }

        return $webp_url;
    }

    private static function rewrite_markdown_asset_urls(string $markdown, array $url_map): string
    {
        if ([] === $url_map) {
            return $markdown;
        }

        $link_label_pattern = '(?:[^\[\]]|\[[^\[\]]*\])*';

        return (string) preg_replace_callback('/(!?\[' . $link_label_pattern . '\]\()([^\s\)]+)((?:\s+"[^"]*")?\))/u', static function (array $matches) use ($url_map): string {
            $path = self::normalize_relative_path($matches[2]);
            if ('' === $path || ! isset($url_map[$path])) {
                return $matches[0];
            }

            return $matches[1] . $url_map[$path] . $matches[3];
        }, $markdown);
    }

    private static function sync_featured_image(int $post_id, array $front_matter, array $id_map): void
    {
        $featured_image = self::get_front_matter_string($front_matter, 'featured_image');
        $path = self::normalize_relative_path($featured_image);

        if ('' !== $path && isset($id_map[$path])) {
            set_post_thumbnail($post_id, $id_map[$path]);
        }
    }

    private static function get_import_post_type(WP_REST_Request $request, array $front_matter)
    {
        $post_type = self::get_front_matter_string($front_matter, 'post_type');
        if ('' === $post_type) {
            $post_type = (string) $request->get_param('post_type');
        }
        if ('' === $post_type) {
            $post_type = 'post';
        }

        $post_type = sanitize_key($post_type);
        if (! in_array($post_type, self::get_supported_post_types(), true)) {
            return new WP_Error('md_html_sync_invalid_post_type', '不支持的文章类型。', ['status' => 400]);
        }

        return $post_type;
    }

    private static function get_import_post_status(WP_REST_Request $request, array $front_matter): string
    {
        $status = self::get_front_matter_string($front_matter, 'status');
        if ('' === $status) {
            $status = (string) $request->get_param('status');
        }
        if ('' === $status) {
            $status = 'draft';
        }

        $status = sanitize_key($status);
        $allowed = ['draft', 'publish', 'pending', 'private', 'future'];

        return in_array($status, $allowed, true) ? $status : 'draft';
    }

    private static function get_import_update_mode(WP_REST_Request $request, array $front_matter): string
    {
        $update_mode = self::get_front_matter_string($front_matter, 'update_mode');
        if ('' === $update_mode) {
            $update_mode = (string) $request->get_param('update_mode');
        }

        $update_mode = sanitize_key($update_mode ?: 'upsert');

        return in_array($update_mode, ['create', 'update', 'upsert'], true) ? $update_mode : 'upsert';
    }

    private static function resolve_import_post_id(WP_REST_Request $request, array $front_matter, string $post_type, string $update_mode)
    {
        $request_post_id = absint($request->get_param('post_id'));
        if ($request_post_id > 0) {
            return $request_post_id;
        }

        if ('create' === $update_mode) {
            return 0;
        }

        $slug = self::get_front_matter_string($front_matter, 'slug');
        if ('' !== $slug) {
            $existing = get_page_by_path(sanitize_title($slug), OBJECT, $post_type);
            if ($existing instanceof WP_Post) {
                return (int) $existing->ID;
            }
        }

        if ('update' === $update_mode) {
            return new WP_Error('md_html_sync_post_not_found', '没有找到可更新的目标文章。', ['status' => 404]);
        }

        return 0;
    }

    private static function normalize_relative_path(string $path): string
    {
        $path = rawurldecode(trim(str_replace('\\', '/', $path)));
        $path = preg_replace('#/+#', '/', $path);
        $path = ltrim((string) $path, '/');

        while (0 === strpos($path, './')) {
            $path = substr($path, 2);
        }

        if (
            '' === $path
            || false !== strpos($path, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $path)
            || preg_match('/^(?:[A-Za-z][A-Za-z0-9+.-]*:|\/\/)/', $path)
        ) {
            return '';
        }

        return $path;
    }

    private static function cleanup_import_package(array $package): void
    {
        foreach ((array) ($package['cleanup_dirs'] ?? []) as $dir) {
            self::delete_directory((string) $dir);
        }
    }

    private static function delete_directory(string $dir): void
    {
        if ('' === $dir || ! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    private static function ensure_filesystem_chmod_constants(): void
    {
        if (! defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777) | 0755);
        }

        if (! defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777) | 0644);
        }
    }

    public static function render_markdown(string $markdown): string
    {
        $markdown = self::sanitize_markdown_meta($markdown);
        $markdown = self::extract_front_matter($markdown)['body'];
        $html = self::render_blocks($markdown);

        return wp_kses_post($html);
    }

    private static function parse_front_matter(string $markdown): array
    {
        return self::extract_front_matter($markdown)['data'];
    }

    private static function extract_front_matter(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        if ([] === $lines || '---' !== trim($lines[0])) {
            return [
                'body' => $markdown,
                'data' => [],
            ];
        }

        $line_count = count($lines);
        for ($i = 1; $i < $line_count; $i++) {
            if ('---' === trim($lines[$i])) {
                $front_matter = implode("\n", array_slice($lines, 1, $i - 1));

                return [
                    'body' => implode("\n", array_slice($lines, $i + 1)),
                    'data' => self::parse_front_matter_block($front_matter),
                ];
            }
        }

        return [
            'body' => $markdown,
            'data' => [],
        ];
    }

    private static function parse_front_matter_block(string $front_matter): array
    {
        $data = [];
        $current_key = null;

        foreach (explode("\n", $front_matter) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/u', $line, $matches)) {
                $key = sanitize_key($matches[1]);
                $value = trim($matches[2]);

                if ('' === $value) {
                    $data[$key] = [];
                    $current_key = $key;
                    continue;
                }

                $data[$key] = self::parse_front_matter_scalar($value);
                $current_key = null;
                continue;
            }

            if (null !== $current_key && preg_match('/^\s*-\s*(.*)$/u', $line, $matches)) {
                $data[$current_key][] = self::parse_front_matter_scalar(trim($matches[1]));
            }
        }

        return $data;
    }

    private static function parse_front_matter_scalar(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (('"' === $quote || "'" === $quote) && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
                if ('"' === $quote) {
                    $value = stripcslashes($value);
                }
            }
        }

        return wp_check_invalid_utf8($value);
    }

    private static function build_post_update_from_front_matter(int $post_id, string $html, array $front_matter): array
    {
        $post_update = self::build_post_fields_from_front_matter($html, $front_matter);
        $post_update['ID'] = $post_id;

        return $post_update;
    }

    private static function build_post_fields_from_front_matter(string $html, array $front_matter): array
    {
        $post_fields = [
            'post_content' => $html,
        ];

        $title = self::get_front_matter_string($front_matter, 'title');
        if ('' !== $title) {
            $post_fields['post_title'] = wp_strip_all_tags($title);
        }

        $slug = self::get_front_matter_string($front_matter, 'slug');
        if ('' !== $slug) {
            $post_fields['post_name'] = sanitize_title($slug);
        }

        $excerpt = self::get_front_matter_string($front_matter, 'excerpt');
        if ('' !== $excerpt) {
            $post_fields['post_excerpt'] = wp_strip_all_tags($excerpt);
        }

        $published_at = self::get_front_matter_string($front_matter, 'published_at');
        $post_date = self::parse_front_matter_date($published_at);
        if ('' !== $post_date) {
            $post_fields['post_date'] = $post_date;
            $post_fields['post_date_gmt'] = get_gmt_from_date($post_date);
        }

        $status = self::get_front_matter_string($front_matter, 'status');
        if ('' !== $status) {
            $status = sanitize_key($status);
            if (in_array($status, ['draft', 'publish', 'pending', 'private', 'future'], true)) {
                $post_fields['post_status'] = $status;
            }
        }

        return $post_fields;
    }

    private static function sync_front_matter_terms(int $post_id, array $front_matter): void
    {
        $categories = self::get_front_matter_list($front_matter, 'taxonomy_category');
        if ([] !== $categories && taxonomy_exists('category')) {
            wp_set_object_terms($post_id, $categories, 'category', false);
        }

        $tags = self::get_front_matter_list($front_matter, 'taxonomy_post_tag');
        if ([] !== $tags && taxonomy_exists('post_tag')) {
            wp_set_object_terms($post_id, $tags, 'post_tag', false);
        }
    }

    private static function get_front_matter_string(array $front_matter, string $key): string
    {
        if (! isset($front_matter[$key]) || is_array($front_matter[$key])) {
            return '';
        }

        return trim((string) $front_matter[$key]);
    }

    private static function get_front_matter_list(array $front_matter, string $key): array
    {
        if (! isset($front_matter[$key]) || ! is_array($front_matter[$key])) {
            return [];
        }

        $values = array_map(static function ($value): string {
            return trim(wp_strip_all_tags((string) $value));
        }, $front_matter[$key]);

        return array_values(array_filter($values, static function (string $value): bool {
            return '' !== $value;
        }));
    }

    private static function parse_front_matter_date(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }

        $timezone = wp_timezone();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } else {
            try {
                $date = new DateTimeImmutable($value, $timezone);
            } catch (Exception $exception) {
                return '';
            }
        }

        if (! $date instanceof DateTimeImmutable) {
            return '';
        }

        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    }

    /**
     * 轻量 Markdown 渲染器，覆盖文章写作中最常见的块级语法。
     */
    private static function render_blocks(string $markdown): string
    {
        $lines = preg_split("/\n/u", $markdown);
        if (! is_array($lines)) {
            return '';
        }

        $html = [];
        $line_count = count($lines);

        for ($i = 0; $i < $line_count; $i++) {
            $line = $lines[$i];

            if ('' === trim($line)) {
                continue;
            }

            if (preg_match('/^\s*```([A-Za-z0-9_-]+)?\s*$/u', $line, $matches)) {
                $language = isset($matches[1]) ? sanitize_html_class($matches[1]) : '';
                $code_lines = [];
                $i++;

                while ($i < $line_count && ! preg_match('/^\s*```\s*$/u', $lines[$i])) {
                    $code_lines[] = $lines[$i];
                    $i++;
                }

                $class_attr = '' !== $language ? ' class="language-' . esc_attr($language) . '"' : '';
                $html[] = '<pre><code' . $class_attr . '>' . esc_html(implode("\n", $code_lines)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+)$/u', $line, $matches)) {
                $level = strlen($matches[1]);
                $html[] = '<h' . $level . '>' . self::parse_inline(trim($matches[2])) . '</h' . $level . '>';
                continue;
            }

            if ($i + 1 < $line_count && self::is_table_separator_line($lines[$i + 1]) && self::is_table_row_line($line)) {
                $header_cells = self::split_table_row($line);
                $rows = [];
                $i += 2;

                while ($i < $line_count && self::is_table_row_line($lines[$i])) {
                    $rows[] = self::split_table_row($lines[$i]);
                    $i++;
                }

                $i--;
                $html[] = self::render_table($header_cells, $rows);
                continue;
            }

            if (preg_match('/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/u', $line)) {
                $html[] = '<hr />';
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/u', $line)) {
                $quote_lines = [];

                while ($i < $line_count && preg_match('/^\s*>\s?(.*)$/u', $lines[$i], $matches)) {
                    $quote_lines[] = $matches[1];
                    $i++;
                }

                $i--;
                $html[] = '<blockquote>' . self::render_blocks(implode("\n", $quote_lines)) . '</blockquote>';
                continue;
            }

            if (self::is_unordered_list_line($line)) {
                $items = [];

                while ($i < $line_count && self::is_unordered_list_line($lines[$i], $matches)) {
                    $items[] = '<li>' . self::parse_inline(trim($matches[1])) . '</li>';
                    $i++;
                }

                $i--;
                $html[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            if (self::is_ordered_list_line($line)) {
                $items = [];

                while ($i < $line_count && self::is_ordered_list_line($lines[$i], $matches)) {
                    $items[] = '<li>' . self::parse_inline(trim($matches[1])) . '</li>';
                    $i++;
                }

                $i--;
                $html[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            $paragraph_lines = [];
            while ($i < $line_count && '' !== trim($lines[$i]) && ! self::is_block_start($lines[$i])) {
                $paragraph_lines[] = trim($lines[$i]);
                $i++;
            }

            $i--;
            if ([] !== $paragraph_lines) {
                $html[] = '<p>' . self::parse_inline(implode(' ', $paragraph_lines)) . '</p>';
            }
        }

        return implode("\n\n", $html);
    }

    /**
     * 轻量处理行内语法，并先转义普通文本，避免把 Markdown 源里的危险 HTML 写进正文。
     */
    private static function parse_inline(string $text): string
    {
        $tokens = [];
        $add_token = static function (string $html) use (&$tokens): string {
            $key = '%%MDHTMLSYNCTOKEN' . count($tokens) . '%%';
            $tokens[$key] = $html;

            return $key;
        };

        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $matches) use ($add_token): string {
            return $add_token('<code>' . esc_html($matches[1]) . '</code>');
        }, $text);

        $link_label_pattern = '((?:[^\[\]]|\[[^\[\]]*\])*)';

        $text = preg_replace_callback('/!\[' . $link_label_pattern . '\]\((\S+?)(?:\s+"([^"]*)")?\)/u', static function (array $matches) use ($add_token): string {
            $url = self::sanitize_markdown_url($matches[2]);
            if ('' === $url) {
                return $matches[0];
            }

            $title = isset($matches[3]) && '' !== $matches[3] ? ' title="' . esc_attr($matches[3]) . '"' : '';

            return $add_token('<img src="' . $url . '" alt="' . esc_attr($matches[1]) . '"' . $title . ' />');
        }, $text);

        $text = preg_replace_callback('/(?<!!)\[' . $link_label_pattern . '\]\((\S+?)(?:\s+"([^"]*)")?\)/u', static function (array $matches) use ($add_token): string {
            $url = self::sanitize_markdown_url($matches[2]);
            if ('' === $url) {
                return $matches[0];
            }

            $title = isset($matches[3]) && '' !== $matches[3] ? ' title="' . esc_attr($matches[3]) . '"' : '';

            return $add_token('<a href="' . $url . '"' . $title . '>' . esc_html($matches[1]) . '</a>');
        }, $text);

        if (function_exists('get_shortcode_regex')) {
            $shortcode_pattern = get_shortcode_regex();
            if ('' !== $shortcode_pattern) {
                $text = preg_replace_callback('/' . $shortcode_pattern . '/su', static function (array $matches) use ($add_token): string {
                    return $add_token(str_replace(['<', '>'], ['&lt;', '&gt;'], $matches[0]));
                }, $text);
            }
        }

        $text = esc_html($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/su', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/~~(.+?)~~/su', '<del>$1</del>', $text);
        $text = (string) preg_replace('/(^|[^\*])\*([^\*\n]+)\*(?!\*)/su', '$1<em>$2</em>', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9_])_([^_\n]+)_(?![A-Za-z0-9_])/su', '<em>$1</em>', $text);

        return strtr($text, $tokens);
    }

    private static function sanitize_markdown_url(string $url): string
    {
        $url = trim(wp_check_invalid_utf8($url));
        $url = str_replace(["\r", "\n", "\t"], '', $url);

        if ('' === $url) {
            return '';
        }

        if (preg_match('/^(?:[A-Za-z][A-Za-z0-9+.-]*:|\/\/)/', $url)) {
            return esc_url($url);
        }

        if (wp_kses_bad_protocol($url, wp_allowed_protocols()) !== $url) {
            return '';
        }

        return esc_attr($url);
    }

    private static function is_table_row_line(string $line): bool
    {
        return false !== strpos(trim($line), '|');
    }

    private static function is_table_separator_line(string $line): bool
    {
        $cells = self::split_table_row($line);
        if (count($cells) < 2) {
            return false;
        }

        foreach ($cells as $cell) {
            if (1 !== preg_match('/^:?-{3,}:?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    private static function split_table_row(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');

        return array_map('trim', explode('|', $line));
    }

    private static function render_table(array $header_cells, array $rows): string
    {
        $html = ['<table>', '<thead><tr>'];

        foreach ($header_cells as $cell) {
            $html[] = '<th>' . self::parse_inline($cell) . '</th>';
        }

        $html[] = '</tr></thead>';
        $html[] = '<tbody>';

        foreach ($rows as $row) {
            $html[] = '<tr>';
            foreach ($header_cells as $index => $_cell) {
                $html[] = '<td>' . self::parse_inline($row[$index] ?? '') . '</td>';
            }
            $html[] = '</tr>';
        }

        $html[] = '</tbody>';
        $html[] = '</table>';

        return implode('', $html);
    }

    private static function is_block_start(string $line): bool
    {
        return (bool) (
            preg_match('/^\s*```/u', $line)
            || preg_match('/^\s{0,3}#{1,6}\s+/u', $line)
            || preg_match('/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/u', $line)
            || preg_match('/^\s*>\s?/u', $line)
            || self::is_unordered_list_line($line)
            || self::is_ordered_list_line($line)
        );
    }

    private static function is_unordered_list_line(string $line, ?array &$matches = null): bool
    {
        return 1 === preg_match('/^\s*[-*+]\s+(.+)$/u', $line, $matches);
    }

    private static function is_ordered_list_line(string $line, ?array &$matches = null): bool
    {
        return 1 === preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $matches);
    }

    private static function get_supported_post_types(): array
    {
        $post_types = get_post_types(['show_ui' => true], 'names');
        if (! is_array($post_types)) {
            return [];
        }

        return array_values(array_filter($post_types, static function (string $post_type): bool {
            return 'attachment' !== $post_type && post_type_supports($post_type, 'editor');
        }));
    }
}

MD_HTML_Sync_Plugin::init();
