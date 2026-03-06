<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!class_exists('GGC_Options')) {
    require_once dirname(__DIR__) . '/includes/class-option-utils.php';
}

/**
 * 共通評価ユーティリティ
 *
 * ページ・メディア双方で使用される評価ロジックや
 * 補助関数をまとめておくためのクラス。
 */
class GGC_Eval_Utils {
    /**
     * グローバル/投稿オプションと強制フラグから
     * "全てのメディアに評価を適用すべきか" を判定する。
     */
    public static function compute_apply_to_all_media(
        $global_mode,
        $global_ua_option,
        $global_ip_option,
        $force_global_ua,
        $force_global_ip,
        $media_hide_all
    ) {
        // Decide whether evaluation should apply to all media items.
        // Historically we required $global_mode !== 'none', but users expect
        // UA/IP list selection to enable evaluation. However, if a global
        // list option exists but no actual items are selected (empty
        // blacklist), treat that as inert.

        $apply = false;

        // If forced by flags or hide_all, always apply.
        if ($force_global_ua || $force_global_ip || !empty($media_hide_all)) {
            return true;
        }

        // Check UA global lists only if the global option indicates a list
        // and the corresponding selected lists are non-empty.
        if ($global_ua_option === 'global_whitelist') {
            // empty whitelist effectively excludes everyone -> evaluation applies
            $apply = true;
        } elseif ($global_ua_option === 'global_blacklist') {
            $global_selected = GGC_Options::get_global_selected_lists();
            $media_crawlers = isset($global_selected['media_selected_crawlers']) ? $global_selected['media_selected_crawlers'] : [];
            $media_patterns = isset($global_selected['media_selected_patterns']) ? $global_selected['media_selected_patterns'] : [];
            if (!empty($media_crawlers) || !empty($media_patterns)) {
                $apply = true;
            }
        }

        // Check IP global lists likewise.
        if (!$apply) {
            if ($global_ip_option === 'global_whitelist') {
                // empty whitelist excludes everyone
                $apply = true;
            } elseif ($global_ip_option === 'global_blacklist') {
                $global_selected = isset($global_selected) ? $global_selected : GGC_Options::get_global_selected_lists();
                $media_ips = isset($global_selected['media_selected_ips']) ? $global_selected['media_selected_ips'] : [];
                $media_ips2 = isset($global_selected['media_selected_ips_2']) ? $global_selected['media_selected_ips_2'] : [];
                if (!empty($media_ips) || !empty($media_ips2)) {
                    $apply = true;
                }
            }
        }

        return $apply;
    }

    /**
     * ブロック内から最適な代替テキストを拾う。メディア用。
     */
    public static function compute_block_alt_text($block) {
        // only provide alt text when block mode explicitly requests replace
        $mode = isset($block['attrs']['ggcMediaMode']) ? $block['attrs']['ggcMediaMode'] : 'normal';
        if ($mode === 'hide') {
            return '';
        }
        if ($mode !== 'replace') {
            return '';
        }

        $alt_text = isset($block['attrs']['ggcAltText']) ? trim($block['attrs']['ggcAltText']) : '';

        if (empty($alt_text) && isset($block['attrs']) && is_array($block['attrs']) && isset($block['attrs']['id'])) {
            $preview_alt = Custom_Media_Meta::get_instance()->get_preview_alt_text(intval($block['attrs']['id']));
            if ($preview_alt !== null && $preview_alt !== '') {
                $alt_text = $preview_alt;
            }
        }

        if (empty($alt_text) && $block['blockName'] === 'core/gallery' && isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
            $map = [];
            foreach ($block['attrs']['ids'] as $gid) {
                $preview_alt = Custom_Media_Meta::get_instance()->get_preview_alt_text(intval($gid));
                if ($preview_alt !== null && $preview_alt !== '') {
                    $map[intval($gid)] = $preview_alt;
                }
            }
            if (!empty($map)) {
                $alt_text = wp_json_encode($map);
            }
        }

        // _ggc_block_attrs メタは画像IDキーのため同一画像複数ブロックで last-one-wins になる。
        // ggcAltText はブロックコメントにブロックごとに保存されているため、メタへのフォールバックは使用しない。

        return $alt_text;
    }

