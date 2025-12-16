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
        'bots'     => 'User-Agent 定義',
        'ips'      => 'IPアドレス範囲',
        'patterns' => '不正UAパターン',
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
        // 行ごとの保存は UX を単純化するため撤廃（フルページ保存を利用）
        // (デバッグ用 AJAX は削除済み)
        // IP更新通知
        add_action('admin_notices', [ $this, 'admin_notice_ip_update' ]);
        add_action('admin_notices', [ $this, 'admin_notice_manual_ip_update_success' ]);
        // 設定保存時に不正なIP入力があれば通知する
        add_action('admin_notices', [ $this, 'admin_notice_invalid_ip_ranges_on_save' ]);
        // リセット完了通知
        add_action('admin_notices', [ $this, 'admin_notice_reset_success' ]);
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

        // small inline marker to help debug when JS isn't being loaded
        add_action('admin_footer', function() use ($hook) {
            echo "<script>console.log('ggc-enqueue-hook-arg: " . esc_js($hook) . "');</script>";
            $screen = get_current_screen();
            if ($screen && $screen->id === 'settings_page_ggc-crawler-definitions') {
                echo "<script>console.log('ggc-settings-hook: admin footer marker');</script>";
            }
        });

        // Additional debug: print the resolved script URL and whether a matching <script> element exists,
        // and whether WP thinks the script is registered/enqueued. Also output concat/debug constants and queue.
        add_action('admin_footer', function() {
            $plugin_url = plugin_dir_url(dirname(__DIR__) . '/custom-crawler-control.php');
            $script_url = $plugin_url . 'js/admin-settings.js';
            $registered = wp_script_is('ggc-settings-js', 'registered') ? 'true' : 'false';
            $enqueued = wp_script_is('ggc-settings-js', 'enqueued') ? 'true' : 'false';
            $concat = defined('CONCATENATE_SCRIPTS') && CONCATENATE_SCRIPTS ? 'true' : 'false';
            $script_debug = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'true' : 'false';
            global $wp_scripts;
            $queue = isset($wp_scripts->queue) ? $wp_scripts->queue : [];
        });


        
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
        register_setting('ggc_general_option_group', 'ggc_default_control_active', ['sanitize_callback' => [ $this, 'sanitize_default_control_active' ], 'default' => 'no']);
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
            'User-Agent 定義リスト (ブラックリスト/ホワイトリスト対象)', 
            [ $this, 'render_crawler_definitions_section' ], 
            'ggc_tab_bots'
        );
        
        // 3. IPアドレス範囲 定義リスト
        register_setting('ggc_ips_option_group', 'ggc_ip_range_definitions', ['sanitize_callback' => [ $this, 'sanitize_ip_range_definitions' ], 'default' => Custom_Crawler_Core::get_allowable_ip_ranges()]);

        add_settings_section(
            'ggc_ip_range_definitions', 
            'IPアドレス範囲 定義リスト (User-Agent偽装対策)', 
            [ $this, 'render_ip_range_definitions_section' ], 
            'ggc_tab_ips'
        );

        // 4. 不正UAパターン 定義リスト
        register_setting('ggc_patterns_option_group', 'ggc_browser_block_patterns', ['sanitize_callback' => [ $this, 'sanitize_browser_block_patterns' ], 'default' => Custom_Crawler_Core::get_browser_block_patterns()]);

        add_settings_section(
            'ggc_browser_block_patterns', 
            '不正UAパターン 定義リスト (悪意のあるボット/古いブラウザ)', 
            [ $this, 'render_browser_block_patterns_section' ], 
            'ggc_tab_patterns'
        );
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

    /**
     * IPアドレス範囲定義のサニタイズ（最終修正版 - IP/CIDR検証の緩和）
     */

    public function sanitize_ip_range_definitions($input) {
        $current = Custom_Crawler_Core::get_allowable_ip_ranges();
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

            $pattern_val = sanitize_text_field($pattern_def['pattern'] ?? '');
            
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
            
            <?php 
            // 診断ツールはIP設定タブに関連性が高いため、IPタブの場合のみ表示
            if ($active_tab === 'ips') {
                echo '<hr>';
                $this->render_diagnostic_tools_section(); 
            }
            ?>
        </div>
        <?php
    }

    /**
     * 一般設定セクションの表示
     */
    public function render_general_settings_section() {
        
        $default_control_active = get_option('ggc_default_control_active', 'no');
        $ip_update_frequency = get_option('ggc_ip_update_frequency', 'daily');
        ?>
        <div class="ggc-about" style="margin-bottom: 20px; padding: 12px 15px; background: #f6f7f7; border-left: 4px solid #2271b1;">
        <p style="margin: 0 0 6px 0; font-weight: bold;">
            🔒 クローラー個別制御（Custom Crawler Control）
        </p>
        <p style="margin: 0 0 8px 0;">
            投稿・固定ページ単位で、検索エンジン・AIクローラー・不正ボットのアクセスを
            <strong>User-Agent / IPアドレス / 不正UAパターン</strong>の三層で精密に制御できる
            管理者向け WordPress プラグインです。(完全にアクセスを防ぐ保証はありません。ご了承ください。詳しくはGitHubリポジトリをご覧ください。)
        </p>
        <p style="margin: 0;">
            🔗 GitHub : <a href="https://github.com/donnma777/custom-crawler-control" target="_blank" rel="noopener noreferrer">
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
                <th scope="row">
                    <label for="ggc_default_control_active">ページ個別制御の初期状態</label>
                </th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span>ページ個別制御の初期状態</span></legend>
                        <label style="margin-right: 20px;">
                            <input type="radio" name="ggc_default_control_active" value="no" <?php checked($default_control_active, 'no'); ?>>
                            <strong style="color: green;">OFF (デフォルト: 制御無効)</strong>
                            <p class="description">投稿・固定ページ編集画面のチェックボックスが初期状態でOFFになります。</p>
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="ggc_default_control_active" value="yes" <?php checked($default_control_active, 'yes'); ?>>
                            <strong style="color: red;">ON (デフォルト: 制御有効)</strong>
                            <p class="description">投稿・固定ページ編集画面のチェックボックスが初期状態でONになります。</p>
                        </label>
                    </fieldset>
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
        </table>
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
        <table class="wp-list-table widefat fixed striped" id="ggc-bots-table">
            <thead>
                <tr>
                    <th style="width: 20%;">定義キー (システム用) / グループ</th>
                    <th style="width: 25%;">表示ラベル / 説明文</th>
                    <th style="width: 45%;">User-Agent 文字列 (カンマ区切り)</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-bots-tbody">
                <?php foreach ($bots as $key => $bot) : 
                    $is_default = isset($default_bots[$key]);
                    $uas_str = implode(', ', $bot['uas'] ?? []);
                    $readonly_attr = $is_default ? 'readonly' : '';
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text" 
                               name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][key]" 
                               value="<?php echo esc_attr($key); ?>" 
                               class="regular-text ggc-bot-key" 
                               <?php echo $readonly_attr; ?> 
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
                        <p class="description">UA文字列が一つでも含まれていれば一致と見なされます。複数のUAをカンマ区切りで入力してください。</p>
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
        $ip_ranges = Custom_Crawler_Core::get_allowable_ip_ranges();
        $default_ip_ranges = ggc_get_default_ip_ranges();
        ?>
        <p class="description"> 
            ALL拒否モードが有効な場合にUser-Agent偽装に対抗するための設定です。
            ここでチェックされたクローラーのIPアドレス範囲からのアクセスのみを許可します。
        </p>
        <p>
            <?php 
            // Nonce provided for AJAX run
            ?>
            <?php $manual_url = wp_nonce_url( admin_url('admin-post.php?action=run_ggc_ip_update'), 'ggc_manual_ip_update_nonce' ); ?>
            <button type="button" id="ggc-run-ip-update" class="button button-secondary" data-nonce="<?php echo esc_attr(wp_create_nonce('ggc_run_update_nonce')); ?>" data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-manual-url="<?php echo esc_url($manual_url); ?>">今すぐ IP 更新を強制実行する</button>
            <span class="description" style="margin-left:10px;">(最終更新: <?php echo get_option('ggc_last_ip_update_time') ? human_time_diff(get_option('ggc_last_ip_update_time')) . '前' : '未実行'; ?>)</span>
            <noscript><a href="<?php echo esc_url($manual_url); ?>" class="button" style="margin-left:10px;">JavaScript無効時に更新を実行</a></noscript>
        </p>

        <p class="description" style="margin-top:8px;">※ 新規に追加した行は、ページ下部の「設定を保存」ボタンで保存してください。</p>
        <table class="wp-list-table widefat fixed striped" id="ggc-ip-ranges-table">
            <thead>
                <tr>
                    <th style="width: 25%;">定義キー</th>
                    <th style="width: 30%;">表示ラベル / 説明文 / 取得元URL</th>
                    <th style="width: 35%;">IPアドレス範囲 (CIDR形式)</th>
                    <th style="width: 10%;">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-ip-ranges-tbody">
                <?php foreach ($ip_ranges as $key => $ip_def) : 
                    $is_default = isset($default_ip_ranges[$key]);
                    $ranges_str = implode("\n", $ip_def['ranges'] ?? []);
                    $readonly_attr = $is_default ? 'readonly' : '';
                    $source_url = $ip_def['source_url'] ?? ''; // URLを取得
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p style="margin-top: 5px;"><strong>定義キー:</strong></p>
                        <input type="text" 
                               name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][key]" 
                               value="<?php echo esc_attr($key); ?>" 
                               class="regular-text ggc-ip-key" 
                               <?php echo $readonly_attr; ?> 
                               style="width: 100%;" />
                            <p style="margin-top: 10px;"><label><input type="checkbox" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][allow_placeholder]" value="1" <?php checked(!empty($ip_def['allow_placeholder']), true); ?> /> <strong>プレースホルダを許可</strong>（形式チェックに失敗してもこの値を保持します）</label></p>
                        <p style="margin-top:6px;"><label><input type="checkbox" name="ggc_ip_range_definitions[<?php echo esc_attr($key); ?>][is_auto]" value="1" <?php checked(!empty($ip_def['is_auto']), true); ?> /> 自動更新 (チェックすると保存時にURLから自動取得されます)</label></p>


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
            悪意のあるスクレイピングボット、Headlessブラウザ、または非常に古い不正なUser-Agentを検出するためのパターンリストです。

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
                        <p class="description">この文字列がUser-Agentに含まれていればブロックされます（大文字/小文字は区別されません）。</p>
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
                    <p class="description">この文字列がUser-Agentに含まれていればブロックされます（大文字/小文字は区別されません）。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-pattern">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }


    // --------------------------------------------------------
    // 管理画面通知 (変更なし)
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
        $ip_ranges = Custom_Crawler_Core::get_allowable_ip_ranges();
        if (!isset($ip_ranges[$key])) {
            $ip_ranges[$key] = ['ranges'=>[], 'label'=>'', 'description'=>'', 'source_url'=>'', 'is_auto'=>false];
        }

        if (is_wp_error($parsed)) {
            // 保存時にユーザーに見えるよう、行ごとのエラー状態を即時保存しておく
            $ip_ranges[$key]['last_parse_error'] = $parsed->get_error_message();
            $ip_ranges[$key]['last_parse_time'] = time();
            update_option('ggc_ip_range_definitions', $ip_ranges);
            wp_send_json_error(['message' => $parsed->get_error_message()]);
        }

        // 成功した場合、エラー情報をクリアして時刻を記録
        $ip_ranges[$key]['last_parse_error'] = null;
        $ip_ranges[$key]['last_parse_time'] = time();
        update_option('ggc_ip_range_definitions', $ip_ranges);

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

        // オプションを削除（これで次回読み込み時にデフォルト値が使われます）
        delete_option('ggc_ip_range_definitions');

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
        $ip_ranges = Custom_Crawler_Core::get_allowable_ip_ranges();
        // Custom_Crawler_Coreのis_in_allowable_ip_rangeメソッドが存在するかをチェック（ロジックは外部クラスに依存）
        $is_core_check_available = class_exists('Custom_Crawler_Core') && method_exists('Custom_Crawler_Core', 'is_in_allowable_ip_range');

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
                // FILTER_FLAG_IPV4 と FILTER_FLAG_IPV6 を同時に渡すと常に失敗するため、
                // 単純に FILTER_VALIDATE_IP を利用してIPv4/IPv6を許容する。
                if (!filter_var($ip_to_test, FILTER_VALIDATE_IP)) {
                    echo '<div class="notice notice-error"><p><strong>IPチェック結果:</strong> 入力された値は有効なIPアドレスではありません。</p></div>';
                } elseif ($is_core_check_available) {
                    $matched_keys = [];

                    // ここで Custom_Crawler_Core クラスの機能を使ってIP範囲チェックを行うべきですが、
                    // クラス全体を呼び出すことはできないため、ここではダミーの結果を表示する構造のみを維持します。
                    // 実際のプラグインでは、Custom_Crawler_Core::is_in_allowable_ip_range($ip_to_test, $ip_def['ranges']) のような呼び出しが必要です。

                    // ダミーロジック (実際のチェックは Custom_Crawler_Core 内で行われます)
                    // このコードは、実際にヒットしたキーを $matched_keys に格納することを想定しています。
                    $all_ranges_flat = [];
                    foreach ($ip_ranges as $key => $ip_def) {
                        $all_ranges_flat[$key] = $ip_def['ranges'];
                    }
                    
                    if (class_exists('Custom_Crawler_Core') && method_exists('Custom_Crawler_Core', 'is_ip_in_ranges_for_diagnostic')) {
                        // 理想的には、このような診断専用のメソッドを用意し、全ての範囲と照合する
                        $matched_keys = Custom_Crawler_Core::is_ip_in_ranges_for_diagnostic($ip_to_test, $all_ranges_flat);
                    } else {
                        // コアメソッドがない場合、単にIP定義リストを表示するのみとする (デバッグ用)
                        // ここでは何も表示せず、以下のメッセージに任せる
                    }
                    // /ダミーロジック

                    if (!empty($matched_keys)) {
                        echo '<div class="notice notice-success"><p><strong>IPチェック結果:</strong> IPアドレス <code>' . esc_html($ip_to_test) . '</code> は以下の定義範囲に一致しました。</p>';
                        echo '<ul>';
                        foreach (array_unique($matched_keys) as $key) {
                            $label = $ip_ranges[$key]['label'] ?? $key;
                            echo '<li><strong>' . esc_html($label) . '</strong> (キー: <code>' . esc_html($key) . '</code>)</li>';
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
        $current_bots = Custom_Crawler_Core::get_allowable_bots();
        $current_ips = Custom_Crawler_Core::get_allowable_ip_ranges();
        $current_patterns = Custom_Crawler_Core::get_browser_block_patterns();

        // デフォルト設定を取得
        $default_bots = ggc_get_default_bots();
        $default_ips = ggc_get_default_ip_ranges();
        $default_patterns = ggc_get_default_browser_patterns();
        
        // --- 堅牢なマージ処理 ---
        // 既存のキーをすべて小文字で保持
        $existing_bot_keys = array_map('strtolower', array_keys($current_bots));
        $existing_ip_keys = array_map('strtolower', array_keys($current_ips));
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
        foreach ($default_patterns as $key => $value) {
            if (!in_array(strtolower($key), $existing_pattern_keys)) {
                $current_patterns[$key] = $value;
            }
        }

        // データベースを更新
        update_option('ggc_crawler_definitions', $current_bots);
        update_option('ggc_ip_range_definitions', $current_ips);
        update_option('ggc_browser_block_patterns', $current_patterns);

        // 完了フラグをつけてリダイレクト
        $redirect_url = remove_query_arg(['action', '_wpnonce', 'settings-imported'], wp_get_referer());
        $redirect_url = add_query_arg('settings-imported', '1', $redirect_url);
        
        wp_safe_redirect($redirect_url);
        exit;
    }

}