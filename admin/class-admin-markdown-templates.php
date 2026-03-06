<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX handlers for markdown templates separated from admin settings.
 */
if (! class_exists('Ajax_Utils')) {
    require_once dirname(__DIR__) . '/includes/class-ajax-utils.php';
}

class Admin_Markdown_Templates {
    public static function ajax_get_markdown_template() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_markdown_template_nonce', 'nonce', 'manage_options');

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        if (empty($key)) {
            Ajax_Utils::error([ 'message' => 'Invalid key' ], 400);
        }

        $templates = GGC_Options::get_markdown_templates();
        if (!is_array($templates)) {
            $templates = [];
        }

        if (!isset($templates[$key])) {
            Ajax_Utils::error([ 'message' => 'Template not found' ], 404);
        }

        $tpl = $templates[$key];
        $image_id = absint($tpl['image_id'] ?? 0);
        $preview_url = '';
        if ($image_id) {
            $img = wp_get_attachment_image_src($image_id, 'thumbnail');
            if ($img && !empty($img[0])) $preview_url = $img[0];
        } elseif (!empty($tpl['image_url'])) {
            $preview_url = $tpl['image_url'];
        }

        Ajax_Utils::success([
            'key' => $key,
            'title' => $tpl['title'] ?? '',
            'markdown' => $tpl['markdown'] ?? '',
            'image_id' => $image_id,
            'image_url' => $tpl['image_url'] ?? '',
            'random_enabled' => !empty($tpl['random_enabled']),
            'preview_url' => $preview_url,
        ]);
    }

    public static function ajax_list_markdown_templates() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_markdown_template_nonce', 'nonce', 'manage_options');

        $templates = GGC_Options::get_markdown_templates();
        if (!is_array($templates)) {
            $templates = [];
        }

        $list = [];
        foreach ($templates as $key => $tpl) {
            $label = !empty($tpl['title']) ? $tpl['title'] : $key;
            $list[] = [
                'key' => $key,
                'label' => $label . ' (' . $key . ')',
            ];
        }

        Ajax_Utils::success($list);
    }

    public static function ajax_save_markdown_template() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_markdown_template_nonce', 'nonce', 'manage_options');

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        if (empty($key)) {
            Ajax_Utils::error([ 'message' => 'Invalid key' ], 400);
        }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $markdown = isset($_POST['markdown']) ? sanitize_textarea_field(wp_unslash($_POST['markdown'])) : '';
        $image_id = isset($_POST['image_id']) ? absint($_POST['image_id']) : 0;
        $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
        $random_enabled = !empty($_POST['random_enabled']) ? 1 : 0;

        $templates = GGC_Options::get_markdown_templates();
        if (!is_array($templates)) $templates = [];

        $templates[$key] = [
            'key' => $key,
            'title' => $title,
            'markdown' => $markdown,
            'image_id' => $image_id,
            'image_url' => $image_url,
            'random_enabled' => $random_enabled,
        ];

        update_option('ggc_markdown_templates', $templates);

        Ajax_Utils::success([ 'saved' => true, 'key' => $key ]);
    }

    public static function ajax_delete_markdown_template() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_markdown_template_nonce', 'nonce', 'manage_options');

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        if (empty($key)) {
            Ajax_Utils::error([ 'message' => 'Invalid key' ], 400);
        }

        $templates = GGC_Options::get_markdown_templates();
        if (!is_array($templates)) $templates = [];

        if (isset($templates[$key])) {
            unset($templates[$key]);
            update_option('ggc_markdown_templates', $templates);
        }

        Ajax_Utils::success([ 'deleted' => true ]);
    }
}
