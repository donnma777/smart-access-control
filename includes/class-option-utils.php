<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 共通オプション取得ユーティリティ
 *
 * 繰り返し使われる get_option ロジックを集約する。
 */
class GGC_Options {
    /**
     * ページ評価に使うグローバルオプション群を配列で返す。
     * - global_ua_option: 'none' 等
     * - global_ip_option: 'none' 等
     * - global_page_mode: 'none'|'all'|'apply_new_posts' など
     *
     * @return array
     */
    public static function get_page_eval_options() {
        $global_ua_option = get_option('ggc_global_page_user_agent_control', 'none');
        $global_ip_option = get_option('ggc_global_page_ip_control', 'none');
        if ($global_ua_option === '' || $global_ua_option === null) {
            $global_ua_option = 'none';
        }
        if ($global_ip_option === '' || $global_ip_option === null) {
            $global_ip_option = 'none';
        }

        $global_page_mode = get_option('ggc_global_page_eval_mode', 'none');
        if ($global_page_mode === '' || $global_page_mode === null) {
            $global_page_mode = 'none';
        }

        return [
            'global_ua_option' => $global_ua_option,
            'global_ip_option' => $global_ip_option,
            'global_page_mode' => $global_page_mode,
        ];
    }

    /**
     * クローラー定義を返す。null は未設定（クリア待ち）を表す。
     * @return array|null
     */
    public static function get_crawler_definitions() {
        $bots = get_option('ggc_crawler_definitions', null);
        if ($bots === null) {
            $cleared = get_option('ggc_clear_all_done', '0') === '1';
            return $cleared ? [] : ggc_get_default_bots();
        }
        return is_array($bots) ? $bots : [];
    }

    /**
     * IP 範囲定義（1系）を返す。null は未設定を示す。
     */
    public static function get_ip_range_definitions_1() {
        return get_option('ggc_ip_range_definitions', null);
    }

    /**
     * IP 範囲定義（2系）を返す。null は未設定を示す。
     */
    public static function get_ip_range_definitions_2() {
        return get_option('ggc_ip_range_definitions_2', null);
    }

    /**
     * ブラウザブロックパターンを返す。null は未設定を示す。
     */
    public static function get_browser_block_patterns() {
        $patterns = get_option('ggc_browser_block_patterns', null);
        if ($patterns === null) {
            $cleared = self::is_clear_all_done();
            return $cleared ? [] : ggc_get_default_browser_patterns();
        }
        return is_array($patterns) ? $patterns : [];
    }

    /**
     * プラグイン全体クリアフラグ
     */
    public static function is_clear_all_done() {
        return get_option('ggc_clear_all_done', '0') === '1';
    }

    /**
     * IP 更新頻度
     */
    public static function get_ip_update_frequency($default = 'daily') {
        return get_option('ggc_ip_update_frequency', $default);
    }

    /**
     * 最終 IP 更新時刻（タイムスタンプ、存在しない場合は null）
     */
    public static function get_last_ip_update_time() {
        $t = get_option('ggc_last_ip_update_time', null);
        if ($t === null) return null;
        return intval($t);
    }

    /**
     * 最終 IP 更新結果（保存された配列や値をそのまま返す）
     */
    public static function get_last_ip_update_result() {
        return get_option('ggc_last_ip_update_result');
    }

    /**
     * マークダウンテンプレートのクリアフラグ
     */
    public static function get_markdown_templates_cleared() {
        return get_option('ggc_markdown_templates_cleared', '0');
    }

    /**
     * ページ評価モード移行済フラグ
     */
    public static function is_page_eval_mode_migration_done() {
        return get_option('ggc_page_eval_mode_migration_done', '0') === '1';
    }

    /**
     * ページ評価メッセージオプション（存在チェックで使用されることが多い）
     */
    public static function get_page_eval_messages_raw() {
        return get_option('ggc_page_eval_messages');
    }

