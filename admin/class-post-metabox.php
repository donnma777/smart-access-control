<?php
// <!-- custom-crawler-control\admin\class-post-metabox.php -->
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// common admin helpers
require_once __DIR__ . '/class-admin-utils.php';
// shared meta helpers
require_once __DIR__ . '/../includes/class-meta-utils.php';
// Ajax helpers
if (! class_exists('Ajax_Utils')) {
    require_once dirname(__DIR__) . '/includes/class-ajax-utils.php';
}

class Custom_Post_Metabox {


    // 投稿タイプや画面フック名の定数化
    private const POST_TYPES = ['post', 'page'];
    private const HOOKS = ['post.php', 'post-new.php'];

    // メタキーの定数化
    private const META_KEYS = [
        'ua_control_mode' => '_ggc_ua_control_mode',
        'ip_control_mode' => '_ggc_ip_control_mode',
        'ua_redirect_mode' => '_ggc_ua_redirect_mode',
        'ip_redirect_mode' => '_ggc_ip_redirect_mode',
        'ua_redirect_url' => '_ggc_ua_redirect_url',
        'ip_redirect_url' => '_ggc_ip_redirect_url',
        'ua_block_message_key' => '_ggc_ua_block_message_key',
        'ip_block_message_key' => '_ggc_ip_block_message_key',
        'ua_block_status_code' => '_ggc_ua_block_status_code',
        'ip_block_status_code' => '_ggc_ip_block_status_code',
        'ua_block_message_custom' => '_ggc_ua_block_message_custom',
        'ip_block_message_custom' => '_ggc_ip_block_message_custom',
        'selected_crawlers' => '_ggc_selected_crawlers',
        'selected_ips' => '_ggc_selected_ips',
        'selected_ips_2' => '_ggc_selected_ips_2',
        'selected_page_browser_patterns' => '_ggc_selected_page_browser_patterns',
        'md_replace_text' => '_ggc_md_replace_text',
        'md_replace_title' => '_ggc_md_replace_title',
        'md_replace_image_id' => '_ggc_md_replace_image_id',
        'md_replace_image_url' => '_ggc_md_replace_image_url',
        'md_replace_mode' => '_ggc_md_replace_mode',
        'md_template_key' => '_ggc_md_template_key',
        'md_template_mode' => '_ggc_md_template_mode',
        'md_ua_mode' => '_ggc_md_ua_mode',
        'md_ip_mode' => '_ggc_md_ip_mode',
        'md_selected_crawlers' => '_ggc_md_replace_crawlers',
        'md_selected_patterns' => '_ggc_md_replace_browser_patterns',
        'md_selected_ips' => '_ggc_md_replace_ips',
        'md_selected_ips_2' => '_ggc_md_replace_ips_2',
        'block_attrs' => '_ggc_block_attrs',
        'block_modes' => '_ggc_block_modes',
        'media_alt_replace' => '_ggc_media_alt_replace',
        'media_hide' => '_ggc_media_hide',
        'media_individual' => '_ggc_media_individual',
        'media_hide_all' => '_ggc_media_hide_all',
        'featured_visible_on_hide' => '_ggc_featured_visible_on_hide',
        'featured_hide_on_hide_all' => '_ggc_featured_hide_on_hide_all',
    ];

    // モードやリダイレクト種別の定数化（重複排除）
    public const MODES = [
        'global'     => '設定しない',
        'blacklist'  => 'ブラックリスト',
        'whitelist'  => 'ホワイトリスト',
        'allow_all'  => '全許可',
        'deny_all'   => '全拒否',
    ];
    public const REDIRECT_MODES = [
        'block'    => 'アクセスをブロックする',
        'redirect' => 'リダイレクトする',
    ];

    // デフォルト値の定数化
    private const DEFAULTS = [
        'ua_control_mode' => 'global',
        'ip_control_mode' => 'global',
        'ua_redirect_mode' => 'global',
        'ip_redirect_mode' => 'global',
        'md_replace_mode' => 'none',
        'md_template_mode' => 'select',
        'md_ua_mode' => 'global',
        'md_ip_mode' => 'global',
    ];


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

