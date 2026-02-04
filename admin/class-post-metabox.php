<?php
// <!-- custom-crawler-control\admin\class-post-metabox.php -->
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Post_Metabox {

    protected static $instance = null;

    private function __construct() {
        // メタボックスの追加フック
        add_action('add_meta_boxes', [ $this, 'add_meta_box' ]);
        // メタボックスのデータ保存フック
        add_action('save_post', [ $this, 'save_crawler_meta_box' ]);
        // JS/CSSのエンキュー (メタボックス用JS/CSSのみ)
        add_action('admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ]);
        // Gutenbergエディタ用にもJSを読み込む
        add_action('enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ]);
        // REST APIでブロック属性メタフィールドを登録
        add_action('rest_api_init', [ $this, 'register_block_attrs_meta' ]);
        
        // AJAXでブロック属性を保存
        add_action('wp_ajax_ggc_save_block_attrs', [ $this, 'ajax_save_block_attrs' ]);

        // AJAXでブロック属性を取得
        add_action('wp_ajax_ggc_get_block_attrs', [ $this, 'ajax_get_block_attrs' ]);
    }

    /**
     * AJAXでブロック属性を保存
     */
    public function ajax_save_block_attrs() {
        check_ajax_referer('ggc_save_block_attrs', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error([ 'message' => 'Permission denied' ], 403);
        }

        $attrs_raw = isset($_POST['attrs']) ? wp_unslash($_POST['attrs']) : '';
        $attrs = json_decode($attrs_raw, true);

        if (!is_array($attrs)) {
            wp_send_json_error([ 'message' => 'Invalid attrs payload' ], 400);
        }

        // 空配列の場合はメタを削除、そうでなければ更新
        if (empty($attrs)) {
            delete_post_meta($post_id, '_ggc_block_attrs');
        } else {
            update_post_meta($post_id, '_ggc_block_attrs', $attrs);
        }

        wp_send_json_success([ 'saved' => true ]);
    }

    /**
     * AJAXでブロック属性を取得
     */
    public function ajax_get_block_attrs() {
        check_ajax_referer('ggc_save_block_attrs', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error([ 'message' => 'Permission denied' ], 403);
        }

        $attrs = get_post_meta($post_id, '_ggc_block_attrs', true);

        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            if (is_array($decoded)) {
                $attrs = $decoded;
            }
        }

        if (!is_array($attrs)) {
            $attrs = [];
        }

        wp_send_json_success([ 'attrs' => $attrs ]);
    }

    /**
     * REST APIでブロック属性メタフィールドを登録
     */
    public function register_block_attrs_meta() {
        $post_meta_args = [
            'type' => 'object',
            'description' => 'GGC Block Attributes (ggcAltText)',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            },
        ];
        
        register_post_meta('post', '_ggc_block_attrs', $post_meta_args);
        register_post_meta('page', '_ggc_block_attrs', $post_meta_args);
    }

    /**
     * Gutenbergエディタ用のJS/CSS読み込み
     */
    public function enqueue_block_editor_assets() {
        $plugin_dir = plugin_dir_path(dirname(__DIR__) . '/custom-crawler-control.php');
        $plugin_url = plugin_dir_url(dirname(__DIR__) . '/custom-crawler-control.php');
        $js_asset_path = $plugin_dir . 'js/admin-meta.js';
        wp_enqueue_script(
            'ggc-admin-meta-js',
            $plugin_url . 'js/admin-meta.js',
            [ 'jquery', 'wp-blocks', 'wp-element', 'wp-components', 'wp-editor', 'wp-data', 'wp-compose', 'wp-edit-post', 'wp-block-editor' ],
            file_exists($js_asset_path) ? filemtime($js_asset_path) : '4.3.4',
            true
        );
        wp_localize_script('ggc-admin-meta-js', 'ggcAdminMeta', [
            'alt_mode' => get_option('ggc_alt_mode', 'none'),
            'alt_fixed' => get_option('ggc_alt_fixed_text', ''),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ggc_save_block_attrs'),
        ]);
        // CSSも必要なら同様に
        $css_asset_path = $plugin_dir . 'css/admin-meta.css';
        wp_enqueue_style(
            'ggc-admin-meta-css',
            $plugin_url . 'css/admin-meta.css',
            [],
            file_exists($css_asset_path) ? filemtime($css_asset_path) : '4.3.4'
        );
    }

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 管理画面でのみメタボックス制御用JS/CSSを読み込む
     */
    public function enqueue_admin_scripts($hook) {
        global $typenow;

        // post.php (編集) または post-new.php (新規) で、投稿タイプが 'post' または 'page' の場合
        if (in_array($hook, ['post.php', 'post-new.php']) && in_array($typenow, ['post', 'page'])) {
            $plugin_dir = plugin_dir_path(dirname(__DIR__) . '/custom-crawler-control.php');
            $plugin_url = plugin_dir_url(dirname(__DIR__) . '/custom-crawler-control.php');

            // admin-meta.js の読み込み (キャッシュ対策あり)
            $js_asset_path = $plugin_dir . 'js/admin-meta.js';
            wp_enqueue_script(
                'ggc-admin-meta-js',
                $plugin_url . 'js/admin-meta.js',
                ['jquery'],
                file_exists($js_asset_path) ? filemtime($js_asset_path) : '4.3.4', // Fallback version
                true
            );

            // Pass alt text mode/fixed text to JS for live preview fallback
            wp_localize_script('ggc-admin-meta-js', 'ggcAdminMeta', [
                'alt_mode' => get_option('ggc_alt_mode', 'none'),
                'alt_fixed' => get_option('ggc_alt_fixed_text', ''),
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ggc_save_block_attrs'),
            ]);

            // admin-meta.css の読み込み (キャッシュ対策あり)
            $css_asset_path = $plugin_dir . 'css/admin-meta.css';
            wp_enqueue_style(
                'ggc-admin-meta-css',
                $plugin_url . 'css/admin-meta.css',
                [],
                file_exists($css_asset_path) ? filemtime($css_asset_path) : '4.3.4' // Fallback version
            );
        }
    }


    /**
     * メタボックスを投稿と固定ページに追加
     */
    public function add_meta_box() {
        $screens = ['post', 'page'];
        foreach ($screens as $screen) {
            add_meta_box(
                'ggc_crawler_control',
                'アクセス制御',
                [ $this, 'meta_box_callback' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * メタボックスのHTML出力
     */
    public function meta_box_callback($post) {
        wp_nonce_field('ggc_crawler_control_save', 'ggc_crawler_control_nonce');

        // Retrieve saved modes or default to 'global'
        $ua_control_mode = get_post_meta($post->ID, '_ggc_ua_control_mode', true) ?: 'global';
        $ip_control_mode = get_post_meta($post->ID, '_ggc_ip_control_mode', true) ?: 'global';

        // Legacy mapping
        if ($ua_control_mode === 'individual') $ua_control_mode = 'blacklist';
        if ($ip_control_mode === 'individual') $ip_control_mode = 'blacklist';

        // Retrieve lists
        $selected_crawlers = get_post_meta($post->ID, '_ggc_selected_crawlers', true);
        if (!is_array($selected_crawlers)) $selected_crawlers = [];

        $selected_ips = get_post_meta($post->ID, '_ggc_selected_ips', true);
        if (!is_array($selected_ips)) $selected_ips = [];

        $selected_ips_2 = get_post_meta($post->ID, '_ggc_selected_ips_2', true);
        if (!is_array($selected_ips_2)) $selected_ips_2 = [];

        $selected_page_browser_patterns = get_post_meta($post->ID, '_ggc_selected_page_browser_patterns', true);
        if (!is_array($selected_page_browser_patterns)) $selected_page_browser_patterns = [];

        // Retrieve definitions
        $all_bots = Custom_Crawler_Core::get_allowable_bots();

        // Split IP ranges for display
        $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
        if (empty($ip_ranges_1)) $ip_ranges_1 = ggc_get_default_ip_ranges();

        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);
        if (empty($ip_ranges_2)) $ip_ranges_2 = ggc_get_default_ip_ranges_2();

        $all_browser_patterns = Custom_Crawler_Core::get_browser_block_patterns();

        // Group bots
        $grouped_bots = [];
        foreach ($all_bots as $key => $bot) {
            $group_label = isset($bot['group_label']) ? $bot['group_label'] : 'その他';
            if (!isset($grouped_bots[$group_label])) {
                $grouped_bots[$group_label] = [];
            }
            $grouped_bots[$group_label][$key] = $bot;
        }

        // Group patterns
        $grouped_browser_patterns = [];
        foreach ($all_browser_patterns as $key => $pattern_def) {
            if (!is_array($pattern_def)) continue;
            $group_label = isset($pattern_def['group_label']) ? $pattern_def['group_label'] : 'その他';
            if (!isset($grouped_browser_patterns[$group_label])) {
                $grouped_browser_patterns[$group_label] = [];
            }
            $grouped_browser_patterns[$group_label][$key] = $pattern_def;
        }

        // Group IP Ranges 1
        $grouped_ip_ranges_1 = [];
        foreach ($ip_ranges_1 as $key => $ip_def) {
            $group_label = isset($ip_def['group_label']) ? $ip_def['group_label'] : 'その他';
            if (!isset($grouped_ip_ranges_1[$group_label])) {
                $grouped_ip_ranges_1[$group_label] = [];
            }
            $grouped_ip_ranges_1[$group_label][$key] = $ip_def;
        }

        // Group IP Ranges 2
        $grouped_ip_ranges_2 = [];
        foreach ($ip_ranges_2 as $key => $ip_def) {
            $group_label = isset($ip_def['group_label']) ? $ip_def['group_label'] : 'その他';
            if (!isset($grouped_ip_ranges_2[$group_label])) {
                $grouped_ip_ranges_2[$group_label] = [];
            }
            $grouped_ip_ranges_2[$group_label][$key] = $ip_def;
        }

        $modes = [
            'global'     => 'グローバル設定に従う',
            'blacklist'  => 'ブラックリスト',
            'whitelist'  => 'ホワイトリスト',
            'allow_all'  => '全許可 (制限なし)',
            'deny_all'   => '全拒否 (すべてブロック)',
        ];

        ?>
        <div id="ggc-control-panel">

            <!-- User-Agent Evaluation Section -->
            <div class="ggc-section" style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
                <h4 style="margin: 0 0 10px;">User-Agent の評価-ページ</h4>
                <select name="ggc_ua_control_mode" id="ggc_ua_control_mode" style="width: 100%; margin-bottom: 10px;">
                    <?php foreach ($modes as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($ua_control_mode, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="ggc-ua-list-wrapper" style="display: <?php echo (in_array($ua_control_mode, ['blacklist', 'whitelist'])) ? 'block' : 'none'; ?>;">
                    <p style="font-size: 11px; margin-bottom: 5px; font-weight: bold;">User-Agent 制御リスト (個別設定):</p>
                    <div id="ggc-crawler-list-panel" class="ggc-panel" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding:10px;">
                        <p id="ggc-ua-description" class="description" style="margin-top: 0; margin-bottom: 8px;">
                            <?php echo ($ua_control_mode === 'whitelist') ? 'ホワイトリスト : チェックしたUser-Agentをアクセス許可します。' : 'ブラックリスト : チェックしたUser-Agentをアクセス拒否します。'; ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-ua-def-1-container" style="margin: 10px 0 5px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義1
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-ua-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-ua-def-1-container" class="ggc-section-content" style="display: none;">
                            <?php $group_counter = 0; foreach ($grouped_bots as $group_label => $bots_in_group) : $group_counter++; $group_id = 'ggc-group-' . $group_counter; ?>
                                <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                    <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                    <?php echo esc_html($group_label); ?>
                                    <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                </h4>
                                <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                    <?php foreach ($bots_in_group as $key => $bot) : ?>
                                        <label class="ggc-crawler-item" style="display: block; margin-bottom: 3px;">
                                            <input type="checkbox" name="ggc_selected_crawlers_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_crawlers), true); ?>>
                                            <strong><?php echo esc_html($bot['label']); ?></strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h5 class="ggc-section-header" data-target="#ggc-ua-def-2-container" style="margin: 15px 0 5px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義2
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-ua-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-ua-def-2-container" class="ggc-section-content" style="display: none;">
                            <?php $group_counter = 0; foreach ($grouped_browser_patterns as $group_label => $patterns_in_group) : $group_counter++; $group_id = 'ggc-pattern-group-' . $group_counter; ?>
                                <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                    <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                            <!-- メディアごとの評価・代替テキスト入力欄 -->
                                            <div class="ggc-section" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
                                                <h4 style="margin: 0 0 10px;">メディアごとの評価と代替テキスト</h4>
                                                <p style="font-size:11px;">投稿本文内のメディアごとに、評価値に応じて代替テキストを入力できます。</p>

                                                <?php
                                                // 投稿本文からメディアを抽出
                                                $content = $post->post_content;
                                                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches);
                                                $image_srcs = $matches[1];

                                                // メディアごとの保存済みテキストを取得
                                                $image_alt_texts = get_post_meta($post->ID, '_ggc_image_alt_texts', true);
                                                if (!is_array($image_alt_texts)) $image_alt_texts = [];

                                                if (!empty($image_srcs)) :
                                                    echo '<ul style="list-style: none; padding-left: 0;">';
                                                    foreach ($image_srcs as $src) {
                                                        // 仮の評価値取得関数（実装に応じて変更）
                                                        if (function_exists('get_image_evaluation')) {
                                                            $eval = get_image_evaluation($src, $post->ID);
                                                        } else {
                                                            $eval = null; // デフォルトはnull
                                                        }

                                                        // 例: 評価値が"low"の場合のみテキストボックスを表示
                                                        $show_textbox = ($eval === 'low' || is_null($eval));

                                                        echo '<li style="margin-bottom: 10px;">';
                                                        echo '<div><img src="' . esc_url($src) . '" style="max-width:80px; max-height:80px; vertical-align:middle; margin-right:8px;">';
                                                        if ($show_textbox) {
                                                            $val = isset($image_alt_texts[$src]) ? esc_attr($image_alt_texts[$src]) : '';
                                                            echo '<input type="text" name="ggc_image_alt_texts[' . esc_attr($src) . ']" value="' . $val . '" placeholder="代替テキストを入力" style="width:60%;">';
                                                            echo ' <span style="font-size:10px; color:#888;">(評価: ' . esc_html($eval ?? '未評価') . ')</span>';
                                                        } else {
                                                            echo '<span style="font-size:12px; color:#888;">評価が高いため代替テキスト不要</span>';
                                                        }
                                                        echo '</div>';
                                                        echo '</li>';
                                                    }
                                                    echo '</ul>';
                                                else:
                                                    echo '<p style="font-size:11px; color:#888;">本文にメディアはありません。</p>';
                                                endif;
                                                ?>
                                            </div>
                                    <?php echo esc_html($group_label); ?>
                                    <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-pattern" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                </h4>
                                <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                    <?php foreach ($patterns_in_group as $key => $pattern_def) : ?>
                                        <label class="ggc-page-pattern-item" style="display: block; margin-bottom: 3px;">
                                            <input type="checkbox" name="ggc_selected_page_browser_patterns_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_page_browser_patterns), true); ?>>
                                            <strong><?php echo esc_html($pattern_def['label']); ?></strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- IP Address Evaluation Section -->
            <div class="ggc-section">
                <h4 style="margin: 0 0 10px;">IPアドレスの評価-ページ</h4>
                <select name="ggc_ip_control_mode" id="ggc_ip_control_mode" style="width: 100%; margin-bottom: 10px;">
                    <?php foreach ($modes as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($ip_control_mode, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="ggc-ip-list-wrapper" style="display: <?php echo (in_array($ip_control_mode, ['blacklist', 'whitelist'])) ? 'block' : 'none'; ?>;">
                    <p style="font-size: 11px; margin-bottom: 5px; font-weight: bold;">IPアドレス制御リスト (個別設定):</p>
                    <div id="ggc-ip-ranges-panel" class="ggc-panel" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding:10px;">
                        <p id="ggc-ip-description" class="description" style="margin-top: 0; margin-bottom: 8px;">
                            <?php echo ($ip_control_mode === 'blacklist') ? 'ホワイトリスト : チェックしたIP範囲をアクセス許可します。' : 'ブラックリスト : チェックしたIP範囲をアクセス拒否します。'; ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-ip-def-1-container" style="margin: 10px 0 5px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲1
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-ip-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-ip-def-1-container" class="ggc-section-content" style="display: none;">
                            <?php if (!empty($grouped_ip_ranges_1)): ?>
                                <?php $group_counter = 0; foreach ($grouped_ip_ranges_1 as $group_label => $ips_in_group) : $group_counter++; $group_id = 'ggc-ip-group-1-' . $group_counter; ?>
                                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                        <?php echo esc_html($group_label); ?>
                                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-ip" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                    </h4>
                                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                        <?php foreach ($ips_in_group as $key => $ip_def) : ?>
                                            <label class="ggc-ip-range-item" style="display: block; margin-bottom: 3px;">
                                                <input type="checkbox" name="ggc_selected_ips_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_ips), true); ?>>
                                                <strong><?php echo esc_html($ip_def['label']); ?></strong>
                                                <?php if (!empty($ip_def['is_auto'])): ?>
                                                    <small style="color: green;">(自動)</small>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>

                        <h5 class="ggc-section-header" data-target="#ggc-ip-def-2-container" style="margin: 15px 0 5px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲2
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-ip-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-ip-def-2-container" class="ggc-section-content" style="display: none;">
                            <?php if (!empty($grouped_ip_ranges_2)): ?>
                                <?php $group_counter = 0; foreach ($grouped_ip_ranges_2 as $group_label => $ips_in_group) : $group_counter++; $group_id = 'ggc-ip-group-2-' . $group_counter; ?>
                                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                        <?php echo esc_html($group_label); ?>
                                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-ip" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                    </h4>
                                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                        <?php foreach ($ips_in_group as $key => $ip_def) : ?>
                                            <label class="ggc-ip-range-item" style="display: block; margin-bottom: 3px;">
                                                <input type="checkbox" name="ggc_selected_ips_2_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_ips_2), true); ?>>
                                                <strong><?php echo esc_html($ip_def['label']); ?></strong>
                                                <?php if (!empty($ip_def['is_auto'])): ?>
                                                    <small style="color: green;">(自動)</small>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Media Control Section -->
            <div class="ggc-section" style="margin-top:15px; border-top: 1px dashed #eee; padding-top: 10px;">
                <h4 style="margin: 0 0 10px;">メディア制御</h4>

                <label for="ggc_media_ua_action" style="display:block; font-weight:bold; margin-bottom:6px;">User-Agent の評価 - メディア：</label>
                <select name="ggc_media_ua_action" id="ggc_media_ua_action" style="width:100%; margin-bottom:10px;">
                    <option value="global" <?php selected(get_post_meta($post->ID, '_ggc_media_ua_action', true) ?: 'global', 'global'); ?>>グローバル設定に従う</option>
                    <option value="blacklist" <?php selected(get_post_meta($post->ID, '_ggc_media_ua_action', true), 'blacklist'); ?>>ブラックリスト</option>
                    <option value="whitelist" <?php selected(get_post_meta($post->ID, '_ggc_media_ua_action', true), 'whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected(get_post_meta($post->ID, '_ggc_media_ua_action', true), 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected(get_post_meta($post->ID, '_ggc_media_ua_action', true), 'deny_all'); ?>>全拒否</option>
                </select>

                <div id="ggc-media-ua-list-wrapper" style="display: <?php echo in_array(get_post_meta($post->ID, '_ggc_media_ua_action', true), ['blacklist','whitelist']) ? 'block' : 'none'; ?>; margin-bottom:10px;">

                    <div class="ggc-panel" id="ggc-media-crawler-list-panel" style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:10px;">
                        <p id="ggc-media-ua-description" class="description" style="margin-top:0; margin-bottom:8px;">
                            <?php $__m_mode = get_post_meta($post->ID, '_ggc_media_ua_action', true) ?: 'global';
                            if ($__m_mode === 'whitelist') {
                                echo 'ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。';
                            } elseif ($__m_mode === 'blacklist') {
                                echo 'ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。';
                            } else {
                                echo 'グローバル設定に従います。';
                            } ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-media-ua-def-1-container" style="margin: 0 0 8px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義1
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-media-ua-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ua-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_crawlers = get_post_meta($post->ID, '_ggc_selected_media_crawlers', true);
                            if (!is_array($selected_media_crawlers)) $selected_media_crawlers = [];
                            $group_counter = 0; foreach ($grouped_bots as $glabel => $bots_in_group) : $group_counter++; $group_id = 'ggc-media-ua-group-' . $group_counter; ?>
                                <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                    <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                    <?php echo esc_html($glabel); ?>
                                    <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                </h4>
                                <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                    <?php foreach ($bots_in_group as $bkey => $bot): ?>
                                        <label class="ggc-crawler-item" style="display: block; margin-bottom: 3px;">
                                            <input type="checkbox" name="ggc_selected_media_crawlers_field[]" value="<?php echo esc_attr($bkey); ?>" <?php checked(in_array(sanitize_key($bkey), $selected_media_crawlers), true); ?>>
                                            <strong><?php echo esc_html($bot['label']); ?></strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h5 class="ggc-section-header" data-target="#ggc-media-ua-def-2-container" style="margin: 12px 0 8px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義2
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-media-ua-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ua-def-2-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_page_browser_patterns = get_post_meta($post->ID, '_ggc_selected_media_page_browser_patterns', true);
                            if (!is_array($selected_media_page_browser_patterns)) $selected_media_page_browser_patterns = [];
                            $group_counter = 0; foreach ($grouped_browser_patterns as $glabel => $patterns_in_group) : $group_counter++; $group_id = 'ggc-media-pattern-group-' . $group_counter; ?>
                                <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                    <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                    <?php echo esc_html($glabel); ?>
                                    <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-pattern" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                </h4>
                                <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                    <?php foreach ($patterns_in_group as $pkey => $pattern_def): ?>
                                        <label class="ggc-page-pattern-item" style="display: block; margin-bottom: 3px;">
                                            <input type="checkbox" name="ggc_selected_media_page_browser_patterns_field[]" value="<?php echo esc_attr($pkey); ?>" <?php checked(in_array(sanitize_key($pkey), $selected_media_page_browser_patterns), true); ?> />
                                            <strong><?php echo esc_html($pattern_def['label']); ?></strong>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>

                <label for="ggc_media_ip_action" style="display:block; font-weight:bold; margin-bottom:6px;">IPアドレスの評価 - メディア：</label>
                <select name="ggc_media_ip_action" id="ggc_media_ip_action" style="width:100%; margin-bottom:10px;">
                    <option value="global" <?php selected(get_post_meta($post->ID, '_ggc_media_ip_action', true) ?: 'global', 'global'); ?>>グローバル設定に従う</option>
                    <option value="blacklist" <?php selected(get_post_meta($post->ID, '_ggc_media_ip_action', true), 'blacklist'); ?>>ブラックリスト</option>
                    <option value="whitelist" <?php selected(get_post_meta($post->ID, '_ggc_media_ip_action', true), 'whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected(get_post_meta($post->ID, '_ggc_media_ip_action', true), 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected(get_post_meta($post->ID, '_ggc_media_ip_action', true), 'deny_all'); ?>>全拒否</option>
                </select>

                <div id="ggc-media-ip-list-wrapper" style="display: <?php echo in_array(get_post_meta($post->ID, '_ggc_media_ip_action', true), ['blacklist','whitelist']) ? 'block' : 'none'; ?>; margin-bottom:10px;">

                    <div class="ggc-panel" id="ggc-media-ip-ranges-panel" style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:10px;">
                        <p id="ggc-media-ip-description" class="description" style="margin-top:0; margin-bottom:8px;">
                            <?php $__m_ip_mode = get_post_meta($post->ID, '_ggc_media_ip_action', true) ?: 'global';
                            if ($__m_ip_mode === 'whitelist') {
                                echo 'ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。';
                            } elseif ($__m_ip_mode === 'blacklist') {
                                echo 'ブラックリスト : チェックしたIP範囲は代替テキスト表示、それ以外はメディア表示します。';
                            } else {
                                echo 'グローバル設定に従います。';
                            } ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-media-ip-def-1-container" style="margin: 0 0 8px; border-bottom: 1px solid #eee; cursor: pointer;">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲1
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-media-ip-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ip-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_ips = get_post_meta($post->ID, '_ggc_selected_media_ips', true);
                            if (!is_array($selected_media_ips)) $selected_media_ips = [];

                            $group_counter = 0; if (!empty($grouped_ip_ranges_1)) :
                                foreach ($grouped_ip_ranges_1 as $glabel => $ips_in_group) : $group_counter++; $group_id = 'ggc-media-ip-group-1-' . $group_counter; ?>
                                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                        <?php echo esc_html($glabel); ?>
                                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-ip" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                    </h4>
                                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                        <?php foreach ($ips_in_group as $ikey => $ipd): ?>
                                            <label class="ggc-ip-range-item" style="display: block; margin-bottom: 3px;">
                                                <input type="checkbox" name="ggc_selected_media_ips_field[]" value="<?php echo esc_attr($ikey); ?>" <?php checked(in_array(sanitize_key($ikey), $selected_media_ips), true); ?> />
                                                <strong><?php echo esc_html($ipd['label']); ?></strong>
                                                <?php if (!empty($ipd['is_auto'])): ?>
                                                    <small style="color: green;">(自動)</small>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    <?php if (!empty($grouped_ip_ranges_2)) : ?>
                    <h5 class="ggc-section-header" data-target="#ggc-media-ip-def-2-container" style="margin: 12px 0 8px; border-bottom: 1px solid #eee; cursor: pointer;">
                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                        IPアドレス範囲2
                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-media-ip-def-2-container"> [全選択/解除] </small>
                    </h5>
                    <div id="ggc-media-ip-def-2-container" class="ggc-section-content" style="display:none;">
                        <div style="max-height:160px; overflow-y:auto; border:1px solid #ddd; padding:8px;">
                            <?php
                                $selected_media_ips_2 = get_post_meta($post->ID, '_ggc_selected_media_ips_2', true);
                                if (!is_array($selected_media_ips_2)) $selected_media_ips_2 = [];
                                $group_counter = 0; foreach ($grouped_ip_ranges_2 as $glabel => $ips_in_group) : $group_counter++; $group_id = 'ggc-media-ip-group-2-' . $group_counter; ?>
                                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                        <?php echo esc_html($glabel); ?>
                                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-ip" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                    </h4>
                                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                        <?php foreach ($ips_in_group as $ikey => $ipd): ?>
                                            <label class="ggc-ip-range-item" style="display: block; margin-bottom: 3px;">
                                                <input type="checkbox" name="ggc_selected_media_ips_2_field[]" value="<?php echo esc_attr($ikey); ?>" <?php checked(in_array(sanitize_key($ikey), $selected_media_ips_2), true); ?> />
                                                <strong><?php echo esc_html($ipd['label']); ?></strong>
                                                <?php if (!empty($ipd['is_auto'])): ?>
                                                    <small style="color: green;">(自動)</small>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                    <?php endif; ?>
                </div>

                <!-- アイキャッチ画像の代替テキスト -->
                <div class="ggc-section" style="margin-top:15px; border-top:1px solid #eee; padding-top:15px;">
                    <h4 style="margin:0 0 10px;">アイキャッチ画像の代替テキスト</h4>
                    <?php 
                    $thumbnail_id = get_post_thumbnail_id($post->ID);
                    
                    if ($thumbnail_id) {
                        $thumbnail_url = wp_get_attachment_image_src($thumbnail_id, 'thumbnail');
                        $saved_featured_alt = get_post_meta($post->ID, '_ggc_featured_image_alt_text', true);
                    ?>
                    <div style="margin-bottom:10px;">
                        <?php if ($thumbnail_url): ?>
                        <img src="<?php echo esc_url($thumbnail_url[0]); ?>" style="max-width:80px; max-height:80px; display:block; margin-bottom:8px;">
                        <?php endif; ?>
                        <label for="ggc_featured_image_alt_text" style="display:block; margin-bottom:5px;">代替テキスト:</label>
                        <input type="text" id="ggc_featured_image_alt_text" name="ggc_featured_image_alt_text" value="<?php echo esc_attr($saved_featured_alt); ?>" style="width:100%;" placeholder="アイキャッチ画像の代替テキストを入力">
                        <p class="description" style="font-size:11px; margin-top:5px;">評価に従ってアイキャッチ画像をこのテキストに置き換えます。<?php echo '<!-- Value: ' . esc_html($saved_featured_alt) . ' -->'; ?></p>
                    </div>
                    <?php
                    } else {
                        echo '<p style="color:#999; font-size:12px;">アイキャッチ画像が設定されていません。先にアイキャッチ画像を設定してください。</p>';
                    }
                    ?>
                </div>

                <!-- Preview button -->
                <?php
                // 日付ベースのパーマリンク構造でURLを生成
                $post_date = get_the_date('Y/m/d', $post->ID);
                $post_slug = $post->post_name;
                
                // スラッグが空の場合はタイトルから生成
                if (empty($post_slug)) {
                    $post_slug = sanitize_title($post->post_title);
                }
                
                $base_url = home_url($post_date . '/' . $post_slug . '/');
                $preview_url = add_query_arg('ggc_preview', '1', $base_url);
                ?>
                <p style="margin-top:15px;">
                    <a class="button button-secondary ggc-alt-preview"
                       href="<?php echo esc_url($preview_url); ?>"
                       target="_blank" rel="noopener">
                        代替テキスト置換プレビュー
                    </a>
                </p>

            </div>

            <p class="description" style="font-size:11px; margin-top: 15px;">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=ggc-crawler-definitions')); ?>" target="_blank">アクセス制御設定画面</a>
            </p>
        </div>
        <?php
    }

    /**
     * メタボックスのデータを保存
     */
    public function save_crawler_meta_box($post_id) {

        if (!isset($_POST['ggc_crawler_control_nonce']) || !wp_verify_nonce($_POST['ggc_crawler_control_nonce'], 'ggc_crawler_control_save')) {
            return $post_id;
        }

        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return $post_id;
        }

        $post_type = get_post_type($post_id);
        if ( ! in_array( $post_type, [ 'post', 'page' ] ) ) {
            return $post_id;
        }

        // Gutenbergブロック属性（ggcAltText）をpost_contentから自動抽出して保存
        $post = get_post($post_id);
        if ($post && $post->post_content) {
            $ggc_block_attrs = $this->extract_ggc_block_attributes($post->post_content);
            
            if (!empty($ggc_block_attrs)) {
                update_post_meta($post_id, '_ggc_block_attrs', $ggc_block_attrs);
            } else {
                // ブロックに ggcAltText が含まれない場合は既存メタを保持する
            }
        }

        // アイキャッチ画像の代替テキストを保存
        if (isset($_POST['ggc_featured_image_alt_text'])) {
            $featured_alt = sanitize_text_field($_POST['ggc_featured_image_alt_text']);
            if (!empty($featured_alt)) {
                update_post_meta($post_id, '_ggc_featured_image_alt_text', $featured_alt);
            } else {
                delete_post_meta($post_id, '_ggc_featured_image_alt_text');
            }
        }

        // Save Modes
        $ua_mode = isset($_POST['ggc_ua_control_mode']) ? sanitize_text_field($_POST['ggc_ua_control_mode']) : 'global';
        update_post_meta($post_id, '_ggc_ua_control_mode', $ua_mode);

        $ip_mode = isset($_POST['ggc_ip_control_mode']) ? sanitize_text_field($_POST['ggc_ip_control_mode']) : 'global';
        update_post_meta($post_id, '_ggc_ip_control_mode', $ip_mode);

        // Save Lists - グローバル設定に従う場合は選択リストをクリア
        if ($ua_mode === 'global') {
            update_post_meta($post_id, '_ggc_selected_crawlers', []);
            update_post_meta($post_id, '_ggc_selected_page_browser_patterns', []);
        } else {
            $selected_crawlers = array_map('sanitize_key', isset($_POST['ggc_selected_crawlers_field']) ? $_POST['ggc_selected_crawlers_field'] : []);
            update_post_meta($post_id, '_ggc_selected_crawlers', $selected_crawlers);

            $selected_page_browser_patterns = array_map('sanitize_key', isset($_POST['ggc_selected_page_browser_patterns_field']) ? $_POST['ggc_selected_page_browser_patterns_field'] : []);
            update_post_meta($post_id, '_ggc_selected_page_browser_patterns', $selected_page_browser_patterns);
        }

        if ($ip_mode === 'global') {
            update_post_meta($post_id, '_ggc_selected_ips', []);
            update_post_meta($post_id, '_ggc_selected_ips_2', []);
        } else {
            $selected_ips = array_map('sanitize_key', isset($_POST['ggc_selected_ips_field']) ? $_POST['ggc_selected_ips_field'] : []);
            update_post_meta($post_id, '_ggc_selected_ips', $selected_ips);

            $selected_ips_2 = array_map('sanitize_key', isset($_POST['ggc_selected_ips_2_field']) ? $_POST['ggc_selected_ips_2_field'] : []);
            update_post_meta($post_id, '_ggc_selected_ips_2', $selected_ips_2);
        }

        // Save Media-related settings
        $ua_media_action = isset($_POST['ggc_media_ua_action']) ? sanitize_text_field($_POST['ggc_media_ua_action']) : 'global';
        update_post_meta($post_id, '_ggc_media_ua_action', $ua_media_action);

        $ip_media_action = isset($_POST['ggc_media_ip_action']) ? sanitize_text_field($_POST['ggc_media_ip_action']) : 'global';
        update_post_meta($post_id, '_ggc_media_ip_action', $ip_media_action);


        // Save media-specific selected crawlers and IPs for blacklist/whitelist modes
        // グローバル設定に従う場合は、空配列を保存してチェックボックス選択を無効化
        if ($ua_media_action === 'global') {
            update_post_meta($post_id, '_ggc_selected_media_crawlers', []);
            update_post_meta($post_id, '_ggc_selected_media_page_browser_patterns', []);
        } else {
            $selected_media_crawlers = array_map('sanitize_key', isset($_POST['ggc_selected_media_crawlers_field']) ? $_POST['ggc_selected_media_crawlers_field'] : []);
            update_post_meta($post_id, '_ggc_selected_media_crawlers', $selected_media_crawlers);

            $selected_media_page_browser_patterns = array_map('sanitize_key', isset($_POST['ggc_selected_media_page_browser_patterns_field']) ? $_POST['ggc_selected_media_page_browser_patterns_field'] : []);
            update_post_meta($post_id, '_ggc_selected_media_page_browser_patterns', $selected_media_page_browser_patterns);
        }

        if ($ip_media_action === 'global') {
            update_post_meta($post_id, '_ggc_selected_media_ips', []);
            update_post_meta($post_id, '_ggc_selected_media_ips_2', []);
        } else {
            $selected_media_ips = array_map('sanitize_key', isset($_POST['ggc_selected_media_ips_field']) ? $_POST['ggc_selected_media_ips_field'] : []);
            update_post_meta($post_id, '_ggc_selected_media_ips', $selected_media_ips);

            $selected_media_ips_2 = array_map('sanitize_key', isset($_POST['ggc_selected_media_ips_2_field']) ? $_POST['ggc_selected_media_ips_2_field'] : []);
            update_post_meta($post_id, '_ggc_selected_media_ips_2', $selected_media_ips_2);
        }

        //メディアごとの代替テキスト保存
        if (isset($_POST['ggc_image_alt_texts']) && is_array($_POST['ggc_image_alt_texts'])) {
            $image_alt_texts = array_map('sanitize_text_field', $_POST['ggc_image_alt_texts']);
            update_post_meta($post_id, '_ggc_image_alt_texts', $image_alt_texts);
        } else {
            delete_post_meta($post_id, '_ggc_image_alt_texts');
        }

        // Remove old meta if exists to avoid confusion
        delete_post_meta($post_id, '_ggc_control_active');
        delete_post_meta($post_id, '_ggc_control_mode');
    }

    /**
     * GutenbergブロックコンテンツからggcAltText属性を抽出
     * ブロックコメント形式: <!-- wp:image {...,"ggcAltText":"text",...} -->
     */
    private function extract_ggc_block_attributes($content) {
        $attributes = [];
        
        // Gutenbergブロックコメントのパターン（core/プレフィックスは省略される）
        // <!-- wp:image {JSON} --> または <!-- wp:core/image {JSON} -->
        if (preg_match_all('/<!-- wp:(?:core\/)?(image|gallery|cover)\s+(\{[^}]*\}) -->/i', $content, $matches)) {
            foreach ($matches[2] as $json_str) {
                $parsed = json_decode($json_str, true);
                if (is_array($parsed)) {
                    if (isset($parsed['ggcAltText']) && !empty($parsed['ggcAltText'])) {
                        $id = isset($parsed['id']) ? intval($parsed['id']) : null;
                        if ($id > 0) {
                            $attributes[$id] = sanitize_text_field($parsed['ggcAltText']);
                        }
                    }
                }
            }
        }
        
        return $attributes;
    }
}