    /**
     * マークダウン関連設定をまとめて返す。
     */
    public static function get_markdown_options() {
        $replace_enabled = get_option('ggc_markdown_replace_enabled', 'off');
        $global_template_mode = get_option('ggc_markdown_global_template_mode', 'select');
        $ua_eval = get_option('ggc_markdown_ua_eval', 'none');
        $ip_eval = get_option('ggc_markdown_ip_eval', 'none');
        return [
            'replace_enabled' => $replace_enabled,
            'global_template_mode' => $global_template_mode,
            'ua_eval' => $ua_eval,
            'ip_eval' => $ip_eval,
        ];
    }

    /**
     * デフォルト制御の有効フラグ
     */
    public static function get_default_control_active() {
        return get_option('ggc_default_control_active', 'no');
    }

    /**
     * 汎用: オプションが未設定（=== false）かどうか
     */
    public static function option_is_missing($name) {
        return get_option($name) === false;
    }

    /**
     * 汎用: オプションを配列として取得し、配列でなければデフォルトを返す。
     */
    public static function get_array_option($name, $default = []) {
        $v = get_option($name, $default);
        return is_array($v) ? $v : $default;
    }

    /**
     * マークダウン用テンプレート群
     */
    public static function get_markdown_templates() {
        $opts = get_option('ggc_markdown_templates', []);
        return is_array($opts) ? $opts : [];
    }

    /**
     * マークダウンのグローバル選択テンプレートキー
     */
    public static function get_markdown_global_template_key() {
        $k = get_option('ggc_markdown_global_template_key', '');
        return is_string($k) ? $k : '';
    }

    /**
     * ページ評価向けのグローバルオプション群（既存の get_page_eval_global_options と同等）
     *
     * NOTE: the keys in this array are a bit confusing because some of them
     * refer to the traditional "global" UA/IP controls (used elsewhere in the
     * plugin) while the *_page_* variants are the per‑page evaluation dropdown
     * values.  the rendering code should always use the latter when showing the
     * settings UI.  previously the UI accidentally read the wrong keys, which
     * resulted in saved values being ignored.
     */
    public static function get_page_eval_global_options() {
        return [
            'global_page_mode' => get_option('ggc_global_page_eval_mode', 'none'),
            // legacy global settings – not used by the page eval renderer
            'global_ua_control' => get_option('ggc_global_user_agent_control', 'none'),
            'global_ip_control' => get_option('ggc_global_ip_evaluation', 'none'),
            // actual page-specific controls
            'global_page_ua_control' => get_option('ggc_global_page_user_agent_control', 'none'),
            'global_page_ip_control' => get_option('ggc_global_page_ip_control', 'none'),
        ];
    }

    /**
     * 管理画面の general 設定取得ラッパー
     */
    public static function get_general_settings() {
        return [
            'ip_update_frequency' => get_option('ggc_ip_update_frequency', 'daily'),
            'global_ua_control' => get_option('ggc_global_user_agent_control', 'none'),
            'global_ip_evaluation' => get_option('ggc_global_ip_evaluation', 'none'),
            'global_media_ua_control' => get_option('ggc_global_media_user_agent_control', 'none'),
            'global_media_ip_evaluation' => get_option('ggc_global_media_ip_evaluation', 'none'),
            'alt_fixed_featured' => get_option('ggc_alt_fixed_text_featured', ''),
            'alt_fixed' => get_option('ggc_alt_fixed_text', ''),
            'markdown_replace_enabled' => get_option('ggc_markdown_replace_enabled', 'off'),
            'markdown_global_template_mode' => get_option('ggc_markdown_global_template_mode', 'select'),
            'markdown_global_template_key' => get_option('ggc_markdown_global_template_key', ''),
            'global_ua_redirect_mode' => get_option('ggc_global_ua_redirect_mode', 'block'),
            'global_ip_redirect_mode' => get_option('ggc_global_ip_redirect_mode', 'block'),
            'global_ua_redirect_url' => get_option('ggc_global_ua_redirect_url', ''),
            'global_ip_redirect_url' => get_option('ggc_global_ip_redirect_url', ''),
            'global_ua_block_message_key' => get_option('ggc_global_ua_block_message_key', ''),
            'global_ip_block_message_key' => get_option('ggc_global_ip_block_message_key', ''),
            'global_ua_block_message' => get_option('ggc_global_ua_block_message', ''),
            'global_ip_block_message' => get_option('ggc_global_ip_block_message', ''),
            'markdown_templates' => self::get_markdown_templates(),
            'global_markdown_selected_crawlers' => get_option('ggc_global_markdown_selected_crawlers', []),
            'global_markdown_selected_ips' => get_option('ggc_global_markdown_selected_ips', []),
            'global_markdown_selected_ips_2' => get_option('ggc_global_markdown_selected_ips_2', []),
            'global_featured_display_mode' => get_option('ggc_global_featured_display_mode', 'normal'),
        ];
    }

