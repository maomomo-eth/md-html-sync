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

    private static $is_syncing = false;

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_post_meta']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post', [__CLASS__, 'save_post'], 20, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_styles']);
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

    public static function sanitize_markdown_meta($value): string
    {
        $markdown = is_string($value) ? $value : '';
        $markdown = wp_check_invalid_utf8($markdown);

        return str_replace(["\r\n", "\r"], "\n", $markdown);
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
        $post_update = [
            'ID' => $post_id,
            'post_content' => $html,
        ];

        $title = self::get_front_matter_string($front_matter, 'title');
        if ('' !== $title) {
            $post_update['post_title'] = wp_strip_all_tags($title);
        }

        $slug = self::get_front_matter_string($front_matter, 'slug');
        if ('' !== $slug) {
            $post_update['post_name'] = sanitize_title($slug);
        }

        $excerpt = self::get_front_matter_string($front_matter, 'excerpt');
        if ('' !== $excerpt) {
            $post_update['post_excerpt'] = wp_strip_all_tags($excerpt);
        }

        $published_at = self::get_front_matter_string($front_matter, 'published_at');
        $post_date = self::parse_front_matter_date($published_at);
        if ('' !== $post_date) {
            $post_update['post_date'] = $post_date;
            $post_update['post_date_gmt'] = get_gmt_from_date($post_date);
        }

        return $post_update;
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
