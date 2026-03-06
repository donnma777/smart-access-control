<?php
// custom-crawler-control\admin\class-admin-settings.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// load traits for auxiliary large sections that are easier to manage on
// their own files. both usage and diagnostic tools live in separate traits.
require_once __DIR__ . '/class-usage.php';
require_once __DIR__ . '/class-diagnostic-tools.php';
// common admin helpers
require_once __DIR__ . '/class-admin-utils.php';
// shared option helpers
require_once dirname(__DIR__) . '/includes/class-option-utils.php';
// display helpers
require_once dirname(__DIR__) . '/includes/class-display-utils.php';
// Ajax helpers
require_once dirname(__DIR__) . '/includes/class-ajax-utils.php';
// markdown template AJAX handlers
require_once __DIR__ . '/class-admin-markdown-templates.php';
// IP range admin helpers
require_once __DIR__ . '/class-admin-ip-ranges.php';

class Custom_Admin_Settings {

    use Custom_Admin_Usage, Custom_Admin_Diagnostic;

    protected static $instance = null;

    // タブの定義（定数化）
    private const TABS = [
        'general'  => 'グローバル設定',
        'markdown' => 'マークダウン',
        'page_eval' => 'ページの評価',
        'bots'     => 'User-Agent 定義1',
        'patterns' => 'User-Agent 定義2',
        'ips'      => 'IPアドレス範囲1',
        'ips2'     => 'IPアドレス範囲2',
        'tools'    => '診断ツール',
        'usage'    => 'プラグインの使い方',
    ];

