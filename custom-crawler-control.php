<?php
/**
 * Plugin Name: Smart Access Control アクセス制御
 * Description: アクセスを精密に制御する管理者向けプラグイン。Advanced access control plugin for administrators.
 * Version: 2.0.0
 * Text Domain: custom-crawler-control
 * Requires at least: 4.9+
 * Requires PHP: 7.0+
 * Copyright: 2025 donnma
 * Author: donnma
 * Author URI: https://donnma.com/
 * Plugin URI: https://github.com/donnma777/smart-access-control
 * GitHub: https://github.com/donnma777/
 * X: https://x.com/donnma777
 */

// <!-- custom-crawler-control\custom-crawler-control.php -->

    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

    // --------------------------------------------------------
    // 1. 定義ファイルの読み込み
    // --------------------------------------------------------
    require_once plugin_dir_path(__FILE__) . 'default-definitions.php';

    // --------------------------------------------------------
    // 2. クラスファイルの読み込み
    // --------------------------------------------------------

    // コアロジック (フロントエンドの制御、IP更新、ヘルパー関数)
    require_once plugin_dir_path(__FILE__) . 'includes/class-crawler-core.php';

    // 管理画面設定ページ (admin/パスで読み込む)
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-settings.php';

    // 投稿編集画面メタボックス (admin/パスで読み込む)
    require_once plugin_dir_path(__FILE__) . 'admin/class-post-metabox.php';

    // --------------------------------------------------------
    // 3. プラグインの実行
    // --------------------------------------------------------

    // プラグイン有効化・無効化フックを登録
    register_activation_hook(__FILE__, ['Custom_Crawler_Core', 'ggc_activation_hooks']);
    register_deactivation_hook(__FILE__, ['Custom_Crawler_Core', 'ggc_deactivation_hooks']);

    // 有効化時にオプションの初期値を設定する（空の配列で初期化）
    register_activation_hook(__FILE__, function() {
        if (false === get_option('ggc_bot_definitions')) update_option('ggc_bot_definitions', []);
        if (false === get_option('ggc_ip_definitions')) update_option('ggc_ip_definitions', []);
    });

    // コアロジックを実行
    Custom_Crawler_Core::get_instance();

    // 管理画面機能の実行
    if (is_admin()) {
        Custom_Admin_Settings::get_instance();
        Custom_Post_Metabox::get_instance();
    }

    // --------------------------------------------------------
    // 4. プラグイン一覧画面への「設定」リンク追加
    // --------------------------------------------------------
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
        // 設定画面のURL (class-admin-settings.phpで設定している slug: ggc-crawler-definitions)
        $settings_url = admin_url( 'options-general.php?page=ggc-crawler-definitions' );

        // リンクを作成
        $settings_link = '<a href="' . esc_url( $settings_url ) . '">設定</a>';

        // 配列の先頭に追加 (「停止」リンクの前に表示されます)
        array_unshift( $links, $settings_link );

        return $links;
    });
