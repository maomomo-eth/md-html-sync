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

        $html = self::render_markdown($markdown);
        $current_content = (string) get_post_field('post_content', $post_id, 'raw');

        if ($current_content === $html) {
            return;
        }

        self::$is_syncing = true;
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $html,
        ]);
        self::$is_syncing = false;
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
        $html = self::render_blocks($markdown);

        return wp_kses_post($html);
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
            $key = '%%MD_HTML_SYNC_TOKEN_' . count($tokens) . '%%';
            $tokens[$key] = $html;

            return $key;
        };

        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $matches) use ($add_token): string {
            return $add_token('<code>' . esc_html($matches[1]) . '</code>');
        }, $text);

        $text = preg_replace_callback('/!\[([^\]]*)\]\((\S+?)(?:\s+"([^"]*)")?\)/u', static function (array $matches) use ($add_token): string {
            $url = esc_url($matches[2]);
            if ('' === $url) {
                return $matches[0];
            }

            $title = isset($matches[3]) && '' !== $matches[3] ? ' title="' . esc_attr($matches[3]) . '"' : '';

            return $add_token('<img src="' . $url . '" alt="' . esc_attr($matches[1]) . '"' . $title . ' />');
        }, $text);

        $text = preg_replace_callback('/(?<!!)\[([^\]]+)\]\((\S+?)(?:\s+"([^"]*)")?\)/u', static function (array $matches) use ($add_token): string {
            $url = esc_url($matches[2]);
            if ('' === $url) {
                return $matches[0];
            }

            $title = isset($matches[3]) && '' !== $matches[3] ? ' title="' . esc_attr($matches[3]) . '"' : '';

            return $add_token('<a href="' . $url . '"' . $title . '>' . esc_html($matches[1]) . '</a>');
        }, $text);

        $text = esc_html($text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/su', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/~~(.+?)~~/su', '<del>$1</del>', $text);
        $text = (string) preg_replace('/(^|[^\*])\*([^\*\n]+)\*(?!\*)/su', '$1<em>$2</em>', $text);
        $text = (string) preg_replace('/(^|[^_])_([^_\n]+)_(?!_)/su', '$1<em>$2</em>', $text);

        return strtr($text, $tokens);
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
