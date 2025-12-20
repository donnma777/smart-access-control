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
            $group_label = $bot['group_label'] ?? 'その他';
            if (!isset($grouped_bots[$group_label])) {
                $grouped_bots[$group_label] = [];
            }
            $grouped_bots[$group_label][$key] = $bot;
        }

        // Group patterns
        $grouped_browser_patterns = [];
        foreach ($all_browser_patterns as $key => $pattern_def) {
            if (!is_array($pattern_def)) continue;
            $group_label = $pattern_def['group_label'] ?? 'その他';
            if (!isset($grouped_browser_patterns[$group_label])) {
                $grouped_browser_patterns[$group_label] = [];
            }
            $grouped_browser_patterns[$group_label][$key] = $pattern_def;
        }

        // Group IP Ranges 1
        $grouped_ip_ranges_1 = [];
        foreach ($ip_ranges_1 as $key => $ip_def) {
            $group_label = $ip_def['group_label'] ?? 'その他';
            if (!isset($grouped_ip_ranges_1[$group_label])) {
                $grouped_ip_ranges_1[$group_label] = [];
            }
            $grouped_ip_ranges_1[$group_label][$key] = $ip_def;
        }

        // Group IP Ranges 2
        $grouped_ip_ranges_2 = [];
        foreach ($ip_ranges_2 as $key => $ip_def) {
            $group_label = $ip_def['group_label'] ?? 'その他';
            if (!isset($grouped_ip_ranges_2[$group_label])) {
                $grouped_ip_ranges_2[$group_label] = [];
            }
            $grouped_ip_ranges_2[$group_label][$key] = $ip_def;
        }

        $modes = [
            'global'     => 'グローバル設定に従う',
            'blacklist'  => 'ブラックリスト (選択したものを拒否)',
            'whitelist'  => 'ホワイトリスト (選択したものを許可)',
            'allow_all'  => '全許可 (制限なし)',
            'deny_all'   => '全拒否 (すべてブロック)',
        ];

        ?>
        <div id="ggc-control-panel">

            <!-- User-Agent Evaluation Section -->
            <div class="ggc-section" style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
                <h4 style="margin: 0 0 10px;">User-Agent の評価</h4>
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
                            <?php echo ($ua_control_mode === 'whitelist') ? 'チェックしたUser-Agentを許可します (ホワイトリスト)。' : 'チェックしたUser-Agentを拒否します (ブラックリスト)。'; ?>
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
                            User-Agent 定義2 (不正UAパターン)
                            <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-section" data-section="ggc-ua-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-ua-def-2-container" class="ggc-section-content" style="display: none;">
                            <?php $group_counter = 0; foreach ($grouped_browser_patterns as $group_label => $patterns_in_group) : $group_counter++; $group_id = 'ggc-pattern-group-' . $group_counter; ?>
                                <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 10px; margin-bottom: 5px;">
                                    <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
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
                <h4 style="margin: 0 0 10px;">IPアドレスの評価</h4>
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
                            <?php echo ($ip_control_mode === 'blacklist') ? 'チェックしたIP範囲を拒否します (ブラックリスト)。' : 'チェックしたIP範囲を許可します (ホワイトリスト)。'; ?>
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
                                                <?php if ($ip_def['is_auto'] ?? false): ?>
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
                                                <?php if ($ip_def['is_auto'] ?? false): ?>
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

        // Save Modes
        $ua_mode = sanitize_text_field($_POST['ggc_ua_control_mode'] ?? 'global');
        update_post_meta($post_id, '_ggc_ua_control_mode', $ua_mode);

        $ip_mode = sanitize_text_field($_POST['ggc_ip_control_mode'] ?? 'global');
        update_post_meta($post_id, '_ggc_ip_control_mode', $ip_mode);

        // Save Lists
        $selected_crawlers = array_map('sanitize_key', $_POST['ggc_selected_crawlers_field'] ?? []);
        update_post_meta($post_id, '_ggc_selected_crawlers', $selected_crawlers);

        $selected_ips = array_map('sanitize_key', $_POST['ggc_selected_ips_field'] ?? []);
        update_post_meta($post_id, '_ggc_selected_ips', $selected_ips);

        $selected_ips_2 = array_map('sanitize_key', $_POST['ggc_selected_ips_2_field'] ?? []);
        update_post_meta($post_id, '_ggc_selected_ips_2', $selected_ips_2);

        $selected_page_browser_patterns = array_map('sanitize_key', $_POST['ggc_selected_page_browser_patterns_field'] ?? []);
        update_post_meta($post_id, '_ggc_selected_page_browser_patterns', $selected_page_browser_patterns);

        // Remove old meta if exists to avoid confusion
        delete_post_meta($post_id, '_ggc_control_active');
        delete_post_meta($post_id, '_ggc_control_mode');
    }
}