    private function __construct() {
        // メニュー登録
        add_action('admin_menu', [ $this, 'add_settings_page' ]);
        // 設定登録とサニタイズ
        add_action('admin_init', [ $this, 'register_settings' ]);
        // 設定保存後の Cron スケジュールチェックをフック
        add_action('update_option_ggc_ip_update_frequency', ['Custom_Crawler_Core', 'ip_update_schedule_check'], 10, 0);
        // JS/CSSのエンキュー (設定ページ用JSのみ)
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ]);
        // IP更新強制実行フック
        add_action('admin_action_run_ggc_ip_update', [ $this, 'admin_action_run_ggc_ip_update' ]);
        // 全データクリア
        add_action('admin_post_ggc_clear_all_data', [ $this, 'admin_action_clear_all_data' ]);
        // 行ごとの保存は UX を単純化するため撤廃（フルページ保存を利用）
        // (デバッグ用 AJAX は削除済み)
        // IP更新通知
        add_action('admin_notices', [ $this, 'admin_notice_ip_update' ]);
        add_action('admin_notices', [ $this, 'admin_notice_manual_ip_update_success' ]);
        // 設定保存時に不正なIP入力があれば通知する
        add_action('admin_notices', [ $this, 'admin_notice_invalid_ip_ranges_on_save' ]);
        // リセット完了通知
        add_action('admin_notices', [ $this, 'admin_notice_reset_success' ]);
        add_action('admin_notices', [ $this, 'admin_notice_clear_all_success' ]);
        // AJAX endpoint for async update
        add_action('wp_ajax_ggc_run_ip_update', [ $this, 'ajax_run_ip_update' ]);
        // AJAX endpoint for parsing a provided source_url
        add_action('wp_ajax_ggc_parse_ip_source', [ $this, 'ajax_parse_ip_source' ]);

        // Markdown templates (AJAX) - delegated to Admin_Markdown_Templates
        add_action('wp_ajax_ggc_get_markdown_template', [ 'Admin_Markdown_Templates', 'ajax_get_markdown_template' ]);
        add_action('wp_ajax_ggc_save_markdown_template', [ 'Admin_Markdown_Templates', 'ajax_save_markdown_template' ]);
        add_action('wp_ajax_ggc_delete_markdown_template', [ 'Admin_Markdown_Templates', 'ajax_delete_markdown_template' ]);
        // used by JS to rebuild template select list after edits
        add_action('wp_ajax_ggc_list_markdown_templates', [ 'Admin_Markdown_Templates', 'ajax_list_markdown_templates' ]);

        // おすすめ設定インポート
        add_action('admin_action_ggc_import_default_settings', [ $this, 'admin_action_import_default_settings' ]);
        add_action('admin_notices', [ $this, 'admin_notice_import_success' ]);
    }

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * JS/CSSのエンキュー (設定ページ用JSのみ)
     */
    public function enqueue_admin_scripts($hook) {
        // Prefer using the $hook provided by WP for reliability
        if ($hook !== 'settings_page_ggc-crawler-definitions') return;

        wp_enqueue_media();

        $plugin_dir = plugin_dir_path(dirname(__DIR__) . '/custom-crawler-control.php');
        $plugin_url = plugin_dir_url(dirname(__DIR__) . '/custom-crawler-control.php');
        $js_asset_path = $plugin_dir . 'js/admin-settings.js';
        $css_asset_path = $plugin_dir . 'css/admin-settings.css';

        wp_enqueue_script(
            'ggc-settings-js',
            $plugin_url . 'js/admin-settings.js',
            ['jquery'],
            file_exists($js_asset_path) ? filemtime($js_asset_path) : '4.3.4', // Cache-busting
            true
        );
        wp_localize_script('ggc-settings-js', 'ggcSettings', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'run_update_nonce' => wp_create_nonce('ggc_run_update_nonce'),
            'markdown_nonce' => wp_create_nonce('ggc_markdown_template_nonce'),
            'page_eval_messages' => ggc_get_default_page_eval_messages(),
        ]);

        wp_enqueue_style(
            'ggc-admin-settings-css',
            $plugin_url . 'css/admin-settings.css',
            [],
            file_exists($css_asset_path) ? filemtime($css_asset_path) : '4.3.4'
        );

    }

    /**
     * 管理画面メニューに設定ページを追加
     */
    public function add_settings_page() {
        add_options_page(
            'アクセス制御 設定',
            'アクセス制御',
            'manage_options',
            'ggc-crawler-definitions',
            [ $this, 'settings_page_html' ]
        );
    }

    /**
     * 現在のタブを取得
     */
    private function get_current_tab() {
        return isset($_GET['tab']) && array_key_exists($_GET['tab'], self::TABS) ? $_GET['tab'] : 'general';
    }

    /**
     * 設定の登録、セクション、フィールドの定義
     */
    public function register_settings() {
        $this->register_general_settings();
        $this->register_user_agent_settings();
        $this->register_ip_settings();
        $this->register_browser_patterns_settings();
        $this->register_ip2_settings();
        $this->register_markdown_settings();
        $this->register_page_eval_settings();
    }

    // --- 各セクションごとに分割 ---
    private function register_general_settings() {
        register_setting('ggc_general_option_group', 'ggc_global_media_eval_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_media_display_mode', [
            'sanitize_callback' => function($value) {
                $value = sanitize_text_field($value);
                // alt_replace で代替テキストが空の場合は通常表示に変換
                if ($value === 'alt_replace') {
                    $alt_text = isset($_POST['ggc_alt_fixed_text'])
                        ? sanitize_text_field($_POST['ggc_alt_fixed_text'])
                        : get_option('ggc_alt_fixed_text', '');
                    if (empty(trim($alt_text))) {
                        return 'normal';
                    }
                }
                return $value;
            },
            'default' => 'normal',
        ]);
        register_setting('ggc_general_option_group', 'ggc_global_featured_display_mode', [
            'sanitize_callback' => function($value) {
                $value = sanitize_text_field($value);
                // alt_replace で代替テキストが空の場合は通常表示に変換
                if ($value === 'alt_replace') {
                    $alt_text = isset($_POST['ggc_alt_fixed_text_featured'])
                        ? sanitize_text_field($_POST['ggc_alt_fixed_text_featured'])
                        : get_option('ggc_alt_fixed_text_featured', '');
                    if (empty(trim($alt_text))) {
                        return 'normal';
                    }
                }
                return $value;
            },
            'default' => 'normal',
        ]);
        register_setting('ggc_general_option_group', 'ggc_ip_update_frequency', ['sanitize_callback' => [ $this, 'sanitize_ip_update_frequency' ], 'default' => 'daily']);
        add_settings_section(
            'ggc_general_settings',
            'グローバル設定',
            [ $this, 'render_general_settings_section' ],
            'ggc_tab_general'
        );
        register_setting('ggc_general_option_group', 'ggc_global_user_agent_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_evaluation', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_media_user_agent_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_media_ip_evaluation', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        // ggc_global_featured_visible_when_hidden は廃止（ggc_global_featured_display_mode に統合）
        register_setting('ggc_general_option_group', 'ggc_global_page_eval_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_page_user_agent_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_page_ip_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_selected_crawlers', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_selected_patterns', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_selected_ips', ['sanitize_callback' => function($v){ return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($v, 'ggc_global_selected_ips'); }, 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_selected_ips_2', ['sanitize_callback' => function($v){ return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($v, 'ggc_global_selected_ips_2'); }, 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_crawlers', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_patterns', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_ips', ['sanitize_callback' => function($v){ return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($v, 'ggc_global_media_selected_ips'); }, 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_ips_2', ['sanitize_callback' => function($v){ return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($v, 'ggc_global_media_selected_ips_2'); }, 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_alt_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_alt_fixed_text', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_alt_fixed_text_featured', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_markdown_replace_enabled', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'off']);
        register_setting('ggc_general_option_group', 'ggc_markdown_global_template_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'select']);
        register_setting('ggc_general_option_group', 'ggc_markdown_global_template_key', ['sanitize_callback' => [ $this, 'sanitize_global_markdown_template_key' ], 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_markdown_selected_crawlers', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_markdown_selected_patterns', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_markdown_selected_ips', [
            'sanitize_callback' => function($input) { return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($input, 'ggc_global_markdown_selected_ips'); },
            'default' => []
        ]);
        register_setting('ggc_general_option_group', 'ggc_global_markdown_selected_ips_2', [
            'sanitize_callback' => function($input) { return Custom_Admin_Settings::sanitize_global_selected_ips_preserve($input, 'ggc_global_markdown_selected_ips_2'); },
            'default' => []
        ]);
        register_setting('ggc_general_option_group', 'ggc_markdown_ua_eval', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_markdown_ip_eval', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_global_ua_redirect_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'block']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_redirect_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'block']);
        register_setting('ggc_general_option_group', 'ggc_global_ua_redirect_url', ['sanitize_callback' => 'esc_url_raw', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_redirect_url', ['sanitize_callback' => 'esc_url_raw', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_ua_block_message_key', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_block_message_key', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_ua_block_message', ['sanitize_callback' => 'sanitize_textarea_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_block_message', ['sanitize_callback' => 'sanitize_textarea_field', 'default' => '']);
    }

    private function register_user_agent_settings() {
        register_setting('ggc_bots_option_group', 'ggc_crawler_definitions', ['sanitize_callback' => [ $this, 'sanitize_crawler_definitions' ], 'default' => []]);
        add_settings_section(
            'ggc_crawler_definitions',
            'User-Agent 定義リスト1',
            [ $this, 'render_crawler_definitions_section' ],
            'ggc_tab_bots'
        );
    }

    private function register_ip_settings() {
        register_setting('ggc_ips_option_group', 'ggc_ip_range_definitions', ['sanitize_callback' => [ $this, 'sanitize_ip_range_definitions' ], 'default' => []]);
        add_settings_section(
            'ggc_ip_range_definitions',
            'IPアドレス範囲 定義リスト1',
            [ $this, 'render_ip_range_definitions_section' ],
            'ggc_tab_ips'
        );
    }

    private function register_browser_patterns_settings() {
        register_setting('ggc_patterns_option_group', 'ggc_browser_block_patterns', ['sanitize_callback' => [ $this, 'sanitize_browser_block_patterns' ], 'default' => []]);
        add_settings_section(
            'ggc_browser_block_patterns',
            'User-Agent 定義リスト2',
            [ $this, 'render_browser_block_patterns_section' ],
            'ggc_tab_patterns'
        );
    }

    private function register_ip2_settings() {
        register_setting('ggc_ips2_option_group', 'ggc_ip_range_definitions_2', ['sanitize_callback' => [ $this, 'sanitize_ip_range_definitions_2' ], 'default' => []]);
        add_settings_section(
            'ggc_ip_range_definitions_2',
            'IPアドレス範囲 定義リスト2',
            [ $this, 'render_ip_range_definitions_section_2' ],
            'ggc_tab_ips2'
        );
    }

    private function register_markdown_settings() {
        // ダミーフィールド（セクションを必ず表示させるため）
        add_settings_field(
            'ggc_markdown_templates_dummy',
            '',
            function () { echo '<div style="display:none"></div>'; },
            'ggc_tab_markdown',
            'ggc_markdown_templates_section'
        );
        register_setting('ggc_markdown_option_group', 'ggc_markdown_templates', ['sanitize_callback' => [ $this, 'sanitize_markdown_templates' ], 'default' => []]);
        add_settings_section(
            'ggc_markdown_templates_section',
            'マークダウンテンプレート定義',
            [ $this, 'render_markdown_templates_section' ],
            'ggc_tab_markdown'
        );
    }

    private function register_page_eval_settings() {
        register_setting('ggc_page_eval_option_group', 'ggc_page_eval_messages', ['sanitize_callback' => [ $this, 'sanitize_page_eval_messages' ], 'default' => []]);
        add_settings_section(
            'ggc_page_eval_messages_section',
            'ページ評価メッセージ定義',
            [ $this, 'render_page_eval_messages_section' ],
            'ggc_tab_page_eval'
        );
    }

    // --------------------------------------------------------
    // サニタイズ関数
    // --------------------------------------------------------

    public function sanitize_crawler_definitions($input) {
        $defaults = ggc_get_default_bots();
        return $this->sanitize_ua_definitions_common($input, $defaults, ['key','label','group_label','description','uas']);
    }

    // Sanitize functions for global selected lists
    /**
     * 配列のキーをsanitizeし、空要素を除去して返す共通関数
     */
    private function sanitize_array_keys($input) {
        if (!is_array($input)) return [];
        return array_values(array_map('sanitize_key', array_filter($input)));
    }

    public function sanitize_global_selected_crawlers($input) {
        return $this->sanitize_array_keys($input);
    }

    public function sanitize_global_selected_ips($input) {
        return $this->sanitize_array_keys($input);
    }

    /**
     * Sanitizer for the global markdown template key that enforces selection when
     * UA/IP evaluation is active and the mode requires a specific template.
     */
    public function sanitize_global_markdown_template_key($input) {
        $input = sanitize_text_field($input);
        $md_opts = GGC_Options::get_markdown_options();
        $mdMode = $md_opts['replace_enabled'];
        $ua_eval = $md_opts['ua_eval'];
        $ip_eval = $md_opts['ip_eval'];

        if ($mdMode === 'all' && ($ua_eval !== 'none' || $ip_eval !== 'none')) {
            $template_mode = $md_opts['global_template_mode'];
            $mode_no_raw = preg_replace('/_raw$/', '', $template_mode);
            if ($mode_no_raw === 'select' && $input === '') {
                add_settings_error(
                    'ggc_general_option_group',
                    'ggc_md_template_key_required',
                    'テンプレートを選択してください。',
                    'error'
                );
                // preserve existing value to avoid clearing
                return GGC_Options::get_markdown_global_template_key();
            }
        }

        return $input;
    }

    // Preserve existing option value when POST omits the field (prevents accidental clearing)
    public static function sanitize_global_selected_ips_preserve($input, $option_name) {
        $present_key = $option_name . '_present';
        // フォームで存在確認された場合は空配列返却
        if ( isset($_POST[$present_key]) ) {
            if (!is_array($input)) return [];
            return Custom_Admin_Settings::sanitize_array_keys_static($input);
        }
        // POSTにフィールドがなければDB値を維持
        if (!is_array($input)) {
            $existing = GGC_Options::get_array_option($option_name, []);
            return is_array($existing) ? Custom_Admin_Settings::sanitize_array_keys_static($existing) : [];
        }
        return Custom_Admin_Settings::sanitize_array_keys_static($input);
    }

    /**
     * static用 共通sanitize関数
     */
    private static function sanitize_array_keys_static($input) {
        if (!is_array($input)) return [];
        return array_values(array_map('sanitize_key', array_filter($input)));
    }

    public function sanitize_markdown_templates($input) {
        $templates_cleared = GGC_Options::get_markdown_templates_cleared();
        // If no data submitted (e.g. autosave form submission without templates),
        // preserve existing option to avoid accidental clearing.
        if (!is_array($input)) {
            $existing = GGC_Options::get_markdown_templates();
            return is_array($existing) ? $existing : [];
        }

        $new_input = [];
        foreach ($input as $key => $tpl) {
            if (!is_array($tpl)) continue;

            $raw_key = $tpl['key'] ?? $key;
            $new_key = sanitize_key($raw_key);
            if (empty($new_key)) continue;

            // 必ず配列のキーと'tpl["key"]'を一致させる
            $tpl_data = [
                'key' => $new_key,
                'title' => sanitize_text_field($tpl['title'] ?? ''),
                'markdown' => sanitize_textarea_field($tpl['markdown'] ?? ''),
                'image_url' => esc_url_raw($tpl['image_url'] ?? ''),
                'image_id' => absint($tpl['image_id'] ?? 0),
                'random_enabled' => !empty($tpl['random_enabled']) ? 1 : 0,
            ];
            $new_input[$new_key] = $tpl_data;
        }

        if (empty($new_input)) {
            return [];
        }

        return $new_input;
    }

    public function sanitize_page_eval_messages($input) {
        if (!is_array($input)) return [];

        $new_input = [];
        foreach ($input as $key => $row) {
            if (!is_array($row)) continue;

            $raw_key = $row['key'] ?? $key;
            $new_key = sanitize_key($raw_key);
            if (empty($new_key)) continue;

            $label = sanitize_text_field($row['label'] ?? '');
            $message = sanitize_textarea_field($row['message'] ?? '');

            if (empty($label) && empty($message)) {
                continue;
            }

            $status_code = isset($row['status_code']) ? intval($row['status_code']) : 403;
            if ($status_code < 400 || $status_code > 599) {
                $status_code = 403;
            }

            $new_input[$new_key] = [
                'label' => $label,
                'is_global' => !empty($row['is_global']) ? 1 : 0,
                'status_code' => $status_code,
                'message' => $message,
            ];
        }

        if (empty($new_input)) {
            return [];
        }

        return $new_input;
    }


    /**
     * IPアドレス範囲定義のサニタイズ
     */

    public function sanitize_ip_range_definitions($input) {
        return Admin_IP_Ranges::sanitize_definitions_1($input);
    }

    public function sanitize_browser_block_patterns($input) {
        $default = ggc_get_default_browser_patterns();
        return $this->sanitize_ua_definitions_common($input, $default, ['key','label','group_label','description','pattern']);
    }

    public function sanitize_default_control_active($input) {
        return in_array($input, ['yes', 'no']) ? $input : 'no';
    }

    public function sanitize_ip_update_frequency($input) {
        // allow disabling automatic updates and new frequencies
        return in_array($input, ['disabled', 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'biannually', 'annually']) ? $input : 'daily';
    }

    private function render_grouped_bots_checklist($selected, $name, $maxHeight = 240, $selected_patterns = null, $patterns_name = null) {
        $bots = Custom_Crawler_Core::get_allowable_bots();
        $grouped = [];
        foreach ($bots as $key => $b) {
            $group_label = $b['group_label'] ?? 'その他';
            if (!isset($grouped[$group_label])) $grouped[$group_label] = [];
            $grouped[$group_label][$key] = $b;
        }

        if (!is_array($selected)) $selected = [];
        $name_attr = esc_attr($name) . '[]';
        $name_key = sanitize_key($name);
        $has_patterns = !empty($patterns_name);
        $grouped_patterns = [];
        if ($has_patterns) {
            $patterns = Custom_Crawler_Core::get_browser_block_patterns();
            foreach ($patterns as $pkey => $pdef) {
                $group_label = $pdef['group_label'] ?? 'その他';
                if (!isset($grouped_patterns[$group_label])) $grouped_patterns[$group_label] = [];
                $grouped_patterns[$group_label][$pkey] = $pdef;
            }
        }
        if (!is_array($selected_patterns)) $selected_patterns = [];
        $patterns_name_attr = $has_patterns ? esc_attr($patterns_name) . '[]' : '';

        $render_group_items = function ($items, $selected_keys, $input_name_attr, $section_prefix) {
            foreach ($items as $glabel => $list) {
                $group_id = $section_prefix . '-group-' . sanitize_key($glabel);
                echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($group_id) . '">';
                echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
                echo '<strong>' . esc_html($glabel) . '</strong>';
                echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($group_id) . '"> [全選択/解除] </small>';
                echo '</h4>';
                echo '<div id="' . esc_attr($group_id) . '" class="ggc-settings-group-content open" style="display:block;">';
                foreach ($list as $key => $item) {
                    $label = $item['label'] ?? $key;
                    echo '<label class="ggc-item-label">';
                    echo '<input type="checkbox" name="' . $input_name_attr . '" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $selected_keys), true, false) . '>';
                    echo esc_html($label);
                    echo '</label>';
                }
                echo '</div>';
            }
        };

        $section_id_1 = $name_key . '-ua-def-1';
        $section_id_2 = $name_key . '-ua-def-2';

        echo '<div class="ggc-scroll-panel" style="max-height:' . intval($maxHeight) . 'px;">';

        echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($section_id_1) . '">';
        echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
        echo '<strong>User-Agent 定義1</strong>';
        echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($section_id_1) . '"> [全選択/解除] </small>';
        echo '</h4>';
        echo '<div id="' . esc_attr($section_id_1) . '" class="ggc-settings-group-content" style="display:none;">';
        if (empty($grouped)) {
            echo '<p class="description ggc-settings-desc">定義がありません。</p>';
        } else {
            $render_group_items($grouped, $selected, $name_attr, $section_id_1);
        }
        echo '</div>';


        if ($has_patterns) {
            // 全解除時も空配列をPOSTするためhidden inputを追加
            echo '<input type="hidden" name="' . esc_attr($patterns_name) . '[]" value="">';
            echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($section_id_2) . '">';
            echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
            echo '<strong>User-Agent 定義2</strong>';
            echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($section_id_2) . '"> [全選択/解除] </small>';
            echo '</h4>';
            echo '<div id="' . esc_attr($section_id_2) . '" class="ggc-settings-group-content" style="display:none;">';
            if (empty($grouped_patterns)) {
                echo '<p class="description ggc-settings-desc">定義がありません。</p>';
            } else {
                $render_group_items($grouped_patterns, $selected_patterns, $patterns_name_attr, $section_id_2);
            }
            echo '</div>';
        }

        echo '</div>';
    }

    private function render_grouped_ip_checklist($selected1, $selected2, $name1, $name2, $maxHeight = 240) {
        $ip_ranges_1 = GGC_Options::get_ip_range_definitions_1() ?: [];
        $ip_ranges_2 = GGC_Options::get_ip_range_definitions_2() ?: [];

        $grouped1 = [];
        foreach ($ip_ranges_1 as $k => $def) {
            $label = $def['group_label'] ?? 'その他';
            if (!isset($grouped1[$label])) $grouped1[$label] = [];
            $grouped1[$label][$k] = $def;
        }

        $grouped2 = [];
        foreach ($ip_ranges_2 as $k => $def) {
            $label = $def['group_label'] ?? 'その他';
            if (!isset($grouped2[$label])) $grouped2[$label] = [];
            $grouped2[$label][$k] = $def;
        }

        if (!is_array($selected1)) $selected1 = [];
        if (!is_array($selected2)) $selected2 = [];

        $name_attr1 = esc_attr($name1) . '[]';
        $name_attr2 = esc_attr($name2) . '[]';
        $name_key1 = sanitize_key($name1);
        $name_key2 = sanitize_key($name2);

        // presence sentinel fields — allow intentional clearing when checkboxes exist but none checked
        echo '<input type="hidden" name="' . esc_attr($name1) . '_present" value="1" />';
        echo '<input type="hidden" name="' . esc_attr($name2) . '_present" value="1" />';

        echo '<div class="ggc-scroll-panel" style="max-height:' . intval($maxHeight) . 'px;">';

        // 範囲1
        $range1_id = $name_key1 . '-range-1';
        echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($range1_id) . '">';
        echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
        echo '<strong>IPアドレス範囲1</strong>';
        echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($range1_id) . '"> [全選択/解除] </small>';
        echo '</h4>';
        echo '<div id="' . esc_attr($range1_id) . '" class="ggc-settings-group-content" style="display:none;">';
        if (!empty($grouped1)) {
            foreach ($grouped1 as $glabel => $group) {
                $group_id = $name_key1 . '-group-' . sanitize_key($glabel);
                echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($group_id) . '">';
                echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
                echo '<strong>' . esc_html($glabel) . '</strong>';
                echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($group_id) . '"> [全選択/解除] </small>';
                echo '</h4>';
                echo '<div id="' . esc_attr($group_id) . '" class="ggc-settings-group-content open" style="display:block;">';
                foreach ($group as $key => $ipd) {
                    echo '<label class="ggc-item-label">';
                    echo '<input type="checkbox" name="' . $name_attr1 . '" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $selected1), true, false) . ' /> ';
                    echo esc_html($ipd['label']);
                    echo '</label>';
                }
                echo '</div>';
            }
        } else {
            echo '<p class="description ggc-settings-desc">定義がありません。</p>';
        }
        echo '</div>';

        // 範囲2
        $range2_id = $name_key2 . '-range-2';
        echo '<h4 class="ggc-settings-group-header ggc-section-title--spaced" data-target="#' . esc_attr($range2_id) . '">';
        echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
        echo '<strong>IPアドレス範囲2</strong>';
        echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($range2_id) . '"> [全選択/解除] </small>';
        echo '</h4>';
        echo '<div id="' . esc_attr($range2_id) . '" class="ggc-settings-group-content" style="display:none;">';
        if (!empty($grouped2)) {
            foreach ($grouped2 as $glabel => $group) {
                $group_id = $name_key2 . '-group-' . sanitize_key($glabel);
                echo '<h4 class="ggc-settings-group-header" data-target="#' . esc_attr($group_id) . '">';
                echo '<span class="dashicons dashicons-arrow-right-alt2 ggc-settings-arrow"></span>';
                echo '<strong>' . esc_html($glabel) . '</strong>';
                echo '<small class="ggc-settings-toggle-all" data-group="' . esc_attr($group_id) . '"> [全選択/解除] </small>';
                echo '</h4>';
                echo '<div id="' . esc_attr($group_id) . '" class="ggc-settings-group-content open" style="display:block;">';
                foreach ($group as $key => $ipd) {
                    echo '<label class="ggc-item-label">';
                    echo '<input type="checkbox" name="' . $name_attr2 . '" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $selected2), true, false) . ' /> ';
                    echo esc_html($ipd['label']);
                    echo '</label>';
                }
                echo '</div>';
            }
        } else {
            echo '<p class="description ggc-settings-desc">定義がありません。</p>';
        }
        echo '</div>';

        echo '</div>';
    }

    private function get_general_settings() {
        return GGC_Options::get_general_settings();
    }

    private function render_markdown_global_settings(
        $markdown_replace_enabled,
        $markdown_global_template_mode,
        $markdown_global_template_key,
        $markdown_templates,
        $global_markdown_selected_crawlers,
        $global_markdown_selected_ips,
        $global_markdown_selected_ips_2
    ) {
        // 新しいサブ設定値を取得
        $md_opts = GGC_Options::get_markdown_options();
        $ua_eval = $md_opts['ua_eval'];
        $ip_eval = $md_opts['ip_eval'];
        ?>

        <tr>
            <th colspan="2" style="background:#f7f7f7;font-weight:bold;">グローバル設定 評価-マークダウン置換</th>
        </tr>
        <tr>
            <th scope="row">評価方法</th>
            <td>
                <select name="ggc_markdown_replace_enabled" id="ggc_markdown_replace_enabled">
                    <option value="off" <?php selected($markdown_replace_enabled, 'off'); ?>>無効</option>
                    <option value="on" <?php selected($markdown_replace_enabled, 'on'); ?>>投稿・固定ページ個別設定</option>
                    <option value="all" <?php selected($markdown_replace_enabled, 'all'); ?>>全ページで設定</option>
                </select>
            </td>
        </tr>
        <tbody id="ggc-markdown-global-eval-wrapper">
            <tr>
                <th scope="row">User-Agentの評価-マークダウン</th>
                <td>
                    <label for="ggc_markdown_ua_eval" class="ggc-settings-label">User-Agentの評価-マークダウン：</label>
                    <select name="ggc_markdown_ua_eval" id="ggc_markdown_ua_eval" class="ggc-settings-select">
                        <option value="none" <?php selected($ua_eval, 'none'); ?>>設定しない</option>
                        <option value="blacklist" <?php selected($ua_eval, 'blacklist'); ?>>ブラックリスト</option>
                        <option value="whitelist" <?php selected($ua_eval, 'whitelist'); ?>>ホワイトリスト</option>
                        <option value="allow_all" <?php selected($ua_eval, 'allow_all'); ?>>全許可</option>                             
                        <option value="deny_all" <?php selected($ua_eval, 'deny_all'); ?>>全拒否</option>                   
                    </select>
                    <!-- User-Agent 制御リスト（マークダウン向け）をセレクト直下に移動 -->
                    <div id="ggc-markdown-global-ua-list" class="ggc-markdown-global-list">
                        <p id="ggc-markdown-ua-description" class="ggc-markdown-global-desc">
                            <?php
                            echo ($ua_eval === 'whitelist')
                                ? 'ホワイトリスト : チェックしたUser-Agent以外をマークダウンに置換します。'
                                : 'ブラックリスト : チェックしたUser-Agentをマークダウンに置換します。';
                            ?>
                        </p>
                        <p class="ggc-settings-note">User-Agent 制御リスト（マークダウン向け）:</p>
                        <?php $this->render_grouped_bots_checklist($global_markdown_selected_crawlers, 'ggc_global_markdown_selected_crawlers', 180, GGC_Options::get_global_selected_lists()['markdown_selected_patterns'], 'ggc_global_markdown_selected_patterns'); ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">IPアドレスの評価-マークダウン</th>
                <td>
                    <label for="ggc_markdown_ip_eval" class="ggc-settings-label">IPアドレスの評価-マークダウン：</label>
                    <select name="ggc_markdown_ip_eval" id="ggc_markdown_ip_eval" class="ggc-settings-select">
                        <option value="none" <?php selected($ip_eval, 'none'); ?>>設定しない</option>
                        <option value="blacklist" <?php selected($ip_eval, 'blacklist'); ?>>ブラックリスト</option>
                        <option value="whitelist" <?php selected($ip_eval, 'whitelist'); ?>>ホワイトリスト</option>
                        <option value="allow_all" <?php selected($ip_eval, 'allow_all'); ?>>全許可</option>
                        <option value="deny_all" <?php selected($ip_eval, 'deny_all'); ?>>全拒否</option>                        
                    </select>
                    <!-- IPアドレス制御リスト（マークダウン向け）をセレクト直下に移動 -->
                    <div id="ggc-markdown-global-ip-list" class="ggc-markdown-global-list" style="margin-top:12px;">
                        <p id="ggc-markdown-ip-description" class="ggc-markdown-global-desc">
                            <?php
                            echo ($ip_eval === 'whitelist')
                                ? 'ホワイトリスト : チェックしたIP範囲以外をマークダウンに置換します。'
                                : 'ブラックリスト : チェックしたIP範囲をマークダウンに置換します。';
                            ?>
                        </p>
                        <p class="ggc-settings-note">IPアドレス制御リスト（マークダウン向け）:</p>
                        <?php $this->render_grouped_ip_checklist($global_markdown_selected_ips, $global_markdown_selected_ips_2, 'ggc_global_markdown_selected_ips', 'ggc_global_markdown_selected_ips_2', 180); ?>
                    </div>
                </td>
            </tr>
            <tr id="ggc-markdown-global-template-wrapper">
                <th scope="row">テンプレート選択方法</th>
                <td>
                        <div class="ggc-settings-block" style="margin-top:8px;">
                            <label for="ggc_markdown_global_template_mode" class="ggc-settings-label">テンプレート選択方法：</label>
                            <select name="ggc_markdown_global_template_mode" id="ggc_markdown_global_template_mode" class="ggc-settings-select">
                                <option value="select" <?php selected($markdown_global_template_mode, 'select'); ?>>テンプレートを置換、専用表示</option>
                                <option value="select_raw" <?php selected($markdown_global_template_mode, 'select_raw'); ?>>テンプレートをマークダウンのまま表示置換</option>
                                <option value="random" <?php selected($markdown_global_template_mode, 'random'); ?>>ランダムにテンプレートを置換、専用表示</option>
                                <option value="random_raw" <?php selected($markdown_global_template_mode, 'random_raw'); ?>>ランダムにマークダウンのまま表示置換</option>
                            </select>
                        </div>
                        <div id="ggc-markdown-global-template-key" class="<?php echo ($markdown_global_template_mode === 'select' || $markdown_global_template_mode === 'select_raw') ? '' : 'ggc-hidden'; ?>" style="margin-top:8px;">
                            <label for="ggc_markdown_global_template_key" class="ggc-settings-label">置換テンプレート：</label>
                            <select name="ggc_markdown_global_template_key" id="ggc_markdown_global_template_key" class="ggc-settings-select--full">
                                <option value="" <?php selected($markdown_global_template_key, ''); ?>>選択してください...</option>
                                <?php foreach ($markdown_templates as $tpl_key => $tpl): ?>
                                    <?php $tpl_key = sanitize_key($tpl_key); ?>
                                    <option value="<?php echo esc_attr($tpl_key); ?>" <?php selected($markdown_global_template_key, $tpl_key); ?>><?php echo esc_html($tpl['title'] ?? $tpl_key); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </td>
                </tr>
        </tbody>
        <?php
    }

    private function render_media_global_settings(
        $global_media_ua_control,
        $global_media_ip_evaluation,
        $alt_fixed_featured,
        $alt_fixed
    ) {
        // 評価方法の値を取得（POST優先、なければDB値）
        $media_eval_mode = isset($_POST['ggc_global_media_eval_mode']) ? sanitize_text_field($_POST['ggc_global_media_eval_mode']) : GGC_Options::get_global_media_options()['media_eval_mode'];
        $media_display_mode = isset($_POST['ggc_global_media_display_mode']) ? sanitize_text_field($_POST['ggc_global_media_display_mode']) : GGC_Options::get_global_media_display_mode();
        $featured_display_mode = isset($_POST['ggc_global_featured_display_mode']) ? sanitize_text_field($_POST['ggc_global_featured_display_mode']) : GGC_Options::get_global_featured_display_mode();
        $media_ua_control = isset($_POST['ggc_global_media_user_agent_control']) ? sanitize_text_field($_POST['ggc_global_media_user_agent_control']) : $global_media_ua_control;
        $media_ip_evaluation = isset($_POST['ggc_global_media_ip_evaluation']) ? sanitize_text_field($_POST['ggc_global_media_ip_evaluation']) : $global_media_ip_evaluation;
        ?>
        <!-- Media-level global settings -->
        <tr>
            <th colspan="2" style="background:#f7f7f7;font-weight:bold;">グローバル設定 評価-メディア</th>
        </tr>
        <tr>
            <th scope="row">評価方法</th>
            <td>
                <select name="ggc_global_media_eval_mode" id="ggc_global_media_eval_mode_select">
                    <option value="none" <?php selected($media_eval_mode, 'none'); ?>>無効</option>
                    <option value="apply_new_posts" <?php selected($media_eval_mode, 'apply_new_posts'); ?>>投稿・固定ページ個別設定</option>
                    <option value="all" <?php selected($media_eval_mode, 'all'); ?>>全ページで設定</option>
                </select>
            </td>
        </tr>
        <tr id="ggc-global-featured-display-mode-row">
            <th scope="row">アイキャッチ画像表示方法</th>
            <td>
                <select name="ggc_global_featured_display_mode" id="ggc_global_featured_display_mode_select">
                    <option value="normal" <?php selected($featured_display_mode, 'normal'); ?>>通常表示</option>
                    <option value="alt_replace" <?php selected($featured_display_mode, 'alt_replace'); ?>>評価に従ってテキストに置換</option>
                    <option value="hide" <?php selected($featured_display_mode, 'hide'); ?>>評価に従って非表示</option>
                </select>
                <p class="ggc-settings-desc-tight">アイキャッチ画像の表示方法を設定します。メディア表示モードとは独立して制御できます。</p>
            </td>
        </tr>
                <!-- PHPではggc-hidden-rowを付与しない。JSでのみ制御 -->
        <tr id="ggc-global-media-text-settings">
            <th scope="row">アイキャッチ画像の代替テキスト</th>
            <td>
                <input type="text" id="ggc_alt_fixed_text_featured" name="ggc_alt_fixed_text_featured" value="<?php echo esc_attr($alt_fixed_featured); ?>" class="regular-text" placeholder="アイキャッチ画像の代替テキスト（使用時）">
                <p class="description">アイキャッチ画像が表示除外された場合に代替テキストとして使用するテキストを指定します。未入力の場合は通常表示します。</p>
            </td>
        </tr>
                <tr id="ggc-global-media-display-mode-row">
            <th scope="row">メディア表示モード</th>
            <td>
                <select name="ggc_global_media_display_mode" id="ggc_global_media_display_mode_select">
                    <option value="normal" <?php selected($media_display_mode, 'normal'); ?>>通常表示</option>
                    <option value="alt_replace" <?php selected($media_display_mode, 'alt_replace'); ?>>評価に従ってテキストに置換</option>
                    <option value="hide" <?php selected($media_display_mode, 'hide'); ?>>評価に従って非表示</option>
                </select>
                <p class="ggc-settings-desc-tight">コンテンツ内のメディア（画像・ギャラリー等）の表示モードを設定します。</p>
            </td>
        </tr>
        <!-- PHPではggc-hidden-rowを付与しない。JSでのみ制御 -->
        <tr id="ggc-global-media-text-settings-2">
            <th scope="row">代替テキスト</th>
            <td>
                <input type="text" id="ggc_alt_fixed_text" name="ggc_alt_fixed_text" value="<?php echo esc_attr($alt_fixed); ?>" class="regular-text" placeholder="代替テキスト（使用時）">
                <p class="description">メディアを代替テキストとして使用するテキストにする場合に指定します。メディア制御でブラックリスト/ホワイトリストを選択した場合に有効になります。</p>
            </td>
        </tr>
        <tr id="ggc-global-media-ua-row">
            <th scope="row">User-Agentの評価-メディア</th>
            <td>
                <select name="ggc_global_media_user_agent_control" id="ggc_global_media_user_agent_control_select">
                    <option value="none" <?php selected($media_ua_control, 'none'); ?>>設定しない</option>
                    <option value="global_blacklist" <?php selected($media_ua_control, 'global_blacklist'); ?>>ブラックリスト</option>
                    <option value="global_whitelist" <?php selected($media_ua_control, 'global_whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($media_ua_control, 'allow_all'); ?>>全許可</option>                   
                    <option value="deny_all" <?php selected($media_ua_control, 'deny_all'); ?>>全拒否</option>
                </select>
                <div id="ggc-global-media-ua-list" class="ggc-settings-block">
                    <p class="ggc-settings-desc-tight">
                        <?php echo ($media_ua_control === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。</strong>' : '<strong>ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。</strong>'; ?>
                    </p>
                    <p class="ggc-settings-note">User-Agent 制御リスト（メディア向け）:</p>
                    <?php $lists = GGC_Options::get_global_selected_lists(); $this->render_grouped_bots_checklist($lists['media_selected_crawlers'], 'ggc_global_media_selected_crawlers', 240, $lists['media_selected_patterns'], 'ggc_global_media_selected_patterns'); ?>
                </div>
            </td>
        </tr>
        <tr id="ggc-global-media-ip-row">
            <th scope="row">IPアドレスの評価-メディア</th>
            <td>
                <select name="ggc_global_media_ip_evaluation" id="ggc_global_media_ip_evaluation_select">
                    <option value="none" <?php selected($media_ip_evaluation, 'none'); ?>>設定しない</option>
                    <option value="global_blacklist" <?php selected($media_ip_evaluation, 'global_blacklist'); ?>>ブラックリスト</option>
                    <option value="global_whitelist" <?php selected($media_ip_evaluation, 'global_whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($media_ip_evaluation, 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected($media_ip_evaluation, 'deny_all'); ?>>全拒否</option>
                </select>
                <div id="ggc-global-media-ip-list" class="ggc-settings-block">
                    <p class="ggc-settings-desc-tight">
                        <?php echo ($media_ip_evaluation === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。</strong>' : '<strong>ブラックリスト : チェックしたIP範囲は代替テキスト表示、それ以外はメディア表示します。</strong>'; ?>
                    </p>
                    <p class="ggc-settings-note">IPアドレス制御リスト（メディア向け）:</p>
                    <?php $lists = GGC_Options::get_global_selected_lists(); $this->render_grouped_ip_checklist($lists['media_selected_ips'], $lists['media_selected_ips_2'], 'ggc_global_media_selected_ips', 'ggc_global_media_selected_ips_2', 240); ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // ページ評価セクションを出力するヘルパー
    private function render_page_global_settings() {
        $opts = GGC_Options::get_page_eval_global_options();
        $page_eval_mode   = $opts['global_page_mode'];
        $page_ua_control  = $opts['global_page_ua_control'];
        $page_ip_control  = $opts['global_page_ip_control'];
        $ua_redirect_mode = GGC_Options::get_global_block_options('ua')['redirect_mode'];
        $ip_redirect_mode = GGC_Options::get_global_block_options('ip')['redirect_mode'];
        $ua_redirect_url  = GGC_Options::get_global_block_options('ua')['redirect_url'];
        $ip_redirect_url  = GGC_Options::get_global_block_options('ip')['redirect_url'];
        $ua_block_message_key = GGC_Options::get_global_block_options('ua')['block_message_key'];
        $ip_block_message_key = GGC_Options::get_global_block_options('ip')['block_message_key'];
        $page_eval_messages = GGC_Options::get_page_eval_messages();
        $cleared = GGC_Options::is_clear_all_done();
        ?>
        <tr>
            <th colspan="2" style="background:#f7f7f7;font-weight:bold;">グローバル設定 評価-ページ</th>
        </tr>
        <tr>
            <th scope="row">評価方法</th>
            <td>
                <select name="ggc_global_page_eval_mode" id="ggc_global_page_eval_mode_select">
                    <option value="none" <?php selected($page_eval_mode, 'none'); ?>>無効</option>
                    <option value="apply_new_posts" <?php selected($page_eval_mode, 'apply_new_posts'); ?>>投稿・固定ページ個別設定</option>
                    <option value="all" <?php selected($page_eval_mode, 'all'); ?>>全ページで設定</option>
                </select>
            </td>
        </tr>
        <tr id="ggc-global-page-ua-row" class="<?php echo (in_array($page_ua_control, ['global_blacklist','global_whitelist']) || in_array($page_ip_control, ['global_blacklist','global_whitelist'])) ? '' : 'ggc-hidden-row'; ?>">
            <th scope="row">User-Agentの評価-ページ</th>
            <td>
                <select name="ggc_global_page_user_agent_control" id="ggc_global_page_user_agent_control_select">
                    <option value="none" <?php selected($page_ua_control, 'none'); ?>>設定しない</option>
                    <option value="global_blacklist" <?php selected($page_ua_control, 'global_blacklist'); ?>>ブラックリスト</option>
                    <option value="global_whitelist" <?php selected($page_ua_control, 'global_whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($page_ua_control, 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected($page_ua_control, 'deny_all'); ?>>全拒否</option>
                </select>
                <div id="ggc-global-page-ua-list" class="ggc-settings-block">
                    <p class="ggc-settings-desc-tight">
                        <?php echo ($page_ua_control === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたUser-Agentをアクセス許可します。</strong>' : '<strong>ブラックリスト : チェックしたUser-Agentをアクセス拒否します。</strong>'; ?>
                    </p>
                    <p class="ggc-settings-note">User-Agent 制御リスト（全設定）:</p>
                    <?php $lists = GGC_Options::get_global_selected_lists(); $this->render_grouped_bots_checklist($lists['selected_crawlers'], 'ggc_global_selected_crawlers', 240, $lists['selected_patterns'], 'ggc_global_selected_patterns'); ?>
                    <div id="ggc-global-page-ua-redirect-row" class="ggc-settings-block" style="margin-top:10px;">
                        <label for="ggc_global_ua_redirect_mode_select" class="ggc-settings-label">ページ評価の動作</label>
                        <select name="ggc_global_ua_redirect_mode" id="ggc_global_ua_redirect_mode_select">
                            <option value="block" <?php selected($ua_redirect_mode, 'block'); ?>>アクセスをブロックする</option>
                            <option value="redirect" <?php selected($ua_redirect_mode, 'redirect'); ?>>リダイレクトする</option>
                        </select>
                        <div id="ggc-global-ua-block-message" class="ggc-settings-block" style="margin-top:8px;<?php echo ($ua_redirect_mode === 'block') ? '' : 'display:none;'; ?>">
                            <label for="ggc_global_ua_block_message_key" class="ggc-settings-label">メッセージ内容</label>
                            <select name="ggc_global_ua_block_message_key" id="ggc_global_ua_block_message_key" class="ggc-settings-select--full">
                                <option value="">デフォルト</option>
                                <?php foreach ($page_eval_messages as $key => $def): ?>
                                    <?php if ($key === 'default_block' || !($def['is_global'] ?? 0)) continue; ?>
                                    <?php $label = $def['label'] ?? $key; ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($ua_block_message_key, $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="ggc-global-ua-redirect-url" class="ggc-settings-block" style="margin-top:8px;<?php echo ($ua_redirect_mode === 'redirect') ? '' : 'display:none;'; ?>">
                            <input type="text" name="ggc_global_ua_redirect_url" value="<?php echo esc_attr($ua_redirect_url); ?>" class="regular-text" placeholder="https://example.com/" />
                            <p class="description ggc-settings-desc-compact">User-Agent の評価結果に応じて、このURLへリダイレクトします。</p>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <tr id="ggc-global-page-ip-row" class="<?php echo (in_array($page_ua_control, ['global_blacklist','global_whitelist']) || in_array($page_ip_control, ['global_blacklist','global_whitelist'])) ? '' : 'ggc-hidden-row'; ?>">
            <th scope="row">IPアドレスの評価-ページ</th>
            <td>
                <select name="ggc_global_page_ip_control" id="ggc_global_page_ip_control_select">
                    <option value="none" <?php selected($page_ip_control, 'none'); ?>>設定しない</option>
                    <option value="global_blacklist" <?php selected($page_ip_control, 'global_blacklist'); ?>>ブラックリスト</option>
                    <option value="global_whitelist" <?php selected($page_ip_control, 'global_whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($page_ip_control, 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected($page_ip_control, 'deny_all'); ?>>全拒否</option>
                </select>
                <div id="ggc-global-page-ip-list" class="ggc-settings-block">
                    <p class="ggc-settings-desc-tight">
                        <?php echo ($page_ip_control === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたIP範囲をアクセス許可します。</strong>' : '<strong>ブラックリスト : チェックしたIP範囲をアクセス拒否します。</strong>'; ?>
                    </p>
                    <p class="ggc-settings-note">IPアドレス制御リスト（全設定）:</p>
                    <?php $global_selected = GGC_Options::get_global_selected_lists(); $this->render_grouped_ip_checklist($global_selected['selected_ips'] ?? [], $global_selected['selected_ips_2'] ?? [], 'ggc_global_selected_ips', 'ggc_global_selected_ips_2', 240); ?>
                    <div id="ggc-global-page-ip-redirect-row" class="ggc-settings-block" style="margin-top:10px;">
                        <label for="ggc_global_ip_redirect_mode_select" class="ggc-settings-label">ページ評価の動作</label>
                        <select name="ggc_global_ip_redirect_mode" id="ggc_global_ip_redirect_mode_select">
                            <option value="block" <?php selected($ip_redirect_mode, 'block'); ?>>アクセスをブロックする</option>
                            <option value="redirect" <?php selected($ip_redirect_mode, 'redirect'); ?>>リダイレクトする</option>
                        </select>
                        <div id="ggc-global-ip-block-message" class="ggc-settings-block" style="margin-top:8px;<?php echo ($ip_redirect_mode === 'block') ? '' : 'display:none;'; ?>">
                            <label for="ggc_global_ip_block_message_key" class="ggc-settings-label">メッセージ内容</label>
                            <select name="ggc_global_ip_block_message_key" id="ggc_global_ip_block_message_key" class="ggc-settings-select--full">
                                <option value="">デフォルト</option>
                                <?php foreach ($page_eval_messages as $key => $def): ?>
                                    <?php if ($key === 'default_block' || !($def['is_global'] ?? 0)) continue; ?>
                                    <?php $label = $def['label'] ?? $key; ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($ip_block_message_key, $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="ggc-global-ip-redirect-url" class="ggc-settings-block" style="margin-top:8px;<?php echo ($ip_redirect_mode === 'redirect') ? '' : 'display:none;'; ?>">
                            <input type="text" name="ggc_global_ip_redirect_url" value="<?php echo esc_attr($ip_redirect_url); ?>" class="regular-text" placeholder="https://example.com/" />
                            <p class="description ggc-settings-desc-compact">IP評価の結果に応じて、このURLへリダイレクトします。</p>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
        <script>
        jQuery(function($){
            function togglePageUAList() {
                var val = $('#ggc_global_page_user_agent_control_select').val();
                // show the UA list and redirect/block options for any mode that
                // actually performs evaluation (blacklist, whitelist, allow_all,
                // deny_all)
                if(val === 'global_blacklist' || val === 'global_whitelist' || val === 'allow_all' || val === 'deny_all'){
                    $('#ggc-global-page-ua-list').show();
                    $('#ggc-global-page-ua-redirect-row').show();
                }else{
                    $('#ggc-global-page-ua-list').hide();
                    $('#ggc-global-page-ua-redirect-row').hide();
                }
            }
            function togglePageIPList() {
                var val = $('#ggc_global_page_ip_control_select').val();
                if(val === 'global_blacklist' || val === 'global_whitelist' || val === 'allow_all' || val === 'deny_all'){
                    $('#ggc-global-page-ip-list').show();
                    $('#ggc-global-page-ip-redirect-row').show();
                }else{
                    $('#ggc-global-page-ip-list').hide();
                    $('#ggc-global-page-ip-redirect-row').hide();
                }
            }
            function toggleUAAction() {
                if($('#ggc_global_ua_redirect_mode_select').val() === 'block'){
                    $('#ggc-global-ua-block-message').show();
                    $('#ggc-global-ua-redirect-url').hide();
                }else{
                    $('#ggc-global-ua-block-message').hide();
                    $('#ggc-global-ua-redirect-url').show();
                }
            }
            function toggleIPAction() {
                if($('#ggc_global_ip_redirect_mode_select').val() === 'block'){
                    $('#ggc-global-ip-block-message').show();
                    $('#ggc-global-ip-redirect-url').hide();
                }else{
                    $('#ggc-global-ip-block-message').hide();
                    $('#ggc-global-ip-redirect-url').show();
                }
            }
            function togglePageRowsByEvalMode() {
                var mode = $('#ggc_global_page_eval_mode_select').val();
                if(mode === 'all'){
                    $('#ggc-global-page-ua-row, #ggc-global-page-ip-row').removeClass('ggc-hidden-row');
                }else{
                    $('#ggc-global-page-ua-row, #ggc-global-page-ip-row').addClass('ggc-hidden-row');
                }
            }
            // イベントバインド
            $('#ggc_global_page_eval_mode_select').on('change', function(){
                togglePageRowsByEvalMode();
            });
            $('#ggc_global_page_user_agent_control_select').on('change', function(){
                togglePageUAList();
                toggleUAAction();
            });
            $('#ggc_global_page_ip_control_select').on('change', function(){
                togglePageIPList();
                toggleIPAction();
            });
            $('#ggc_global_ua_redirect_mode_select').on('change', toggleUAAction);
            $('#ggc_global_ip_redirect_mode_select').on('change', toggleIPAction);
            // 初期表示制御（ページロード時にも必ず実行）
            $(document).ready(function(){
                togglePageRowsByEvalMode();
                togglePageUAList();
                togglePageIPList();
                toggleUAAction();
                toggleIPAction();
            });
        });
        </script>
        <?php
    }

    private function render_ip_update_settings($ip_update_frequency) {
        ?>
        <table class="form-table">
            <tr>
                <th colspan="2" style="background:#f7f7f7;font-weight:bold;">IPアドレス自動更新設定</th>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ggc_ip_update_frequency">IPアドレスの自動更新頻度</label>
                </th>
                <td>
                    <select name="ggc_ip_update_frequency" id="ggc_ip_update_frequency">
                        <option value="disabled" <?php selected($ip_update_frequency, 'disabled'); ?>>停止</option>
                        <option value="hourly" <?php selected($ip_update_frequency, 'hourly'); ?>>毎時</option>
                        <option value="twicedaily" <?php selected($ip_update_frequency, 'twicedaily'); ?>>半日</option>
                        <option value="daily" <?php selected($ip_update_frequency, 'daily'); ?>>毎日</option>
                        <option value="weekly" <?php selected($ip_update_frequency, 'weekly'); ?>>毎週</option>
                        <option value="monthly" <?php selected($ip_update_frequency, 'monthly'); ?>>毎月</option>
                        <option value="biannually" <?php selected($ip_update_frequency, 'biannually'); ?>>半年</option>
                        <option value="annually" <?php selected($ip_update_frequency, 'annually'); ?>>毎年</option>
                    </select>
                    <p class="description">GooglebotやGPTBotのIPアドレスリストを更新する頻度を設定します。</p>
                </td>
            </tr>
            <tr>
                <th colspan="2" style="background:#f7f7f7;font-weight:bold;">その他の設定</th>
            </tr>
            <tr>
                <th scope="row">
                    おすすめ設定のインポート
                </th>
                <td>
                    <?php $import_url = wp_nonce_url( admin_url('admin.php?action=ggc_import_default_settings'), 'ggc_import_defaults_nonce' ); ?>
                    <a href="<?php echo esc_url($import_url); ?>" class="button button-secondary">おすすめ設定をインポートする</a>
                    <p class="description">User-Agent, IPアドレス範囲, 不正UAパターン、マークダウンテンプレートの推奨初期設定をインポートします。既存のカスタム設定は上書きされません。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    全データのクリア
                </th>
                <td>
                    <?php $clear_url = wp_nonce_url( admin_url('admin-post.php?action=ggc_clear_all_data'), 'ggc_clear_all_data_nonce' ); ?>
                    <a href="<?php echo esc_url($clear_url); ?>" class="button button-secondary" onclick="return confirm('設定オプション・投稿/メディアのメタデータ・IP更新履歴をすべて削除します。投稿編集画面で設定した各種オプションもリセットされます。よろしいですか？');">すべての保存データをクリアする</a>
                    <p class="description">設定画面の保存済みオプションや、投稿/メディア編集画面に保存されたメタデータ（UA/IP制御など）を含むすべてのデータを削除します。プラグインのバージョンアップで動作がおかしい場合などにお使いください。</p>
                </td>
            </tr>
        </table>
        <?php
    }



    // --------------------------------------------------------
    // HTML 出力関数
    // --------------------------------------------------------

    /**
     * タブナビゲーションの描画
     */
    private function render_nav_tabs($current_tab) {
        echo '<nav class="nav-tab-wrapper">';
        foreach (self::TABS as $tab_key => $tab_label) {
            $active_class = ($current_tab === $tab_key) ? 'nav-tab-active' : '';
            $url = add_query_arg(['page' => 'ggc-crawler-definitions', 'tab' => $tab_key], admin_url('options-general.php'));
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . esc_attr($active_class) . '">' . esc_html($tab_label) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * 設定ページ全体
     */
    public function settings_page_html() {
        if (! Admin_Utils::current_user_can_manage_options()) {
            return;
        }

        $active_tab = $this->get_current_tab();
        ?>
        <div class="wrap">

            <div style="background: linear-gradient(90deg, #f7fafc 60%, #e3e9f3 100%); border: 1px solid #d1d5db; border-radius: 8px; padding: 1.5em 2em; margin-bottom: 2em; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                <div style="font-size:1.25em; font-weight:bold; margin-bottom:0.5em; display:flex; align-items:center; gap:0.5em;">
                    <span style="font-size:1.5em;">🔒</span> クローラー個別制御（Custom Crawler Control）
                </div>
                <div style="font-size:1.05em; color:#222; margin-bottom:0.7em;">
                    投稿・固定ページ単位で、検索エンジン・AIクローラー・不正ボットのアクセスを <b>User-Agent</b> / <b>IPアドレス</b> で精密に制御できる <b>管理者向け WordPress プラグイン</b>です。アクセス制限を行ったり、画像などのメディアをテキストに置換可能です。<br>
                    <span style="color:#b91c1c; font-size:0.98em;">(完全にアクセスを防ぐ保証はありません。ご了承ください。詳しくは「プラグインの使い方」タブやGitHubリポジトリをご覧ください。)</span>
                </div>
                <div style="margin-bottom:0.3em;">
                    <a href="https://github.com/donnma777/smart-access-control" target="_blank" style="text-decoration:none; color:#2563eb; font-weight:bold;">🔗 GitHub : リポジトリを見る</a>
                </div>
                <div style="color:#555; font-size:0.98em;">
                    🔗 作者 : <a href="https://x.com/donnma777" target="_blank">donnma777</a> (<a href="https://donnma.com/" target="_blank">donnma.com</a>)
                </div>
            </div>

            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->render_nav_tabs($active_tab); ?>

            <?php if ($active_tab === 'tools'): ?>
                <?php $this->render_diagnostic_tools_section(); ?>
            <?php elseif ($active_tab === 'usage'): ?>
                <?php $this->render_usage_section(); ?>
            <?php else: ?>
                <form action="options.php" method="post">
                    <?php
                    // タブに応じて、対応する設定グループのフィールドを描画
                    switch ($active_tab) {
                        case 'general':
                            settings_fields('ggc_general_option_group');
                            do_settings_sections('ggc_tab_general');
                            break;
                        case 'markdown':
                            // マークダウンタブでもグローバルIPリスト保存のためgeneral_option_groupを必ず出力
                            settings_fields('ggc_general_option_group');
                            settings_fields('ggc_markdown_option_group');
                            do_settings_sections('ggc_tab_markdown');
                            break;
                        case 'page_eval':
                            settings_fields('ggc_page_eval_option_group');
                            do_settings_sections('ggc_tab_page_eval');
                            break;
                        case 'bots':
                            settings_fields('ggc_bots_option_group');
                            do_settings_sections('ggc_tab_bots');
                            break;
                        case 'ips':
                            settings_fields('ggc_ips_option_group');
                            do_settings_sections('ggc_tab_ips');
                            break;
                        case 'ips2':
                            settings_fields('ggc_ips2_option_group');
                            do_settings_sections('ggc_tab_ips2');
                            break;
                        case 'patterns':
                            settings_fields('ggc_patterns_option_group');
                            do_settings_sections('ggc_tab_patterns');
                            break;
                        default:
                            settings_fields('ggc_general_option_group');
                            do_settings_sections('ggc_tab_general');
                            break;
                    }

                    if ($active_tab === 'markdown') {
                        // markdownタブは各項目変更時に自動保存するため、下部ボタンは表示しない
                        echo '<p class="description">※このタブでは項目を変更すると自動的に保存されます。手動のボタンはありません。</p>';
                    } else {
                        submit_button('設定を保存');
                    }
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }


    /**
     * グローバル設定セクションの表示
     */
    public function render_general_settings_section() {

        // Removed $default_control_active
        $settings = $this->get_general_settings();
        $ip_update_frequency = $settings['ip_update_frequency'];
        $global_ua_control = $settings['global_ua_control'];
        $global_ip_evaluation = $settings['global_ip_evaluation'];
        $global_media_ua_control = $settings['global_media_ua_control'];
        $global_media_ip_evaluation = $settings['global_media_ip_evaluation'];
        $alt_fixed_featured = $settings['alt_fixed_featured'];
        $alt_fixed = $settings['alt_fixed'];
        $markdown_replace_enabled = $settings['markdown_replace_enabled'];
        $markdown_global_template_mode = $settings['markdown_global_template_mode'];
        $markdown_global_template_key = $settings['markdown_global_template_key'];
        $global_ua_redirect_mode = $settings['global_ua_redirect_mode'];
        $global_ip_redirect_mode = $settings['global_ip_redirect_mode'];
        $global_ua_redirect_url = $settings['global_ua_redirect_url'];
        $global_ip_redirect_url = $settings['global_ip_redirect_url'];
        $global_ua_block_message_key = $settings['global_ua_block_message_key'];
        $global_ip_block_message_key = $settings['global_ip_block_message_key'];
        $global_ua_block_message = $settings['global_ua_block_message'];
        $global_ip_block_message = $settings['global_ip_block_message'];
        $markdown_templates = $settings['markdown_templates'];
        $global_markdown_selected_crawlers = $settings['global_markdown_selected_crawlers'];
        $global_markdown_selected_ips = $settings['global_markdown_selected_ips'];
        $global_markdown_selected_ips_2 = $settings['global_markdown_selected_ips_2'];
        $global_featured_display_mode = isset($settings['global_featured_display_mode']) ? $settings['global_featured_display_mode'] : 'normal';
        ?>
        <div class="ggc-about">
        <p class="ggc-about-title">
            グローバル設定では、すべての投稿・固定ページに適用されるデフォルトのアクセス制御設定を行います。ここで設定した内容は、各投稿・固定ページの個別設定よりも優先されます。
        </p>
        </div>

        <table class="form-table">
            <?php
            $this->render_markdown_global_settings(
                $markdown_replace_enabled,
                $markdown_global_template_mode,
                $markdown_global_template_key,
                $markdown_templates,
                $global_markdown_selected_crawlers,
                $global_markdown_selected_ips,
                $global_markdown_selected_ips_2
            );
            ?>
            <?php
            $this->render_media_global_settings(
                $global_media_ua_control,
                $global_media_ip_evaluation,
                $alt_fixed_featured,
                $alt_fixed
            );
            // no parameters are needed – the helper reads its own values
            // from options.  passing the globals was a leftover from an earlier
            // refactor and produced needless warning messages.
            $this->render_page_global_settings();
            ?>
        </table>
        <hr>
        <?php $this->render_ip_update_settings($ip_update_frequency); ?>
        <?php
    }

    /**
     * マークダウンテンプレート（グローバル）セクション
     */
    public function render_markdown_templates_section() {
        $templates = GGC_Options::get_markdown_templates();
        ?>
        <p class="ggc-md-template-desc">
            テンプレートキーを選択して読み込み、編集・保存できます。ランダム表示はテンプレートごとにON/OFFできます。
        </p>
        <p class="description">
            - テンプレート選択 : 編集したいテンプレートを選択してください。<br>
            - テンプレートキー : テンプレートの一意の識別子です。<br>
            - ページタイトル : ページのタイトルを設定します。<br>
            - マークダウン本文 : マークダウン形式で本文を入力します。<br>
            - ランダム表示に含める : ランダム表示に含めるかどうかを設定します。<br>
            - アイキャッチ（画像選択 or URL） : アイキャッチ画像を設定します。画像URLを直接入力するか、画像選択ボタンからメディアライブラリの画像を選択できます。
        </p>
        <div class="ggc-md-template-controls">
            <label class="ggc-label-inline">テンプレート選択</label>
            <select id="ggc-md-template-select" class="ggc-md-template-select">
                <option value="">選択してください...</option>
                <?php foreach ($templates as $key => $tpl) :
                    $label = $tpl['title'] ?? $key; ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label . ' (' . $key . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <!-- 呼び出しボタンは自動読み込みに変更したため不要 -->
            <button type="button" class="button ggc-button-spacer" id="ggc-md-template-new">新規</button>
        </div>

        <div id="ggc-md-template-editor" class="ggc-md-template-editor">
            <p class="ggc-md-template-row">
                <label class="ggc-label-strong">テンプレートキー</label><br>
                <input type="text" id="ggc-md-template-key" class="ggc-md-template-field" placeholder="例: default" />
            </p>
            <p class="ggc-md-template-row">
                <label class="ggc-label-strong">ページタイトル</label><br>
                <input type="text" id="ggc-md-template-title" class="ggc-md-template-field" />
            </p>
            <p class="ggc-md-template-row">
                <label class="ggc-label-strong">マークダウン本文</label><br>
                <textarea id="ggc-md-template-markdown" rows="8" class="ggc-md-template-field"></textarea>
            </p>
            <p class="ggc-md-template-row">
                <label>
                    <input type="checkbox" id="ggc-md-template-random" />
                    ランダム表示に含める
                </label>
            </p>
            <div class="ggc-md-template-row">
                <label class="ggc-label-strong">アイキャッチ（画像選択 or URL）</label><br>
                <input type="hidden" id="ggc-md-template-image-id" value="" />
                <input type="text" id="ggc-md-template-image-url" class="ggc-md-template-field" placeholder="https://..." />
                <div id="ggc-md-template-image-preview" class="ggc-md-template-image-preview">
                    <span class="ggc-muted-text">未設定</span>
                </div>
                <button type="button" class="button" id="ggc-md-template-image-select">画像を選択</button>
                <button type="button" class="button ggc-button-spacer" id="ggc-md-template-image-remove">削除</button>
            </div>
            <p class="ggc-md-template-row ggc-md-template-row--none">
                <button type="button" class="button button-primary" id="ggc-md-template-save">このテンプレートを保存</button>
                <button type="button" class="button ggc-button-spacer" id="ggc-md-template-delete">このテンプレートを削除</button>
            </p>
        </div>
        <?php
    }

    /**
     * ページ評価メッセージ定義セクション
     */
    public function render_page_eval_messages_section() {
        $messages = GGC_Options::get_page_eval_messages();
        $cleared = GGC_Options::is_clear_all_done();
        ?>
        <p class="description">
            ページ評価で「アクセスをブロックする」場合に使用するメッセージ定義です。<br>
            投稿画面・グローバル設定で、ここで登録したラベルを選択できます。
        </p>
        <p class="description">
            - 定義キー : システム内部で使用される一意の識別子です。必須項目です。英数字のみ登録可能です。<br>
            - 表示ラベル : 投稿編集画面で表示される名前です。わかりやすい名前を設定してください。<br>
            - グローバル : このチェックを設定すると、チェックしたメッセージが選択可能になります。<br>
            - ステータスコード : ブロック時のステータスコードを設定します。<br>
            - メッセージ内容 : ブロック時に表示するメッセージを設定します。<br>
        </p>
        <table class="wp-list-table widefat fixed striped" id="ggc-page-eval-table">
            <thead>
                <tr>
                    <th style="width: 14%;">定義キー</th>
                    <th style="width: 16%;">表示ラベル</th>
                    <th style="width: 8%;">グローバル</th>
                    <th style="width: 10%;">ステータスコード</th>
                    <th>メッセージ内容</th>
                    <th style="width: 8%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-page-eval-tbody">
                <?php foreach ($messages as $key => $def): ?>
                    <tr>
                        <td>
                            <input type="text" name="ggc_page_eval_messages[<?php echo esc_attr($key); ?>][key]" value="<?php echo esc_attr($key); ?>" class="regular-text" />
                        </td>
                        <td>
                            <input type="text" name="ggc_page_eval_messages[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($def['label'] ?? $key); ?>" class="regular-text" />
                        </td>
                        <td style="text-align:center;">
                            <input type="checkbox" name="ggc_page_eval_messages[<?php echo esc_attr($key); ?>][is_global]" value="1" <?php checked(!empty($def['is_global']), true); ?> />
                        </td>
                        <td>
                            <input type="number" min="400" max="599" name="ggc_page_eval_messages[<?php echo esc_attr($key); ?>][status_code]" value="<?php echo esc_attr(intval($def['status_code'] ?? 403)); ?>" class="small-text" />
                        </td>
                        <td>
                            <textarea name="ggc_page_eval_messages[<?php echo esc_attr($key); ?>][message]" rows="2" class="large-text"><?php echo esc_textarea($def['message'] ?? ''); ?></textarea>
                        </td>
                        <td>
                            <button type="button" class="button ggc-remove-page-eval">削除</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
              <button type="button" class="button button-primary" id="ggc-add-page-eval">定義を追加</button>
        </p>

        <script type="text/html" id="ggc-page-eval-row-template">
            <tr>
                <td>
                    <input type="text" name="ggc_page_eval_messages[__KEY__][key]" value="__KEY__" class="regular-text" />
                </td>
                <td>
                    <input type="text" name="ggc_page_eval_messages[__KEY__][label]" value="" class="regular-text" />
                </td>
                <td style="text-align:center;">
                    <input type="checkbox" name="ggc_page_eval_messages[__KEY__][is_global]" value="1" />
                </td>
                <td>
                    <input type="number" min="400" max="599" name="ggc_page_eval_messages[__KEY__][status_code]" value="403" class="small-text" />
                </td>
                <td>
                    <textarea name="ggc_page_eval_messages[__KEY__][message]" rows="2" class="large-text"></textarea>
                </td>
                <td>
                    <button type="button" class="button ggc-remove-page-eval">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }

    

    private function render_crawler_definitions_intro() {
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するUser-Agentのリストです。カスタムのボットを追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム内部で使用される一意の識別子です。ここにテキストを入力すると、メディアが評価条件に従い代替テキストに置き換わります。空欄の場合はメディアが表示されます。英数字のみ登録可能です。<br>
            - グループラベル : ボットのグループ名を設定します。同じグループ名を設定すると、投稿編集画面でまとめて表示されます。<br>
            - 表示ラベル : 投稿編集画面で表示される名前です。わかりやすい名前を設定してください。<br>
            - 説明文       : 投稿編集画面で表示される説明文です。必要に応じて設定してください。<br>
            - User-Agent 文字列  : UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。</p>
        <?php
    }

    private function render_crawler_definitions_table($bots, $default_bots) {
        $this->render_ua_definitions_table_common($bots, $default_bots, 'bot');
    }

    private function render_crawler_definitions_template() {
        ?>
        <script type="text/template" id="ggc-bot-row-template">
            <tr class="ggc-bot-row new-row" data-key="__KEY__">
                <td>
                    <p class="ggc-field-label"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][key]" value="__KEY__" class="regular-text ggc-bot-key ggc-field-full" />
                    <p class="ggc-field-label"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][group_label]" value="カスタム" class="regular-text ggc-field-full" />
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][label]" value="カスタムボット" class="regular-text ggc-field-full" />
                    <p class="ggc-field-label"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][description]" value="" class="regular-text ggc-field-full" />
                </td>
                <td>
                    <textarea name="ggc_crawler_definitions[__KEY__][uas]" rows="4" cols="50" class="large-text code ggc-field-full"></textarea>
                    <p class="description">UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-bot">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }

    private function render_ip_range_definitions_intro() {
        Admin_IP_Ranges::render_intro();
    }

    private function render_ip_range_definitions_update_controls($button_id) {
        Admin_IP_Ranges::render_update_controls($button_id);
    }

    private function render_ip_range_definitions_table($ip_ranges, $default_ip_ranges, $field_prefix, $table_id, $tbody_id) {
        Admin_IP_Ranges::render_table($ip_ranges, $default_ip_ranges, $field_prefix, $table_id, $tbody_id);
    }

    private function render_ip_range_definitions_template($field_prefix, $template_id, $add_button_id) {
        Admin_IP_Ranges::render_template($field_prefix, $template_id, $add_button_id);
    }

    private function render_browser_block_patterns_intro() {
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するUser-Agentのリストです。カスタムのボットを追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム内部で使用される一意の識別子です。必須項目です。英数字のみ登録可能です。<br>
            - グループラベル : ボットのグループ名を設定します。同じグループ名を設定すると、投稿編集画面でまとめて表示されます。<br>
            - 表示ラベル : 投稿編集画面で表示される名前です。わかりやすい名前を設定してください。<br>
            - 説明文       : 投稿編集画面で表示される説明文です。必要に応じて設定してください。<br>
            - User-Agent 文字列  : UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。
        </p>
        <?php
    }

    private function render_browser_block_patterns_table($patterns, $default_patterns) {
        $this->render_ua_definitions_table_common($patterns, $default_patterns, 'pattern');
    }

    private function render_browser_block_patterns_template() {
        ?>
        <script type="text/template" id="ggc-pattern-row-template">
            <tr class="ggc-pattern-row new-row" data-key="__KEY__">
                <td>
                    <p class="ggc-field-label"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][key]" value="__KEY__" class="regular-text ggc-pattern-key ggc-field-full" />
                    <p class="ggc-field-label"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][group_label]" value="カスタム" class="regular-text ggc-field-full" />
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][label]" value="カスタムパターン" class="regular-text ggc-field-full" />
                    <p class="ggc-field-label"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][description]" value="" class="regular-text ggc-field-full" />
                </td>
                <td>
                    <textarea name="ggc_browser_block_patterns[__KEY__][pattern]" rows="2" class="large-text code ggc-field-full"></textarea>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-pattern">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }


    // 診断用アクセス情報表示は trait の共通メソッドを利用してください

    // traitのCustom_Admin_Diagnosticのrender_diagnostic_scheduleを利用するため、ここでの定義は削除


    /**
     * User-Agent 定義リストセクションの表示
     */
    public function render_crawler_definitions_section() {
        $bots = Custom_Crawler_Core::get_allowable_bots();
        $default_bots = ggc_get_default_bots();
        $this->render_crawler_definitions_intro();
        $this->render_crawler_definitions_table($bots, $default_bots);
        $this->render_crawler_definitions_template();
    }

    /**
     * IPアドレス範囲 定義リストセクションの表示 (URL入力欄追加版)
     */
    public function render_ip_range_definitions_section() {
        Admin_IP_Ranges::render_section_1();
    }
    /**
     * 不正UAパターン 定義リストセクションの表示
     */
    public function render_browser_block_patterns_section() {
        $patterns = Custom_Crawler_Core::get_browser_block_patterns();
        $default_patterns = ggc_get_default_browser_patterns();
        $this->render_browser_block_patterns_intro();
        $this->render_browser_block_patterns_table($patterns, $default_patterns);
        $this->render_browser_block_patterns_template();
    }

    public function sanitize_ip_range_definitions_2($input) {
        return Admin_IP_Ranges::sanitize_definitions_2($input);
    }

    public function render_ip_range_definitions_section_2() {
        Admin_IP_Ranges::render_section_2();
    }
    // --------------------------------------------------------

    public function admin_notice_ip_update() {
        if (! Admin_Utils::current_user_can_manage_options() || is_network_admin()) return;

        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_ggc-crawler-definitions') return;

        $last_update = GGC_Options::get_last_ip_update_time();

        if ($last_update) {
            $time_diff = Display_Utils::human_time_diff($last_update, current_time('timestamp'));
            $frequency = GGC_Options::get_ip_update_frequency('daily');
            $interval_seconds = DAY_IN_SECONDS * 2;

            if (current_time('timestamp') - $last_update > $interval_seconds) {
                $nonce = wp_create_nonce('ggc_run_update_nonce');
                $ajax_url = admin_url('admin-ajax.php');
                $manual_url = wp_nonce_url(admin_url('admin-post.php?action=run_ggc_ip_update'), 'ggc_manual_ip_update_nonce');
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><strong>クローラー個別制御プラグイン:</strong> 既知クローラーIPアドレス範囲の自動更新が長期間実行されていません (最終更新: <?php echo esc_html($time_diff); ?>)。Cronが正常に動作しているか確認してください。</p>
                    <p>
                        <button type="button" class="button button-primary ggc-run-ip-update-btn" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax-url="<?php echo esc_attr($ajax_url); ?>" data-manual-url="<?php echo esc_url($manual_url); ?>" style="margin-bottom: 10px;">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                            <span class="ggc-btn-text">今すぐ IP 更新を強制実行する</span>
                        </button>
                        <span class="description" style="margin-left: 8px;">クリックすると即座にIP範囲を更新します。</span>
                    </p>
                </div>
                <?php
            }
        }
    }

    public function admin_notice_manual_ip_update_success() {
        if (! Admin_Utils::current_user_can_manage_options() || is_network_admin()) return;
        if (isset($_GET['ip-updated']) && $_GET['ip-updated'] === '1') {
            $res = GGC_Options::get_last_ip_update_result();
            $time = $res['time'] ?? GGC_Options::get_last_ip_update_time();
            $google = isset($res['google_count']) ? intval($res['google_count']) : null;

            $openai = isset($res['openai_count']) ? intval($res['openai_count']) : null;
            $msg = '<strong>クローラー個別制御プラグイン:</strong> IPアドレス範囲の強制手動更新が完了しました。';
            if ($google !== null || $openai !== null) {
                $parts = [];
                if ($google !== null) $parts[] = 'Google: ' . number_format($google) . ' 件';
                if ($openai !== null) $parts[] = 'GPTBot: ' . number_format($openai) . ' 件';
                $msg .= ' (' . implode(', ', $parts) . ')';
            }
            if ($time) {
                $msg .= ' 最終更新: ' . Display_Utils::human_time_diff($time);
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
           } else if (isset($_GET['ip-updated']) && $_GET['ip-updated'] === '0') {
               echo '<div class="notice notice-error is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> IPアドレス範囲の強制手動更新に失敗しました。外部APIにアクセスできない可能性があります。</p></div>';
           }
    }

    /**
     * AJAX: run IP update and return result
     */
    public function ajax_run_ip_update() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_run_update_nonce', 'nonce', 'manage_options');

        $core = Custom_Crawler_Core::get_instance();
        $res = $core->run_update_with_result();

        Ajax_Utils::success(['result' => $res]);
    }

    /**
     * AJAX: 指定された source_url を解析して IP/CIDR リストを返す
     */
    public function ajax_parse_ip_source() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_run_update_nonce', 'nonce', 'manage_options');

        $url = isset($_POST['url']) ? esc_url_raw(trim($_POST['url'])) : '';
        $key = isset($_POST['key']) ? sanitize_key($_POST['key']) : '';

        if (empty($url)) {
            Ajax_Utils::error(['message' => 'URLが指定されていません'], 400);
        }

        $parsed = Custom_Crawler_Core::parse_ip_list_from_url($url);

        // Determine which list to update based on key prefix or check both?
        // For AJAX simplicity, we check if the key exists in list 1 or list 2.
        // However, the current implementation of get_allowable_ip_ranges merges both.
        // We need to know which option to update.
        // Since this is just a helper to parse and return JSON, we don't necessarily need to save here?
        // Wait, the original code updates the option.

        $ip_ranges_1 = GGC_Options::get_ip_range_definitions_1() ?: [];
        $ip_ranges_2 = GGC_Options::get_ip_range_definitions_2() ?: [];

        $target_option = 'ggc_ip_range_definitions';
        $target_ranges = $ip_ranges_1;

        if (array_key_exists($key, $ip_ranges_2)) {
            $target_option = 'ggc_ip_range_definitions_2';
            $target_ranges = $ip_ranges_2;
        } elseif (!array_key_exists($key, $ip_ranges_1)) {
            // New key? Default to list 1 for now, or maybe we should pass the list ID from JS.
            // For now, let's assume list 1 if not found in either.
        }

        if (!isset($target_ranges[$key])) {
            $target_ranges[$key] = ['ranges'=>[], 'label'=>'', 'description'=>'', 'source_url'=>'', 'is_auto'=>false];
        }

        if (is_wp_error($parsed)) {
            // 保存時にユーザーに見えるよう、行ごとのエラー状態を即時保存しておく
            $target_ranges[$key]['last_parse_error'] = $parsed->get_error_message();
            $target_ranges[$key]['last_parse_time'] = time();
            update_option($target_option, $target_ranges);
            Ajax_Utils::error(['message' => $parsed->get_error_message()], 400);
        }

        // 成功した場合、エラー情報をクリアして時刻を記録
        $target_ranges[$key]['last_parse_error'] = null;
        $target_ranges[$key]['last_parse_time'] = time();
        update_option($target_option, $target_ranges);

        Ajax_Utils::success(['ranges' => $parsed]);
    }


        /**
         * 管理画面: 設定保存時に不正なIP範囲があればユーザーに通知する
         */
        public function admin_notice_invalid_ip_ranges_on_save() {
            if (! Admin_Utils::current_user_can_manage_options() || is_network_admin()) return;
            $screen = get_current_screen();
            if (!$screen || $screen->id !== 'settings_page_ggc-crawler-definitions') return;

            if (!isset($_GET['settings-updated']) || $_GET['settings-updated'] !== 'true') return;

            $ip_ranges = Custom_Crawler_Core::get_allowable_ip_ranges();
            $problems = [];
            foreach ($ip_ranges as $key => $def) {
                $raw = $def['ranges'] ?? [];
                $valid = $def['validated_ranges'] ?? [];
                if (!empty($raw)) {
                    $invalid = array_values(array_diff($raw, $valid));
                    if (!empty($invalid)) {
                        $problems[$key] = $invalid;
                    }
                }
            }

            if (!empty($problems)) {
                // filter out those where allow_placeholder is set
                $filtered = [];
                foreach ($problems as $key => $invalids) {
                    if (!empty($ip_ranges[$key]['allow_placeholder'])) continue;
                    $filtered[$key] = $invalids;
                }
                if (!empty($filtered)) {
                    echo '<div class="notice notice-warning is-dismissible"><p><strong>クローラー個別制御:</strong> 入力された一部の IP/CIDR は形式チェックに失敗しましたが、プレースホルダとして保存されました。必要に応じて正しい形式に修正してください。</p>';
                    echo '<ul class="ggc-list-compact">';
                    foreach ($filtered as $key => $invalids) {
                        $label = $ip_ranges[$key]['label'] ?? $key;
                        echo '<li><strong>' . esc_html($label) . '</strong> (キー: <code>' . esc_html($key) . '</code>): ' . esc_html(implode(', ', $invalids)) . '</li>';
                    }
                    echo '</ul></div>';
                }
            }
        // デバッグ用 UI は運用に不要なため削除 (必要なら履歴やログで対応)
        }


    public function admin_action_run_ggc_ip_update() {
        Admin_Utils::require_manage_options_or_die();

        Admin_Utils::require_get_nonce_and_cap('ggc_manual_ip_update_nonce');

        $updated = Custom_Crawler_Core::get_instance()->update_all_ip_ranges();

        $redirect_url = remove_query_arg(['action', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('ip-updated', $updated ? '1' : '0', $redirect_url);

        // タブパラメータを維持
        if (isset($_GET['tab'])) {
            $redirect_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * IP設定を削除して初期状態に戻す
     */
    public function admin_action_reset_ggc_ip_settings() {
        Admin_Utils::require_manage_options_or_die();

        Admin_Utils::require_get_nonce_and_cap('ggc_reset_ip_settings_nonce');

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'ips';

        if ($tab === 'ips2') {
            delete_option('ggc_ip_range_definitions_2');
        } else {
            delete_option('ggc_ip_range_definitions');
        }

        // リセット完了通知用のフラグをつけてリダイレクト
        $redirect_url = remove_query_arg(['action', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('ip-reset', '1', $redirect_url);

        // タブパラメータを維持
        if (isset($_GET['tab'])) {
            $redirect_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * リセット完了通知
     */
    public function admin_notice_reset_success() {
        if (! Admin_Utils::current_user_can_manage_options() || is_network_admin()) return;
        if (isset($_GET['ip-reset']) && $_GET['ip-reset'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> IPアドレス範囲設定を初期化（削除）しました。デフォルト値が表示されています。</p></div>';
        }
    }

    /**
     * 全データクリア完了通知
     */
    public function admin_notice_clear_all_success() {
        if (! Admin_Utils::current_user_can_manage_options() || is_network_admin()) return;
        if (isset($_GET['ggc-cleared']) && $_GET['ggc-cleared'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> 設定オプション・投稿/メディアのメタデータ・IP更新履歴を削除しました。</p></div>';
            // 2秒後に自動リロード（リロード時に ggc-cleared クエリを除去して無限ループ防止）
            echo '<script>setTimeout(function(){var url = new URL(window.location.href);url.searchParams.delete("ggc-cleared");window.location.replace(url.toString());}, 2000);</script>';
        }
    }

    /**
     * 全データ（オプション/メタ/履歴）を削除
     */
    public function admin_action_clear_all_data() {
        Admin_Utils::require_manage_options_or_die();

        Admin_Utils::require_get_nonce_and_cap('ggc_clear_all_data_nonce');

        // まずフラグを設定（できるだけ早く）
        update_option('ggc_clear_all_done', '1');
        wp_cache_flush();

        global $wpdb;

        // ggc_ で始まるオプションを削除
        $opt_like = $wpdb->esc_like('ggc_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $opt_like));

        // 投稿/メディアに保存された ggc_ 系メタを削除
        $meta_like = $wpdb->esc_like('_ggc_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like));
        $meta_like2 = $wpdb->esc_like('ggc_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like2));

        // スケジュールをクリア
        wp_clear_scheduled_hook('ggc_daily_ip_update');

        // IP更新頻度を初期化してスケジュール再設定
        update_option('ggc_ip_update_frequency', 'daily');
        Custom_Crawler_Core::ip_update_schedule_check();

        // 定義リストは空として保持（デフォルト復元を抑止）
        update_option('ggc_crawler_definitions', []);
        update_option('ggc_browser_block_patterns', []);
        update_option('ggc_ip_range_definitions', []);
        update_option('ggc_ip_range_definitions_2', []);
        update_option('ggc_page_eval_messages', []);

        // Markdownテンプレートは空として保持（デフォルト復元を抑止）
        update_option('ggc_markdown_templates', []);
        update_option('ggc_markdown_templates_cleared', '1');
        // フラグは既に設定済み
        update_option('ggc_clear_all_done', '1');

        // キャッシュをクリア
        wp_cache_flush();

        $redirect_url = remove_query_arg(['action', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('ggc-cleared', '1', $redirect_url);

        if (isset($_GET['tab'])) {
            $redirect_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }


    /**
     * おすすめ設定のインポート完了通知
     */
    public function admin_notice_import_success() {
        if (! Admin_Utils::current_user_can_manage_options()) return;
        if (isset($_GET['settings-imported']) && $_GET['settings-imported'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御:</strong> おすすめ設定をインポートしました。</p></div>';
        }
    }

    /**
     * おすすめ設定をインポートするアクション
     */
    public function admin_action_import_default_settings() {
        Admin_Utils::require_manage_options_or_die();

        Admin_Utils::require_get_nonce_and_cap('ggc_import_defaults_nonce');

        // 現在の設定を取得
        $current_bots = GGC_Options::get_crawler_definitions() ?: [];
        $current_ips = GGC_Options::get_ip_range_definitions_1() ?: [];
        $current_ips_2 = GGC_Options::get_ip_range_definitions_2() ?: [];
        $current_patterns = GGC_Options::get_browser_block_patterns() ?: [];
        $current_templates = GGC_Options::get_markdown_templates();
        $current_page_eval = GGC_Options::get_page_eval_messages();

        // デフォルト設定を取得
        $default_bots = ggc_get_default_bots();
        $default_ips = ggc_get_default_ip_ranges();
        $default_ips_2 = ggc_get_default_ip_ranges_2();
        $default_patterns = ggc_get_default_browser_patterns();
        $default_templates = ggc_get_default_markdown_templates();
        $default_page_eval = ggc_get_default_page_eval_messages();

        // --- 堅牢なマージ処理 ---
        // 既存のキーをすべて小文字で保持
        $existing_bot_keys = array_map('strtolower', array_keys($current_bots));
        $existing_ip_keys = array_map('strtolower', array_keys($current_ips));
        $existing_ip_keys_2 = array_map('strtolower', array_keys($current_ips_2));
        $existing_pattern_keys = array_map('strtolower', array_keys($current_patterns));
        $existing_template_keys = array_map('strtolower', array_keys($current_templates));
        $existing_page_eval_keys = array_map('strtolower', array_keys($current_page_eval));

        // デフォルト設定をループし、小文字に変換したキーが存在しない場合のみ追加
        foreach ($default_bots as $key => $value) {
            if (!in_array(strtolower($key), $existing_bot_keys)) {
                $current_bots[$key] = $value;
            }
        }
        foreach ($default_ips as $key => $value) {
            if (!in_array(strtolower($key), $existing_ip_keys)) {
                $current_ips[$key] = $value;
            }
        }
        foreach ($default_ips_2 as $key => $value) {
            if (!in_array(strtolower($key), $existing_ip_keys_2)) {
                $current_ips_2[$key] = $value;
            }
        }
        foreach ($default_patterns as $key => $value) {
            if (!in_array(strtolower($key), $existing_pattern_keys)) {
                $current_patterns[$key] = $value;
            }
        }
        foreach ($default_page_eval as $key => $value) {
            if (!in_array(strtolower($key), $existing_page_eval_keys)) {
                $current_page_eval[$key] = $value;
            }
        }
        foreach ($default_templates as $key => $value) {
            if (!in_array(strtolower($key), $existing_template_keys)) {
                $current_templates[$key] = $value;
            }
        }

        // データベースを更新
        // update_option は register_setting で登録した sanitize_option フィルターを
        // 自動的に通すため、sanitize_crawler_definitions が呼ばれてキー名が
        // 小文字化されてしまう問題を防ぐため、保存前にフィルターを一時的に外す。
        global $wp_filter;
        $saved_bot_filter = isset($wp_filter['sanitize_option_ggc_crawler_definitions'])
            ? clone $wp_filter['sanitize_option_ggc_crawler_definitions'] : null;
        $saved_pattern_filter = isset($wp_filter['sanitize_option_ggc_browser_block_patterns'])
            ? clone $wp_filter['sanitize_option_ggc_browser_block_patterns'] : null;
        remove_all_filters('sanitize_option_ggc_crawler_definitions');
        remove_all_filters('sanitize_option_ggc_browser_block_patterns');

        update_option('ggc_crawler_definitions', $current_bots);
        update_option('ggc_ip_range_definitions', $current_ips);
        update_option('ggc_ip_range_definitions_2', $current_ips_2);
        update_option('ggc_browser_block_patterns', $current_patterns);
        update_option('ggc_markdown_templates', $current_templates);
        update_option('ggc_page_eval_messages', $current_page_eval);

        // フィルターを元に戻す
        if ($saved_bot_filter !== null) {
            $wp_filter['sanitize_option_ggc_crawler_definitions'] = $saved_bot_filter;
        }
        if ($saved_pattern_filter !== null) {
            $wp_filter['sanitize_option_ggc_browser_block_patterns'] = $saved_pattern_filter;
        }

        // インポート直後にIPアドレスの自動取得を実行
        Custom_Crawler_Core::get_instance()->update_all_ip_ranges();

        // 完了フラグをつけてリダイレクト
        $redirect_url = remove_query_arg(['action', '_wpnonce', 'settings-imported'], wp_get_referer());
        $redirect_url = add_query_arg('settings-imported', '1', $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

}
