<?php
// custom-crawler-control\admin\class-admin-settings.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Admin_Settings {

    protected static $instance = null;

    // タブの定義
    private $tabs = [
        'general'  => '一般設定',
        'bots'     => 'User-Agent 定義1',
        'patterns' => 'User-Agent 定義2',
        'ips'      => 'IPアドレス範囲1',
        'ips2'     => 'IPアドレス範囲2',
        'tools'    => '診断ツール',
        'usage'    => 'アプリの使い方',
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

        $plugin_dir = plugin_dir_path(dirname(__DIR__) . '/custom-crawler-control.php');
        $plugin_url = plugin_dir_url(dirname(__DIR__) . '/custom-crawler-control.php');
        $js_asset_path = $plugin_dir . 'js/admin-settings.js';

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
        ]);

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
        return isset($_GET['tab']) && array_key_exists($_GET['tab'], $this->tabs) ? $_GET['tab'] : 'general';
    }

    /**
     * 設定の登録、セクション、フィールドの定義
     */
    public function register_settings() {
        // 1. 一般設定
        register_setting('ggc_general_option_group', 'ggc_ip_update_frequency', ['sanitize_callback' => [ $this, 'sanitize_ip_update_frequency' ], 'default' => 'daily']);

        add_settings_section(
            'ggc_general_settings',
            '一般設定',
            [ $this, 'render_general_settings_section' ],
            'ggc_tab_general'
        );

        // 2. User-Agent 定義リスト
        register_setting('ggc_bots_option_group', 'ggc_crawler_definitions', ['sanitize_callback' => [ $this, 'sanitize_crawler_definitions' ], 'default' => Custom_Crawler_Core::get_allowable_bots()]);

        add_settings_section(
            'ggc_crawler_definitions',
            'User-Agent 定義リスト1',
            [ $this, 'render_crawler_definitions_section' ],
            'ggc_tab_bots'
        );

        // 3. IPアドレス範囲 定義リスト
        register_setting('ggc_ips_option_group', 'ggc_ip_range_definitions', ['sanitize_callback' => [ $this, 'sanitize_ip_range_definitions' ], 'default' => ggc_get_default_ip_ranges()]);

        add_settings_section(
            'ggc_ip_range_definitions',
            'IPアドレス範囲 定義リスト1',
            [ $this, 'render_ip_range_definitions_section' ],
            'ggc_tab_ips'
        );

        // 4. 不正UAパターン 定義リスト
        register_setting('ggc_patterns_option_group', 'ggc_browser_block_patterns', ['sanitize_callback' => [ $this, 'sanitize_browser_block_patterns' ], 'default' => Custom_Crawler_Core::get_browser_block_patterns()]);

        add_settings_section(
            'ggc_browser_block_patterns',
            'User-Agent 定義リスト2',
            [ $this, 'render_browser_block_patterns_section' ],
            'ggc_tab_patterns'
        );

        // 5. IPアドレス範囲 定義リスト2
        register_setting('ggc_ips2_option_group', 'ggc_ip_range_definitions_2', ['sanitize_callback' => [ $this, 'sanitize_ip_range_definitions_2' ], 'default' => ggc_get_default_ip_ranges_2()]);

        add_settings_section(
            'ggc_ip_range_definitions_2',
            'IPアドレス範囲 定義リスト2',
            [ $this, 'render_ip_range_definitions_section_2' ],
            'ggc_tab_ips2'
        );

        // Removed 'ページ個別制御の初期状態' setting
        // Added new global settings for User-Agent and IP address evaluation
        register_setting('ggc_general_option_group', 'ggc_global_user_agent_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'apply_new_posts']);
        register_setting('ggc_general_option_group', 'ggc_global_ip_evaluation', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'apply_new_posts']);

        // Media-specific global settings (separate from page-level globals)
        register_setting('ggc_general_option_group', 'ggc_global_media_user_agent_control', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'apply_new_posts']);
        register_setting('ggc_general_option_group', 'ggc_global_media_ip_evaluation', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'apply_new_posts']);

        // Global selection lists when using global blacklist/whitelist (page-level)
        register_setting('ggc_general_option_group', 'ggc_global_selected_crawlers', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_selected_ips', ['sanitize_callback' => [ $this, 'sanitize_global_selected_ips' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_selected_ips_2', ['sanitize_callback' => [ $this, 'sanitize_global_selected_ips' ], 'default' => []]);

        // Global selection lists for media when using global blacklist/whitelist (media-level)
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_crawlers', ['sanitize_callback' => [ $this, 'sanitize_global_selected_crawlers' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_ips', ['sanitize_callback' => [ $this, 'sanitize_global_selected_ips' ], 'default' => []]);
        register_setting('ggc_general_option_group', 'ggc_global_media_selected_ips_2', ['sanitize_callback' => [ $this, 'sanitize_global_selected_ips' ], 'default' => []]);

        // Alt text behavior for excluded media: none | individual | fixed
        register_setting('ggc_general_option_group', 'ggc_alt_mode', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'none']);
        register_setting('ggc_general_option_group', 'ggc_alt_fixed_text', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
        register_setting('ggc_general_option_group', 'ggc_alt_fixed_text_featured', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    }

    // --------------------------------------------------------
    // サニタイズ関数
    // --------------------------------------------------------

    public function sanitize_crawler_definitions($input) {
        $new_input = [];
        if (!is_array($input)) {
            return [];
        }
        // Preserve canonical default keys: map lowercase -> original
        $defaults = ggc_get_default_bots();
        $default_key_map = [];
        foreach ($defaults as $dkey => $_) {
            $default_key_map[strtolower($dkey)] = $dkey;
        }
        foreach ($input as $key => $bot) {
            $new_key = sanitize_key($bot['key'] ?? $key);
            // If this key corresponds to a default (case-insensitive), restore canonical default key name
            $lk = strtolower($new_key);
            if (isset($default_key_map[$lk])) {
                $new_key = $default_key_map[$lk];
            }
            if (empty($new_key)) continue;

            $uas = isset($bot['uas']) ? $bot['uas'] : '';
            if (is_string($uas)) {
                $uas = array_map('trim', explode(',', $uas));
            }
            $new_input[$new_key] =
            [
                'uas' => is_array($uas) ? array_map('sanitize_text_field', array_filter($uas)) : [],
                'label' => sanitize_text_field($bot['label'] ?? ''),
                'group_label' => sanitize_text_field($bot['group_label'] ?? 'その他'),
                'description' => sanitize_textarea_field($bot['description'] ?? ''),
            ];
        }
        return $new_input;
    }

    // Sanitize functions for global selected lists
    public function sanitize_global_selected_crawlers($input) {
        if (!is_array($input)) return [];
        return array_values(array_map('sanitize_key', array_filter($input)));
    }

    public function sanitize_global_selected_ips($input) {
        if (!is_array($input)) return [];
        return array_values(array_map('sanitize_key', array_filter($input)));
    }

    /**
     * IPアドレス範囲定義のサニタイズ
     */

    public function sanitize_ip_range_definitions($input) {
        $current = get_option('ggc_ip_range_definitions', []);
        $defaults = ggc_get_default_ip_ranges();
        $default_keys_map = [];
        foreach ($defaults as $def_key => $def_val) {
            $default_keys_map[strtolower($def_key)] = $def_key;
        }

        if (!is_array($input)) return [];

        $new_input = [];

        $simple_validate_ip_cidr = function ($range) {
            $range = trim($range);
            if (empty($range)) return false;
            if (filter_var($range, FILTER_VALIDATE_IP)) return sanitize_text_field($range);
            if (strpos($range, '/') !== false) {
                list($ip, $mask) = explode('/', $range, 2);
                $mask = intval($mask);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) return sanitize_text_field($range);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) return sanitize_text_field($range);
                if (strpos($ip, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $ip) && $mask >= 0 && $mask <= 128) return sanitize_text_field($range);
                if (strpos($ip, '.') !== false && preg_match('/^[0-9.]+$/', $ip) && $mask >= 0 && $mask <= 32) return sanitize_text_field($range);
            }
            return false;
        };

        foreach ($input as $key => $ip_def) {
            $raw_key = $ip_def['key'] ?? $key;
            if (empty(trim($raw_key))) continue;

            $lower_key = strtolower($raw_key);
            $new_key = '';
            $is_default_key = false;

            if (array_key_exists($lower_key, $default_keys_map)) {
                $new_key = $default_keys_map[$lower_key];
                $is_default_key = true;
            } else {
                $sanitized_key = sanitize_key($raw_key);
                $new_key = !empty($sanitized_key) ? $sanitized_key : sanitize_key($key);
            }

            $ranges_input = '';
            if (isset($ip_def['ranges'])) {
                $ranges_input = is_array($ip_def['ranges']) ? implode("\n", array_map('strval', $ip_def['ranges'])) : (string) $ip_def['ranges'];
            }

            if (!$is_default_key && empty($ranges_input) && empty($ip_def['label']) && empty($ip_def['source_url'])) {
                continue;
            }

            // Initialize variables for this loop iteration
            $loop_last_parse_error = null;
            $loop_last_parse_time = null;
            $loop_last_parse_count = 0;

            $ranges = preg_split('/[\r\n,]+/', $ranges_input, -1, PREG_SPLIT_NO_EMPTY);
            $ranges = array_map('trim', $ranges);
            $sanitized_ranges_raw = array_values(array_map('sanitize_text_field', array_filter($ranges)));
            $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $ranges)));

            $is_auto = !empty($ip_def['is_auto']);
            $default_def = $current[$new_key] ?? ['source_url' => ''];
            $source_url_to_save = isset($ip_def['source_url']) ? esc_url_raw(trim($ip_def['source_url'])) : $default_def['source_url'];
            if (empty($source_url_to_save) && isset($defaults[$new_key]['source_url'])) {
                $source_url_to_save = $defaults[$new_key]['source_url'];
            }

            if ($is_auto && !empty($source_url_to_save)) {
                $parsed = Custom_Crawler_Core::parse_ip_list_from_url($source_url_to_save);
                $loop_last_parse_time = time();

                if (is_wp_error($parsed)) {
                    $loop_last_parse_error = $parsed->get_error_message();
                    add_settings_error('ggc_ips_option_group', 'ggc_parse_failed_' . $new_key, sprintf('%s の自動解析に失敗しました: %s', esc_html($new_key), esc_html($loop_last_parse_error)), 'error');
                } else {
                    $parsed_clean = array_values(array_map('sanitize_text_field', $parsed));
                    $sanitized_ranges_raw = $parsed_clean;
                    $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $parsed_clean)));
                    $loop_last_parse_count = count($sanitized_ranges_raw);
                }
            } else {
                // If not auto-updating on this save, preserve the last known status.
                $loop_last_parse_error = $current[$new_key]['last_parse_error'] ?? null;
                $loop_last_parse_time = $current[$new_key]['last_parse_time'] ?? null;
                $loop_last_parse_count = $current[$new_key]['last_parse_count'] ?? 0;
            }

            $new_input[$new_key] = [
                'ranges' => $sanitized_ranges_raw,
                'validated_ranges' => empty($sanitized_ranges_valid) ? null : $sanitized_ranges_valid,
                'allow_placeholder' => !empty($ip_def['allow_placeholder']),
                'label' => sanitize_text_field($ip_def['label'] ?? ($current[$new_key]['label'] ?? '')),
                'group_label' => sanitize_text_field($ip_def['group_label'] ?? ($current[$new_key]['group_label'] ?? 'その他')),
                'description' => sanitize_textarea_field($ip_def['description'] ?? ($current[$new_key]['description'] ?? '')),
                'source_url' => $source_url_to_save,
                'is_default' => $is_default_key,
                'is_auto' => $is_auto,
                'last_parse_error' => $loop_last_parse_error,
                'last_parse_time' => $loop_last_parse_time,
                'last_parse_count' => $loop_last_parse_count,
            ];

            if ($is_auto && $is_default_key && isset($defaults[$new_key])) {
                $new_input[$new_key]['label'] = $defaults[$new_key]['label'];
                $new_input[$new_key]['group_label'] = $defaults[$new_key]['group_label'] ?? 'その他';
                $new_input[$new_key]['description'] = $defaults[$new_key]['description'];
            }
        }
        return $new_input;
    }

    public function sanitize_browser_block_patterns($input) {
        $new_input = [];
        $default = ggc_get_default_browser_patterns();

        if (!is_array($input)) return [];

        foreach ($input as $key => $pattern_def) {
            $new_key = sanitize_key($pattern_def['key'] ?? $key);
            if (empty($new_key)) continue;
            if (!is_array($pattern_def)) continue;

            $pattern_val = sanitize_textarea_field($pattern_def['pattern'] ?? '');

            // 完全に空のカスタムエントリーを排除
            $is_default = isset($default[$new_key]);
            if (!$is_default && empty($pattern_val) && empty($pattern_def['label'])) {
                continue;
            }

            $new_input[$new_key] =
            [
                'pattern' => $pattern_val,
                'label' => sanitize_text_field($pattern_def['label'] ?? ''),
                'group_label' => sanitize_text_field($pattern_def['group_label'] ?? 'カスタム'),
                'description' => sanitize_textarea_field($pattern_def['description'] ?? ''),
                'is_default' => $is_default,
            ];
        }
        return $new_input;
    }

    public function sanitize_default_control_active($input) {
        return in_array($input, ['yes', 'no']) ? $input : 'no';
    }

    public function sanitize_ip_update_frequency($input) {
        // allow disabling automatic updates and new frequencies
        return in_array($input, ['disabled', 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly', 'biannually', 'annually']) ? $input : 'daily';
    }



    // --------------------------------------------------------
    // HTML 出力関数
    // --------------------------------------------------------

    /**
     * タブナビゲーションの描画
     */
    private function render_nav_tabs($current_tab) {
        echo '<nav class="nav-tab-wrapper">';
        foreach ($this->tabs as $tab_key => $tab_label) {
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = $this->get_current_tab();
        ?>
        <div class="wrap">
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

                    submit_button('設定を保存');
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * アプリの使い方セクションの表示
     */
    public function render_usage_section() {
        ?>
        <div class="ggc-usage-section" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px; max-width: 1000px;">
            <h2>アプリの使い方</h2>
            <p>このプラグインは、WordPressの投稿や固定ページごとに、特定のクローラー（検索エンジン、AIボットなど）やIPアドレスからのアクセスを制御するための強力なツールです。</p>

            <div class="notice notice-error inline" style="margin: 15px 0; padding: 15px;">
                <h3 style="margin-top: 0;">⚠️ 重要な注意事項と免責事項</h3>

                <h4 style="margin-bottom: 5px;">1. 制御の優先順位と技術的制限</h4>
                <p>本プラグインは <strong>WordPress (PHP) レイヤー</strong> で動作します。そのため、以下の制限があります。</p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><strong>robots.txt やサーバー設定が優先されます:</strong> robots.txt、Webサーバー設定（Apache/Nginx）、WAF、CDNなどで拒否されているアクセスは、本プラグインに到達する前にブロックされます。</li>
                    <li><strong>PHPが実行されないアクセスは制御できません:</strong> 画像ファイル、CSS、JSなどの静的ファイルへの直接アクセスや、キャッシュプラグイン（WP Super Cacheなど）によって生成された静的HTMLへのアクセスは、PHPを経由しないため制御できません。<br>
                    <strong>対策:</strong> 会員限定ページなど重要なページでは、キャッシュプラグインの除外設定を行ってください。</li>
                    <li><strong>表示するブラウザのキャッシュが残っている場合、ページが表示されたり、画像が表示されたままになる事があります。</li>
                </ul>

                <h4 style="margin-bottom: 5px;">2. 完全なブロックの保証はありません</h4>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><strong>UA偽装:</strong> 悪意のあるクローラーが一般的なブラウザの User-Agent を偽装した場合、UA判定だけでは防げないことがあります（IP制限との併用を推奨）。</li>
                    <li><strong>保証の限界:</strong> 本プラグインは、アクセス制御の労力を減らし、既知のボットを効率的に管理するためのツールです。完全なセキュリティ防御が必要な場合は、WAFなどの導入をご検討ください。</li>
                </ul>

                <h4 style="margin-bottom: 5px;">3. 設定時の注意</h4>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><strong>自分自身をブロックしない:</strong> 特に「IPアドレス評価」でホワイトリスト（許可）モードを使用する場合、自分のIPアドレスを含めないとページを閲覧できなくなります（管理画面には影響しません）。</li>
                </ul>
            </div>

            <hr>

            <h3>1. 設定のステップ</h3>

            <h4>Step 1: 定義リストの作成（この画面）</h4>
            <p>まず、制御に使用する「リスト」を作成します。初期設定として「おすすめ設定をインポート」することをお勧めします。</p>
            <table class="widefat striped" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th style="width: 20%;">タブ名</th>
                        <th>用途例</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>User-Agent 定義1</strong></td>
                        <td>Googlebot, Bingbot, GPTBot などの「既知のボット」を登録します。これらは通常、特定の目的でアクセスしてくる善良なボットです。</td>
                    </tr>
                    <tr>
                        <td><strong>User-Agent 定義2</strong></td>
                        <td>「headless」, 「selenium」, 「python」 など、一般的なブラウザではないアクセスや、悪意のあるスクレイピングツールを検出するための「パターン（部分一致）」を登録します。</td>
                    </tr>
                    <tr>
                        <td><strong>IPアドレス範囲 1 & 2</strong></td>
                        <td>許可または拒否したいIPアドレスの範囲（CIDR形式）を登録します。<br>
                        GoogleやOpenAIなどの公開IPリストURLを設定し「自動更新」を有効にすると、定期的に最新のIP範囲を取り込みます。</td>
                    </tr>
                </tbody>
            </table>

            <h4>Step 2: 設定画面の一般設定</h4>
            <p>まず「一般設定」タブでグローバル設定を決めます。ここでの選択が投稿画面、固定ページより<strong>優先</strong>されます。</p>
            <ul>
                <li><strong>グローバル設定 User-Agent-ページ : </strong>User-Agentでアクセス制限の設定を行います。</li>
                <li><strong>グローバル設定 IPアドレスの評価-ページ : </strong>IPアドレスでアクセス制限の設定を行います。</li>
                <li><strong>グローバル設定 User-Agent-メディア : </strong>User-Agentでメディア制御の設定を行います。</li>
                <li><strong>グローバル設定 IPアドレスの評価-メディア : </strong>IPアドレスでメディア制御の設定を行います。</li>
                <li><strong>アイキャッチ画像の代替テキスト（ブラックリスト/ホワイトリスト使用時）: </strong>グローバル設定でブラックリスト、ホワイトリスト設定した場合にアイキャッチ画像を代替テキストにします。</li>
                <Li><strong>代替テキスト（ブラックリスト/ホワイトリスト使用時）: </strong>グローバル設定でブラックリスト、ホワイトリスト設定した場合にメディアを代替テキストにします。</li>
                <li><strong>IPアドレスの自動更新頻度 : </strong>IPアドレス範囲の自動更新の頻度を設定します。</li>
                <li><strong>おすすめ設定のインポート : </strong>設定の推奨値をインポートします。</li>
                <li><strong>全データのクリア : </strong>すべての設定データを初期化します。プラグインのアップデートやトラブルシューティング時に使用してください。</li>
            </ul>

            <h4>Step 3: 投稿・固定ページでの適用</h4>
            <p>記事の投稿画面（または固定ページの編集画面）のサイドバーにある「アクセス制御」ボックスで設定します。</p>
            <ul>
                <li><strong>User-Agent の評価-ページ / IPアドレスの評価-ページ : </strong> アクセス制限の設定を行います。</li>
                <li><strong>User-Agent の評価 - メディア / IPアドレスの評価 - メディア : </strong> メディアを代替テキストに置換する設定を行います。</li>
                <li><strong>アイキャッチ画像の代替テキスト : </strong>アイキャッチ画像の代替テキストを置換する設定を行います。空欄の場合は置換しません。</li>
                <li><strong>代替テキスト : </strong>「メディア選択」→「ブロック」→「アクセス制限」内の「代替えテキスト」入力でメディアを代替テキストに置換します。空欄の場合は置換しません。メディア単位で設定可能です。</li>
            </ul>

            <hr>

            <h3>2. 判定の優先順位とロジック</h3>
            <p>アクセスがあった際、以下の順序で評価が行われます。どちらかで「拒否」と判定された時点でアクセスはブロックされます。</p>
            <ol>
                <li><strong>グローバル設定の優先:</strong>
                    <ul>
                        <li>「グローバル設定」は設定画面の「一般設定」タブで決定されます。</li>    
                        <li>「グローバル設定」＞「投稿ページ・固定ページ」の優先度で制御が行われます。</li>                
                    </ul>
                </li>
                <li><strong> User-Agent-ページ/IPアドレスの評価-ページ:</strong>
                    <ul>
                        <li>「一般設定」タブで「アクセス制御設定を適用しない」を選択している場合、投稿ページ、固定ページごとの設定（ブラックリスト/ホワイトリスト等）に関わらず、<strong>すべてのアクセス制御が無効化</strong>されます。</li>
                        <li>「一般設定」タブで「アクセス制限を新規投稿で適用」を選択している場合のみ、記事ごとの設定が優先されます。</li>
                        <li>「一般設定」タブで「全ページでブラックリスト」/「全ページでホワイトリスト」を選択している場合は、設定画面のリストが常に優先されます。</li>
                    </ul>
                </li>
                <li><strong> User-Agent-メディア/ IPアドレスの評価-メディア:</strong>
                    <ul>
                        <li>「一般設定」タブで「メディア制御設定を適用しない」を選択している場合、投稿ページ、固定ページごとの設定（ブラックリスト/ホワイトリスト等）に関わらず、<strong>すべてのメディア制御が無効化</strong>されます。</li>
                        <li>「一般設定」タブで「メディア制限を新規投稿で適用」を選択している場合のみ、記事ごとの設定が優先されます。</li>
                        <li>「一般設定」タブで「全ページでブラックリスト」/「全ページでホワイトリスト」を選択している場合は、設定画面のリストが常に優先されます。</li>
                    </ul>
                </li>

                <li><strong>User-Agent 評価:</strong> ブラウザ名（User-Agent）が一致した場合に評価されます。</li>
                <li><strong>IPアドレス 評価:</strong> UA評価を通過した場合、次にIPアドレスが評価されます。</li>

                <li><strong>各モードの適用範囲:</strong>
                    <table class="widefat striped" style="margin-top:6px; max-width: 820px;">
                        <thead>
                            <tr>
                                <th style="width: 18%;">モード</th>
                                <th style="width: 42%;">モードの解説</th>
                                <th style="width: 20%;">ページ</th>
                                <th style="width: 20%;">メディア</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>ブラックリスト</strong></td>
                                <td>一致したものを拒否、それ以外は許可</td>
                                <td>選択したものをアクセス制限、それ以外は許可します。</td>
                                <td>選択したものをメディアを代替テキストに変更。それ以外はメディアを表示します。</td>
                            </tr>
                            <tr>
                                <td><strong>ホワイトリスト</strong></td>
                                <td>一致したものだけ許可、それ以外は拒否</td>
                                <td>選択したものだけアクセス許可、それ以外は拒否します。</td>
                                <td>選択したものはメディアを表示、それ以外は代替テキストに変更します。</td>
                            </tr>
                            <tr>
                                <td><strong>全許可</strong></td>
                                <td>すべて許可（評価なし）</td>
                                <td>アクセス制限をしません。</td>
                                <td>メディアを表示します。</td>
                            </tr>
                            <tr>
                                <td><strong>全拒否</strong></td>
                                <td>すべて拒否（評価なし）</td>
                                <td>アクセス制限をします。</td>
                                <td>すべてのメディアを代替テキストに変更します。</td>
                            </tr>
                        </tbody>
                    </table>
                </li>
            </ol>

            <hr>

            <h3>3. トラブルシューティング</h3>

            <h4>Q. 設定を間違えてページが見られなくなりました。</h4>
            <p>A. 管理画面（ダッシュボード）にはアクセス制御は適用されません。管理画面にログインし、グローバル設定を「制御設定しない」にします。個別設定は、該当する記事の編集画面で「全許可」または「グローバル設定に従う」に戻してください。</p>

            <h4>Q. IPアドレスの自動更新が動きません。</h4>
            <p>A. 「一般設定」タブで更新頻度が「停止」になっていないか確認してください。また、「診断ツール」タブで次回の実行予定時刻を確認できます。「今すぐIP更新を強制実行する」ボタンで手動更新も可能です。</p>

            <h4>Q. 特定のボットだけブロックしたいのですが？</h4>
            <p>A. 「User-Agent 定義1」にそのボットを追加し、記事の編集画面で「User-Agent の評価」を「ブラックリスト」にして、そのボットにチェックを入れてください。</p>

            <h4>Q. 504などのサーバエラーが発生します。</h4>
            <p>サーバの設定によってアプリが動作しない場合があります。WAF設定などを確認してください。また本プラグインはクーロンを使用します。許可されているか確認してください。</p>

            <hr>

            <h3>4. アプリ情報</h3>
            <table class="widefat striped" style="max-width: 600px;">
                <tbody>
                    <tr>
                        <td style="width: 30%;"><strong>プラグイン名</strong></td>
                        <td>Smart Access Control</td>
                    </tr>
                    <tr>
                        <td><strong>バージョン</strong></td>
                        <td>3.0.1</td>
                    </tr>
                    <tr>
                        <td><strong>作者</strong></td>
                        <td>donnma (<a href="https://donnma.com/" target="_blank">donnma.com</a>)</td>
                    </tr>
                    <tr>
                        <td><strong>GitHub</strong></td>
                        <td><a href="https://github.com/donnma777/smart-access-control" target="_blank">donnma777/smart-access-control</a></td>
                    </tr>
                    <tr>
                        <td><strong>リリース情報</strong></td>
                        <td><a href="https://github.com/donnma777/smart-access-control/releases" target="_blank">Releases</a></td>
                    </tr>
                    <tr>
                        <td><strong>X (Twitter)</strong></td>
                        <td><a href="https://x.com/donnma777" target="_blank">@donnma777</a></td>
                    </tr>
                </tbody>
            </table>

        </div>
        <?php
    }

    /**
     * 一般設定セクションの表示
     */
    public function render_general_settings_section() {

        // Removed $default_control_active
        $ip_update_frequency = get_option('ggc_ip_update_frequency', 'daily');
        $global_ua_control = get_option('ggc_global_user_agent_control', 'apply_new_posts');
        $global_ip_evaluation = get_option('ggc_global_ip_evaluation', 'apply_new_posts');
        ?>
        <div class="ggc-about" style="margin-bottom: 20px; padding: 12px 15px; background: #f6f7f7; border-left: 4px solid #2271b1;">
        <p style="margin: 0 0 6px 0; font-weight: bold;">
            🔒 クローラー個別制御（Custom Crawler Control）
        </p>
        <p style="margin: 0 0 8px 0;">
            投稿・固定ページ単位で、検索エンジン・AIクローラー・不正ボットのアクセスを
            <strong>User-Agent / IPアドレス </strong>で精密に制御できる
            管理者向け WordPress プラグインです。アクセス制限を行ったり、画像などのメディアをテキストに置換可能です。(完全にアクセスを防ぐ保証はありません。ご了承ください。詳しくはアプリの使い方、GitHubリポジトリをご覧ください。)
        </p>
        <p style="margin: 0;">
            🔗 GitHub : <a href="https://github.com/donnma777/smart-access-control" target="_blank" rel="noopener noreferrer">
                 リポジトリを見る
            </a>
        </p>
        <p style="margin: 0;">
            🔗 作者 : <a href="https://donnma.com/" target="_blank" rel="noopener noreferrer">
                donnma
            </a>
        </p>
        </div>

        <table class="form-table">
            <tr>
                <th scope="row">グローバル設定 User-Agent-ページ</th>
                <td>
                    <select name="ggc_global_user_agent_control" id="ggc_global_user_agent_control_select">
                        <option value="none" <?php selected($global_ua_control, 'none'); ?>>アクセス制御設定を適用しない</option>
                        <option value="apply_new_posts" <?php selected($global_ua_control, 'apply_new_posts'); ?>>アクセス制御設定を新規投稿で適用</option>
                        <option value="global_blacklist" <?php selected($global_ua_control, 'global_blacklist'); ?>>全ページでブラックリスト設定</option>
                        <option value="global_whitelist" <?php selected($global_ua_control, 'global_whitelist'); ?>>全ページでホワイトリスト設定</option>
                    </select>

                    <div id="ggc-global-ua-list" style="display: <?php echo in_array($global_ua_control, ['global_blacklist','global_whitelist']) ? 'block' : 'none'; ?>;">
                        <p style="font-size:11px; margin-top:8px; margin-bottom:6px;">
                            <?php echo ($global_ua_control === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたUser-Agentをアクセス許可します。</strong>' : '<strong>ブラックリスト : チェックしたUser-Agentをアクセス拒否します。</strong>'; ?>
                        </p>
                        <p style="font-size:11px; margin-top:0; font-weight:bold;">User-Agent 制御リスト（全設定）:</p>
                        <div style="max-height:240px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php
                            $bots = Custom_Crawler_Core::get_allowable_bots();
                            $grouped = [];
                            foreach ($bots as $key => $b) {
                                $group_label = $b['group_label'] ?? 'その他';
                                if (!isset($grouped[$group_label])) $grouped[$group_label] = [];
                                $grouped[$group_label][$key] = $b;
                            }

                            $global_selected_crawlers = get_option('ggc_global_selected_crawlers', []);
                            if (!is_array($global_selected_crawlers)) $global_selected_crawlers = [];

                            foreach ($grouped as $glabel => $bots_in_group) : ?>
                                <h4 style="margin: 8px 0 6px; font-size:13px;"><strong><?php echo esc_html($glabel); ?></strong></h4>
                                <?php foreach ($bots_in_group as $bkey => $bot): ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="ggc_global_selected_crawlers[]" value="<?php echo esc_attr($bkey); ?>" <?php checked(in_array(sanitize_key($bkey), $global_selected_crawlers), true); ?>>
                                        <?php echo esc_html($bot['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">グローバル設定 IPアドレスの評価-ページ</th>
                <td>
                    <select name="ggc_global_ip_evaluation" id="ggc_global_ip_evaluation_select">
                        <option value="none" <?php selected($global_ip_evaluation, 'none'); ?>>アクセス制御設定を適用しない</option>
                        <option value="apply_new_posts" <?php selected($global_ip_evaluation, 'apply_new_posts'); ?>>アクセス制御設定を新規投稿で適用</option>
                        <option value="global_blacklist" <?php selected($global_ip_evaluation, 'global_blacklist'); ?>>全ページでブラックリスト設定</option>
                        <option value="global_whitelist" <?php selected($global_ip_evaluation, 'global_whitelist'); ?>>全ページでホワイトリスト設定</option>
                    </select>

                    <div id="ggc-global-ip-list" style="display: <?php echo in_array($global_ip_evaluation, ['global_blacklist','global_whitelist']) ? 'block' : 'none'; ?>;">
                        <p style="font-size:11px; margin-top:8px; margin-bottom:6px;">
                            <?php echo ($global_ip_evaluation === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたIP範囲をアクセス許可します。</strong>' : '<strong>ブラックリスト : チェックしたIP範囲をアクセス拒否します。</strong>'; ?>
                        </p>
                        <p style="font-size:11px; margin-top:0; font-weight:bold;">IPアドレス制御リスト（全設定）:</p>
                        <div style="max-height:240px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php
                            $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
                            $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);

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

                            $global_selected_ips = get_option('ggc_global_selected_ips', []);
                            if (!is_array($global_selected_ips)) $global_selected_ips = [];

                            $global_selected_ips_2 = get_option('ggc_global_selected_ips_2', []);
                            if (!is_array($global_selected_ips_2)) $global_selected_ips_2 = [];

                            if (!empty($grouped1)) {
                                echo '<h4 style="margin-top:0;">IPアドレス範囲1</h4>';
                                foreach ($grouped1 as $glabel => $group) {
                                    echo '<strong>' . esc_html($glabel) . '</strong><br/>';
                                    foreach ($group as $key => $ipd) {
                                        echo '<label style="display:block; margin-bottom:4px;">';
                                        echo '<input type="checkbox" name="ggc_global_selected_ips[]" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $global_selected_ips), true, false) . ' /> ';
                                        echo esc_html($ipd['label']);
                                        echo '</label>';
                                    }
                                }
                            }

                            if (!empty($grouped2)) {
                                echo '<h4 style="margin-top:10px;">IPアドレス範囲2</h4>';
                                foreach ($grouped2 as $glabel => $group) {
                                    echo '<strong>' . esc_html($glabel) . '</strong><br/>';
                                    foreach ($group as $key => $ipd) {
                                        echo '<label style="display:block; margin-bottom:4px;">';
                                        echo '<input type="checkbox" name="ggc_global_selected_ips_2[]" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $global_selected_ips_2), true, false) . ' /> ';
                                        echo esc_html($ipd['label']);
                                        echo '</label>';
                                    }
                                }
                            }

                            ?>
                        </div>
                    </div>
                </td>
            </tr>
            <!-- Media-level global settings -->
            <tr>
                <th scope="row">グローバル設定 User-Agent-メディア</th>
                <td>
                    <?php $global_media_ua_control = get_option('ggc_global_media_user_agent_control', 'apply_new_posts'); ?>
                    <select name="ggc_global_media_user_agent_control" id="ggc_global_media_user_agent_control_select">
                        <option value="none" <?php selected($global_media_ua_control, 'none'); ?>>メディア制御設定を適用しない</option>
                        <option value="apply_new_posts" <?php selected($global_media_ua_control, 'apply_new_posts'); ?>>メディア制御設定を新規投稿で適用</option>
                        <option value="global_blacklist" <?php selected($global_media_ua_control, 'global_blacklist'); ?>>全ページでブラックリスト</option>
                        <option value="global_whitelist" <?php selected($global_media_ua_control, 'global_whitelist'); ?>>全ページでホワイトリスト設定</option>
                    </select>
                    <div id="ggc-global-media-ua-list" style="display: <?php echo in_array($global_media_ua_control, ['global_blacklist','global_whitelist']) ? 'block' : 'none'; ?>; margin-top:8px;">
                        <p style="font-size:11px; margin-top:8px; margin-bottom:6px;">
                            <?php echo ($global_media_ua_control === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。</strong>' : '<strong>ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。</strong>'; ?>
                        </p>
                        <p style="font-size:11px; margin-top:0; font-weight:bold;">User-Agent 制御リスト（メディア向け）:</p>
                        <div style="max-height:240px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php
                            $bots = Custom_Crawler_Core::get_allowable_bots();
                            $grouped = [];
                            foreach ($bots as $key => $b) {
                                $group_label = $b['group_label'] ?? 'その他';
                                if (!isset($grouped[$group_label])) $grouped[$group_label] = [];
                                $grouped[$group_label][$key] = $b;
                            }

                            $global_media_selected_crawlers = get_option('ggc_global_media_selected_crawlers', []);
                            if (!is_array($global_media_selected_crawlers)) $global_media_selected_crawlers = [];

                            foreach ($grouped as $glabel => $bots_in_group) : ?>
                                <h4 style="margin: 8px 0 6px; font-size:13px;"><strong><?php echo esc_html($glabel); ?></strong></h4>
                                <?php foreach ($bots_in_group as $bkey => $bot): ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="ggc_global_media_selected_crawlers[]" value="<?php echo esc_attr($bkey); ?>" <?php checked(in_array(sanitize_key($bkey), $global_media_selected_crawlers), true); ?>>
                                        <?php echo esc_html($bot['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row">グローバル設定 IPアドレスの評価-メディア</th>
                <td>
                    <?php $global_media_ip_evaluation = get_option('ggc_global_media_ip_evaluation', 'apply_new_posts'); ?>
                    <select name="ggc_global_media_ip_evaluation" id="ggc_global_media_ip_evaluation_select">
                        <option value="none" <?php selected($global_media_ip_evaluation, 'none'); ?>>メディア制御設定を適用しない</option>
                        <option value="apply_new_posts" <?php selected($global_media_ip_evaluation, 'apply_new_posts'); ?>>メディア制御設定を新規投稿で適用</option>
                        <option value="global_blacklist" <?php selected($global_media_ip_evaluation, 'global_blacklist'); ?>>全ページでブラックリスト</option>
                        <option value="global_whitelist" <?php selected($global_media_ip_evaluation, 'global_whitelist'); ?>>全ページでホワイトリスト設定</option>
                    </select>
                    <div id="ggc-global-media-ip-list" style="display: <?php echo in_array($global_media_ip_evaluation, ['global_blacklist','global_whitelist']) ? 'block' : 'none'; ?>; margin-top:8px;">
                        <p style="font-size:11px; margin-top:8px; margin-bottom:6px;">
                            <?php echo ($global_media_ip_evaluation === 'global_whitelist') ? '<strong>ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。</strong>' : '<strong>ブラックリスト : チェックしたIP範囲は代替テキスト表示、それ以外はメディア表示します。</strong>'; ?>
                        </p>
                        <p style="font-size:11px; margin-top:0; font-weight:bold;">IPアドレス制御リスト（メディア向け）:</p>
                        <div style="max-height:240px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php
                            $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
                            $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);

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

                            $global_media_selected_ips = get_option('ggc_global_media_selected_ips', []);
                            if (!is_array($global_media_selected_ips)) $global_media_selected_ips = [];

                            $global_media_selected_ips_2 = get_option('ggc_global_media_selected_ips_2', []);
                            if (!is_array($global_media_selected_ips_2)) $global_media_selected_ips_2 = [];

                            if (!empty($grouped1)) {
                                echo '<h4 style="margin-top:0;">IPアドレス範囲1</h4>';
                                foreach ($grouped1 as $glabel => $group) {
                                    echo '<strong>' . esc_html($glabel) . '</strong><br/>';
                                    foreach ($group as $key => $ipd) {
                                        echo '<label style="display:block; margin-bottom:4px;">';
                                        echo '<input type="checkbox" name="ggc_global_media_selected_ips[]" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $global_media_selected_ips), true, false) . ' /> ';
                                        echo esc_html($ipd['label']);
                                        echo '</label>';
                                    }
                                }
                            }

                            if (!empty($grouped2)) {
                                echo '<h4 style="margin-top:10px;">IPアドレス範囲2</h4>';
                                foreach ($grouped2 as $glabel => $group) {
                                    echo '<strong>' . esc_html($glabel) . '</strong><br/>';
                                    foreach ($group as $key => $ipd) {
                                        echo '<label style="display:block; margin-bottom:4px;">';
                                        echo '<input type="checkbox" name="ggc_global_media_selected_ips_2[]" value="' . esc_attr($key) . '" ' . checked(in_array(sanitize_key($key), $global_media_selected_ips_2), true, false) . ' /> ';
                                        echo esc_html($ipd['label']);
                                        echo '</label>';
                                    }
                                }
                            }

                            ?>
                        </div>
                    </div>
                </td>
            </tr>
                        <tr>
                <th scope="row">アイキャッチ画像の代替テキスト（ブラックリスト/ホワイトリスト使用時）</th>
                <td>
                    <?php 
                    $alt_fixed_featured = get_option('ggc_alt_fixed_text_featured', ''); 
                    ?>
                    <input type="text" id="ggc_alt_fixed_text_featured" name="ggc_alt_fixed_text_featured" value="<?php echo esc_attr($alt_fixed_featured); ?>" class="regular-text" placeholder="アイキャッチ画像の代替テキスト（使用時）">
                    <p class="description">アイキャッチ画像が表示除外された場合に代替テキストとして使用するテキストを指定します。未入力の場合は通常の代替テキストを使用します。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">代替テキスト（ブラックリスト/ホワイトリスト使用時）</th>
                <td>
                    <?php 
                    $alt_fixed = get_option('ggc_alt_fixed_text', ''); 
                    ?>
                    <input type="text" id="ggc_alt_fixed_text" name="ggc_alt_fixed_text" value="<?php echo esc_attr($alt_fixed); ?>" class="regular-text" placeholder="代替テキスト（使用時）">
                    <p class="description">メディアを代替テキストとして使用するテキストにする場合に指定します。メディア制御でブラックリスト/ホワイトリストを選択した場合に有効になります。</p>
                </td>
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
                    <?php
                    $last = get_option('ggc_last_ip_update_result');
                    if ($last && is_array($last)) {
                        $time = $last['time'] ?? get_option('ggc_last_ip_update_time');
                        $g = isset($last['google_count']) ? number_format(intval($last['google_count'])) . ' 件' : 'なし';
                        $o = isset($last['openai_count']) ? number_format(intval($last['openai_count'])) . ' 件' : 'なし';
                        echo '<p class="description">直近の手動/自動更新: Google: ' . esc_html($g) . ' / GPTBot: ' . esc_html($o) . ' （最終: ' . ($time ? human_time_diff($time) . '前' : '未実行') . '）</p>';
                    }
                    ?>
                </td>
            </tr>
        </table>
        <hr>
        <table class="form-table">
            <tr>
                <th scope="row">
                    おすすめ設定のインポート
                </th>
                <td>
                    <?php $import_url = wp_nonce_url( admin_url('admin.php?action=ggc_import_default_settings'), 'ggc_import_defaults_nonce' ); ?>
                    <a href="<?php echo esc_url($import_url); ?>" class="button button-secondary">おすすめ設定をインポートする</a>
                    <p class="description">User-Agent, IPアドレス範囲, 不正UAパターンの推奨初期設定をインポートします。既存のカスタム設定は上書きされません。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    全データのクリア
                </th>
                <td>
                    <?php $clear_url = wp_nonce_url( admin_url('admin-post.php?action=ggc_clear_all_data'), 'ggc_clear_all_data_nonce' ); ?>
                    <a href="<?php echo esc_url($clear_url); ?>" class="button button-secondary" onclick="return confirm('設定オプション・投稿/メディアのメタデータ・IP更新履歴をすべて削除します。よろしいですか？');">すべての保存データをクリアする</a>
                    <p class="description">設定画面の保存済みオプション、投稿/メディアに保存されたメタデータなどのすべてを削除します。プラグインのバージョンアップをして動作が不安定な場合などにご利用ください。</p>
                </td>
            </tr>
        </table>
        </div>
        <?php
    }

    /**
     * User-Agent 定義リストセクションの表示
     */
    public function render_crawler_definitions_section() {
        $bots = Custom_Crawler_Core::get_allowable_bots();
        $default_bots = ggc_get_default_bots();
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するUser-Agentのリストです。カスタムのボットを追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム内部で使用される一意の識別子です。ここにテキストを入力すると、メディアが評価条件に従い代替テキストに置き換わります。空欄の場合はメディアが表示されます。英数字のみ登録可能です。
            - グループラベル : ボットのグループ名を設定します。同じグループ名を設定すると、投稿編集画面でまとめて表示されます。
            - 表示ラベル : 投稿編集画面で表示される名前です。わかりやすい名前を設定してください。<br>
            - 説明文       : 投稿編集画面で表示される説明文です。必要に応じて設定してください。<br>
            - User-Agent 文字列  : UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。</p>
        <table class="wp-list-table widefat fixed striped" id="ggc-bots-table">
            <thead>
                <tr>
                    <th style="width: 20%;">定義キー (システム用) / グループ</th>
                    <th style="width: 25%;">表示ラベル / 説明文</th>
                    <th style="width: 45%;">User-Agent 文字列</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-bots-tbody">
                <?php foreach ($bots as $key => $bot) :
                    $is_default = isset($default_bots[$key]);
                    $uas_str = implode(', ', $bot['uas'] ?? []);
                    // $readonly_attr = $is_default ? 'readonly' : ''; // Allow editing default keys
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text"
                               name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][key]"
                               value="<?php echo esc_attr($key); ?>"
                               class="regular-text ggc-bot-key"
                               style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                        <input type="text" name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($bot['group_label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($bot['label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                        <input type="text" name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($bot['description'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                    </td>
                    <td>
                        <textarea name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][uas]" rows="4" cols="50" class="large-text code" style="width: 100%;"><?php echo esc_textarea($uas_str); ?></textarea>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-bot">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-primary" id="ggc-add-bot">新しいボット定義を追加</button></p>

        <script type="text/template" id="ggc-bot-row-template">
            <tr class="ggc-bot-row new-row" data-key="__KEY__">
                <td>
                    <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][key]" value="__KEY__" class="regular-text ggc-bot-key" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][group_label]" value="カスタム" class="regular-text" style="width: 100%;" />
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][label]" value="カスタムボット" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_crawler_definitions[__KEY__][description]" value="" class="regular-text" style="width: 100%;" />
                </td>
                <td>
                    <textarea name="ggc_crawler_definitions[__KEY__][uas]" rows="4" cols="50" class="large-text code" style="width: 100%;"></textarea>
                    <p class="description">UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-bot">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }

    /**
     * IPアドレス範囲 定義リストセクションの表示 (URL入力欄追加版)
     */
    public function render_ip_range_definitions_section() {
        $ip_ranges = get_option('ggc_ip_range_definitions', []);
        $default_ip_ranges = ggc_get_default_ip_ranges();
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するIPアドレス範囲のリストです。カスタムのIP範囲を追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム用、一意のキー。必須項目。英数字のみ登録可能。<br/>
            - グローバルラベル : 管理画面でのグループ分けに使用されます。<br/>
            - プレースホルダを許可 : （形式チェックに失敗してもこの値を保持します）<br/>
            - 自動更新 : チェックすると保存時にURLから自動取得されます<br/>
            - 説明文 : 管理画面での説明文。<br/>
            - 表示ラベル : 管理画面での表示ラベル。<br/>
            - 取得元URL (自動更新用) : IPアドレス範囲を定期的に自動取得するためのURLを指定します。<br/>
            IPアドレス範囲 (CIDR形式) : 1行にIPv4またはIPv6の範囲を1つのCIDR形式で入力してください。例: <code>192.168.0.0/16</code><br/>
        </p>
        <p>
            <?php
            // Nonce provided for AJAX run
            ?>
            <?php $manual_url = wp_nonce_url( admin_url('admin-post.php?action=run_ggc_ip_update'), 'ggc_manual_ip_update_nonce' ); ?>
            <button type="button" id="ggc-run-ip-update" class="button button-secondary ggc-run-ip-update-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('ggc_run_update_nonce')); ?>" data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-manual-url="<?php echo esc_url($manual_url); ?>">今すぐ IP 更新を強制実行する</button>
            <span class="description" style="margin-left:10px;">(最終更新: <?php echo get_option('ggc_last_ip_update_time') ? human_time_diff(get_option('ggc_last_ip_update_time')) . '前' : '未実行'; ?>)</span>
            <noscript><a href="<?php echo esc_url($manual_url); ?>" class="button" style="margin-left:10px;">JavaScript無効時に更新を実行</a></noscript>
        </p>
        <table class="wp-list-table widefat fixed striped" id="ggc-ip-ranges-table">
            <thead>
                <tr>
                    <th style="width: 25%;">定義キー / グループ</th>
                    <th style="width: 30%;">表示ラベル / 説明文 / 取得元URL</th>
                    <th style="width: 35%;">IPアドレス範囲 (CIDR形式)</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-ip-ranges-tbody">
                <?php foreach ($ip_ranges as $key => $ip_def) :
                    $is_default = isset($default_ip_ranges[$key]);
                    $ranges_str = implode("\n", $ip_def['ranges'] ?? []);
                    // $readonly_attr = $is_default ? 'readonly' : ''; // Allow editing default keys
                    $source_url = $ip_def['source_url'] ?? ''; // URLを取得
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text"
                               name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][key]"
                               value="<?php echo esc_attr($key); ?>"
                               class="regular-text ggc-ip-key"
                               style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($ip_def['group_label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                            <p style="margin-top: 10px;"><label><input type="checkbox" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][allow_placeholder]" value="1" <?php checked(!empty($ip_def['allow_placeholder']), true); ?> /> <strong>プレースホルダを許可</strong></label></p>
                        <p style="margin-top:6px;"><label><input type="checkbox" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][is_auto]" value="1" <?php checked(!empty($ip_def['is_auto']), true); ?> /> 自動更新 </label></p>


                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($ip_def['label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($ip_def['description'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 6px;"><strong>取得元URL (自動更新用):</strong></p>
                        <input type="text" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][source_url]" value="<?php echo esc_url($source_url); ?>" class="regular-text" placeholder="https://..." style="width: 100%; font-size: 11px; color: #666;" />
                    </td>
                    <td>
                        <textarea name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][ranges]" rows="6" cols="50" class="large-text code" style="width: 100%;" <?php disabled($ip_def['is_auto'] ?? false, true); ?>><?php echo esc_textarea($ranges_str); ?></textarea>
                        <p class="description">IPv4またはIPv6のCIDR形式。</p>
                            <div class="ggc-parse-status" style="margin-top:6px;">
                            <?php if (!empty($ip_def['last_parse_error'])): ?>
                                <p style="color:#d9534f;margin:0;"><strong>解析エラー:</strong> <?php echo esc_html($ip_def['last_parse_error']); ?> (<?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>)</p>
                            <?php elseif (!empty($ip_def['last_parse_time'])): ?>
                                <p style="color:#28a745;margin:0;"><strong>最終解析成功:</strong> <?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>
                                <?php if (isset($ip_def['last_parse_count'])): ?>
                                    (<?php echo esc_html(number_format($ip_def['last_parse_count'])); ?> 件)
                                <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ip_def['is_auto'])): ?>
                            <p style="color: green; margin-top: 5px;">✅ 自動更新対象 (テキスト入力は無視されます)</p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-primary" id="ggc-add-ip">新しいIP範囲定義を追加</button></p>

        <script type="text/template" id="ggc-ip-row-template">
            <tr class="ggc-ip-row new-row" data-key="__KEY__">
                <td>
                    <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions[__KEY__][key]" value="__KEY__" class="regular-text ggc-ip-key" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions[__KEY__][group_label]" value="カスタム" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 10px;"><label><input type="checkbox" name="ggc_ip_range_definitions[__KEY__][allow_placeholder]" value="1" checked="checked" /> <strong>プレースホルダを許可</strong></label></p>
                    <p style="margin-top:6px;"><label><input type="checkbox" name="ggc_ip_range_definitions[__KEY__][is_auto]" value="1" checked="checked" /> 自動更新 (チェックすると保存時にURLから自動取得されます)</label></p>
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions[__KEY__][label]" value="カスタムIP範囲" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions[__KEY__][description]" value="" class="regular-text" style="width: 100%;" />
                    <p style="margin-top:6px;"><strong>取得元URL (自動更新用):</strong></p>
                    <input type="text" name="ggc_ip_range_definitions[__KEY__][source_url]" value="" class="regular-text" placeholder="https://..." style="width: 100%; font-size: 11px; color: #666;" />
                </td>
                <td>
                    <textarea name="ggc_ip_range_definitions[__KEY__][ranges]" rows="4" cols="50" class="large-text code" style="width: 100%;"></textarea>
                    <p class="description">IPv4またはIPv6のCIDR形式。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }
    /**
     * 不正UAパターン 定義リストセクションの表示
     */
    public function render_browser_block_patterns_section() {
        $patterns = Custom_Crawler_Core::get_browser_block_patterns();
        $default_patterns = ggc_get_default_browser_patterns();
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するUser-Agentのリストです。カスタムのボットを追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム内部で使用される一意の識別子です。必須項目です。英数字のみ登録可能です。
            - グループラベル : ボットのグループ名を設定します。同じグループ名を設定すると、投稿編集画面でまとめて表示されます。
            - 表示ラベル : 投稿編集画面で表示される名前です。わかりやすい名前を設定してください。<br>
            - 説明文       : 投稿編集画面で表示される説明文です。必要に応じて設定してください。<br>
            - User-Agent 文字列  : UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。
        </p>
        </p>
        <table class="wp-list-table widefat fixed striped" id="ggc-patterns-table">
            <thead>
                <tr>
                    <th style="width: 20%;">定義キー (システム用) / グループ</th>
                    <th style="width: 25%;">表示ラベル / 説明文</th>
                    <th style="width: 45%;">User-Agent パターン文字列</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-patterns-tbody">
                <?php foreach ($patterns as $key => $pattern_def) :
                    $is_default = $pattern_def['is_default'] ?? isset($default_patterns[$key]);
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text"
                               name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][key]"
                               value="<?php echo esc_attr($key); ?>"
                               class="regular-text ggc-pattern-key"
                               style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                        <input type="text" name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($pattern_def['group_label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($pattern_def['label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                        <input type="text" name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($pattern_def['description'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                    </td>
                    <td>
                        <textarea name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][pattern]" rows="2" class="large-text code" style="width: 100%;"><?php echo esc_textarea($pattern_def['pattern'] ?? ''); ?></textarea>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-pattern">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-primary" id="ggc-add-pattern">新しい不正UAパターンを追加</button></p>

        <script type="text/template" id="ggc-pattern-row-template">
            <tr class="ggc-pattern-row new-row" data-key="__KEY__">
                <td>
                    <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][key]" value="__KEY__" class="regular-text ggc-pattern-key" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][group_label]" value="カスタム" class="regular-text" style="width: 100%;" />
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][label]" value="カスタムパターン" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_browser_block_patterns[__KEY__][description]" value="" class="regular-text" style="width: 100%;" />
                </td>
                <td>
                    <textarea name="ggc_browser_block_patterns[__KEY__][pattern]" rows="2" class="large-text code" style="width: 100%;"></textarea>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-pattern">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }

    public function sanitize_ip_range_definitions_2($input) {
        $current = get_option('ggc_ip_range_definitions_2', []);
        $defaults = ggc_get_default_ip_ranges_2();
        $default_keys_map = [];
        foreach ($defaults as $def_key => $def_val) {
            $default_keys_map[strtolower($def_key)] = $def_key;
        }

        if (!is_array($input)) return [];

        $new_input = [];

        $simple_validate_ip_cidr = function ($range) {
            $range = trim($range);
            if (empty($range)) return false;
            if (filter_var($range, FILTER_VALIDATE_IP)) return sanitize_text_field($range);
            if (strpos($range, '/') !== false) {
                list($ip, $mask) = explode('/', $range, 2);
                $mask = intval($mask);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) return sanitize_text_field($range);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) return sanitize_text_field($range);
                if (strpos($ip, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $ip) && $mask >= 0 && $mask <= 128) return sanitize_text_field($range);
                if (strpos($ip, '.') !== false && preg_match('/^[0-9.]+$/', $ip) && $mask >= 0 && $mask <= 32) return sanitize_text_field($range);
            }
            return false;
        };

        foreach ($input as $key => $ip_def) {
            $raw_key = $ip_def['key'] ?? $key;
            if (empty(trim($raw_key))) continue;

            $lower_key = strtolower($raw_key);
            $new_key = '';
            $is_default_key = false;

            if (array_key_exists($lower_key, $default_keys_map)) {
                $new_key = $default_keys_map[$lower_key];
                $is_default_key = true;
            } else {
                $sanitized_key = sanitize_key($raw_key);
                $new_key = !empty($sanitized_key) ? $sanitized_key : sanitize_key($key);
            }

            $ranges_input = '';
            if (isset($ip_def['ranges'])) {
                $ranges_input = is_array($ip_def['ranges']) ? implode("\n", array_map('strval', $ip_def['ranges'])) : (string) $ip_def['ranges'];
            }

            if (!$is_default_key && empty($ranges_input) && empty($ip_def['label']) && empty($ip_def['source_url'])) {
                continue;
            }

            $ranges = preg_split('/[\r\n,]+/', $ranges_input, -1, PREG_SPLIT_NO_EMPTY);
            $ranges = array_map('trim', $ranges);
            $sanitized_ranges_raw = array_values(array_map('sanitize_text_field', array_filter($ranges)));
            $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $ranges)));

            $is_auto = !empty($ip_def['is_auto']);
            $default_def = $current[$new_key] ?? ['source_url' => ''];
            $source_url_to_save = isset($ip_def['source_url']) ? esc_url_raw(trim($ip_def['source_url'])) : $default_def['source_url'];

            // Initialize variables for this loop iteration
            $loop_last_parse_error = null;
            $loop_last_parse_time = null;
            $loop_last_parse_count = 0;

            if ($is_auto && !empty($source_url_to_save)) {
                $parsed = Custom_Crawler_Core::parse_ip_list_from_url($source_url_to_save);
                $loop_last_parse_time = time();

                if (is_wp_error($parsed)) {
                    $loop_last_parse_error = $parsed->get_error_message();
                    add_settings_error('ggc_ips2_option_group', 'ggc_parse_failed_' . $new_key, sprintf('%s の自動解析に失敗しました: %s', esc_html($new_key), esc_html($loop_last_parse_error)), 'error');
                } else {
                    $parsed_clean = array_values(array_map('sanitize_text_field', $parsed));
                    $sanitized_ranges_raw = $parsed_clean;
                    $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $parsed_clean)));
                    $loop_last_parse_count = count($sanitized_ranges_raw);
                }
            } else {
                // If not auto-updating on this save, preserve the last known status.
                $loop_last_parse_error = $current[$new_key]['last_parse_error'] ?? null;
                $loop_last_parse_time = $current[$new_key]['last_parse_time'] ?? null;
                $loop_last_parse_count = $current[$new_key]['last_parse_count'] ?? 0;
            }

            $new_input[$new_key] = [
                'ranges' => $sanitized_ranges_raw,
                'validated_ranges' => $sanitized_ranges_valid,
                'label' => sanitize_text_field($ip_def['label'] ?? ''),
                'group_label' => sanitize_text_field($ip_def['group_label'] ?? 'その他'),
                'description' => sanitize_textarea_field($ip_def['description'] ?? ''),
                'allow_placeholder' => !empty($ip_def['allow_placeholder']),
                'is_auto' => $is_auto,
                'source_url' => $source_url_to_save,
                'last_parse_error' => $loop_last_parse_error,
                'last_parse_time' => $loop_last_parse_time,
                'last_parse_count' => $loop_last_parse_count,
            ];
        }
        return $new_input;
    }

    public function render_ip_range_definitions_section_2() {
        $ip_ranges = get_option('ggc_ip_range_definitions_2', []);
        $default_ip_ranges = ggc_get_default_ip_ranges_2();
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するIPアドレス範囲のリストです。カスタムのIP範囲を追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム用、一意のキー。必須項目。英数字のみ登録可能。<br/>
            - グローバルラベル : 管理画面でのグループ分けに使用されます。<br/>
            - プレースホルダを許可 : （形式チェックに失敗してもこの値を保持します）<br/>
            - 自動更新 : チェックすると保存時にURLから自動取得されます<br/>
            - 説明文 : 管理画面での説明文。<br/>
            - 表示ラベル : 管理画面での表示ラベル。<br/>
            - 取得元URL (自動更新用) : IPアドレス範囲を定期的に自動取得するためのURLを指定します。<br/>
            IPアドレス範囲 (CIDR形式) : 1行にIPv4またはIPv6の範囲を1つのCIDR形式で入力してください。例: <code>192.168.0.0/16</code><br/>
        </p>
        <p>
            <?php
            // Nonce provided for AJAX run
            ?>
            <?php $manual_url = wp_nonce_url( admin_url('admin-post.php?action=run_ggc_ip_update'), 'ggc_manual_ip_update_nonce' ); ?>
            <button type="button" id="ggc-run-ip-update-2" class="button button-secondary ggc-run-ip-update-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('ggc_run_update_nonce')); ?>" data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-manual-url="<?php echo esc_url($manual_url); ?>">今すぐ IP 更新を強制実行する</button>
            <span class="description" style="margin-left:10px;">(最終更新: <?php echo get_option('ggc_last_ip_update_time') ? human_time_diff(get_option('ggc_last_ip_update_time')) . '前' : '未実行'; ?>)</span>
            <noscript><a href="<?php echo esc_url($manual_url); ?>" class="button" style="margin-left:10px;">JavaScript無効時に更新を実行</a></noscript>
        </p>
        <table class="wp-list-table widefat fixed striped" id="ggc-ip-ranges-table-2">
            <thead>
                <tr>
                    <th style="width: 25%;">定義キー / グループ</th>
                    <th style="width: 30%;">表示ラベル / 説明文 / 取得元URL</th>
                    <th style="width: 35%;">IPアドレス範囲 (CIDR形式)</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-ip-ranges-tbody-2">
                <?php foreach ($ip_ranges as $key => $ip_def) :
                    $is_default = isset($default_ip_ranges[$key]);
                    $ranges_str = implode("\n", $ip_def['ranges'] ?? []);
                    // $readonly_attr = $is_default ? 'readonly' : ''; // Allow editing default keys
                    $source_url = $ip_def['source_url'] ?? ''; // URLを取得
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text"
                               name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][key]"
                               value="<?php echo esc_attr($key); ?>"
                               class="regular-text ggc-ip-key"
                               style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($ip_def['group_label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                            <p style="margin-top: 10px;"><label><input type="checkbox" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][allow_placeholder]" value="1" <?php checked(!empty($ip_def['allow_placeholder']), true); ?> /> <strong>プレースホルダを許可</strong></label></p>
                        <p style="margin-top:6px;"><label><input type="checkbox" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][is_auto]" value="1" <?php checked(!empty($ip_def['is_auto']), true); ?> /> 自動更新 </label></p>


                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($ip_def['label'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                        <input type="text" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($ip_def['description'] ?? ''); ?>" class="regular-text" style="width: 100%;" />
                        <p style="margin-top: 6px;"><strong>取得元URL (自動更新用):</strong></p>
                        <input type="text" name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][source_url]" value="<?php echo esc_url($source_url); ?>" class="regular-text" placeholder="https://..." style="width: 100%; font-size: 11px; color: #666;" />
                    </td>
                    <td>
                        <textarea name="ggc_ip_range_definitions_2[<?php echo esc_attr($key); ?>][ranges]" rows="6" cols="50" class="large-text code" style="width: 100%;" <?php disabled($ip_def['is_auto'] ?? false, true); ?>><?php echo esc_textarea($ranges_str); ?></textarea>
                        <p class="description">IPv4またはIPv6のCIDR形式。</p>
                            <div class="ggc-parse-status" style="margin-top:6px;">
                            <?php if (!empty($ip_def['last_parse_error'])): ?>
                                <p style="color:#d9534f;margin:0;"><strong>解析エラー:</strong> <?php echo esc_html($ip_def['last_parse_error']); ?> (<?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>)</p>
                            <?php elseif (!empty($ip_def['last_parse_time'])): ?>
                                <p style="color:#28a745;margin:0;"><strong>最終解析成功:</strong> <?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>
                                <?php if (isset($ip_def['last_parse_count'])): ?>
                                    (<?php echo esc_html(number_format($ip_def['last_parse_count'])); ?> 件)
                                <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ip_def['is_auto'])): ?>
                            <p style="color: green; margin-top: 5px;">✅ 自動更新対象 (テキスト入力は無視されます)</p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-primary" id="ggc-add-ip-2">新しいIP範囲定義を追加</button></p>

        <script type="text/template" id="ggc-ip-row-template-2">
            <tr class="ggc-ip-row new-row" data-key="__KEY__">
                <td>
                    <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions_2[__KEY__][key]" value="__KEY__" class="regular-text ggc-ip-key" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>グループラベル:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions_2[__KEY__][group_label]" value="カスタム" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 10px;"><label><input type="checkbox" name="ggc_ip_range_definitions_2[__KEY__][allow_placeholder]" value="1" checked="checked" /> <strong>プレースホルダを許可</strong></label></p>
                    <p style="margin-top:6px;"><label><input type="checkbox" name="ggc_ip_range_definitions_2[__KEY__][is_auto]" value="1" checked="checked" /> 自動更新 (チェックすると保存時にURLから自動取得されます)</label></p>
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions_2[__KEY__][label]" value="カスタムIP範囲" class="regular-text" style="width: 100%;" />
                    <p style="margin-top: 5px;"><strong>説明文:</strong></p>
                    <input type="text" name="ggc_ip_range_definitions_2[__KEY__][description]" value="" class="regular-text" style="width: 100%;" />
                    <p style="margin-top:6px;"><strong>取得元URL (自動更新用):</strong></p>
                    <input type="text" name="ggc_ip_range_definitions_2[__KEY__][source_url]" value="" class="regular-text" placeholder="https://..." style="width: 100%; font-size: 11px; color: #666;" />
                </td>
                <td>
                    <textarea name="ggc_ip_range_definitions_2[__KEY__][ranges]" rows="4" cols="50" class="large-text code" style="width: 100%;"></textarea>
                    <p class="description">IPv4またはIPv6のCIDR形式。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }
    // --------------------------------------------------------

    public function admin_notice_ip_update() {
        if (!current_user_can('manage_options') || is_network_admin()) return;

        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_ggc-crawler-definitions') return;

        $last_update = get_option('ggc_last_ip_update_time');

        if ($last_update) {
            $time_diff = human_time_diff($last_update, current_time('timestamp'));
            $frequency = get_option('ggc_ip_update_frequency', 'daily');
            $interval_seconds = DAY_IN_SECONDS * 2;

            if (current_time('timestamp') - $last_update > $interval_seconds) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> 既知クローラーIPアドレス範囲の自動更新が長期間実行されていません (最終更新: ' . esc_html($time_diff) . '前)。Cronが正常に動作しているか確認してください。</p></div>';
            }
        }
    }

    public function admin_notice_manual_ip_update_success() {
        if (!current_user_can('manage_options') || is_network_admin()) return;
        if (isset($_GET['ip-updated']) && $_GET['ip-updated'] === '1') {
            $res = get_option('ggc_last_ip_update_result');
            $time = $res['time'] ?? get_option('ggc_last_ip_update_time');
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
                $msg .= ' 最終更新: ' . human_time_diff($time) . '前';
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
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません']);
        }
        check_ajax_referer('ggc_run_update_nonce', 'nonce');

        $core = Custom_Crawler_Core::get_instance();
        $res = $core->run_update_with_result();

        wp_send_json_success(['result' => $res]);
    }

    /**
     * AJAX: 指定された source_url を解析して IP/CIDR リストを返す
     */
    public function ajax_parse_ip_source() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません']);
        }
        check_ajax_referer('ggc_run_update_nonce', 'nonce');

        $url = isset($_POST['url']) ? esc_url_raw(trim($_POST['url'])) : '';
        $key = isset($_POST['key']) ? sanitize_key($_POST['key']) : '';

        if (empty($url)) {
            wp_send_json_error(['message' => 'URLが指定されていません']);
        }

        $parsed = Custom_Crawler_Core::parse_ip_list_from_url($url);

        // Determine which list to update based on key prefix or check both?
        // For AJAX simplicity, we check if the key exists in list 1 or list 2.
        // However, the current implementation of get_allowable_ip_ranges merges both.
        // We need to know which option to update.
        // Since this is just a helper to parse and return JSON, we don't necessarily need to save here?
        // Wait, the original code updates the option.

        $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);

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
            wp_send_json_error(['message' => $parsed->get_error_message()]);
        }

        // 成功した場合、エラー情報をクリアして時刻を記録
        $target_ranges[$key]['last_parse_error'] = null;
        $target_ranges[$key]['last_parse_time'] = time();
        update_option($target_option, $target_ranges);

        wp_send_json_success(['ranges' => $parsed]);
    }


        /**
         * 管理画面: 設定保存時に不正なIP範囲があればユーザーに通知する
         */
        public function admin_notice_invalid_ip_ranges_on_save() {
            if (!current_user_can('manage_options') || is_network_admin()) return;
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
                    echo '<ul style="margin-top:6px;">';
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
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'ggc_manual_ip_update_nonce')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

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
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'ggc_reset_ip_settings_nonce')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

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
        if (!current_user_can('manage_options') || is_network_admin()) return;
        if (isset($_GET['ip-reset']) && $_GET['ip-reset'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> IPアドレス範囲設定を初期化（削除）しました。デフォルト値が表示されています。</p></div>';
        }
    }

    /**
     * 全データクリア完了通知
     */
    public function admin_notice_clear_all_success() {
        if (!current_user_can('manage_options') || is_network_admin()) return;
        if (isset($_GET['ggc-cleared']) && $_GET['ggc-cleared'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御プラグイン:</strong> 設定オプション・投稿/メディアのメタデータ・IP更新履歴を削除しました。</p></div>';
        }
    }

    /**
     * 全データ（オプション/メタ/履歴）を削除
     */
    public function admin_action_clear_all_data() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'ggc_clear_all_data_nonce')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        global $wpdb;

        // ggc_ で始まるオプションを削除
        $opt_like = $wpdb->esc_like('ggc_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $opt_like));

        // _ggc_ で始まる post_meta を削除（投稿/メディア）
        $meta_like = $wpdb->esc_like('_ggc_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like));

        // スケジュールをクリア
        wp_clear_scheduled_hook('ggc_daily_ip_update');

        // IP更新頻度を初期化してスケジュール再設定
        update_option('ggc_ip_update_frequency', 'daily');
        Custom_Crawler_Core::ip_update_schedule_check();

        $redirect_url = remove_query_arg(['action', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('ggc-cleared', '1', $redirect_url);

        if (isset($_GET['tab'])) {
            $redirect_url = add_query_arg('tab', sanitize_text_field($_GET['tab']), $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }
    /**
     * 診断ツールセクションの表示
     */

    public function render_diagnostic_tools_section() {
        // 現在のIPアドレスとUAを取得 (診断用)
        // NOTE: HTTP_X_FORWARDED_FORなどは利用せず、最も信頼性の高い REMOTE_ADDR を使用
        $client_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '不明';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '不明';

        // Cronジョブの次の実行時刻を取得
        $next_schedule = wp_next_scheduled('ggc_daily_ip_update');
        $next_run = $next_schedule ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_schedule) : '未設定';
        $frequency = get_option('ggc_ip_update_frequency', 'daily');
        // IP範囲定義リストを取得
        $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);

        // Merge defaults if empty
        if (empty($ip_ranges_1)) $ip_ranges_1 = ggc_get_default_ip_ranges();
        if (empty($ip_ranges_2)) $ip_ranges_2 = ggc_get_default_ip_ranges_2();

        // Custom_Crawler_Coreのip_in_cidrメソッドが存在するかをチェック
        $is_core_check_available = class_exists('Custom_Crawler_Core') && method_exists('Custom_Crawler_Core', 'ip_in_cidr');

        ?>
        <div id="ggc-diagnostic-tools">
            <h2>診断ツール</h2>
            <p class="description">現在のアクセス情報やCronスケジュールの確認、特定のIPアドレスのチェックができます。</p>

            <h3 style="margin-top: 20px;">現在のアクセス情報</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">あなたの現在のIPアドレス</th>
                    <td><code><?php echo esc_html($client_ip); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">あなたの現在のUser-Agent</th>
                    <td><code><?php echo esc_html($user_agent); ?></code></td>
                </tr>
            </table>

            <h3 style="margin-top: 20px;">IPアドレス更新スケジュール</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">設定頻度</th>
                    <td><?php
                        $frequency_labels = [
                            'disabled' => '停止',
                            'hourly' => '毎時',
                            'twicedaily' => '半日',
                            'daily' => '毎日',
                            'weekly' => '毎週',
                            'monthly' => '毎月',
                            'biannually' => '半年',
                            'annually' => '毎年'
                        ];
                        echo esc_html($frequency_labels[$frequency] ?? '毎日');
                    ?></td>
                </tr>
                <tr>
                    <th scope="row">次回の実行予定時刻</th>
                    <td>
                        <?php echo esc_html($next_run); ?>
                        <?php if ($next_schedule): ?>
                            <p class="description">（<?php echo human_time_diff($next_schedule) . '後に実行予定'; ?>）</p>
                        <?php else: ?>
                            <p class="description" style="color: red;">⚠️ スケジュールが設定されていません。一般設定に戻って保存し直してください。</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top: 20px;">IPアドレス範囲チェック（テスト）</h3>
            <form method="post" action="" id="ggc-ip-test-form">
                <?php wp_nonce_field('ggc_ip_range_test_nonce', 'ggc_ip_range_test_nonce_field'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ggc_ip_to_test">テストするIPアドレス</label></th>
                        <td>
                            <input type="text" id="ggc_ip_to_test" name="ggc_ip_to_test" value="<?php echo esc_attr($client_ip); ?>" class="regular-text code" placeholder="例: 66.249.66.1" style="width: 300px;" />
                            <input type="submit" class="button button-secondary" value="チェックを実行" />
                            <p class="description">指定したIPアドレスが、登録されているどのIP範囲定義に該当するかをチェックします。</p>
                        </td>
                    </tr>
                </table>
            </form>

            <?php
            // IPチェックの結果表示ロジック
            if (isset($_POST['ggc_ip_to_test']) && check_admin_referer('ggc_ip_range_test_nonce', 'ggc_ip_range_test_nonce_field')) {
                $ip_to_test = sanitize_text_field(wp_unslash($_POST['ggc_ip_to_test']));

                // IPアドレスの基本的な形式チェック
                if (!filter_var($ip_to_test, FILTER_VALIDATE_IP)) {
                    echo '<div class="notice notice-error"><p><strong>IPチェック結果:</strong> 入力された値は有効なIPアドレスではありません。</p></div>';
                } elseif ($is_core_check_available) {
                    $matched_results = [];

                    // Check List 1
                    foreach ($ip_ranges_1 as $key => $ip_def) {
                        $ranges = $ip_def['validated_ranges'] ?? $ip_def['ranges'] ?? [];
                        if (is_array($ranges)) {
                            foreach ($ranges as $cidr) {
                                if (Custom_Crawler_Core::ip_in_cidr($ip_to_test, $cidr)) {
                                    $matched_results[] = [
                                        'list' => 'IPアドレス範囲1',
                                        'key' => $key,
                                        'label' => $ip_def['label'] ?? $key
                                    ];
                                    break; // Found in this key, move to next key
                                }
                            }
                        }
                    }

                    // Check List 2
                    foreach ($ip_ranges_2 as $key => $ip_def) {
                        $ranges = $ip_def['validated_ranges'] ?? $ip_def['ranges'] ?? [];
                        if (is_array($ranges)) {
                            foreach ($ranges as $cidr) {
                                if (Custom_Crawler_Core::ip_in_cidr($ip_to_test, $cidr)) {
                                    $matched_results[] = [
                                        'list' => 'IPアドレス範囲2',
                                        'key' => $key,
                                        'label' => $ip_def['label'] ?? $key
                                    ];
                                    break; // Found in this key, move to next key
                                }
                            }
                        }
                    }

                    if (!empty($matched_results)) {
                        echo '<div class="notice notice-success"><p><strong>IPチェック結果:</strong> IPアドレス <code>' . esc_html($ip_to_test) . '</code> は以下の定義範囲に一致しました。</p>';
                        echo '<ul>';
                        foreach ($matched_results as $match) {
                            echo '<li><strong>' . esc_html($match['list']) . '</strong> - <strong>' . esc_html($match['label']) . '</strong> (キー: <code>' . esc_html($match['key']) . '</code>)</li>';
                        }
                        echo '</ul></div>';
                    } else {
                        echo '<div class="notice notice-warning"><p><strong>IPチェック結果:</strong> IPアドレス <code>' . esc_html($ip_to_test) . '</code> は、登録されている<strong>どのIP範囲にも一致しませんでした</strong>。</p></div>';
                    }
                } else {
                     echo '<div class="notice notice-info"><p><strong>IPチェック情報:</strong> IPアドレスチェックを実行するコア機能が利用できません。プラグインのコアファイル (class-crawler-core.php) が正しく読み込まれているか確認してください。</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }


    // 行ごとの IP 保存は廃止しています（フルページ保存を使用してください）

    /**
     * Validate a single IP or CIDR and return sanitized string or false
     */
    private function validate_ip_or_cidr($range) {
        $range = trim($range);
        if (empty($range)) return false;

        if (filter_var($range, FILTER_VALIDATE_IP)) {
            return sanitize_text_field($range);
        }

        if (strpos($range, '/') !== false) {
            list($ip, $mask) = explode('/', $range, 2);
            $mask = intval($mask);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
                return sanitize_text_field($range);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) {
                return sanitize_text_field($range);
            }
            // Fallback permissive CIDR checks (when filter_var may fail on valid-looking IPv6 shorthand)
            if (strpos($ip, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $ip) && $mask >= 0 && $mask <= 128) {
                return sanitize_text_field($range);
            }
            if (strpos($ip, '.') !== false && preg_match('/^[0-9.]+$/', $ip) && $mask >= 0 && $mask <= 32) {
                return sanitize_text_field($range);
            }
        }

        return false;
    }


    /**
     * おすすめ設定のインポート完了通知
     */
    public function admin_notice_import_success() {
        if (!current_user_can('manage_options')) return;
        if (isset($_GET['settings-imported']) && $_GET['settings-imported'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>クローラー個別制御:</strong> おすすめ設定をインポートしました。</p></div>';
        }
    }

    /**
     * おすすめ設定をインポートするアクション
     */
    public function admin_action_import_default_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません。');
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'ggc_import_defaults_nonce')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        // 現在の設定を取得
        $current_bots = get_option('ggc_crawler_definitions', []);
        $current_ips = get_option('ggc_ip_range_definitions', []);
        $current_ips_2 = get_option('ggc_ip_range_definitions_2', []);
        $current_patterns = get_option('ggc_browser_block_patterns', []);

        // デフォルト設定を取得
        $default_bots = ggc_get_default_bots();
        $default_ips = ggc_get_default_ip_ranges();
        $default_ips_2 = ggc_get_default_ip_ranges_2();
        $default_patterns = ggc_get_default_browser_patterns();

        // --- 堅牢なマージ処理 ---
        // 既存のキーをすべて小文字で保持
        $existing_bot_keys = array_map('strtolower', array_keys($current_bots));
        $existing_ip_keys = array_map('strtolower', array_keys($current_ips));
        $existing_ip_keys_2 = array_map('strtolower', array_keys($current_ips_2));
        $existing_pattern_keys = array_map('strtolower', array_keys($current_patterns));

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

        // データベースを更新
        update_option('ggc_crawler_definitions', $current_bots);
        update_option('ggc_ip_range_definitions', $current_ips);
        update_option('ggc_ip_range_definitions_2', $current_ips_2);
        update_option('ggc_browser_block_patterns', $current_patterns);

        // インポート直後にIPアドレスの自動取得を実行
        Custom_Crawler_Core::get_instance()->update_all_ip_ranges();

        // 完了フラグをつけてリダイレクト
        $redirect_url = remove_query_arg(['action', '_wpnonce', 'settings-imported'], wp_get_referer());
        $redirect_url = add_query_arg('settings-imported', '1', $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }

}