    /**
     * メディア向けのグローバルオプション群（既存の get_global_media_options と同等）
     */
    public static function get_global_media_options() {
        return [
            'media_eval_mode' => get_option('ggc_global_media_eval_mode', 'none'),
            'featured_display_mode' => get_option('ggc_global_featured_display_mode', 'normal'),
            'media_ua_control' => get_option('ggc_global_media_ua_control', 'none'),
            'media_selected_crawlers' => get_option('ggc_global_media_selected_crawlers', []) ?: [],
            'media_selected_patterns' => get_option('ggc_global_media_selected_patterns', []) ?: [],
        ];
    }

    /**
     * ブロック/リダイレクト系のグローバル設定
     */
    public static function get_global_block_options($type = 'ua') {
        if ($type === 'ua') {
            return [
                'redirect_mode' => get_option('ggc_global_ua_redirect_mode', 'block'),
                'redirect_url' => get_option('ggc_global_ua_redirect_url', ''),
                'block_message_key' => get_option('ggc_global_ua_block_message_key', ''),
                'block_message' => get_option('ggc_global_ua_block_message', ''),
            ];
        }
        return [
            'redirect_mode' => get_option('ggc_global_ip_redirect_mode', 'block'),
            'redirect_url' => get_option('ggc_global_ip_redirect_url', ''),
            'block_message_key' => get_option('ggc_global_ip_block_message_key', ''),
            'block_message' => get_option('ggc_global_ip_block_message', ''),
        ];
    }

    /**
     * グローバル選択リスト群（各種選択された ID / リスト）
     */
    public static function get_global_selected_lists() {
        return [
            'selected_crawlers' => get_option('ggc_global_selected_crawlers', []) ?: [],
            'selected_patterns' => get_option('ggc_global_selected_patterns', []) ?: [],
            'selected_ips' => get_option('ggc_global_selected_ips', []) ?: [],
            'selected_ips_2' => get_option('ggc_global_selected_ips_2', []) ?: [],
            'markdown_selected_crawlers' => get_option('ggc_global_markdown_selected_crawlers', []) ?: [],
            'markdown_selected_patterns' => get_option('ggc_global_markdown_selected_patterns', []) ?: [],
            'markdown_selected_ips' => get_option('ggc_global_markdown_selected_ips', []) ?: [],
            'markdown_selected_ips_2' => get_option('ggc_global_markdown_selected_ips_2', []) ?: [],
            'media_selected_crawlers' => get_option('ggc_global_media_selected_crawlers', []) ?: [],
            'media_selected_patterns' => get_option('ggc_global_media_selected_patterns', []) ?: [],
            'media_selected_ips' => get_option('ggc_global_media_selected_ips', []) ?: [],
            'media_selected_ips_2' => get_option('ggc_global_media_selected_ips_2', []) ?: [],
        ];
    }

