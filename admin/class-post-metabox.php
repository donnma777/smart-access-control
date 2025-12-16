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

        // 1. マスター ON/OFF
        $post_control_active = get_post_meta($post->ID, '_ggc_control_active', true);
        $default_active = Custom_Crawler_Core::get_default_control_active();

        // post meta がない場合は、グローバルデフォルトを使用
        if ($post_control_active === '') {
            $controls_enabled = ($default_active === 'yes');
        } else {
            $controls_enabled = ($post_control_active === 'yes');
        }
        
        // 2. 制御モード
        $control_mode = get_post_meta($post->ID, '_ggc_control_mode', true) ?: 'blacklist'; 
        
        // 3. 選択中の UA リスト
        $selected_crawlers = get_post_meta($post->ID, '_ggc_selected_crawlers', true);
        if (!is_array($selected_crawlers)) $selected_crawlers = [];

        // 4. 選択中の IP リスト
        $selected_ips = get_post_meta($post->ID, '_ggc_selected_ips', true);
        if (!is_array($selected_ips)) $selected_ips = [];

        // 5. 選択中の 不正UAパターン
        $selected_page_browser_patterns = get_post_meta($post->ID, '_ggc_selected_page_browser_patterns', true);
        if (!is_array($selected_page_browser_patterns)) $selected_page_browser_patterns = [];

        // 全ての定義データ
        $all_bots = Custom_Crawler_Core::get_allowable_bots();
        $all_ip_ranges = Custom_Crawler_Core::get_allowable_ip_ranges();
        $all_browser_patterns = Custom_Crawler_Core::get_browser_block_patterns();
        $grouped_bots = [];

        // グループ化 (User-Agent)
        foreach ($all_bots as $key => $bot) {
            $group_label = $bot['group_label'] ?? 'その他';
            if (!isset($grouped_bots[$group_label])) {
                $grouped_bots[$group_label] = [];
            }
            $grouped_bots[$group_label][$key] = $bot;
        }

        // 不正UAパターンのグループ化
        $grouped_browser_patterns = [];
        foreach ($all_browser_patterns as $key => $pattern_def) { // $pattern_def が正しく定義されているか
            if (!is_array($pattern_def)) {
                continue; // 配列ではない不正なデータはスキップ
            }
            $group_label = $pattern_def['group_label'] ?? 'その他'; // ここで 'group_label' を参照しているか
            if (!isset($grouped_browser_patterns[$group_label])) {
                $grouped_browser_patterns[$group_label] = [];
            }
            $grouped_browser_patterns[$group_label][$key] = $pattern_def;
        }

        // パネルの初期スタイル（JSで制御される）
        $panel_style = $controls_enabled ? 'opacity: 1; pointer-events: auto;' : 'opacity: 0.45; pointer-events: none;';
        ?>

        <div id="ggc-control-panel">
            <p>
                <label for="ggc_control_active_field">
                    <input type="checkbox" name="ggc_control_active_field" id="ggc_control_active_field" value="yes" <?php checked($controls_enabled, true); ?>>
                    <strong>このページでアクセス制御を有効にする</strong>
                </label>
            </p>
            <p style="font-size: 11px; margin-top: -5px; color:#666;">
                チェックを外すと、グローバル設定に関わらずアクセス制御を許可します。
                <?php if ($post_control_active === ''): ?>
                    <span style="color:<?php echo $default_active === 'yes' ? 'red' : 'green'; ?>;">(初期状態: グローバルデフォルト設定 <?php echo $default_active === 'yes' ? 'ON' : 'OFF'; ?>)</span>
                <?php else: ?>
                     <span style="color:<?php echo $post_control_active === 'yes' ? 'red' : 'green'; ?>;">(現在: <?php echo $post_control_active === 'yes' ? 'ON' : 'OFF'; ?> に上書き)</span>
                <?php endif; ?>
            </p>

            <div id="ggc-mode-selector-panel" class="ggc-panel" style="padding:10px; margin-bottom: 10px; <?php echo esc_attr($panel_style); ?>">
                <p style="font-size: 11px; margin-bottom: 5px; font-weight: bold;"> 制御モード: </p>
                <label for="ggc-mode-blacklist" style="display: block; margin-bottom: 5px;">
                    <input type="radio" name="ggc_control_mode_field" value="blacklist" id="ggc-mode-blacklist" <?php checked($control_mode, 'blacklist', true); ?>>
                    <strong style="color:#0073aa;">個別拒否 (ブラックリスト)</strong>
                </label>
                <label for="ggc-mode-whitelist" style="display: block;">
                    <input type="radio" name="ggc_control_mode_field" value="whitelist" id="ggc-mode-whitelist" <?php checked($control_mode, 'whitelist', true); ?>>
                    <strong style="color:red;">ALL拒否 (ホワイトリスト)</strong>
                </label>
            </div>

            <p style="font-size: 11px; margin-bottom: 5px; border-top: 1px dashed #ccc; padding-top: 10px; font-weight: bold;"> User-Agent 制御リスト: </p>
            <div id="ggc-crawler-list-panel" class="ggc-panel" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding:10px; <?php echo esc_attr($panel_style); ?>">
                <p class="description" id="ggc-ua-control-description" style="color:<?php echo $control_mode === 'blacklist' ? '#0073aa' : 'red'; ?>; margin-top: 5px;">
                <?php echo $control_mode === 'blacklist'
                    ? '【個別拒否（ブラックリスト）】: チェックしたUser-Agentからのアクセスを拒否します。それ以外のUser-Agentからのアクセスは許可されます。'
                    : '【ALL拒否（ホワイトリスト）】: チェックしたUser-Agentからのアクセスのみを許可します。それ以外のUser-Agentからのアクセスは拒否されます。';
                ?>
                </p>
                <?php $group_counter = 0; foreach ($grouped_bots as $group_label => $bots_in_group) : $group_counter++; $group_id = 'ggc-group-' . $group_counter; ?>
                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 15px; margin-bottom: 10px;">
                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow" style="font-size: 16px; margin-right: 5px;"></span> 
                        <?php echo esc_html($group_label); ?>
                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                    </h4>
                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content" style="padding-bottom: 0px;">
                        <?php foreach ($bots_in_group as $key => $bot) : ?>
                            <label class="ggc-crawler-item" style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="ggc_selected_crawlers_field[]" value="<?php echo esc_attr($key); ?>" class="ggc-selected-crawler-checkbox <?php echo esc_attr($group_id); ?>" <?php checked(in_array($key, $selected_crawlers), true); ?>>
                                <strong><?php echo esc_html($bot['label']); ?></strong> <small><?php echo esc_html($bot['description']); ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="font-size: 11px; margin-bottom: 5px; border-top: 1px dashed #ccc; padding-top: 10px; font-weight: bold;"> IPアドレス制御リスト (User-Agent偽装対策): </p>
            <div id="ggc_ip_ranges_panel" class="ggc-panel" style="max-height: 300px; overflow-y: auto; padding:10px; <?php echo esc_attr($panel_style); ?>">
                <p id="ggc-ip-control-description" class="description" style="color:<?php echo $control_mode === 'blacklist' ? '#0073aa' : 'red'; ?>;">
                <?php
                echo
                $message = ($control_mode === 'blacklist')
                    ? '【個別拒否 (ブラックリスト)】: チェックしたクローラーをUser-Agentでブロックしない場合でも、IPが範囲外であればブロックします。'
                    : '【ALL拒否 (ホワイトリスト)】: チェックしたクローラーをUser-Agentで許可する場合、IPが範囲内でなければブロックします。';
                    echo $message;
                ?>

                </p>
                
                <?php if (!empty($all_ip_ranges)): ?>
                    <?php foreach ($all_ip_ranges as $key => $ip_def) : ?>
                        <label class="ggc-ip-range-item" style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="ggc_selected_ips_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected_ips), true); ?> class="ggc-selected-ip-checkbox">
                            <strong><?php echo esc_html($ip_def['label']); ?></strong> 
                            <small><?php echo esc_html($ip_def['description']); ?></small>
                            <?php if ($ip_def['is_auto'] ?? false): ?>
                                <small style="color: green;">(自動更新)</small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="description">IPアドレス範囲の定義がありません。設定ページで定義してください。</p>
                <?php endif; ?>
            </div>

            <p style="font-size: 11px; margin-bottom: 5px; border-top: 1px dashed #ccc; padding-top: 10px; font-weight: bold;"> ページ固有の不正UAパターン適用: </p>
             <div id="ggc_page_browser_patterns_panel" class="ggc-panel" style="max-height: 300px; overflow-y: auto; padding:10px; <?php echo esc_attr($panel_style); ?>">
                <p class="description" id="ggc-page-browser-patterns-description" style="color:<?php echo $control_mode === 'blacklist' ? '#0073aa' : 'red'; ?>;">
                <?php echo $control_mode === 'blacklist' 
                    ? '【個別拒否 (ブラックリスト)】: チェックした不正UAパターンに合致するアクセスをブロックします。<br>'
                    : '【ALL拒否 (ホワイトリスト)】: チェックした不正UAパターンに合致するアクセスも含め、制御ルールに基づいてアクセスを判定します。<br>';
                ?>
                グローバルで常に適用される不正UAパターンに加え、このページでのみ追加で適用するパターンを選択できます。
                これは、悪意のあるボットや古いブラウザをブロックする目的であり、クローラー制御のモードとは独立して機能します。
                </p>
                
                <?php $group_counter = 0; foreach ($grouped_browser_patterns as $group_label => $patterns_in_group) : $group_counter++; $group_id = 'ggc-pattern-group-' . $group_counter; ?>
                    <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>" style="cursor: pointer; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 15px; margin-bottom: 10px;">
                        <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow" style="font-size: 16px; margin-right: 5px;"></span> 
                        <?php echo esc_html($group_label); ?>
                        <small style="float: right; cursor: pointer; color: #0073aa;" class="ggc-toggle-all-pattern" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                    </h4>
                    <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content" style="padding-bottom: 0px;">
                        <?php foreach ($patterns_in_group as $key => $pattern_def) : ?>
                            <label class="ggc-page-pattern-item" style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="ggc_selected_page_browser_patterns_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected_page_browser_patterns), true); ?> class="ggc-page-pattern <?php echo esc_attr($group_id); ?>">
                                <strong><?php echo esc_html($pattern_def['label']); ?></strong> 
                                <small><?php echo esc_html($pattern_def['description']); ?> (パターン: <code style="font-size: 10px;"><?php echo esc_html($pattern_def['pattern']); ?></code>)</small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>


            <p style="font-size:11px; margin-top:10px; color:#666;" id="ggc-current-mode-status">
                現在のモード: 
                <?php if ($controls_enabled): ?>
                    <strong style="color:<?php echo $control_mode === 'blacklist' 
                    ? '#0073aa' 
                    : 'red'; ?>;">
                        <?php echo $control_mode === 'blacklist' 
                        ? '個別拒否 (ブラックリスト)' 
                        : 'ALL拒否 (ホワイトリスト)';
                        ?>
                    </strong>
                <?php else: ?>
                    <strong style="color:green;">ALL許可 (制御無効)</strong>
                <?php endif; ?>
            </p>
            <p class="description" style="font-size:11px; margin-top: 5px;">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=ggc-crawler-definitions')); ?>" target="_blank">アクセス制御設定画面</a>
            </p>
        </div>
        <?php
    }

    /**
     * メタボックスのデータを保存
     */
    public function save_crawler_meta_box($post_id) {

        // 1. ノンス（Nonce）チェック：不正なリクエストでないか検証
        if (!isset($_POST['ggc_crawler_control_nonce']) || !wp_verify_nonce(sanitize_key($_POST['ggc_crawler_control_nonce']), 'ggc_crawler_control_save')) {
            return $post_id;
        }

        // 2. 権限チェック：設定を保存する権限があるか検証
        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        // 3. 自動保存チェック：リビジョン作成時など、自動保存でないか検証
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // 1. マスターON/OFFスイッチの保存
        $control_active = isset($_POST['ggc_control_active_field']) ? 'yes' : 'no'; 
        $default_active = Custom_Crawler_Core::get_default_control_active();

        // 値がグローバルデフォルトと同じ場合は、post metaを削除してグローバル設定を優先させる
        if ($control_active === $default_active) {
            delete_post_meta($post_id, '_ggc_control_active');
        } else {
            update_post_meta($post_id, '_ggc_control_active', $control_active);
        }

        // 制御がONの場合のみ、詳細設定を保存
        if ($control_active === 'yes') {
            // 2. 制御モード
            $control_mode = sanitize_text_field($_POST['ggc_control_mode_field'] ?? 'blacklist');
            update_post_meta($post_id, '_ggc_control_mode', $control_mode);

            // 3. 選択された UA リスト
            $selected_crawlers = array_map('sanitize_key', $_POST['ggc_selected_crawlers_field'] ?? []);
            update_post_meta($post_id, '_ggc_selected_crawlers', $selected_crawlers);

            // 4. 選択された IP リスト
            $selected_ips = array_map('sanitize_key', $_POST['ggc_selected_ips_field'] ?? []);
            update_post_meta($post_id, '_ggc_selected_ips', $selected_ips);
            
            // 5. 選択された 不正UAパターン
            $selected_page_browser_patterns = array_map('sanitize_key', $_POST['ggc_selected_page_browser_patterns_field'] ?? []);
            update_post_meta($post_id, '_ggc_selected_page_browser_patterns', $selected_page_browser_patterns);
            
        } else {
            // マスターOFFの時は個別設定をクリア
            delete_post_meta($post_id, '_ggc_control_mode');
            delete_post_meta($post_id, '_ggc_selected_crawlers');
            delete_post_meta($post_id, '_ggc_selected_ips');
            delete_post_meta($post_id, '_ggc_selected_page_browser_patterns');
        }
    }
}