    /**
     * グローバル & 投稿オプションに基づいて最終的な制御モードを返す。
     * deny_all/allow_all などもここで扱う。
     */
    public static function resolve_control_mode($global_option, $post_action, $force_global) {
        if ($global_option === '' || $global_option === null) {
            $global_option = 'none';
        }

        // globally mandated explicit states
        if ($global_option === 'deny_all') {
            return 'deny_all';
        }
        if ($global_option === 'allow_all') {
            return 'allow_all';
        }
        if ($global_option === 'none') {
            return 'none';
        }
        // Note: media evaluation may want to treat 'none' differently; use
        // resolve_control_mode_for_media when media-specific semantics are required.
        if ($force_global) {
            // force_global means "ignore post-specific settings and use
            // whatever the global option dictates (converted to runtime mode)".
            switch ($global_option) {
                case 'global_blacklist':
                    return 'blacklist';
                case 'global_whitelist':
                    return 'whitelist';
                case 'deny_all':
                    return 'deny_all';
                case 'allow_all':
                    return 'allow_all';
                    default:
                        // If global option is not a known list/allow/deny value,
                        // prefer a permissive fallback to avoid accidentally
                        // blocking content when globals are nominally enabled
                        // but left unset. Return 'allow_all' rather than
                        // 'blacklist'.
                        return 'allow_all';
            }
        }

        if ($post_action === 'global') {
            // 投稿側が「グローバル設定に従う」場合でも
            // グローバルにブラックリスト/ホワイトリストが設定されていれば適用する。
            // 修正前はここで無条件に allow_all を返していたため、
            // global_blacklist / global_whitelist が完全に無視されていた。
            if ($global_option === 'global_blacklist') {
                return 'blacklist';
            }
            if ($global_option === 'global_whitelist') {
                return 'whitelist';
            }
            return 'allow_all';
        }

        if ($post_action === 'deny_all') {
            return 'deny_all';
        }
        if ($post_action === 'allow_all') {
            return 'allow_all';
        }

        return $post_action;
    }

    /**
     * Media-specific resolver: mirrors resolve_control_mode but does not
     * early-return for global 'none' so post-level settings are honored
     * when media globals are unset.
     */
    public static function resolve_control_mode_for_media($global_option, $post_action, $force_global) {
        if ($global_option === '' || $global_option === null) {
            $global_option = 'none';
        }

        // 仕様: 投稿側の「全拒否」「全許可」はグローバル設定より優先する。
        // これにより、グローバルが deny_all でも投稿側で allow_all を指定すれば
        // メディアは通常表示される。
        if ($post_action === 'deny_all') {
            return 'deny_all';
        }
        if ($post_action === 'allow_all') {
            return 'allow_all';
        }

        if ($global_option === 'deny_all') {
            return 'deny_all';
        }
        if ($global_option === 'allow_all') {
            return 'allow_all';
        }
        if ($force_global) {
            switch ($global_option) {
                case 'global_blacklist':
                    return 'blacklist';
                case 'global_whitelist':
                    return 'whitelist';
                case 'deny_all':
                    return 'deny_all';
                case 'allow_all':
                    return 'allow_all';
                    default:
                        return 'allow_all';
            }
        }

        if ($post_action === 'global') {
            if ($global_option === 'global_blacklist') {
                return 'blacklist';
            }
            if ($global_option === 'global_whitelist') {
                return 'whitelist';
            }
            return 'allow_all';
        }

        return $post_action;
    }