    /**
     * マークダウンのグローバルコンテキスト（既存の get_markdown_global_context と同等）
     */
    public static function get_markdown_global_context() {
        $md = self::get_markdown_options();
        $global_md_mode = $md['replace_enabled'];
        $ua_eval = $md['ua_eval'];
        $ip_eval = $md['ip_eval'];
        $force_global_template = ($global_md_mode === 'all')
            || (
                in_array($global_md_mode, ['all','blacklist','whitelist'], true)
                && ($ua_eval === 'global' && $ip_eval === 'global')
            );
        return [
            'global_md_mode' => $global_md_mode,
            'ua_eval' => $ua_eval,
            'ip_eval' => $ip_eval,
            'force_global_template' => $force_global_template,
        ];
    }

    /**
     * 代替テキスト関連のグローバル設定
     */
    public static function get_alt_mode() {
        $v = get_option('ggc_alt_mode', 'none');
        return is_string($v) ? $v : 'none';
    }

    public static function get_alt_fixed_text() {
        $v = get_option('ggc_alt_fixed_text', '');
        return is_string($v) ? $v : '';
    }

    /**
     * 代替テキスト（アイキャッチ用固定テキスト）
     */
    public static function get_alt_fixed_text_featured() {
        $v = get_option('ggc_alt_fixed_text_featured', '');
        return is_string($v) ? $v : '';
    }

    /**
     * デバッグフラグ（メディア評価）
     */
    public static function get_debug_media_eval() {
        return get_option('ggc_debug_media_eval', false);
    }

    /**
     * メディア表示モード（管理画面のドロップダウン）
     */
    public static function get_global_media_display_mode() {
        $v = get_option('ggc_global_media_display_mode', 'normal');
        return is_string($v) ? $v : 'normal';
    }

    /**
     * アイキャッチ画像表示モード（管理画面のドロップダウン）
     * メディア表示モードとは独立して設定可能。
     * 'normal' | 'alt_replace' | 'hide'
     */
    public static function get_global_featured_display_mode() {
        $v = get_option('ggc_global_featured_display_mode', 'normal');
        return is_string($v) ? $v : 'normal';
    }

    /**
     * グローバル Markdown evaluation mode (legacy key)
     */
    public static function get_global_md_evaluation() {
        $v = get_option('ggc_global_md_evaluation', 'post');
        return is_string($v) ? $v : 'post';
    }

    /**
     * legacy markdown template key
     */
    public static function get_md_template_key() {
        $v = get_option('ggc_md_template_key', 'default');
        return is_string($v) ? $v : 'default';
    }

    /**
     * ページ評価メッセージを正規化して返す（未設定時はデフォルトやクリアフラグを考慮）
     */
    public static function get_page_eval_messages() {
        $msgs = get_option('ggc_page_eval_messages', null);
        if ($msgs === null) {
            $cleared = self::is_clear_all_done();
            return $cleared ? [] : ggc_get_default_page_eval_messages();
        }
        return is_array($msgs) ? $msgs : [];
    }

    /**
     * メディア評価に使うグローバルオプション群を配列で返す。
     * - global_mode: 'none' 等
     * - global_ua_option: 'apply_new_posts' 等
     * - global_ip_option: 'apply_new_posts' 等
     *
     * @return array
     */
    public static function get_media_eval_options() {
        $global_mode = get_option('ggc_global_media_eval_mode', 'none');
        if ($global_mode === '' || $global_mode === null) {
            $global_mode = 'none';
        }

        $global_ua_option = get_option('ggc_global_media_user_agent_control', 'apply_new_posts');
        $global_ip_option = get_option('ggc_global_media_ip_evaluation', 'apply_new_posts');
        if ($global_ua_option === '' || $global_ua_option === null) {
            $global_ua_option = 'apply_new_posts';
        }
        if ($global_ip_option === '' || $global_ip_option === null) {
            $global_ip_option = 'apply_new_posts';
        }

        return [
            'global_mode' => $global_mode,
            'global_ua_option' => $global_ua_option,
            'global_ip_option' => $global_ip_option,
        ];
    }
}