        // AJAXでマークダウンプレビューを取得
        add_action('wp_ajax_ggc_markdown_preview', [ $this, 'ajax_markdown_preview' ]);
    }

    /**
     * AJAXでブロック属性を保存
     */
    public function ajax_save_block_attrs() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_save_block_attrs', 'nonce', 'edit_post', [ isset($_POST['post_id']) ? intval($_POST['post_id']) : 0 ]);

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        $attrs_raw = isset($_POST['attrs']) ? wp_unslash($_POST['attrs']) : '';
        $attrs = json_decode($attrs_raw, true);

        $modes_raw = isset($_POST['modes']) ? wp_unslash($_POST['modes']) : '';
        $modes = json_decode($modes_raw, true);

        if (!is_array($attrs)) {
            Ajax_Utils::error([ 'message' => 'Invalid attrs payload' ], 400);
        }
        if (!is_array($modes)) {
            $modes = [];
        }

        // 空配列の場合はメタを削除、そうでなければ更新
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_block_attrs', $attrs, null, true);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_block_modes', $modes, null, true);

        Ajax_Utils::success([ 'saved' => true ]);
    }

    /**
     * AJAXでブロック属性を取得
     */
    public function ajax_get_block_attrs() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_save_block_attrs', 'nonce', 'edit_post', [ isset($_POST['post_id']) ? intval($_POST['post_id']) : 0 ]);

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        $post_obj = get_post($post_id);
        $attrs = $this->get_meta_array($post_obj, '_ggc_block_attrs');
        $modes = $this->get_meta_array($post_obj, '_ggc_block_modes');

        Ajax_Utils::success([ 'attrs' => $attrs, 'modes' => $modes ]);
    }

    /**
     * AJAXでマークダウンプレビューを取得
     */
    public function ajax_markdown_preview() {
        Admin_Utils::ajax_require_nonce_and_cap('ggc_markdown_preview', 'nonce', 'edit_posts');

        $markdown = isset($_POST['markdown']) ? wp_unslash($_POST['markdown']) : '';
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;

        $html = '';
        if (!empty($title)) {
            $html .= '<h1>' . esc_html($title) . '</h1>';
        }

        if ($image_id > 0) {
            $img = wp_get_attachment_image($image_id, 'large', false, ['style' => 'max-width:100%;height:auto;']);
            if (!empty($img)) {
                $html .= $img;
            }
        }

        $html .= Custom_Crawler_Core::render_markdown_to_html($markdown);

        Ajax_Utils::success([ 'html' => $html, 'title' => $title ]);
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
                return Admin_Utils::current_user_can_edit_posts();
            },
        ];
        
        register_post_meta('post', '_ggc_block_attrs', $post_meta_args);
        register_post_meta('page', '_ggc_block_attrs', $post_meta_args);

        $post_mode_meta_args = [
            'type' => 'object',
            'description' => 'GGC Block Modes (ggcMediaMode)',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return Admin_Utils::current_user_can_edit_posts();
            },
        ];
        register_post_meta('post', '_ggc_block_modes', $post_mode_meta_args);
        register_post_meta('page', '_ggc_block_modes', $post_mode_meta_args);
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
            'alt_mode' => GGC_Options::get_alt_mode(),
            'alt_fixed' => GGC_Options::get_alt_fixed_text(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ggc_save_block_attrs'),
            'markdown_preview_nonce' => wp_create_nonce('ggc_markdown_preview'),
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
            // Media uploader
            wp_enqueue_media();

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
                'alt_mode' => GGC_Options::get_alt_mode(),
                'alt_fixed' => GGC_Options::get_alt_fixed_text(),
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ggc_save_block_attrs'),
                'markdown_preview_nonce' => wp_create_nonce('ggc_markdown_preview'),
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

        // メタデータ取得（共通化）
        $ua_control_mode = $this->get_meta_value($post, self::META_KEYS['ua_control_mode'], self::DEFAULTS['ua_control_mode']);
        $ip_control_mode = $this->get_meta_value($post, self::META_KEYS['ip_control_mode'], self::DEFAULTS['ip_control_mode']);
        $ua_redirect_mode = $this->get_meta_value($post, self::META_KEYS['ua_redirect_mode'], self::DEFAULTS['ua_redirect_mode']);
        $ip_redirect_mode = $this->get_meta_value($post, self::META_KEYS['ip_redirect_mode'], self::DEFAULTS['ip_redirect_mode']);
        $ua_redirect_url = $this->get_meta_string($post, self::META_KEYS['ua_redirect_url']);
        $ip_redirect_url = $this->get_meta_string($post, self::META_KEYS['ip_redirect_url']);
        $ua_block_message_key = $this->get_meta_string($post, self::META_KEYS['ua_block_message_key']);
        $ip_block_message_key = $this->get_meta_string($post, self::META_KEYS['ip_block_message_key']);
        $ua_block_status_code = $this->get_meta_value($post, self::META_KEYS['ua_block_status_code']);
        $ip_block_status_code = $this->get_meta_value($post, self::META_KEYS['ip_block_status_code']);
        $ua_block_message_custom = $this->get_meta_string($post, self::META_KEYS['ua_block_message_custom']);
        $ip_block_message_custom = $this->get_meta_string($post, self::META_KEYS['ip_block_message_custom']);

        // Legacy mapping
        if ($ua_control_mode === 'individual') $ua_control_mode = 'blacklist';
        if ($ip_control_mode === 'individual') $ip_control_mode = 'blacklist';

        // リスト系
        $selected_crawlers = $this->get_meta_array($post, self::META_KEYS['selected_crawlers']);
        $selected_ips = $this->get_meta_array($post, self::META_KEYS['selected_ips']);
        $selected_ips_2 = $this->get_meta_array($post, self::META_KEYS['selected_ips_2']);
        $selected_page_browser_patterns = $this->get_meta_array($post, self::META_KEYS['selected_page_browser_patterns']);

        // Markdown replacement settings
        $md_replace_text = $this->get_meta_string($post, self::META_KEYS['md_replace_text']);
        $md_replace_title = $this->get_meta_string($post, self::META_KEYS['md_replace_title']);
        $md_replace_image_id = intval($this->get_meta_value($post, self::META_KEYS['md_replace_image_id']));
        $md_replace_image_url_custom = $this->get_meta_string($post, self::META_KEYS['md_replace_image_url']);
        $md_replace_mode = $this->get_meta_value($post, self::META_KEYS['md_replace_mode'], self::DEFAULTS['md_replace_mode']);
        $md_template_key = $this->get_meta_string($post, self::META_KEYS['md_template_key']);
        $md_template_mode = $this->get_meta_value($post, self::META_KEYS['md_template_mode'], self::DEFAULTS['md_template_mode']);
        $markdown_templates = GGC_Options::get_markdown_templates();
        $md_replace_image_url = '';
        if ($md_replace_image_id) {
            $img_src = wp_get_attachment_image_src($md_replace_image_id, 'thumbnail');
            if ($img_src && !empty($img_src[0])) {
                $md_replace_image_url = $img_src[0];
            }
        }
        if (!empty($md_replace_image_url_custom)) {
            $md_replace_image_url = $md_replace_image_url_custom;
        }
        $md_ua_mode = $this->get_meta_value($post, self::META_KEYS['md_ua_mode'], self::DEFAULTS['md_ua_mode']);
        $md_ip_mode = $this->get_meta_value($post, self::META_KEYS['md_ip_mode'], self::DEFAULTS['md_ip_mode']);
        $md_selected_crawlers = $this->get_meta_array($post, self::META_KEYS['md_selected_crawlers']);
        $md_selected_patterns = $this->get_meta_array($post, self::META_KEYS['md_selected_patterns']);
        $md_selected_ips = $this->get_meta_array($post, self::META_KEYS['md_selected_ips']);
        $md_selected_ips_2 = $this->get_meta_array($post, self::META_KEYS['md_selected_ips_2']);

        // Check if all data was cleared
        $cleared = GGC_Options::is_clear_all_done();

        // Retrieve definitions - use centralized helpers where available
        $all_bots = Custom_Crawler_Core::get_allowable_bots();

        // Split IP ranges for display
        $ip_ranges_1 = GGC_Options::get_ip_range_definitions_1();
        if ($ip_ranges_1 === null) {
            $ip_ranges_1 = $cleared ? [] : ggc_get_default_ip_ranges();
        } elseif (!is_array($ip_ranges_1)) {
            $ip_ranges_1 = [];
        }

        $ip_ranges_2 = GGC_Options::get_ip_range_definitions_2();
        if ($ip_ranges_2 === null) {
            $ip_ranges_2 = $cleared ? [] : ggc_get_default_ip_ranges_2();
        } elseif (!is_array($ip_ranges_2)) {
            $ip_ranges_2 = [];
        }

        // Retrieve browser patterns via core helper
        $all_browser_patterns = Custom_Crawler_Core::get_browser_block_patterns();

        $page_eval_messages = GGC_Options::get_page_eval_messages();

        // Group definitions
        $grouped_bots = $this->group_definitions_by_label($all_bots);
        $grouped_browser_patterns = $this->group_definitions_by_label($all_browser_patterns, 'group_label', 'その他', true);
        $grouped_ip_ranges_1 = $this->group_definitions_by_label($ip_ranges_1, 'group_label', 'その他', true);
        $grouped_ip_ranges_2 = $this->group_definitions_by_label($ip_ranges_2, 'group_label', 'その他', true);

        // ...existing code...
        $modes = self::MODES;
        $redirect_modes = self::REDIRECT_MODES;

        ?>
        <div id="ggc-control-panel">
            <p class="ggc-intro">User-Agent、IPアドレスの評価を行ってコンテンツの表示制御を行います。※グローバル設定でブラックリスト、ホワイトリストを設定した場合はグローバル設定が優先されます。アクセス制御設定画面から詳細設定が可能です。</p>
            <p class="description ggc-link-note">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=ggc-crawler-definitions')); ?>" target="_blank">アクセス制御設定画面</a>
            </p>

            <!-- Markdown Replacement Section -->
            <details class="ggc-collapsible">
                <summary class="ggc-collapsible-summary">マークダウン評価</summary>
            <div class="ggc-section">
                <h4 class="ggc-heading">マークダウン置換</h4>
                <p class="ggc-description-small ggc-mt-0">指定したUser-AgentまたはIPアドレスに一致した場合、本文をマークダウン内容に置き換えます。</p>

                <!-- hidden field so JS can know the current global markdown mode -->
                <input type="hidden" id="ggc_global_md_mode_hidden" value="<?php echo esc_attr(GGC_Options::get_markdown_options()['replace_enabled']); ?>" />

                <p id="ggc-md-global-note" class="description ggc-description-tight ggc-mb-8" style="display: none;">
                    投稿レベルで「設定しない」が選択されています。設定はグローバル制御に従い、個別の調整は無効になります。
                </p>

                <label for="ggc_md_replace_mode" class="ggc-label">置換方法：</label>
                <select name="ggc_md_replace_mode" id="ggc_md_replace_mode" class="ggc-input-full ggc-mb-8">
                    <option value="none" <?php selected($md_replace_mode, 'none'); ?>>設定しない</option>
                    <option value="manual" <?php selected($md_replace_mode, 'manual'); ?>>マークダウンに置換、専用表示</option>
                    <option value="manual_raw" <?php selected($md_replace_mode, 'manual_raw'); ?>>マークダウンのまま表示</option>
                    <option value="template" <?php selected($md_replace_mode, 'template'); ?>>テンプレートを置換、専用表示</option>
                    <option value="template_raw" <?php selected($md_replace_mode, 'template_raw'); ?>>テンプレートをマークダウンのまま表示置換</option>
                    <option value="template_random" <?php selected($md_replace_mode, 'template_random'); ?>>ランダムにテンプレートを置換、専用表示</option>
                    <option value="template_random_raw" <?php selected($md_replace_mode, 'template_random_raw'); ?>>ランダムにマークダウンのまま表示置換</option>
                </select>

                <div id="ggc-md-template-select-wrapper" class="ggc-mb-8" style="display: <?php echo (in_array($md_replace_mode, ['template', 'template_raw'])) ? 'block' : 'none'; ?>;">
                    <label for="ggc_md_template_key" class="ggc-label">テンプレート選択：</label>
                    <select name="ggc_md_template_key" id="ggc_md_template_key" class="ggc-input-full">
                        <option value="">テンプレートを選択...</option>
                        <?php foreach ($markdown_templates as $key => $tpl) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($md_template_key, $key); ?>><?php echo esc_html(($tpl['title'] ?? $key) . ' (' . $key . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="ggc-md-content-wrapper" style="display: <?php echo (in_array($md_replace_mode, ['manual', 'manual_raw'])) ? 'block' : 'none'; ?>;">
                    <p class="description ggc-description-top">本文を入力した場合に置換が行われます。未入力の場合は記事の原文のまま表示されます。</p>
                    <label for="ggc_md_replace_title" class="ggc-label ggc-mt-8 ggc-mb-8">マークダウンのページタイトル：</label>
                    <input type="text" name="ggc_md_replace_title" id="ggc_md_replace_title" value="<?php echo esc_attr($md_replace_title); ?>" class="ggc-input-full" placeholder="例: 限定コンテンツ" />
                    <p class="description ggc-description-top">本文を入力した場合にマークダウン置換が実行され、タイトルを入力している場合のみこのタイトルで上書きされます。未入力の場合は投稿ページのタイトルを表示します。</p>

                    <?php $show_image_ui = in_array($md_replace_mode, ['manual', 'template', 'template_random']); ?>
                    <div id="ggc-md-image-ui-block" style="margin-bottom:16px;<?php echo $show_image_ui ? '' : ' display:none;'; ?>">
                        <label for="ggc_md_replace_image_id" class="ggc-label ggc-mt-8 ggc-mb-8">マークダウン用のアイキャッチ画像：</label>
                        <label for="ggc_md_replace_image_url" class="ggc-label ggc-mt-8 ggc-mb-8">画像URLを直接指定：</label>
                        <input type="text" name="ggc_md_replace_image_url" id="ggc_md_replace_image_url_input" value="<?php echo esc_attr($md_replace_image_url_custom); ?>" class="ggc-input-full ggc-mb-8" placeholder="https://example.com/image.jpg" />
                        <input type="hidden" name="ggc_md_replace_image_id" id="ggc_md_replace_image_id" value="<?php echo esc_attr($md_replace_image_id); ?>" />
                        <input type="hidden" id="ggc-md-image-url" value="<?php echo esc_url($md_replace_image_url); ?>" />
                        <div id="ggc-md-image-preview" class="ggc-mb-8">
                            <?php if (!empty($md_replace_image_url)) : ?>
                                <img src="<?php echo esc_url($md_replace_image_url); ?>" class="ggc-img-thumb" />
                            <?php else: ?>
                                <span class="ggc-muted">未設定</span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="ggc-md-image-select">画像を選択</button>
                        <button type="button" class="button ggc-ml-8" id="ggc-md-image-remove">画像を削除</button>
                        <p class="description ggc-description-top">画像を選択した場合、投稿画面で評価しているときはこの画像で上書きされます。未選択の場合は投稿ページのアイキャッチ画像を表示します。</p>
                    </div>

                </div>

                <div id="ggc-md-text-wrapper">
                        <label for="ggc_md_replace_text" class="ggc-label">置換するマークダウン本文：</label>
                        <textarea name="ggc_md_replace_text" id="ggc_md_replace_text" rows="6" class="ggc-textarea-full" placeholder="例: # 見出し\n本文テキスト\n- 箇条書き\n[リンク](https://example.com)"><?php echo esc_textarea($md_replace_text); ?></textarea>
                        <p class="description ggc-description-top">未入力の場合は投稿ページの本文を表示します。</p>
                </div>

                <div id="ggc-md-ua-wrapper" class="ggc-mt-16">
                    <label for="ggc_md_ua_mode" class="ggc-label">User-Agent の評価-マークダウン：</label>
                    <select name="ggc_md_ua_mode" id="ggc_md_ua_mode" class="ggc-input-full ggc-mb-8">
                        <option value="global" <?php selected($md_ua_mode, 'global'); ?>>設定しない</option>
                        <option value="blacklist" <?php selected($md_ua_mode, 'blacklist'); ?>>ブラックリスト</option>
                        <option value="whitelist" <?php selected($md_ua_mode, 'whitelist'); ?>>ホワイトリスト</option>
                        <option value="allow_all" <?php selected($md_ua_mode, 'allow_all'); ?>>全許可</option>
                        <option value="deny_all" <?php selected($md_ua_mode, 'deny_all'); ?>>全拒否</option>
                    </select>

                    <div id="ggc-md-ua-list-wrapper" style="display: <?php echo in_array($md_ua_mode, ['blacklist','whitelist']) ? 'block' : 'none'; ?>;">


                        <div class="ggc-panel ggc-panel--scroll">
                        <p id="ggc-md-ua-description" class="description ggc-description-tight">
                            <?php echo ($md_ua_mode === 'whitelist') ? 'ホワイトリスト : チェックしたUser-Agent以外をマークダウンに置換します。' : 'ブラックリスト : チェックしたUser-Agentをマークダウンに置換します。'; ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-md-ua-def-1-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義1
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-md-ua-def-1-container"> [全選択/解除] </small>
                        </h5>
                        
                        <div id="ggc-md-ua-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php if (!empty($grouped_bots)): ?>
                                <?php $this->render_grouped_bot_checklist($grouped_bots, $md_selected_crawlers, 'ggc-md-ua-group-', 'ggc_md_replace_crawlers_field[]'); ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>

                        <h5 class="ggc-section-header ggc-section-header--spaced" data-target="#ggc-md-ua-def-2-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義2
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-md-ua-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-md-ua-def-2-container" class="ggc-section-content" style="display:none;">
                            <?php if (!empty($grouped_browser_patterns)): ?>
                                <?php $this->render_grouped_pattern_checklist($grouped_browser_patterns, $md_selected_patterns, 'ggc-md-pattern-group-', 'ggc_md_replace_browser_patterns_field[]'); ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
                </div>

                <div id="ggc-md-ip-wrapper" class="ggc-mt-16">
                    <label for="ggc_md_ip_mode" class="ggc-label">IPアドレスの評価-マークダウン：</label>
                    <select name="ggc_md_ip_mode" id="ggc_md_ip_mode" class="ggc-input-full ggc-mb-8">
                        <option value="global" <?php selected($md_ip_mode, 'global'); ?>>設定しない</option>
                        <option value="blacklist" <?php selected($md_ip_mode, 'blacklist'); ?>>ブラックリスト</option>
                        <option value="whitelist" <?php selected($md_ip_mode, 'whitelist'); ?>>ホワイトリスト</option>
                        <option value="allow_all" <?php selected($md_ip_mode, 'allow_all'); ?>>全許可 </option>
                        <option value="deny_all" <?php selected($md_ip_mode, 'deny_all'); ?>>全拒否</option>
                    </select>

                    <div id="ggc-md-ip-list-wrapper" style="display: <?php echo in_array($md_ip_mode, ['blacklist','whitelist']) ? 'block' : 'none'; ?>;">

                        <div class="ggc-panel ggc-panel--scroll">
                        <p id="ggc-md-ip-description" class="description ggc-description-tight">
                            <?php echo ($md_ip_mode === 'whitelist') ? 'ホワイトリスト : チェックしたIP範囲以外をマークダウンに置換します。' : 'ブラックリスト : チェックしたIP範囲をマークダウンに置換します。'; ?>
                        </p>
                        <h5 class="ggc-section-header" data-target="#ggc-md-ip-def-1-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲1
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-md-ip-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-md-ip-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php if (!empty($grouped_ip_ranges_1)): ?>
                                <?php $this->render_grouped_ip_checklist($grouped_ip_ranges_1, $md_selected_ips, 'ggc-md-ip-group-1-', 'ggc_md_replace_ips_field[]'); ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>

                        <h5 class="ggc-section-header ggc-section-header--spaced" data-target="#ggc-md-ip-def-2-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲2
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-md-ip-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-md-ip-def-2-container" class="ggc-section-content" style="display:none;">
                            <?php if (!empty($grouped_ip_ranges_2)): ?>
                                <?php $this->render_grouped_ip_checklist($grouped_ip_ranges_2, $md_selected_ips_2, 'ggc-md-ip-group-2-', 'ggc_md_replace_ips_2_field[]'); ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
                </div>

                <div class="ggc-mt-16">
                    <?php
                    $md_preview_url = get_permalink($post);
                    if (empty($md_preview_url)) {
                        $md_preview_url = function_exists('get_preview_post_link') ? get_preview_post_link($post) : '';
                    }
                    if (!empty($md_preview_url)) {
                        $md_preview_url = add_query_arg('ggc_md_preview', '1', $md_preview_url);
                    }
                    $md_preview_nonce = wp_create_nonce('ggc_md_preview_' . $post->ID);
                    ?>
                    <button type="button" class="button" id="ggc-md-preview-btn"
                        data-preview-url="<?php echo esc_url($md_preview_url); ?>"
                        data-preview-nonce="<?php echo esc_attr($md_preview_nonce); ?>">マークダウンのプレビュー</button>
                    <div id="ggc-md-preview" class="ggc-preview-box"></div>
                </div>
            </div>

            </details>

            <!-- Media Control Section -->
            <details class="ggc-collapsible">
                <summary class="ggc-collapsible-summary">メディア評価</summary>
            <div class="ggc-section">
                <h4 class="ggc-heading">メディア制御</h4>

                <!-- hidden field so JS can know the current global setting and disable the box when needed -->
<!-- media eval hidden input removed per request -->

                <?php
                // 新アーキテクチャ: メディアモードとアイキャッチモードは独立
                $media_mode_raw = get_post_meta($post->ID, '_ggc_media_mode', true);
                $featured_mode_raw = get_post_meta($post->ID, '_ggc_featured_mode', true);

                // レガシーデータからの移行: 旧フラグが設定されている場合は新モードに変換
                if (empty($media_mode_raw) || !in_array($media_mode_raw, ['normal', 'individual', 'hide_all'], true)) {
                    $media_alt_replace_legacy = $this->get_meta_value($post, self::META_KEYS['media_alt_replace']);
                    $media_hide_legacy = $this->get_meta_value($post, self::META_KEYS['media_hide']);
                    $media_individual_legacy = $this->get_meta_value($post, self::META_KEYS['media_individual']);
                    $media_hide_all_legacy = $this->get_meta_value($post, self::META_KEYS['media_hide_all']);
                    if (!empty($media_hide_all_legacy) || !empty($media_hide_legacy)) {
                        $media_mode_raw = 'hide_all';
                    } elseif (!empty($media_individual_legacy) || !empty($media_alt_replace_legacy)) {
                        $media_mode_raw = 'individual';
                    } else {
                        $media_mode_raw = 'normal';
                    }
                }
                $media_mode = in_array($media_mode_raw, ['normal', 'individual', 'hide_all'], true) ? $media_mode_raw : 'normal';

                // アイキャッチモード: レガシーからの移行
                if (empty($featured_mode_raw) || !in_array($featured_mode_raw, ['normal', 'alt_replace', 'hide'], true)) {
                    $feat_vis_legacy = $this->get_meta_value($post, self::META_KEYS['featured_visible_on_hide']);
                    $feat_hideall_legacy = $this->get_meta_value($post, self::META_KEYS['featured_hide_on_hide_all']);
                    $legacy_feat = !empty($feat_hideall_legacy) ? $feat_hideall_legacy : $feat_vis_legacy;
                    if ($legacy_feat === 'hide' || $legacy_feat === '1') {
                        $featured_mode_raw = 'hide';
                    } elseif ($legacy_feat === 'replace') {
                        $featured_mode_raw = 'alt_replace';
                    } else {
                        $featured_mode_raw = 'normal';
                    }
                }
                $featured_mode = in_array($featured_mode_raw, ['normal', 'alt_replace', 'hide'], true) ? $featured_mode_raw : 'normal';

                // ヘルプテキスト
                switch ($media_mode) {
                    case 'hide_all':
                        $media_mode_help = '評価に従って本文内のメディアをすべて非表示にします。';
                        break;
                    case 'individual':
                        $media_mode_help = '評価に従って個別にメディアをテキスト置換・非表示にします。ブロックごとの設定が適用されます。';
                        break;
                    case 'normal':
                    default:
                        $media_mode_help = 'メディア制御は設定されていません。';
                        break;
                }
                ?>
                <div id="ggc-media-mode-wrapper">
                    <label for="ggc_media_mode" class="ggc-label">メディア表示モード：</label>
                    <select name="ggc_media_mode" id="ggc_media_mode" class="ggc-input-full ggc-mb-8">
                    <option value="normal" <?php selected($media_mode, 'normal'); ?>>設定しない</option>
                    <option value="individual" <?php selected($media_mode, 'individual'); ?>>評価に従って個別でテキスト置換・非表示</option>
                    <option value="hide_all" <?php selected($media_mode, 'hide_all'); ?>>評価に従ってすべて非表示</option>
                </select>
                </div>
                <p id="ggc-media-mode-help" class="description ggc-description-tight ggc-mb-8">
                        <?php echo esc_html($media_mode_help); ?>
                </p>

                <div id="ggc-featured-mode-wrapper" style="margin-bottom:8px;<?php echo ($media_mode === 'normal') ? ' display:none;' : ''; ?>">
                    <label for="ggc_featured_mode" class="ggc-label">アイキャッチ画像の非表示設定：</label>
                    <select name="ggc_featured_mode" id="ggc_featured_mode" class="ggc-input-full ggc-mb-8">
                        <option value="normal" <?php selected($featured_mode, 'normal'); ?>>表示する</option>
                        <option value="alt_replace" <?php selected($featured_mode, 'alt_replace'); ?>>テキストに置換</option>
                        <option value="hide" <?php selected($featured_mode, 'hide'); ?>>非表示</option>
                    </select>
                </div>

                <!-- アイキャッチ画像の代替テキスト -->
                <?php
                    $show_featured_alt = ($featured_mode === 'alt_replace');
                ?>
                <div id="ggc-featured-alt-section" style="display: <?php echo $show_featured_alt ? 'block' : 'none'; ?>;">
                    <h4 class="ggc-heading">アイキャッチ画像の代替テキスト:</h4>
                    <?php 
                    $thumbnail_id = get_post_thumbnail_id($post->ID);
                    $has_thumbnail = !empty($thumbnail_id);
                    $thumbnail_url = $has_thumbnail ? wp_get_attachment_image_src($thumbnail_id, 'thumbnail') : false;
                    $saved_featured_alt = $this->get_meta_string($post, '_ggc_featured_image_alt_text');
                    ?>
                    <div id="ggc-featured-alt-wrapper" class="ggc-mb-8" data-featured-id="<?php echo esc_attr($thumbnail_id); ?>">
                        <div id="ggc-featured-alt-empty" class="ggc-muted" style="display: <?php echo $has_thumbnail ? 'none' : 'block'; ?>;">
                            アイキャッチ画像が設定されていません。先にアイキャッチ画像を設定してください。
                        </div>
                        <div id="ggc-featured-alt-fields" style="display: <?php echo $has_thumbnail ? 'block' : 'none'; ?>;">
                            <div id="ggc-featured-thumb-preview" class="ggc-mb-8">
                                <?php if ($thumbnail_url): ?>
                                    <img id="ggc-featured-thumb-img" src="<?php echo esc_url($thumbnail_url[0]); ?>" class="ggc-img-thumb-sm">
                                <?php else: ?>
                                    <img id="ggc-featured-thumb-img" src="" class="ggc-img-thumb-sm" style="display:none;">
                                <?php endif; ?>
                            </div>
                            <input type="text" id="ggc_featured_image_alt_text" name="ggc_featured_image_alt_text" value="<?php echo esc_attr($saved_featured_alt); ?>" class="ggc-input-full" placeholder=" アイキャッチ画像の代替テキストを入力">
                            <p class="description ggc-description-small ggc-mt-8">評価に従ってアイキャッチ画像をこのテキストに置き換えます。</p>
                        </div>
                    </div>
                </div>



                <div id="ggc-media-eval-wrapper" style="display: <?php echo ($media_mode === 'normal' && $featured_mode === 'normal') ? 'none' : 'block'; ?>;">
                <label for="ggc_media_ua_action" class="ggc-label">User-Agent の評価 - メディア：</label>
                <?php $__m_action = $this->get_meta_value($post, '_ggc_media_ua_action') ?: 'global'; ?>
                <select name="ggc_media_ua_action" id="ggc_media_ua_action" class="ggc-input-full ggc-mb-8">
                    <option value="global" <?php selected($__m_action, 'global'); ?>>設定しない</option>
                    <option value="blacklist" <?php selected($__m_action, 'blacklist'); ?>>ブラックリスト</option>
                    <option value="whitelist" <?php selected($__m_action, 'whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($__m_action, 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected($__m_action, 'deny_all'); ?>>全拒否</option>
                </select>

                <div id="ggc-media-ua-list-wrapper" class="ggc-mb-8" style="display: <?php echo in_array($__m_action, ['blacklist','whitelist']) ? 'block' : 'none'; ?>;">

                    <div class="ggc-panel ggc-panel--scroll" id="ggc-media-crawler-list-panel">
                        <p id="ggc-media-ua-description" class="description ggc-description-tight">
                            <?php $__m_mode = $__m_action;
                            if ($__m_mode === 'whitelist') {
                                echo 'ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。';
                            } elseif ($__m_mode === 'blacklist') {
                                echo 'ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。';
                            } else {
                                echo 'グローバル設定に従います。';
                            } ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-media-ua-def-1-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義1
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-media-ua-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ua-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_crawlers = $this->get_meta_array($post, '_ggc_selected_media_crawlers');
                            $this->render_grouped_bot_checklist($grouped_bots, $selected_media_crawlers, 'ggc-media-ua-group-', 'ggc_selected_media_crawlers_field[]');
                            ?>
                        </div>

                        <h5 class="ggc-section-header ggc-section-header--spaced" data-target="#ggc-media-ua-def-2-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            User-Agent 定義2
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-media-ua-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ua-def-2-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_page_browser_patterns = $this->get_meta_array($post, '_ggc_selected_media_page_browser_patterns');
                            $this->render_grouped_pattern_checklist($grouped_browser_patterns, $selected_media_page_browser_patterns, 'ggc-media-pattern-group-', 'ggc_selected_media_page_browser_patterns_field[]');
                            ?>
                        </div>

                    </div>
                </div>

                <?php $__m_ip_action = $this->get_meta_value($post, '_ggc_media_ip_action') ?: 'global'; ?>
                <label for="ggc_media_ip_action" class="ggc-label">IPアドレスの評価 - メディア：</label>
                <select name="ggc_media_ip_action" id="ggc_media_ip_action" class="ggc-input-full ggc-mb-8">
                    <option value="global" <?php selected($__m_ip_action, 'global'); ?>>設定しない</option>
                    <option value="blacklist" <?php selected($__m_ip_action, 'blacklist'); ?>>ブラックリスト</option>
                    <option value="whitelist" <?php selected($__m_ip_action, 'whitelist'); ?>>ホワイトリスト</option>
                    <option value="allow_all" <?php selected($__m_ip_action, 'allow_all'); ?>>全許可</option>
                    <option value="deny_all" <?php selected($__m_ip_action, 'deny_all'); ?>>全拒否</option>
                </select>

                <div id="ggc-media-ip-list-wrapper" class="ggc-mb-8" style="display: <?php echo in_array($__m_ip_action, ['blacklist','whitelist']) ? 'block' : 'none'; ?>;">

                    <div class="ggc-panel ggc-panel--scroll" id="ggc-media-ip-ranges-panel">
                        <p id="ggc-media-ip-description" class="description ggc-description-tight">
                            <?php $__m_ip_mode = $__m_ip_action;
                            if ($__m_ip_mode === 'whitelist') {
                                echo 'ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。';
                            } elseif ($__m_ip_mode === 'blacklist') {
                                echo 'ブラックリスト : チェックしたIP範囲は代替テキスト表示、それ以外はメディア表示します。';
                            } else {
                                echo 'グローバル設定に従います。';
                            } ?>
                        </p>

                        <h5 class="ggc-section-header" data-target="#ggc-media-ip-def-1-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲1
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-media-ip-def-1-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ip-def-1-container" class="ggc-section-content" style="display:none;">
                            <?php
                            $selected_media_ips = $this->get_meta_array($post, '_ggc_selected_media_ips');

                            if (!empty($grouped_ip_ranges_1)) :
                                $this->render_grouped_ip_checklist($grouped_ip_ranges_1, $selected_media_ips, 'ggc-media-ip-group-1-', 'ggc_selected_media_ips_field[]');
                            else:
                                echo '<p class="description">定義がありません。</p>';
                            endif;
                            ?>
                        </div>

                        <h5 class="ggc-section-header ggc-section-header--spaced" data-target="#ggc-media-ip-def-2-container">
                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                            IPアドレス範囲2
                            <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-media-ip-def-2-container"> [全選択/解除] </small>
                        </h5>
                        <div id="ggc-media-ip-def-2-container" class="ggc-section-content" style="display:none;">
                            <?php if (!empty($grouped_ip_ranges_2)): ?>
                                <?php
                                    $selected_media_ips_2 = $this->get_meta_array($post, '_ggc_selected_media_ips_2');
                                    $this->render_grouped_ip_checklist($grouped_ip_ranges_2, $selected_media_ips_2, 'ggc-media-ip-group-2-', 'ggc_selected_media_ips_2_field[]');
                                ?>
                            <?php else: ?>
                                <p class="description">定義がありません。</p>
                            <?php endif; ?>
                        </div>
                        </div>
                    </div>
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
                <p class="ggc-mt-16">
                    <a class="button button-secondary ggc-alt-preview"
                       href="<?php echo esc_url($preview_url); ?>"
                       target="_blank" rel="noopener">
                        メディアプレビュー
                    </a>
                    <span class="description ggc-description-tight ggc-ml-8">※未保存の内容は反映されない場合があります。必要に応じて保存後にプレビューしてください。</span>
                </p>

            </div>

            </details>

            <details class="ggc-collapsible">
                <summary class="ggc-collapsible-summary">ページ評価</summary>

            <div class="ggc-section">
                <!-- hidden global page eval mode for JS notifications -->
<!-- page eval hidden input removed per request -->

                <label for="ggc_ua_redirect_mode" class="ggc-label">User-Agentの評価方法-ページ：</label>
                <select name="ggc_ua_redirect_mode" id="ggc_ua_redirect_mode" class="ggc-input-full ggc-mb-8">
                    <option value="global" <?php selected($ua_redirect_mode, 'global'); ?>>設定しない</option>
                    <?php foreach ($redirect_modes as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($ua_redirect_mode, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description ggc-description-tight">アクセスをブロックしたりリダイレクト出来ます。</p>

                <div id="ggc-ua-page-eval-wrapper" class="ggc-section">
                    <h4 class="ggc-heading">User-Agent の評価-ページ</h4>

                    <select name="ggc_ua_control_mode" id="ggc_ua_control_mode" class="ggc-input-full ggc-mb-8">
                        <?php foreach ($modes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($ua_control_mode, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="ggc-ua-list-wrapper" style="display: <?php echo (in_array($ua_control_mode, ['blacklist', 'whitelist'])) ? 'block' : 'none'; ?>;">
                        <div id="ggc-crawler-list-panel" class="ggc-panel ggc-panel--scroll">
                            <p id="ggc-ua-description" class="description ggc-description-tight">
                                <?php echo ($ua_control_mode === 'whitelist') ? 'ホワイトリスト : チェックしたUser-Agentをアクセス許可します。' : 'ブラックリスト : チェックしたUser-Agentをアクセス拒否します。'; ?>
                            </p>

                            <h5 class="ggc-section-header ggc-section-header--tight" data-target="#ggc-ua-def-1-container">
                                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                User-Agent 定義1
                                <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-ua-def-1-container"> [全選択/解除] </small>
                            </h5>
                            <div id="ggc-ua-def-1-container" class="ggc-section-content" style="display: none;">
                                <?php if (!empty($grouped_bots)): ?>
                                    <?php $this->render_grouped_bot_checklist($grouped_bots, $selected_crawlers, 'ggc-group-', 'ggc_selected_crawlers_field[]'); ?>
                                <?php else: ?>
                                    <p class="description">定義がありません。</p>
                                <?php endif; ?>
                            </div>

                            <h5 class="ggc-section-header ggc-section-header--loose" data-target="#ggc-ua-def-2-container">
                                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                User-Agent 定義2
                                <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-ua-def-2-container"> [全選択/解除] </small>
                            </h5>
                            <div id="ggc-ua-def-2-container" class="ggc-section-content" style="display: none;">
                                <?php if (!empty($grouped_browser_patterns)): ?>
                                    <?php $group_counter = 0; foreach ($grouped_browser_patterns as $group_label => $patterns_in_group) : $group_counter++; $group_id = 'ggc-pattern-group-' . $group_counter; ?>
                                        <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>">
                                            <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                            <?php echo esc_html($group_label); ?>
                                            <small class="ggc-toggle-all-pattern ggc-toggle-link" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
                                        </h4>
                                        <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                                            <?php foreach ($patterns_in_group as $key => $pattern_def) : ?>
                                                <label class="ggc-page-pattern-item ggc-item-tight">
                                                    <input type="checkbox" name="ggc_selected_page_browser_patterns_field[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_page_browser_patterns), true); ?>>
                                                    <strong><?php echo esc_html($pattern_def['label']); ?></strong>
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
                <div id="ggc-ua-redirect-url" style="display: <?php echo ($ua_redirect_mode === 'redirect') ? 'block' : 'none'; ?>;">
                    <p class="ggc-text-12 ggc-text-bold ggc-mb-8">User-Agent の評価-リダイレクトURL：</p>
                    <input type="text" name="ggc_ua_redirect_url" value="<?php echo esc_attr($ua_redirect_url); ?>" class="regular-text ggc-input-full" placeholder="https://example.com/" />
                    <p class="description ggc-mt-8">User-Agent評価の結果に応じてリダイレクトします。</p>
                </div>

                <div id="ggc-ua-block-settings" class="ggc-section ggc-mt-12">
                    <label for="ggc_ua_block_message_key" class="ggc-label">User-Agentの評価-ブロックメッセージ：</label>
                    <select name="ggc_ua_block_message_key" id="ggc_ua_block_message_key" class="ggc-input-full ggc-mb-8">
                        <option value="">設定しない</option>
                        <option value="custom" <?php selected($ua_block_message_key, 'custom'); ?>>任意のメッセージ内容を表示</option>
                        <?php foreach ($page_eval_messages as $key => $def): ?>
                            <?php if (!($def['is_global'] ?? 0)) continue; // グローバル設定のみ表示 ?>
                            <?php $label = $def['label'] ?? $key; ?>
                            <option value="<?php echo esc_attr($key); ?>" data-status="<?php echo esc_attr(intval($def['status_code'] ?? 403)); ?>" data-message="<?php echo esc_attr($def['message'] ?? ''); ?>" <?php selected($ua_block_message_key, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="ggc-ua-block-preview" class="ggc-panel ggc-mt-8" style="display:none;">
                        <p class="ggc-text-12 ggc-text-bold">プレビュー</p>
                        <p class="ggc-text-12 ggc-text-muted">ステータスコード: <span class="ggc-ua-block-preview-status"></span></p>
                        <div class="ggc-text-12 ggc-text-muted ggc-ua-block-preview-message"></div>
                    </div>
                    <div id="ggc-ua-block-custom" class="ggc-mt-8">
                        <label for="ggc_ua_block_status_code" class="ggc-label ggc-mb-8">User-Agentの評価時のメッセージ定義：</label>
                        <input type="number" min="400" max="599" name="ggc_ua_block_status_code" id="ggc_ua_block_status_code" value="<?php echo esc_attr($ua_block_status_code); ?>" class="small-text ggc-mb-8" placeholder="403" />
                        <textarea name="ggc_ua_block_message_custom" rows="2" class="large-text" placeholder="User-Agentブロック時のメッセージ（任意）"><?php echo esc_textarea($ua_block_message_custom); ?></textarea>
                    </div>
                </div>

                <label for="ggc_ip_redirect_mode" class="ggc-label ggc-mt-8 ggc-mb-8">IPアドレス-評価方法-ページ：</label>
                <select name="ggc_ip_redirect_mode" id="ggc_ip_redirect_mode" class="ggc-input-full ggc-mb-8">
                    <option value="global" <?php selected($ip_redirect_mode, 'global'); ?>>設定しない</option>
                    <?php foreach ($redirect_modes as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($ip_redirect_mode, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description ggc-description-tight">アクセスをブロックしたりリダイレクト出来ます。</p>

                <div id="ggc-ip-page-eval-wrapper" class="ggc-section">
                    <h4 class="ggc-heading">IPアドレスの評価-ページ：</h4>

                    <select name="ggc_ip_control_mode" id="ggc_ip_control_mode" class="ggc-input-full ggc-mb-8">
                        <?php foreach ($modes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($ip_control_mode, $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="ggc-ip-list-wrapper" style="display: <?php echo (in_array($ip_control_mode, ['blacklist', 'whitelist'])) ? 'block' : 'none'; ?>;">
                        <div id="ggc-ip-ranges-panel" class="ggc-panel ggc-panel--scroll">
                            <p id="ggc-ip-description" class="description ggc-description-tight">
                                <?php echo ($ip_control_mode === 'blacklist') ? 'ホワイトリスト : チェックしたIP範囲をアクセス許可します。' : 'ブラックリスト : チェックしたIP範囲をアクセス拒否します。'; ?>
                            </p>

                            <h5 class="ggc-section-header ggc-section-header--tight" data-target="#ggc-ip-def-1-container">
                                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                IPアドレス範囲1
                                <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-ip-def-1-container"> [全選択/解除] </small>
                            </h5>
                            <div id="ggc-ip-def-1-container" class="ggc-section-content" style="display: none;">
                                <?php if (!empty($grouped_ip_ranges_1)): ?>
                                    <?php $this->render_grouped_ip_checklist($grouped_ip_ranges_1, $selected_ips, 'ggc-ip-group-1-', 'ggc_selected_ips_field[]'); ?>
                                <?php else: ?>
                                    <p class="description">定義がありません。</p>
                                <?php endif; ?>
                            </div>

                            <h5 class="ggc-section-header ggc-section-header--loose" data-target="#ggc-ip-def-2-container">
                                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                                IPアドレス範囲2
                                <small class="ggc-toggle-section ggc-toggle-link" data-section="ggc-ip-def-2-container"> [全選択/解除] </small>
                            </h5>
                            <div id="ggc-ip-def-2-container" class="ggc-section-content" style="display: none;">
                                <?php if (!empty($grouped_ip_ranges_2)): ?>
                                    <?php $this->render_grouped_ip_checklist($grouped_ip_ranges_2, $selected_ips_2, 'ggc-ip-group-2-', 'ggc_selected_ips_2_field[]'); ?>
                                <?php else: ?>
                                    <p class="description">定義がありません。</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="ggc-ip-redirect-url" style="display: <?php echo ($ip_redirect_mode === 'redirect') ? 'block' : 'none'; ?>;">
                    <p class="ggc-text-12 ggc-text-bold ggc-mb-8">リダイレクトURL：</p>
                    <input type="text" name="ggc_ip_redirect_url" value="<?php echo esc_attr($ip_redirect_url); ?>" class="regular-text ggc-input-full" placeholder="https://example.com/" />
                    <p class="description ggc-mt-8">IP評価の結果に応じてリダイレクトします。</p>
                </div>

                <div id="ggc-ip-block-settings" class="ggc-section ggc-mt-12">
                    <label for="ggc_ip_block_message_key" class="ggc-label">IPアドレスの評価-ブロックメッセージ：</label>
                    <select name="ggc_ip_block_message_key" id="ggc_ip_block_message_key" class="ggc-input-full ggc-mb-8">
                        <option value="">設定しない</option>
                        <option value="custom" <?php selected($ip_block_message_key, 'custom'); ?>>任意のメッセージ内容を表示</option>
                        <?php foreach ($page_eval_messages as $key => $def): ?>
                            <?php if (!($def['is_global'] ?? 0)) continue; // グローバル設定のみ表示 ?>
                            <?php $label = $def['label'] ?? $key; ?>
                            <option value="<?php echo esc_attr($key); ?>" data-status="<?php echo esc_attr(intval($def['status_code'] ?? 403)); ?>" data-message="<?php echo esc_attr($def['message'] ?? ''); ?>" <?php selected($ip_block_message_key, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="ggc-ip-block-preview" class="ggc-panel ggc-mt-8" style="display:none;">
                        <p class="ggc-text-12 ggc-text-bold">プレビュー</p>
                        <p class="ggc-text-12 ggc-text-muted">ステータスコード: <span class="ggc-ip-block-preview-status"></span></p>
                        <div class="ggc-text-12 ggc-text-muted ggc-ip-block-preview-message"></div>
                    </div>
                    <div id="ggc-ip-block-custom" class="ggc-mt-8">
                        <label for="ggc_ip_block_status_code" class="ggc-label ggc-mb-8">IPアドレス評価時のメッセージ定義：</label>
                        <input type="number" min="400" max="599" name="ggc_ip_block_status_code" id="ggc_ip_block_status_code" value="<?php echo esc_attr($ip_block_status_code); ?>" class="small-text ggc-mb-8" placeholder="403" />
                        <textarea name="ggc_ip_block_message_custom" rows="2" class="large-text" placeholder="IPブロック時のメッセージ（任意）"><?php echo esc_textarea($ip_block_message_custom); ?></textarea>
                        <p class="description ggc-description-tight ggc-mt-8">IPアドレスの評価-ページ</p>
                    </div>
                </div>
            </div>

            </details>
        </div>
        <?php
    }

    private function group_definitions_by_label($definitions, $label_key = 'group_label', $default_label = 'その他', $require_array = false) {
        $grouped = [];
        if (!is_array($definitions)) {
            return $grouped;
        }
        foreach ($definitions as $key => $def) {
            if ($require_array && !is_array($def)) {
                continue;
            }
            $group_label = (is_array($def) && isset($def[$label_key])) ? $def[$label_key] : $default_label;
            if (!isset($grouped[$group_label])) {
                $grouped[$group_label] = [];
            }
            $grouped[$group_label][$key] = $def;
        }
        return $grouped;
    }

    private function render_grouped_bot_checklist($grouped_bots, $selected_keys, $group_id_prefix, $input_name) {
        $selected_keys = is_array($selected_keys) ? $selected_keys : [];
        if (empty($grouped_bots)) {
            echo '<p class="description">定義がありません。</p>';
            return;
        }
        $group_counter = 0;
        foreach ($grouped_bots as $group_label => $bots_in_group) {
            $group_counter++;
            $group_id = $group_id_prefix . $group_counter;
            ?>
            <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>">
                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                <?php echo esc_html($group_label); ?>
                <small class="ggc-toggle-all ggc-toggle-link" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
            </h4>
            <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                <?php foreach ($bots_in_group as $key => $bot) : ?>
                    <label class="ggc-crawler-item ggc-item-tight">
                        <input type="checkbox" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_keys), true); ?>>
                        <strong><?php echo esc_html($bot['label']); ?></strong>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }

    private function render_grouped_pattern_checklist($grouped_patterns, $selected_keys, $group_id_prefix, $input_name) {
        $selected_keys = is_array($selected_keys) ? $selected_keys : [];
        if (empty($grouped_patterns)) {
            echo '<p class="description">定義がありません。</p>';
            return;
        }
        $group_counter = 0;
        foreach ($grouped_patterns as $group_label => $patterns_in_group) {
            $group_counter++;
            $group_id = $group_id_prefix . $group_counter;
            ?>
            <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>">
                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                <?php echo esc_html($group_label); ?>
                <small class="ggc-toggle-all-pattern ggc-toggle-link" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
            </h4>
            <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                <?php foreach ($patterns_in_group as $key => $pattern_def) : ?>
                    <label class="ggc-page-pattern-item ggc-item-tight">
                        <input type="checkbox" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_keys), true); ?>>
                        <strong><?php echo esc_html($pattern_def['label']); ?></strong>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }

    private function render_grouped_ip_checklist($grouped_ip_ranges, $selected_keys, $group_id_prefix, $input_name) {
        $selected_keys = is_array($selected_keys) ? $selected_keys : [];
        if (empty($grouped_ip_ranges)) {
            echo '<p class="description">定義がありません。</p>';
            return;
        }
        $group_counter = 0;
        foreach ($grouped_ip_ranges as $group_label => $ips_in_group) {
            $group_counter++;
            $group_id = $group_id_prefix . $group_counter;
            ?>
            <h4 class="ggc-group-header" data-target="#<?php echo esc_attr($group_id); ?>">
                <span class="dashicons dashicons-arrow-right-alt2 ggc-arrow"></span>
                <?php echo esc_html($group_label); ?>
                <small class="ggc-toggle-all-ip ggc-toggle-link" data-group="<?php echo esc_attr($group_id); ?>"> [全選択/解除] </small>
            </h4>
            <div id="<?php echo esc_attr($group_id); ?>" class="ggc-group-content">
                <?php foreach ($ips_in_group as $key => $ip_def) : ?>
                    <label class="ggc-ip-range-item ggc-item-tight">
                        <input type="checkbox" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($key); ?>" <?php checked(in_array(sanitize_key($key), $selected_keys), true); ?>>
                        <strong><?php echo esc_html($ip_def['label']); ?></strong>
                        <?php if (!empty($ip_def['is_auto'])): ?>
                            <small class="ggc-text-success">(自動)</small>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php
        }
    }

    /**
     * メタボックスのデータを保存
     */
    public function save_crawler_meta_box($post_id) {
        if (! Admin_Utils::verify_post_save_nonce_and_cap('ggc_crawler_control_nonce', 'ggc_crawler_control_save', $post_id)) {
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
            $ggc_block_modes = $this->extract_ggc_block_modes($post->post_content);
            
            if (!empty($ggc_block_attrs)) {
                GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_block_attrs', $ggc_block_attrs);
            }
            if (!empty($ggc_block_modes)) {
                GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_block_modes', $ggc_block_modes);
            }
        }

        // アイキャッチ画像の代替テキストを保存
        if (isset($_POST['ggc_featured_image_alt_text'])) {
            $featured_alt = sanitize_text_field($_POST['ggc_featured_image_alt_text']);
            if (!empty($featured_alt)) {
                GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_featured_image_alt_text', $featured_alt);
            } else {
                GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_featured_image_alt_text', null);
            }
        }

        // ブロックメッセージの取得を先に行う（評価方法の決定に影響するため）
        $ua_block_message_key = isset($_POST['ggc_ua_block_message_key']) ? sanitize_key($_POST['ggc_ua_block_message_key']) : '';
        $ip_block_message_key = isset($_POST['ggc_ip_block_message_key']) ? sanitize_key($_POST['ggc_ip_block_message_key']) : '';

        // Save Modes
        $allowed_control_modes = ['global', 'whitelist', 'blacklist', 'allow_all', 'deny_all'];
        $ua_mode = isset($_POST['ggc_ua_control_mode']) ? sanitize_text_field($_POST['ggc_ua_control_mode']) : 'global';
        if (!in_array($ua_mode, $allowed_control_modes, true)) {
            $ua_mode = 'global';
        }
        $ip_mode = isset($_POST['ggc_ip_control_mode']) ? sanitize_text_field($_POST['ggc_ip_control_mode']) : 'global';
        if (!in_array($ip_mode, $allowed_control_modes, true)) {
            $ip_mode = 'global';
        }

        $ua_redirect_mode = isset($_POST['ggc_ua_redirect_mode']) ? sanitize_text_field($_POST['ggc_ua_redirect_mode']) : 'global';
        if (!in_array($ua_redirect_mode, ['block','redirect','global'], true)) {
            $ua_redirect_mode = 'global';
        }
        $ip_redirect_mode = isset($_POST['ggc_ip_redirect_mode']) ? sanitize_text_field($_POST['ggc_ip_redirect_mode']) : 'global';
        if (!in_array($ip_redirect_mode, ['block','redirect','global'], true)) {
            $ip_redirect_mode = 'global';
        }

        // if evaluation method is set to "global/none" we also clear the
        // per-post control mode to avoid stale values being used later. this
        // mirrors the behaviour added to perform_blocking and keeps the meta
        // easier to reason about.
        if ($ua_redirect_mode === 'global') {
            $ua_mode = 'global';
        }
        if ($ip_redirect_mode === 'global') {
            $ip_mode = 'global';
        }

        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_control_mode', $ua_mode);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_control_mode', $ip_mode);

        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_redirect_mode', $ua_redirect_mode);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_redirect_mode', $ip_redirect_mode);

        $ua_redirect_url = isset($_POST['ggc_ua_redirect_url']) ? esc_url_raw($_POST['ggc_ua_redirect_url']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_redirect_url', $ua_redirect_url);

        $ip_redirect_url = isset($_POST['ggc_ip_redirect_url']) ? esc_url_raw($_POST['ggc_ip_redirect_url']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_redirect_url', $ip_redirect_url);

        // ブロックメッセージの保存（既に取得済み）
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_block_message_key', $ua_block_message_key);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_block_message_key', $ip_block_message_key);

        $ua_block_status_code = isset($_POST['ggc_ua_block_status_code']) ? intval($_POST['ggc_ua_block_status_code']) : 0;
        if ($ua_block_status_code >= 400 && $ua_block_status_code <= 599) {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_block_status_code', $ua_block_status_code);
        } else {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_block_status_code', null);
        }

        $ip_block_status_code = isset($_POST['ggc_ip_block_status_code']) ? intval($_POST['ggc_ip_block_status_code']) : 0;
        if ($ip_block_status_code >= 400 && $ip_block_status_code <= 599) {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_block_status_code', $ip_block_status_code);
        } else {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_block_status_code', null);
        }

        $ua_block_message_custom = isset($_POST['ggc_ua_block_message_custom']) ? sanitize_textarea_field($_POST['ggc_ua_block_message_custom']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ua_block_message_custom', $ua_block_message_custom);

        $ip_block_message_custom = isset($_POST['ggc_ip_block_message_custom']) ? sanitize_textarea_field($_POST['ggc_ip_block_message_custom']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_ip_block_message_custom', $ip_block_message_custom);

        // Save Lists - 設定しない場合は選択リストをクリア
        if ($ua_mode === 'global') {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_crawlers', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_page_browser_patterns', [], null, true);
        } else {
            $selected_crawlers = array_map('sanitize_key', isset($_POST['ggc_selected_crawlers_field']) ? $_POST['ggc_selected_crawlers_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_crawlers', $selected_crawlers, null, true);

            $selected_page_browser_patterns = array_map('sanitize_key', isset($_POST['ggc_selected_page_browser_patterns_field']) ? $_POST['ggc_selected_page_browser_patterns_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_page_browser_patterns', $selected_page_browser_patterns, null, true);
        }

        if ($ip_mode === 'global') {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_ips', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_ips_2', [], null, true);
        } else {
            $selected_ips = array_map('sanitize_key', isset($_POST['ggc_selected_ips_field']) ? $_POST['ggc_selected_ips_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_ips', $selected_ips, null, true);

            $selected_ips_2 = array_map('sanitize_key', isset($_POST['ggc_selected_ips_2_field']) ? $_POST['ggc_selected_ips_2_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_ips_2', $selected_ips_2, null, true);
        }

        // Save Markdown replacement settings
        $md_text = isset($_POST['ggc_md_replace_text']) ? sanitize_textarea_field($_POST['ggc_md_replace_text']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_text', $md_text);

        $md_mode = isset($_POST['ggc_md_replace_mode']) ? sanitize_text_field($_POST['ggc_md_replace_mode']) : 'none';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_mode', $md_mode === 'none' ? null : $md_mode);

        $md_template_key = isset($_POST['ggc_md_template_key']) ? sanitize_key($_POST['ggc_md_template_key']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_template_key', $md_template_key);

        // md_template_mode is no longer used (template/random handled by md_replace_mode)
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_template_mode', null);

        $md_title = isset($_POST['ggc_md_replace_title']) ? sanitize_text_field($_POST['ggc_md_replace_title']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_title', $md_title);


        if (!in_array($md_mode, ['template', 'template_raw'], true)) {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_template_key', null);
        }

        $md_image_id = isset($_POST['ggc_md_replace_image_id']) ? intval($_POST['ggc_md_replace_image_id']) : 0;
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_image_id', $md_image_id > 0 ? $md_image_id : null);

        $md_image_url = isset($_POST['ggc_md_replace_image_url']) ? esc_url_raw($_POST['ggc_md_replace_image_url']) : '';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_image_url', $md_image_url);


        $md_ua_mode = isset($_POST['ggc_md_ua_mode']) ? sanitize_text_field($_POST['ggc_md_ua_mode']) : 'global';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_ua_mode', $md_ua_mode);

        $md_ip_mode = isset($_POST['ggc_md_ip_mode']) ? sanitize_text_field($_POST['ggc_md_ip_mode']) : 'global';
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_ip_mode', $md_ip_mode);

        if (in_array($md_ua_mode, ['blacklist','whitelist'], true)) {
            $md_selected_crawlers = array_map('sanitize_key', isset($_POST['ggc_md_replace_crawlers_field']) ? $_POST['ggc_md_replace_crawlers_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_crawlers', $md_selected_crawlers, null, true);

            $md_selected_patterns = array_map('sanitize_key', isset($_POST['ggc_md_replace_browser_patterns_field']) ? $_POST['ggc_md_replace_browser_patterns_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_browser_patterns', $md_selected_patterns, null, true);
        } else {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_crawlers', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_browser_patterns', [], null, true);
        }

        if (in_array($md_ip_mode, ['blacklist','whitelist'], true)) {
            $md_selected_ips = array_map('sanitize_key', isset($_POST['ggc_md_replace_ips_field']) ? $_POST['ggc_md_replace_ips_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_ips', $md_selected_ips, null, true);

            $md_selected_ips_2 = array_map('sanitize_key', isset($_POST['ggc_md_replace_ips_2_field']) ? $_POST['ggc_md_replace_ips_2_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_ips_2', $md_selected_ips_2, null, true);
        } else {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_ips', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_md_replace_ips_2', [], null, true);
        }

        // Save Media-related settings
        $ua_media_action = isset($_POST['ggc_media_ua_action']) ? sanitize_text_field($_POST['ggc_media_ua_action']) : 'global';
        $ip_media_action = isset($_POST['ggc_media_ip_action']) ? sanitize_text_field($_POST['ggc_media_ip_action']) : 'global';


        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_ua_action', $ua_media_action);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_ip_action', $ip_media_action);

        // Save media mode (normal / individual / hide_all)
        $media_mode = isset($_POST['ggc_media_mode']) ? sanitize_text_field($_POST['ggc_media_mode']) : 'normal';
        // if global evaluation is disabled or applies to all pages, ignore any
        // post-specific selection and treat as normal so meta won't be used later.
        $global_media_mode = GGC_Options::get_global_media_options()['media_eval_mode'];
        if ($global_media_mode !== 'apply_new_posts') {
            $media_mode = 'normal';
        }
        if (!in_array($media_mode, ['normal', 'individual', 'hide_all'], true)) {
            $media_mode = 'normal';
        }

        // Save featured mode (normal / alt_replace / hide) - independent of media mode
        $featured_mode = isset($_POST['ggc_featured_mode']) ? sanitize_text_field($_POST['ggc_featured_mode']) : 'normal';
        if ($global_media_mode !== 'apply_new_posts') {
            $featured_mode = 'normal';
        }
        if (!in_array($featured_mode, ['normal', 'alt_replace', 'hide'], true)) {
            $featured_mode = 'normal';
        }
        // メディア表示モードが「設定しない」の場合、アイキャッチも通常表示にリセット
        if ($media_mode === 'normal') {
            $featured_mode = 'normal';
        }
        // alt_replace で代替テキストが空の場合は通常表示に変換
        if ($featured_mode === 'alt_replace') {
            $featured_alt_text = isset($_POST['ggc_featured_image_alt_text'])
                ? sanitize_text_field($_POST['ggc_featured_image_alt_text']) : '';
            if (empty(trim($featured_alt_text))) {
                $featured_mode = 'normal';
            }
        }

        // 新アーキテクチャのメタを保存
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_mode', $media_mode);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_featured_mode', $featured_mode);

        // レガシーメタをクリア（新アーキテクチャでは不要）
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_alt_replace', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_hide', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_individual', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_media_hide_all', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_featured_visible_on_hide', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_featured_hide_on_hide_all', null);


        // Save media-specific selected crawlers and IPs for blacklist/whitelist modes
        // 設定しない場合は、空配列を保存してチェックボックス選択を無効化
        if ($ua_media_action === 'global') {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_crawlers', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_page_browser_patterns', [], null, true);
        } else {
            $selected_media_crawlers = array_map('sanitize_key', isset($_POST['ggc_selected_media_crawlers_field']) ? $_POST['ggc_selected_media_crawlers_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_crawlers', $selected_media_crawlers, null, true);

            $selected_media_page_browser_patterns = array_map('sanitize_key', isset($_POST['ggc_selected_media_page_browser_patterns_field']) ? $_POST['ggc_selected_media_page_browser_patterns_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_page_browser_patterns', $selected_media_page_browser_patterns, null, true);
        }

        if ($ip_media_action === 'global') {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_ips', [], null, true);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_ips_2', [], null, true);
        } else {
            $selected_media_ips = array_map('sanitize_key', isset($_POST['ggc_selected_media_ips_field']) ? $_POST['ggc_selected_media_ips_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_ips', $selected_media_ips, null, true);

            $selected_media_ips_2 = array_map('sanitize_key', isset($_POST['ggc_selected_media_ips_2_field']) ? $_POST['ggc_selected_media_ips_2_field'] : []);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_selected_media_ips_2', $selected_media_ips_2, null, true);
        }

        //メディアごとの代替テキスト保存
        if (isset($_POST['ggc_image_alt_texts']) && is_array($_POST['ggc_image_alt_texts'])) {
            $image_alt_texts = array_map('sanitize_text_field', $_POST['ggc_image_alt_texts']);
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_image_alt_texts', $image_alt_texts);
        } else {
            GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_image_alt_texts', null);
        }

        // Remove old meta if exists to avoid confusion
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_control_active', null);
        GGC_Meta_Utils::update_or_delete_meta($post_id, '_ggc_control_mode', null);
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

    /**
     * GutenbergブロックコンテンツからggcMediaMode属性を抽出
     * ブロックコメント形式: <!-- wp:image {...,"ggcMediaMode":"hide",...} -->
     */
    private function extract_ggc_block_modes($content) {
        $modes = [];

        if (preg_match_all('/<!-- wp:(?:core\/)?(image|gallery|cover)\s+(\{[^}]*\}) -->/i', $content, $matches)) {
            foreach ($matches[2] as $json_str) {
                $parsed = json_decode($json_str, true);
                if (!is_array($parsed)) {
                    continue;
                }
                if (!isset($parsed['id']) || intval($parsed['id']) <= 0) {
                    continue;
                }
                $mode = isset($parsed['ggcMediaMode']) ? sanitize_text_field($parsed['ggcMediaMode']) : 'normal';
                if (!in_array($mode, ['hide', 'replace'], true)) {
                    continue;
                }
                $modes[intval($parsed['id'])] = $mode;
            }
        }

        return $modes;
    }

    public function get_meta_array($post, $meta_key) {
        $value = get_post_meta($post->ID, $meta_key, true);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && strlen($value) > 0) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    public function get_meta_string($post, $meta_key) {
        $value = get_post_meta($post->ID, $meta_key, true);
        if (is_array($value)) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        return '';
    }

    public function get_meta_value($post, $meta_key, $default = null) {
        $value = get_post_meta($post->ID, $meta_key, true);
        if ($value === '' || $value === null) {
            return $default;
        }
        return $value;
    }

}