    /**
     * 汎用的なUser-Agent判定ロジック。メディア・ページ双方で使用される。
     *
     * @param string $user_agent
     * @param array  $selected_crawlers      Array of crawler keys selected by user/global.
     * @param array  $selected_pattern_keys  Array of pattern keys selected by user/global.
     * @return bool  true if the UA matches any of the supplied lists
     */
    public static function matches_user_agent($user_agent, $selected_crawlers, $selected_pattern_keys) {
        if (empty($user_agent)) {
            return false;
        }

        $all_bots = Custom_Crawler_Core::get_allowable_bots();
        foreach ($selected_crawlers as $key) {
            if (isset($all_bots[$key]['uas'])) {
                foreach ($all_bots[$key]['uas'] as $pattern) {
                    if ($pattern === '') {
                        continue;
                    }
                    if (strpos($pattern, '^') === 0) {
                        $regex = '/' . substr($pattern, 1) . '/i';
                        if (preg_match($regex, $user_agent)) {
                            return true;
                        }
                    } else {
                        if (stripos($user_agent, $pattern) !== false) {
                            return true;
                        }
                    }
                }
            }
        }

        if (empty($selected_pattern_keys)) {
            return false;
        }

        $all_global_patterns_structured = Custom_Crawler_Core::get_browser_block_patterns();
        foreach ($selected_pattern_keys as $key) {
            if (isset($all_global_patterns_structured[$key]['pattern'])) {
                $patterns = preg_split('/[\r\n,]+/', $all_global_patterns_structured[$key]['pattern']);
                foreach ($patterns as $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern === '') {
                        continue;
                    }
                    if (strpos($pattern, '^') === 0) {
                        $regex = '/' . substr($pattern, 1) . '/i';
                        if (preg_match($regex, $user_agent)) {
                            return true;
                        }
                    } else {
                        if (stripos($user_agent, $pattern) !== false) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * IPアドレスリストが許容範囲内かどうか判定する共通ヘルパ。
     * 空リストは常に false を返す（空ブラックリストはマッチなし）。
     *
     * @param array $selected_ips
     * @return bool
     */
    public static function is_ip_in_ranges($selected_ips) {
        if (empty($selected_ips)) {
            return false;
        }
        return Custom_Crawler_Core::is_in_allowable_ip_range($selected_ips);
    }

    /**
     * 内部汎用ルーチン：投稿とグローバル設定からIPリストを取得する。
     *
     * @param int $post_id
     * @param string $global_ip_option
     * @param bool $force_global_ip
     * @param array $global_option_names [primary, secondary]
     * @param array $post_meta_names   [primary, secondary]
     * @param bool $fallback_defaults  trueの場合、全グローバルリスト空時にデフォルト範囲を返す
     * @return array
     */
    private static function get_selected_ips_for_match(
        $post_id,
        $global_ip_option,
        $force_global_ip,
        array $global_option_names,
        array $post_meta_names,
        $fallback_defaults = false
    ) {
        if (in_array($global_ip_option, ['global_blacklist','global_whitelist'], true) || $force_global_ip) {
            $global_selected = GGC_Options::get_global_selected_lists();
            // map option name to helper keys
            $primary = $global_option_names[0] ?? '';
            $secondary = $global_option_names[1] ?? '';
            $map = [
                'ggc_global_selected_ips' => 'selected_ips',
                'ggc_global_selected_ips_2' => 'selected_ips_2',
                'ggc_global_media_selected_ips' => 'media_selected_ips',
                'ggc_global_media_selected_ips_2' => 'media_selected_ips_2',
                'ggc_global_markdown_selected_ips' => 'markdown_selected_ips',
                'ggc_global_markdown_selected_ips_2' => 'markdown_selected_ips_2',
            ];
            $selected_ips = [];
            $selected_ips_2 = [];
            if (!empty($primary) && isset($map[$primary]) && isset($global_selected[$map[$primary]])) {
                $selected_ips = $global_selected[$map[$primary]] ?: [];
            }
            if (!empty($secondary) && isset($map[$secondary]) && isset($global_selected[$map[$secondary]])) {
                $selected_ips_2 = $global_selected[$map[$secondary]] ?: [];
            }
            if ($fallback_defaults && empty($selected_ips) && empty($selected_ips_2)) {
                $defaults = ggc_get_default_ip_ranges();
                $selected_ips = array_keys($defaults);
            }
        } else {
            $selected_ips = get_post_meta($post_id, $post_meta_names[0], true) ?: [];
            $selected_ips_2 = get_post_meta($post_id, $post_meta_names[1], true) ?: [];
        }
        return array_merge($selected_ips, $selected_ips_2);
    }

    /**
     * メディア評価向けIPリスト取得ラッパー。
     */
    public static function get_media_selected_ips_for_match($post_id, $global_ip_option, $force_global_ip) {
        return self::get_selected_ips_for_match(
            $post_id,
            $global_ip_option,
            $force_global_ip,
            ['ggc_global_media_selected_ips', 'ggc_global_media_selected_ips_2'],
            ['_ggc_selected_media_ips', '_ggc_selected_media_ips_2'],
            false
        );
    }

    /**
     * ページ評価向けIPリスト取得ラッパー (デフォルト範囲フォールバック付き)。
     */
    public static function get_page_selected_ips_for_match($post_id, $global_ip_option, $force_global_ip) {
        return self::get_selected_ips_for_match(
            $post_id,
            $global_ip_option,
            $force_global_ip,
            ['ggc_global_selected_ips', 'ggc_global_selected_ips_2'],
            ['_ggc_selected_ips', '_ggc_selected_ips_2'],
            true
        );
    }

    /**
     * 共通: UAクローラー/パターン選択リスト取得ルーチン。
     *
     * @param int $post_id
     * @param string $global_option
     * @param bool $force_global
     * @param array $global_option_names  [crawlers, patterns]
     * @param array $post_meta_names      [crawlers, patterns]
     * @param bool $fallback_default_bots  クロールリストが空のときデフォルトbotへフォールバックするか
     * @return array
     */
    private static function get_selected_ua_for_match(
        $post_id,
        $global_option,
        $force_global,
        array $global_option_names,
        array $post_meta_names,
        $fallback_default_bots = false
    ) {
        if (in_array($global_option, ['global_blacklist','global_whitelist'], true) || $force_global) {
            $global_selected = GGC_Options::get_global_selected_lists();
            $primary = $global_option_names[0] ?? '';
            $map = [
                'ggc_global_selected_crawlers' => 'selected_crawlers',
                'ggc_global_selected_patterns' => 'selected_patterns',
                'ggc_global_media_selected_crawlers' => 'media_selected_crawlers',
                'ggc_global_media_selected_patterns' => 'media_selected_patterns',
                'ggc_global_markdown_selected_crawlers' => 'markdown_selected_crawlers',
                'ggc_global_markdown_selected_patterns' => 'markdown_selected_patterns',
            ];
            $list = [];
            if (!empty($primary) && isset($map[$primary]) && isset($global_selected[$map[$primary]])) {
                $list = $global_selected[$map[$primary]] ?: [];
            }
            if ($fallback_default_bots && empty($list)) {
                $list = array_keys(ggc_get_default_bots());
            }
            return $list;
        }

        $primary_meta = isset($post_meta_names[0]) ? $post_meta_names[0] : '';
        $secondary_meta = isset($post_meta_names[1]) ? $post_meta_names[1] : '';
        $list = [];
        if ($primary_meta !== '') {
            $list = get_post_meta($post_id, $primary_meta, true) ?: [];
        }
        if (empty($list) && $secondary_meta !== '') {
            $list = get_post_meta($post_id, $secondary_meta, true) ?: [];
        }
        return $list ?: [];
    }

    /**
     * メディア評価向けクローラー選択リスト取得。
     */
    public static function get_media_selected_crawlers_for_match($post_id, $global_option, $force_global) {
        return self::get_selected_ua_for_match(
            $post_id,
            $global_option,
            $force_global,
            ['ggc_global_media_selected_crawlers', ''],
            ['_ggc_selected_media_crawlers', '_ggc_selected_crawlers'],
            false
        );
    }

    /**
     * ページ評価向けクローラー選択リスト取得。
     */
    public static function get_page_selected_crawlers_for_match($post_id, $global_option, $force_global) {
        return self::get_selected_ua_for_match(
            $post_id,
            $global_option,
            $force_global,
            ['ggc_global_selected_crawlers', ''],
            ['_ggc_selected_crawlers', ''],
            true
        );
    }

    /**
     * メディア評価向けパターンキー選択リスト取得。
     */
    public static function get_media_selected_patterns_for_match($post_id, $global_option, $force_global) {
        return self::get_selected_ua_for_match(
            $post_id,
            $global_option,
            $force_global,
            ['ggc_global_media_selected_patterns', ''],
            ['_ggc_selected_media_page_browser_patterns', '_ggc_selected_page_browser_patterns'],
            false
        );
    }

    /**
     * ページ評価向けパターンキー選択リスト取得。
     */
    public static function get_page_selected_patterns_for_match($post_id, $global_option, $force_global) {
        return self::get_selected_ua_for_match(
            $post_id,
            $global_option,
            $force_global,
            ['ggc_global_selected_patterns', ''],
            ['_ggc_selected_page_browser_patterns', ''],
            false
        );
    }

    /**
     * ページ評価用のコンテキストを返す。
     * get_page_eval_context と同じロジックを提供し、他クラスからも利用可能にする。
     * @param int $post_id
     * @return array
     */
    public static function get_page_eval_context($post_id) {
        $post_ua_mode = get_post_meta($post_id, '_ggc_ua_control_mode', true);
        $post_ip_mode = get_post_meta($post_id, '_ggc_ip_control_mode', true);

        $opts = GGC_Options::get_page_eval_options();
        $global_ua_option = $opts['global_ua_option'];
        $global_ip_option = $opts['global_ip_option'];
        $global_page_mode = $opts['global_page_mode'];

        if ($global_page_mode === 'all') {
            // 全ページ強制：グローバル設定をすべての投稿に適用
            $post_ua_mode    = 'global';
            $post_ip_mode    = 'global';
            $force_global_ua = true;
            $force_global_ip = true;

        } elseif ($global_page_mode === 'apply_new_posts') {
            // 投稿・固定ページ個別設定：グローバル設定は完全に無効化し
            // 投稿ごとの設定のみを使用する。
            $global_ua_option = 'none';
            $global_ip_option = 'none';
            $force_global_ua  = false;
            $force_global_ip  = false;
            // 投稿側の設定がない（未設定または'global'）場合は評価しない
            if (empty($post_ua_mode) || $post_ua_mode === 'global') {
                $post_ua_mode = 'allow_all';
            }
            if (empty($post_ip_mode) || $post_ip_mode === 'global') {
                $post_ip_mode = 'allow_all';
            }

        } else {
            // none: グローバル評価なし
            $force_global_ua = ($post_ua_mode === 'global');
            $force_global_ip = ($post_ip_mode === 'global');
        }

        return [
            'global_ua_option' => $global_ua_option,
            'global_ip_option' => $global_ip_option,
            'post_ua_mode'     => $post_ua_mode,
            'post_ip_mode'     => $post_ip_mode,
            'force_global_ua'  => $force_global_ua,
            'force_global_ip'  => $force_global_ip,
        ];
    }

    /**
     * メディア評価用コンテキスト。
     */
    public static function get_media_eval_context($post_id) {
        $opts        = GGC_Options::get_media_eval_options();
        $global_mode = $opts['global_mode'];

        $global_ua_option = $opts['global_ua_option'];
        $global_ip_option = $opts['global_ip_option'];
        $post_ua_meta = get_post_meta($post_id, '_ggc_media_ua_action', true) ?: 'global';
        $post_ip_meta = get_post_meta($post_id, '_ggc_media_ip_action', true) ?: 'global';

        // グローバル設定「無効 (none)」はメディア評価の完全停止を意味する。
        // UA/IP の設定値や過去の投稿メタが残っていても、このモードでは
        // 置換・非表示を行わないため、評価コンテキストを明示的に無効化する。
        if ($global_mode === 'none') {
            return [
                'global_ua_option' => 'none',
                'global_ip_option' => 'none',
                'post_ua_meta'     => 'global',
                'post_ip_meta'     => 'global',
                'force_global_ua'  => false,
                'force_global_ip'  => false,
            ];
        }

        if ($global_mode === 'all') {
            // 全ページ強制モード: グローバル設定を全投稿に常に強制適用する。
            // per-post の UA/IP メタは無視し、グローバルリストのみを使用する。
            // post_ua_meta / post_ip_meta を 'global' に固定することで
            // resolve_control_mode_for_media の deny_all/allow_all early-return を
            // 誤って発動させず、force_global のスイッチに正しく到達させる。
            $force_global_ua = true;
            $force_global_ip = true;
            $post_ua_meta    = 'global';
            $post_ip_meta    = 'global';

        } elseif ($global_mode === 'apply_new_posts') {
            // 投稿・固定ページ個別設定：グローバル強制フラグは無効。
            // 投稿側のUA/IP評価が「設定しない（'global'）」の場合は評価しない（allow_all）。
            // 投稿側に明示設定（blacklist/whitelist等）がある場合は投稿ごとのリストを使用する。
            // global_ua_option / global_ip_option を 'none' に上書きすることで
            // get_selected_*_for_match が投稿メタ（per-post）のリストを参照するようにする。
            // ※ グローバルリスト設定値は resolve_control_mode_for_media には不要（post_action を直接返すため）。
            $force_global_ua = false;
            $force_global_ip = false;
            $global_ua_option = 'none';
            $global_ip_option = 'none';
            if (empty($post_ua_meta) || $post_ua_meta === 'global') {
                $post_ua_meta = 'allow_all';
            }
            if (empty($post_ip_meta) || $post_ip_meta === 'global') {
                $post_ip_meta = 'allow_all';
            }

        } else {
            $force_global_ua = ($post_ua_meta === 'global');
            $force_global_ip = ($post_ip_meta === 'global');
        }

        return [
            'global_ua_option' => $global_ua_option,
            'global_ip_option' => $global_ip_option,
            'post_ua_meta'     => $post_ua_meta,
            'post_ip_meta'     => $post_ip_meta,
            'force_global_ua'  => $force_global_ua,
            'force_global_ip'  => $force_global_ip,
        ];
    }

    /**
     * UA/IP評価に基づいてメディアを変更すべきかどうかを判定する。
     *
     * 仕様:
     *   - 設定しない / 全許可 → 通常表示する (false)
     *   - 全拒否 → 常に変更する (true)
     *   - ブラックリスト → マッチした場合のみ変更 (true)
     *   - ホワイトリスト → マッチしなかった場合のみ変更 (true)
     *
     * @param int $post_id
     * @return bool true=メディアを変更すべき、false=通常表示すべき
     */
    public static function should_modify_media_by_eval($post_id) {
        $ctx = self::get_media_eval_context($post_id);
        $ua_action = self::resolve_control_mode_for_media(
            $ctx['global_ua_option'], $ctx['post_ua_meta'], $ctx['force_global_ua']
        );
        $ip_action = self::resolve_control_mode_for_media(
            $ctx['global_ip_option'], $ctx['post_ip_meta'], $ctx['force_global_ip']
        );

        // deny_all → 常に変更
        if ($ua_action === 'deny_all' || $ip_action === 'deny_all') {
            return true;
        }

        // 両方とも allow_all/none → 通常表示（変更しない）
        if (in_array($ua_action, ['allow_all', 'none'], true)
            && in_array($ip_action, ['allow_all', 'none'], true)) {
            return false;
        }

        // blacklist/whitelist → 実際のマッチ判定
        $user_agent = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        if ($ua_action === 'blacklist' || $ua_action === 'whitelist') {
            $crawlers = self::get_media_selected_crawlers_for_match(
                $post_id, $ctx['global_ua_option'], $ctx['force_global_ua']
            );
            $patterns = self::get_media_selected_patterns_for_match(
                $post_id, $ctx['global_ua_option'], $ctx['force_global_ua']
            );
            $ua_list_nonempty = (!empty($crawlers) || !empty($patterns));
            // 空のブラックリストは無害（マッチしない）
            if ($ua_action === 'blacklist' && !$ua_list_nonempty) {
                // skip
            } else {
                $ua_match = self::matches_user_agent($user_agent, $crawlers, $patterns);
                if ($ua_action === 'blacklist' && $ua_match) return true;
                if ($ua_action === 'whitelist' && !$ua_match) return true;
            }
        }

        if ($ip_action === 'blacklist' || $ip_action === 'whitelist') {
            $ips = self::get_media_selected_ips_for_match(
                $post_id, $ctx['global_ip_option'], $ctx['force_global_ip']
            );
            $ip_list_nonempty = !empty($ips);
            if ($ip_action === 'blacklist' && !$ip_list_nonempty) {
                // skip
            } else {
                $ip_match = self::is_ip_in_ranges($ips);
                if ($ip_action === 'blacklist' && $ip_match) return true;
                if ($ip_action === 'whitelist' && !$ip_match) return true;
            }
        }

        return false;
    }
}

