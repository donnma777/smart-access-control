<?php
// custom-crawler-control\includes\class-crawler-core.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Expose a global instance for procedural tests that expect `$core`.
$instance_core = Custom_Crawler_Core::get_instance();
$GLOBALS['core'] = $instance_core;

class Custom_Crawler_Core {

    protected static $instance = null;
    // HTTP fetch details for last update run
    private $last_update_fetch_details = [];

    private function __construct() {
        // the constructor is called on every request, so the log line can be
        // very noisy. only emit it when the caller explicitly opts in via a
        // filter. default is false to keep production logs clean.
        if ( apply_filters('ggc_log_instantiation', false) ) {
            static $logged = false;
            if ( ! $logged ) {
                ggc_debug_log('Custom_Crawler_Core instantiated');
                $logged = true;
            }
        }

        // Cron スケジュールフック
        add_filter('cron_schedules', [ $this, 'custom_cron_schedules' ]);
        // Cron イベントフック
        add_action('ggc_daily_ip_update', [ $this, 'update_all_ip_ranges' ]);
        // アクセス制御実行フック
        add_action('template_redirect', [ $this, 'perform_blocking' ]);
        // マークダウン原文表示（ヘッダー/フッターなし）
        add_action('template_redirect', [ $this, 'maybe_render_raw_markdown_page' ], 20);
        add_action('update_option_ggc_global_page_eval_mode', [ $this, 'maybe_migrate_page_eval_mode' ], 10, 3);
        $global_list_options = [
            'ggc_global_selected_crawlers',
            'ggc_global_selected_patterns',
            'ggc_global_selected_ips',
            'ggc_global_selected_ips_2',
            'ggc_global_page_user_agent_control',
            'ggc_global_page_ip_control',
        ];
        foreach ($global_list_options as $opt) {
            add_action('update_option_' . $opt, [ $this, 'maybe_clear_lists_on_global_change' ], 10, 3);
        }
        // one‑time initializer for migrations
        add_action('init', [ $this, 'maybe_run_pending_page_eval_migration' ]);
        // マークダウン置換
        add_filter('the_content', [ $this, 'maybe_replace_markdown_content' ], 5);
        // マークダウン置換時のタイトル変更
        add_filter('pre_get_document_title', [ $this, 'maybe_override_markdown_title' ], 5);
        // マークダウン置換時のタイトル変更（テーマ互換）
        add_filter('document_title_parts', [ $this, 'maybe_override_markdown_title_parts' ], 5);
        add_filter('the_title', [ $this, 'maybe_override_markdown_the_title' ], 5, 2);
        // マークダウン置換時のアイキャッチ画像変更
        add_filter('post_thumbnail_id', [ $this, 'maybe_override_markdown_thumbnail_id' ], 5, 2);
        add_filter('has_post_thumbnail', [ $this, 'maybe_override_markdown_has_thumbnail' ], 5, 3);
        add_filter('post_thumbnail_html', [ $this, 'maybe_override_markdown_thumbnail_html' ], 5, 5);
    }

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // --------------------------------------------------------
    // A. ヘルパー関数 (静的メソッドとして定義)
    // --------------------------------------------------------

    public static function get_allowable_bots() {
        $bots = get_option('ggc_crawler_definitions', null);
        if ($bots === null) {
            $cleared = get_option('ggc_clear_all_done', '0') === '1';
            return $cleared ? [] : ggc_get_default_bots();
        }
        return is_array($bots) ? $bots : [];
    }

    public static function get_allowable_ip_ranges() {
        $ip_ranges_1 = get_option('ggc_ip_range_definitions', null);
        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', null);

        $cleared = get_option('ggc_clear_all_done', '0') === '1';
        $ip_ranges_1 = ($ip_ranges_1 === null) ? ($cleared ? [] : ggc_get_default_ip_ranges()) : (is_array($ip_ranges_1) ? $ip_ranges_1 : []);
        $ip_ranges_2 = ($ip_ranges_2 === null) ? ($cleared ? [] : ggc_get_default_ip_ranges_2()) : (is_array($ip_ranges_2) ? $ip_ranges_2 : []);

        return array_merge($ip_ranges_1, $ip_ranges_2);
    }

    public static function get_browser_block_patterns() {
        $patterns = get_option('ggc_browser_block_patterns', null);
        if ($patterns === null) {
            $cleared = get_option('ggc_clear_all_done', '0') === '1';
            return $cleared ? [] : ggc_get_default_browser_patterns();
        }
        return is_array($patterns) ? $patterns : [];
    }

    public static function get_default_control_active() {
        return get_option('ggc_default_control_active', 'no');
    }

    private static function get_client_ip() {
        // セキュリティ上の理由から、デフォルトでは REMOTE_ADDR のみを信頼します。
        // HTTP_CLIENT_IP や HTTP_X_FORWARDED_FOR はリクエストヘッダーであり、
        // 攻撃者が自由に偽装できるため、IPホワイトリスト/ブラックリストの
        // バイパスに悪用される可能性があります。
        //
        // ロードバランサーやリバースプロキシ環境でクライアントの実IPを
        // 取得する必要がある場合は、wp-config.php に以下を定義してください:
        //   define( 'GGC_TRUSTED_PROXY', true );
        //
        // この設定を有効にすると X-Forwarded-For ヘッダーの末尾IPを使用します。
        // 信頼できるプロキシ経由のリクエストのみを受け付ける環境でのみ
        // 有効化してください。

        $remote_addr = isset($_SERVER['REMOTE_ADDR'])
            ? trim( wp_strip_all_tags( wp_unslash($_SERVER['REMOTE_ADDR']) ) )
            : '';

        // 信頼できるプロキシモードが有効な場合のみ転送ヘッダーを参照する
        if ( defined('GGC_TRUSTED_PROXY') && GGC_TRUSTED_PROXY ) {
            $forwarded_for_headers = [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'HTTP_CLIENT_IP',
            ];
            foreach ( $forwarded_for_headers as $key ) {
                if ( ! empty($_SERVER[$key]) ) {
                    $ip_list = wp_strip_all_tags( wp_unslash($_SERVER[$key]) );
                    // カンマ区切りリストの末尾（最後に追記したプロキシの直前のIP）を使用
                    $parts = array_map('trim', explode(',', $ip_list));
                    // 末尾から最初に有効なIPを探す
                    foreach ( array_reverse($parts) as $candidate ) {
                        if ( filter_var($candidate, FILTER_VALIDATE_IP,
                                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ) {
                            return $candidate;
                        }
                    }
                }
            }
        }

        // デフォルト: REMOTE_ADDR のみ信頼
        if ( filter_var($remote_addr, FILTER_VALIDATE_IP) ) {
            return $remote_addr;
        }

        return '';
    }

    public static function ip_in_cidr($ip, $cidr) {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($subnet, $mask) = explode('/', $cidr);

        if (strpos($ip, ':') !== false) {
            // IPv6
            if (strpos($subnet, ':') === false) return false;

            $ip_packed = @inet_pton($ip);
            $subnet_packed = @inet_pton($subnet);

            if ($ip_packed === false || $subnet_packed === false) {
                return false;
            }

            $mask_bits = (int) $mask;
            $ip_len = strlen($ip_packed);

            $bytes = (int) ($mask_bits / 8);
            $bits = $mask_bits % 8;

            $mask_packed = str_repeat(chr(0xff), $bytes);
            if ($bits > 0) {
                $mask_packed .= chr((0xff << (8 - $bits)) & 0xff);
            }
            $mask_packed = str_pad($mask_packed, $ip_len, chr(0x00));

            $ip_net = $ip_packed & $mask_packed;
            $subnet_net = $subnet_packed & $mask_packed;

            return $ip_net === $subnet_net;

        } else {
            // IPv4
            $ip_long = @ip2long($ip);
            $subnet_long = @ip2long($subnet);

            if ($ip_long === false || $subnet_long === false) return false;

            $mask_long = -1 << (32 - (int)$mask);
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }
    }

    public static function is_in_allowable_ip_range($selected_ip_keys) {
        $client_ip = self::get_client_ip();

        if (empty($client_ip)) {

            return false;
        }

        $all_ip_ranges = self::get_allowable_ip_ranges();

        // キーの整合性を担保
        // If the supplied list looks like raw IPs/CIDRs (e.g. '1.2.3.4' or '1.2.3.0/24'),
        // treat them as CIDR entries and match directly against the client IP.
        $selected_list = (array) $selected_ip_keys;
        $looks_like_cidr = false;
        foreach ($selected_list as $entry) {
            if (!is_string($entry)) continue;
            if (strpos($entry, '/') !== false || filter_var($entry, FILTER_VALIDATE_IP)) {
                $looks_like_cidr = true;
                break;
            }
        }

        if ($looks_like_cidr) {
            foreach ($selected_list as $cidr) {
                if (!is_string($cidr) || $cidr === '') continue;
                if (self::ip_in_cidr($client_ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }

        // Otherwise treat the entries as keys into the configured ranges
        $valid_keys = array_keys($all_ip_ranges);
        $selected_ip_keys = array_filter((array)$selected_ip_keys, function($k) use ($valid_keys) {
            return in_array($k, $valid_keys, true);
        });

        foreach ($selected_ip_keys as $key) {
            $ranges_to_check = $all_ip_ranges[$key]['validated_ranges'] ?? $all_ip_ranges[$key]['ranges'] ?? null;

            if (is_array($ranges_to_check)) {
                foreach ($ranges_to_check as $cidr) {
                    if (self::ip_in_cidr($client_ip, $cidr)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function die_forbidden($message, $status_code = 403) {
        $title = 'アクセス禁止';
        $status_code = intval($status_code);
        if ($status_code < 400 || $status_code > 599) {
            $status_code = 403;
        }

        // transport code normally matches the requested status; proxies or
        // caches are expected to handle it appropriately.
        $transport_code = $status_code;

        // make sure WP and PHP know about the status early so proxies respect it
        status_header($transport_code);
        http_response_code($transport_code);
        // also send explicit HTTP status line in case headers are rewritten later
        header(sprintf('HTTP/1.1 %d %s', $transport_code, get_status_header_desc($transport_code)), true, $transport_code);

        ggc_debug_log('die_forbidden headers before output: ' . implode('; ', headers_list()));
        ggc_debug_log('die_forbidden headers_sent? ' . (headers_sent() ? 'yes' : 'no'));
        ggc_debug_log("die_forbidden original={$status_code} transport={$transport_code}");

        if (defined('REST_REQUEST') && REST_REQUEST) {
            wp_send_json_error(['code' => 'crawler_forbidden', 'message' => $message], $status_code);
            // wp_send_json_error exits internally
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            wp_send_json_error(['code' => 'crawler_forbidden', 'message' => $message], $status_code);
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false || stripos(wp_unslash($accept), 'text/json') !== false) {
            header('Content-Type: application/json', true, $status_code);
            echo json_encode(['code' => 'crawler_forbidden', 'message' => $message]);
            ggc_debug_log("die_forbidden json response code={$status_code}");
            exit;
        }

        ggc_debug_log("die_forbidden html response code={$status_code}");

        wp_die($message, $title, ['response' => $status_code]);
    }


    // --------------------------------------------------------
    // B. CronとIPアドレス更新
    // --------------------------------------------------------

    public function custom_cron_schedules($schedules) {
        if (!isset($schedules['hourly'])) {
            $schedules['hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly'];
        }
        if (!isset($schedules['twicedaily'])) {
            $schedules['twicedaily'] = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily'];
        }
        if (!isset($schedules['daily'])) {
            $schedules['daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'];
        }
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Once Monthly']; // Approximately 30 days
        }
        if (!isset($schedules['biannually'])) {
            $schedules['biannually'] = ['interval' => 6 * 30 * DAY_IN_SECONDS, 'display' => 'Once Biannually']; // Approximately 6 months

        }
        if (!isset($schedules['annually'])) {
            $schedules['annually'] = ['interval' => 365 * DAY_IN_SECONDS, 'display' => 'Once Annually']; // Approximately 365 days
        }
        return $schedules;
    }

    /**
     * すべての自動更新対象IPアドレス範囲を更新する
     */
    public function update_all_ip_ranges() {
        $res = $this->run_update_with_result();
        return !empty($res['success']);
    }

    /**
     * IP更新を実行し、結果の構造化データを返す (AJAX/Cron兼用)
     * 登録されている全てのIP定義のうち「is_auto」が有効なものを動的に更新する
     */
    public function run_update_with_result() {
        // Process Group 1
        $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
        $ip_ranges_1 = is_array($ip_ranges_1) ? $ip_ranges_1 : [];

        // Process Group 2
        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);
        $ip_ranges_2 = is_array($ip_ranges_2) ? $ip_ranges_2 : [];

        $this->last_update_fetch_details = [];
        $results_list = [];
        $has_success = false;

        // Helper function to process a list of ranges
        $process_ranges = function(&$ranges) use (&$results_list, &$has_success) {
            foreach ($ranges as $key => $def) {
                if (empty($def['is_auto']) || empty($def['source_url'])) {
                    continue; // Skip if not auto-update.
                }

                $log_key = strtolower($key);
                $log_entry = [];
                $new_ips = $this->process_ip_range_update($def['source_url'], $log_entry);

                $this->last_update_fetch_details[$log_key] = $log_entry;

                if ($new_ips !== false) {
                    // Update the corresponding entry
                    $ranges[$key]['ranges'] = $new_ips;
                    $ranges[$key]['validated_ranges'] = $new_ips;
                    $ranges[$key]['last_parse_error'] = null;
                    $ranges[$key]['last_parse_time'] = time();
                    $ranges[$key]['last_parse_count'] = count($new_ips);

                    $results_list[] = ['key' => $key, 'label' => $def['label'] ?? $key, 'count' => count($new_ips)];
                    $has_success = true;
                } else {
                    // Even on failure, update the status
                    $ranges[$key]['last_parse_error'] = $log_entry['error'] ?? 'Unknown error';
                    $ranges[$key]['last_parse_time'] = time();
                    $ranges[$key]['last_parse_count'] = 0;

                    $results_list[] = ['key' => $key, 'label' => $def['label'] ?? $key, 'count' => false];
                }
            }
        };

        // Process both groups
        $process_ranges($ip_ranges_1);
        $process_ranges($ip_ranges_2);

        // Save results regardless of success to persist error messages and last update time
        update_option('ggc_ip_range_definitions', $ip_ranges_1);
        update_option('ggc_ip_range_definitions_2', $ip_ranges_2);


        $result = [
            'time'    => time(),
            'results' => $results_list,
            'success' => $has_success,
            'details' => $this->last_update_fetch_details,
        ];

        if (isset($this->last_update_fetch_details['google_ip_range'])) {
            $result['details']['google'] = $this->last_update_fetch_details['google_ip_range'];
        }
        if (isset($this->last_update_fetch_details['gptbot_ip_range'])) {
            $result['details']['openai'] = $this->last_update_fetch_details['gptbot_ip_range'];
        }

        update_option('ggc_last_ip_update_time', $result['time']);
        update_option('ggc_last_ip_update_result', $result);

        return $result;
    }

    /**
     * 共通化されたIP範囲更新処理
     * 指定されたキーのIP範囲を source_url から取得して更新する
     * * @param string $config_key DB上の定義キー (例: Google_IP_Range)
     * @param string $log_key    ログ/詳細情報用のキー
     * @param string $url        取得元URL
     * @return int|false 更新されたIP数, 失敗時はfalse
     */
    private function process_ip_range_update($url, &$log_entry) {
        $parsed = self::parse_ip_list_from_url($url);

        if (is_wp_error($parsed)) {
            $log_entry = [
                'status' => null,
                'error'  => $parsed->get_error_message(),
                'url'    => $url,
            ];
            return false;
        }

        $log_entry = [
            'status' => 200,
            'error'  => null,
            'url'    => $url,
        ];

        return array_values(array_unique($parsed));
    }

    /**
     * 指定されたURLからIP/CIDRのリストを取得して返す
     */
    public static function parse_ip_list_from_url($url) {
        if (empty($url)) {
            return new WP_Error('invalid_url', 'URLが空です');
        }

        $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('http_error', 'HTTP ' . intval($status));
        }

        $body = wp_remote_retrieve_body($response);
        $results = [];

        // Try JSON prefixes style (Google/OpenAI/AWS style)
        $data = json_decode($body, true);
        if (is_array($data)) {
            if (isset($data['prefixes']) && is_array($data['prefixes'])) {
                foreach ($data['prefixes'] as $p) {
                    if (isset($p['ipv4Prefix'])) $results[] = sanitize_text_field($p['ipv4Prefix']);
                    if (isset($p['ipv6Prefix'])) $results[] = sanitize_text_field($p['ipv6Prefix']);
                    if (isset($p['ip_prefix'])) $results[] = sanitize_text_field($p['ip_prefix']);
                    if (isset($p['ipv6_prefix'])) $results[] = sanitize_text_field($p['ipv6_prefix']);
                    if (isset($p['ipv4_prefix'])) $results[] = sanitize_text_field($p['ipv4_prefix']);
                }
            }
            if (empty($results)) {
                $flat = [];
                array_walk_recursive($data, function($v) use (&$flat){ if (is_string($v)) $flat[] = $v; });
                $body = implode("\n", $flat);
            }
        }

        // Generic regex extraction
        if (empty($results)) {
            if (preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}(?:\/\d{1,2})?\b/', $body, $m)) {
                foreach ($m[0] as $ip) $results[] = sanitize_text_field($ip);
            }
            if (preg_match_all('/\b[0-9a-fA-F:]{3,}(?:\/\d{1,3})?\b/', $body, $m2)) {
                foreach ($m2[0] as $ip) $results[] = sanitize_text_field($ip);
            }
        }

        $results = array_values(array_unique(array_filter($results)));

        // Validate
        $validated = array_values(array_filter($results, function($r){ return self::is_valid_ip_or_cidr($r); }));

        if (empty($validated) && !empty($results)) {
            $with_mask = array_values(array_filter($results, function($r){ return strpos($r, '/') !== false && self::is_valid_ip_or_cidr($r); }));
            if (!empty($with_mask)) {
                $validated = $with_mask;
            } else {
                if (count($results) >= 5) {
                    $validated = $results;
                }
            }
        }

        if (empty($validated)) {
            return new WP_Error('no_ips', '有効なIP/CIDRが見つかりませんでした');
        }

        return $validated;
    }

    public static function normalize_parse_error_message($err) {
        $msg = '';
        if (is_wp_error($err)) {
            $msgs = $err->get_error_messages();
            $msg = reset($msgs) ?: '';
        } elseif (is_string($err)) {
            $msg = $err;
        }
        $msg = trim(strip_tags($msg));

        if (stripos($msg, 'Could not resolve host') !== false) {
            return 'DNS解決エラー: ホストが見つかりません';
        }
        if (preg_match('/cURL error (\d+):/i', $msg, $m)) {
            return 'ネットワークエラー (cURL ' . intval($m[1]) . ')';
        }
        if (preg_match('/^HTTP\s*(\d{3})/i', $msg, $m2)) {
            return 'HTTPエラー: ' . $m2[1];
        }
        if (mb_strlen($msg) > 200) $msg = mb_substr($msg, 0, 200) . '...';
        return $msg;
    }

    public static function is_valid_ip_or_cidr($range) {
        $range = trim($range);
        if (empty($range)) return false;

        if (filter_var($range, FILTER_VALIDATE_IP)) return true;

        if (strpos($range, '/') !== false) {
            list($ip, $mask) = explode('/', $range, 2);
            $mask = intval($mask);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) return true;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) return true;
            if (strpos($ip, ':') !== false && preg_match('/^[0-9a-fA-F:]+$/', $ip) && $mask >= 0 && $mask <= 128) return true;
            if (strpos($ip, '.') !== false && preg_match('/^[0-9.]+$/', $ip) && $mask >= 0 && $mask <= 32) return true;
        }

        return false;
    }


    // --------------------------------------------------------
    // C. プラグイン有効化/無効化時の処理
    // --------------------------------------------------------

    public static function activate_plugin() {
        if (get_option('ggc_crawler_definitions') === false) {
            update_option('ggc_crawler_definitions', []);
        }
        if (get_option('ggc_ip_range_definitions') === false) {
            update_option('ggc_ip_range_definitions', []);
        }
        if (get_option('ggc_browser_block_patterns') === false) {
            update_option('ggc_browser_block_patterns', []);
        }
        if (get_option('ggc_default_control_active') === false) {
             update_option('ggc_default_control_active', 'no');
        }
        if (get_option('ggc_ip_update_frequency') === false) {
             update_option('ggc_ip_update_frequency', 'daily');
        }

        // ------------------------------------------------------------------
        // ページ評価メッセージはインストール直後に自動で読み込まれないよう
        // 初期化時点で空配列を保存し、フラグを立ててデフォルト復元を抑止する。
        // ユーザーが明示的にメッセージを追加した場合のみ配列が変更され
        // デフォルトの挙動（ggc_get_default_page_eval_messages）が適用される。
        // ------------------------------------------------------------------
        if (get_option('ggc_page_eval_messages') === false) {
            update_option('ggc_page_eval_messages', []);
            update_option('ggc_clear_all_done', '1');
        }
    }

    public static function ip_update_schedule_check() {

        // cron_schedules を確実に登録
        self::get_instance();

        $frequency = get_option('ggc_ip_update_frequency', 'daily');
        $hook = 'ggc_daily_ip_update';

        // ① 停止
        if ($frequency === 'disabled') {
            wp_clear_scheduled_hook($hook);
            return;
        }

        // ② 念のため既存Cronを削除
        wp_clear_scheduled_hook($hook);

        // ③ 登録（★必ず UTC 基準）
        wp_schedule_event(
            time(),       // ← これが最重要
            $frequency,   // hourly / twicedaily / daily
            $hook
        );
    }



    public static function ggc_activation_hooks() {
        // Ensure the instance is created so the constructor runs and registers cron schedules
        self::get_instance();
        self::activate_plugin();
        self::ip_update_schedule_check();
        self::get_instance()->update_all_ip_ranges();
    }

    public static function ggc_deactivation_hooks() {
        wp_clear_scheduled_hook('ggc_daily_ip_update');
    }

    // --------------------------------------------------------
    // D. アクセス制御の実行
    // --------------------------------------------------------

    public function maybe_replace_markdown_content($content) {

        if (is_admin()) { return $content; }
        if (defined('REST_REQUEST') && REST_REQUEST) { return $content; }
        if (defined('WP_CLI') && WP_CLI) { return $content; }
        if (is_feed()) { return $content; }
        if (!is_singular(['post', 'page'])) { return $content; }
        $post_id = get_queried_object_id();
        if (empty($post_id)) { return $content; }

        $is_preview = $this->is_markdown_preview_request($post_id);
        if (is_user_logged_in() && !$is_preview) { return $content; }

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');

        if ($enabled === 'off' && !$is_preview) { return $content; }

        $context = $this->get_markdown_replace_context($post_id);

        if (empty($context['should_replace'])) { return $content; }

        // Check if raw mode (display markdown as-is)
        if (!empty($context['render_as_raw'])) {

            return '<pre style="white-space: pre-wrap; word-wrap: break-word; background: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 3px;">' . esc_html($context['markdown']) . '</pre>';
        }

        $html = self::render_markdown_to_html($context['markdown']);

        return !empty($html) ? $html : $content;
    }

    public function maybe_render_raw_markdown_page() {
        if (is_admin()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (defined('WP_CLI') && WP_CLI) return;
        if (is_feed()) return;
        if (!is_singular(['post', 'page'])) return;

        $post_id = get_queried_object_id();
        if (empty($post_id)) return;

        $is_preview = $this->is_markdown_preview_request($post_id);
        if (is_user_logged_in() && !$is_preview) return;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return;

        $context = $this->get_markdown_replace_context($post_id);
        if (empty($context['should_replace']) || empty($context['render_as_raw'])) return;

        $title = !empty($context['title']) ? $context['title'] : get_the_title($post_id);
        $markdown = isset($context['markdown']) ? (string) $context['markdown'] : '';

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        echo '<!doctype html><html><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>body{margin:20px;font-family:sans-serif;line-height:1.6;}h1{font-size:20px;margin:0 0 12px;}pre{white-space:pre-wrap;word-wrap:break-word;background:#f5f5f5;padding:15px;border:1px solid #ddd;border-radius:3px;}</style>';
        echo '</head><body>';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<pre>' . esc_html($markdown) . '</pre>';
        echo '</body></html>';
        exit;
    }

    public function maybe_override_markdown_title($title) {
        if (is_admin()) return $title;
        if (defined('REST_REQUEST') && REST_REQUEST) return $title;
        if (defined('WP_CLI') && WP_CLI) return $title;
        if (is_feed()) return $title;
        if (!is_singular(['post', 'page'])) return $title;
        $post_id = get_queried_object_id();
        if (empty($post_id)) return $title;

        $is_preview = $this->is_markdown_preview_request($post_id);
        if (is_user_logged_in() && !$is_preview) return $title;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $title;

        $context = $this->get_markdown_replace_context($post_id);
        if (empty($context['should_replace'])) return $title;

        return !empty($context['title']) ? $context['title'] : $title;
    }

    public function maybe_override_markdown_title_parts($parts) {
        if (!is_array($parts)) return $parts;
        if (is_admin()) return $parts;
        if (defined('REST_REQUEST') && REST_REQUEST) return $parts;
        if (defined('WP_CLI') && WP_CLI) return $parts;
        if (is_feed()) return $parts;
        if (!is_singular(['post', 'page'])) return $parts;
        $post_id = get_queried_object_id();
        if (empty($post_id)) return $parts;

        $is_preview = $this->is_markdown_preview_request($post_id);
        if (is_user_logged_in() && !$is_preview) return $parts;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $parts;

        $context = $this->get_markdown_replace_context($post_id);
        if (empty($context['should_replace']) || empty($context['title'])) return $parts;

        $parts['title'] = $context['title'];
        return $parts;
    }

    public function maybe_override_markdown_the_title($title, $post_id) {
        if (is_admin()) return $title;
        if (defined('REST_REQUEST') && REST_REQUEST) return $title;
        if (defined('WP_CLI') && WP_CLI) return $title;
        if (is_feed()) return $title;
        if (!is_singular(['post', 'page'])) return $title;
        if (!in_the_loop()) return $title;
        if (!is_main_query()) return $title;

        $queried_id = get_queried_object_id();
        if (empty($queried_id) || intval($post_id) !== intval($queried_id)) return $title;

        $is_preview = $this->is_markdown_preview_request($queried_id);
        if (is_user_logged_in() && !$is_preview) return $title;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $title;

        $context = $this->get_markdown_replace_context($queried_id);
        if (empty($context['should_replace']) || empty($context['title'])) return $title;

        return $context['title'];
    }

    public function maybe_override_markdown_thumbnail_id($thumbnail_id, $post_id) {
        // 早期リターン: 管理画面・REST・CLI・フィード・非単数表示は処理不要
        if (is_admin()) return $thumbnail_id;
        if (defined('REST_REQUEST') && REST_REQUEST) return $thumbnail_id;
        if (defined('WP_CLI') && WP_CLI) return $thumbnail_id;
        if (is_feed()) return $thumbnail_id;
        if (!is_singular(['post', 'page'])) return $thumbnail_id;

        // WordPress の post_thumbnail_id フィルターは第2引数に WP_Post オブジェクトを渡すため、
        // get_post_meta で使う前に整数の投稿IDに変換する
        $post_id = is_object($post_id) ? $post_id->ID : intval($post_id);

        // グローバルメディア設定によるアイキャッチ表示制御（新アーキテクチャ）
        $global_mode = get_option('ggc_global_media_eval_mode', 'none');

        // アイキャッチ表示モードの決定
        $featured_display = 'normal';
        if ($global_mode === 'all') {
            $featured_display = GGC_Options::get_global_featured_display_mode();
        } elseif ($global_mode === 'apply_new_posts') {
            // メディア表示モードが「設定しない」の場合、アイキャッチも通常表示
            $media_mode_meta = get_post_meta($post_id, '_ggc_media_mode', true);
            if ($media_mode_meta !== 'normal' && !empty($media_mode_meta)) {
                $featured_mode = get_post_meta($post_id, '_ggc_featured_mode', true);
                if ($featured_mode === 'normal' || empty($featured_mode)) {
                    // 「設定しない」→ 通常表示、マークダウン置換のみ続行
                } else {
                    $featured_display = $featured_mode;
                }
            }
        }

        // 非表示モードの場合はサムネイルIDを0にして非表示
        // ただしUA/IP評価が「設定しない」「全許可」の場合は通常表示する
        if ($featured_display === 'hide') {
            if (GGC_Eval_Utils::should_modify_media_by_eval($post_id)) {
                return 0;
            }
            // UA/IP評価が通常表示を指示 → featured_displayを無視
        }

        $queried_id = get_queried_object_id();
        if (empty($queried_id) || intval($post_id) !== intval($queried_id)) return $thumbnail_id;

        $is_preview = $this->is_markdown_preview_request($queried_id);
        if (is_user_logged_in() && !$is_preview) return $thumbnail_id;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $thumbnail_id;

        $context = $this->get_markdown_replace_context($queried_id);
        if (empty($context['should_replace'])) return $thumbnail_id;

        if (!empty($context['image_id'])) {
            return intval($context['image_id']);
        }

        return $thumbnail_id;
    }

    public function maybe_override_markdown_has_thumbnail($has_thumbnail, $post, $thumbnail_id) {
        // 早期リターン: 管理画面・REST・CLI・フィード・非単数表示は処理不要
        if (is_admin()) return $has_thumbnail;
        if (defined('REST_REQUEST') && REST_REQUEST) return $has_thumbnail;
        if (defined('WP_CLI') && WP_CLI) return $has_thumbnail;
        if (is_feed()) return $has_thumbnail;
        if (!is_singular(['post', 'page'])) return $has_thumbnail;

        // グローバルメディア設定によるアイキャッチ表示制御（新アーキテクチャ）
        $global_mode = get_option('ggc_global_media_eval_mode', 'none');
        $pid = is_object($post) ? $post->ID : intval($post);

        if ($global_mode !== 'none') {
            // アイキャッチ表示モードの決定
            $featured_display = 'normal';
            if ($global_mode === 'all') {
                $featured_display = GGC_Options::get_global_featured_display_mode();
            } elseif ($global_mode === 'apply_new_posts') {
                // メディア表示モードが「設定しない」の場合、アイキャッチも通常表示
                $media_mode_meta = get_post_meta($pid, '_ggc_media_mode', true);
                if ($media_mode_meta !== 'normal' && !empty($media_mode_meta)) {
                    $featured_mode = get_post_meta($pid, '_ggc_featured_mode', true);
                    if ($featured_mode === 'normal' || empty($featured_mode)) {
                        // 「設定しない」→ 通常表示
                    } else {
                        $featured_display = $featured_mode;
                    }
                }
            }

            // 非表示モードの場合はサムネイルなしとして返す
            // ただしUA/IP評価が「設定しない」「全許可」の場合は通常表示する
            if ($featured_display === 'hide') {
                if (GGC_Eval_Utils::should_modify_media_by_eval($pid)) {
                    return false;
                }
            }
        }

        $queried_id = get_queried_object_id();
        $post_id    = is_object($post) ? $post->ID : intval($post);
        if (empty($queried_id) || intval($post_id) !== intval($queried_id)) return $has_thumbnail;

        $is_preview = $this->is_markdown_preview_request($queried_id);
        if (is_user_logged_in() && !$is_preview) return $has_thumbnail;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $has_thumbnail;

        $context = $this->get_markdown_replace_context($queried_id);
        if (empty($context['should_replace'])) {
            return $has_thumbnail;
        }

        if (!empty($context['image_id']) || !empty($context['image_url'])) {
            return true;
        }

        return $has_thumbnail;
    }

    public function maybe_override_markdown_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        // 早期リターン: 管理画面・REST・CLI・フィード・非単数表示は処理不要
        if (is_admin()) return $html;
        if (defined('REST_REQUEST') && REST_REQUEST) return $html;
        if (defined('WP_CLI') && WP_CLI) return $html;
        if (is_feed()) return $html;
        if (!is_singular(['post', 'page'])) return $html;

        // post_thumbnail_html フィルターは $post->ID (int) を渡すが、安全のため型変換
        $post_id = is_object($post_id) ? $post_id->ID : intval($post_id);

        // グローバルメディア設定によるアイキャッチ表示制御（新アーキテクチャ）
        $global_mode = get_option('ggc_global_media_eval_mode', 'none');

        if ($global_mode !== 'none') {
            // アイキャッチ表示モードの決定
            $featured_display = 'normal';
            if ($global_mode === 'all') {
                $featured_display = GGC_Options::get_global_featured_display_mode();
            } elseif ($global_mode === 'apply_new_posts') {
                // メディア表示モードが「設定しない」の場合、アイキャッチも通常表示
                $media_mode_meta = get_post_meta($post_id, '_ggc_media_mode', true);
                if ($media_mode_meta !== 'normal' && !empty($media_mode_meta)) {
                    $featured_mode = get_post_meta($post_id, '_ggc_featured_mode', true);
                    if ($featured_mode === 'normal' || empty($featured_mode)) {
                        // 「設定しない」→ 通常表示
                    } else {
                        $featured_display = $featured_mode;
                    }
                }
            }

            // 非表示モードの場合は空を返す
            // ただしUA/IP評価が「設定しない」「全許可」の場合は通常表示する
            if ($featured_display === 'hide') {
                if (GGC_Eval_Utils::should_modify_media_by_eval($post_id)) {
                    return '';
                }
                // UA/IP評価が通常表示を指示 → featured_displayを無視
                $featured_display = 'normal';
            }

            // アイキャッチ表示モードが「代替テキストに置換」の場合、代替テキストを返す
            // ただしUA/IP評価が「設定しない」「全許可」の場合は通常表示する
            // ※マークダウン置換設定に依存しないよう、ここで処理する
            if ($featured_display === 'alt_replace') {
                if (GGC_Eval_Utils::should_modify_media_by_eval($post_id)) {
                    $alt = get_post_meta($post_id, '_ggc_featured_image_alt_text', true);
                    // 代替テキストが空なら通常表示（投稿タイトルにフォールバックしない）
                    if (!is_string($alt) || trim($alt) === '') {
                        return $html;
                    }
                    return '<div class="ggc-featured-alt-text">' . esc_html($alt) . '</div>';
                }
                // UA/IP評価が通常表示 → alt_replaceを無視
                $featured_display = 'normal';
            }
        }

        $queried_id = get_queried_object_id();
        if (empty($queried_id) || intval($post_id) !== intval($queried_id)) return $html;

        $is_preview = $this->is_markdown_preview_request($queried_id);
        if (is_user_logged_in() && !$is_preview) return $html;

        $enabled = get_option('ggc_markdown_replace_enabled', 'off');
        if ($enabled === 'off' && !$is_preview) return $html;

        $context = $this->get_markdown_replace_context($queried_id);
        if (empty($context['should_replace'])) return $html;

        if (!empty($context['image_id'])) {
            return wp_get_attachment_image($context['image_id'], $size, false, $attr);
        }

        if (!empty($context['image_url'])) {
            $extra_attr = '';
            if (is_array($attr)) {
                foreach ($attr as $key => $val) {
                    $extra_attr .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
                }
            }
            return '<img src="' . esc_url($context['image_url']) . '"' . $extra_attr . ' />';
        }

        return $html;
    }

    private function get_markdown_replace_context($post_id) {
        $preview_context = $this->get_markdown_preview_context($post_id);
        if (!empty($preview_context)) {
            return $preview_context;
        }

        // [共通化③] グローバルマークダウン設定を GGC_Options から取得
        $md_global = GGC_Options::get_markdown_global_context();
        $global_md_mode        = $md_global['global_md_mode'];
        $ua_eval               = $md_global['ua_eval'];
        $ip_eval               = $md_global['ip_eval'];
        $force_global_template = $md_global['force_global_template'];

        // fall back to "none" (use global settings) when no post-specific value exists
        $md_mode = get_post_meta($post_id, '_ggc_md_replace_mode', true);
        if ($md_mode === '' || $md_mode === false) {
            $md_mode = 'none';
        }
        $render_as_raw = false;

        // Check for raw display modes
        if (in_array($md_mode, ['manual_raw', 'template_raw', 'template_random_raw'])) {
            $render_as_raw = true;
        }
        if ($force_global_template) {
            // 置換モードをテンプレートに切り替え
            $md_mode = 'template';
            $global_template_mode = get_option('ggc_markdown_global_template_mode', 'select');
            // グローバル設定が raw を要求していれば生表示、それ以外は HTML に変換
            $render_as_raw = in_array($global_template_mode, ['select_raw', 'random_raw'], true);
        }
        if ($md_mode === 'none') {
            return ['should_replace' => false];
        }

        $markdown = get_post_meta($post_id, '_ggc_md_replace_text', true);

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // post meta may be empty; fall back to global setting rather than allowing all
        $ua_mode = get_post_meta($post_id, '_ggc_md_ua_mode', true) ?: 'global';
        $ip_mode = get_post_meta($post_id, '_ggc_md_ip_mode', true) ?: 'global';
        $raw_ua_mode = $ua_mode;
        $raw_ip_mode = $ip_mode;

        $ua_keys = get_post_meta($post_id, '_ggc_md_replace_crawlers', true);
        $ua_patterns = get_post_meta($post_id, '_ggc_md_replace_browser_patterns', true);
        if (!is_array($ua_keys)) $ua_keys = [];
        if (!is_array($ua_patterns)) $ua_patterns = [];

        $ip_keys = get_post_meta($post_id, '_ggc_md_replace_ips', true);
        $ip_keys_2 = get_post_meta($post_id, '_ggc_md_replace_ips_2', true);
        if (!is_array($ip_keys)) $ip_keys = [];
        if (!is_array($ip_keys_2)) $ip_keys_2 = [];

        // Apply global settings if selected (MEDIA: use media-specific options)
        if ($ua_mode === 'global') {
            $global_ua_option = get_option('ggc_global_media_ua_control', 'none');
            if ($global_ua_option === 'global_blacklist') {
                $ua_mode = 'blacklist';
                $ua_keys = get_option('ggc_global_media_selected_crawlers', []) ?: [];
                $ua_patterns = get_option('ggc_global_media_selected_patterns', []) ?: [];
            } elseif ($global_ua_option === 'global_whitelist') {
                $ua_mode = 'whitelist';
                $ua_keys = get_option('ggc_global_media_selected_crawlers', []) ?: [];
                $ua_patterns = get_option('ggc_global_media_selected_patterns', []) ?: [];
            } else {
                $ua_mode = 'allow_all';
                $ua_keys = [];
                $ua_patterns = [];
            }
        }

        if ($ip_mode === 'global') {
            $global_ip_option = get_option('ggc_global_ip_evaluation', 'none');
            if ($global_ip_option === 'global_blacklist') {
                $ip_mode = 'blacklist';
                $ip_keys = get_option('ggc_global_selected_ips', []) ?: [];
                $ip_keys_2 = get_option('ggc_global_selected_ips_2', []) ?: [];
            } elseif ($global_ip_option === 'global_whitelist') {
                $ip_mode = 'whitelist';
                $ip_keys = get_option('ggc_global_selected_ips', []) ?: [];
                $ip_keys_2 = get_option('ggc_global_selected_ips_2', []) ?: [];
            } else {
                $ip_mode = 'allow_all';
                $ip_keys = [];
                $ip_keys_2 = [];
            }
        }

        // Force global markdown mode (blacklist/whitelist) with global lists
        if ($force_global_template) {
            // Respect explicit markdown UA/IP evaluation settings when available.
            // For backward compatibility, if global mode is 'all' but the specific eval is left as 'none',
            // fall back to 'blacklist' (previous behavior).
            $ua_eval = get_option('ggc_markdown_ua_eval', 'none');
            $ip_eval = get_option('ggc_markdown_ip_eval', 'none');

            // Regardless of 'all' mode, respect explicit markdown eval settings.
            // 'none' => allow_all (no evaluation) ; 'deny_all' => deny_all (always replace)
            if ($ua_eval === 'blacklist') {
                $ua_mode = 'blacklist';
            } elseif ($ua_eval === 'whitelist') {
                $ua_mode = 'whitelist';
            } elseif ($ua_eval === 'deny_all') {
                $ua_mode = 'deny_all';
            } else {
                $ua_mode = 'allow_all';
            }
            if ($ip_eval === 'blacklist') {
                $ip_mode = 'blacklist';
            } elseif ($ip_eval === 'whitelist') {
                $ip_mode = 'whitelist';
            } elseif ($ip_eval === 'deny_all') {
                $ip_mode = 'deny_all';
            } else {
                $ip_mode = 'allow_all';
            }

            $ua_keys = get_option('ggc_global_markdown_selected_crawlers', []) ?: [];
            $ua_patterns = get_option('ggc_global_markdown_selected_patterns', []) ?: [];
            $ip_keys = get_option('ggc_global_markdown_selected_ips', []) ?: [];
            $ip_keys_2 = get_option('ggc_global_markdown_selected_ips_2', []) ?: [];
        }

        $title = '';
        $image_url = '';
        $image_id = 0;
        $template_image_id = 0;

        // If template mode, use template selection (manual mode should not fall back to templates)
        $using_global = false;
        $actual_mode = preg_replace('/_raw$/', '', $md_mode); // Remove _raw suffix for logic
        $is_template_mode = in_array($actual_mode, ['template', 'template_random'], true);
        if ($is_template_mode) {
            $templates_option = get_option('ggc_markdown_templates', []);
            $templates = is_array($templates_option) ? $templates_option : [];

            $selected_key = '';
            if ($force_global_template) {
                $template_mode = get_option('ggc_markdown_global_template_mode', 'select');
                $template_mode = preg_replace('/_raw$/', '', $template_mode); // Remove _raw suffix
                if ($template_mode === 'random') {
                    $selected_key = '';
                } else {
                    $selected_key = get_option('ggc_markdown_global_template_key', '');
                    $selected_key = is_string($selected_key) ? sanitize_key($selected_key) : '';
                }
            } elseif (in_array($actual_mode, ['template', 'template_random'])) {
                if ($actual_mode === 'template_random') {
                    $selected_key = '';
                } else {
                    $selected_key = get_post_meta($post_id, '_ggc_md_template_key', true);
                    $selected_key = is_string($selected_key) ? sanitize_key($selected_key) : '';
                }
            }

            if (empty($selected_key) || !isset($templates[$selected_key])) {
                $random_pool = [];
                foreach ($templates as $tkey => $tpl) {
                    if (!empty($tpl['random_enabled'])) {
                        $random_pool[] = $tkey;
                    }
                }

                if (!empty($random_pool)) {
                    $selected_key = $random_pool[array_rand($random_pool)];
                } else {
                    $keys = array_keys($templates);
                    $selected_key = !empty($keys) ? $keys[0] : '';
                }
            }

            if (!empty($selected_key) && isset($templates[$selected_key])) {
                $markdown = $templates[$selected_key]['markdown'] ?? '';
                $title = $templates[$selected_key]['title'] ?? '';
                $image_url = $templates[$selected_key]['image_url'] ?? '';
                $template_image_id = absint($templates[$selected_key]['image_id'] ?? 0);
                $using_global = true;
            }
        }

        // Manual modes: if markdown is empty, do not replace (show original post)
        if (!$is_template_mode && (!is_string($markdown) || trim($markdown) === '')) {
            return ['should_replace' => false];
        }

        if (!is_string($markdown) || trim($markdown) === '') {
            return ['should_replace' => false];
        }

        $should_replace = false;

        if ($ua_mode === 'deny_all' || $ip_mode === 'deny_all') {
            $should_replace = true;
        }

        // Allow-all means no restriction (do not trigger replacement by itself)
        $ua_allows = ($ua_mode === 'allow_all');
        $ip_allows = ($ip_mode === 'allow_all');

        // Whitelist: チェックが全て外れている場合は必ず置換（空文字やゴミデータも除外して厳密に判定）
        if (!$should_replace && !$ua_allows && $ua_mode === 'whitelist') {
            $ua_keys_clean = array_filter((array)$ua_keys, function($v){ return (string)trim($v) !== ''; });
            $ua_patterns_clean = array_filter((array)$ua_patterns, function($v){ return (string)trim($v) !== ''; });
            if (empty($ua_keys_clean) && empty($ua_patterns_clean)) {
                $should_replace = true;
            }
        }
        if (!$should_replace && !$ip_allows && $ip_mode === 'whitelist') {
            $ip_keys_clean = array_filter((array)$ip_keys, function($v){ return (string)trim($v) !== ''; });
            $ip_keys_2_clean = array_filter((array)$ip_keys_2, function($v){ return (string)trim($v) !== ''; });
            $all_clean = array_merge($ip_keys_clean, $ip_keys_2_clean);
            if (empty($all_clean)) {
                // 全て未選択なら全て置換
                $should_replace = true;
            } else {
                // ホワイトリスト: チェックしたIPだけ通常表示、それ以外は置換
                $ip_match = self::is_in_allowable_ip_range($all_clean);
                $should_replace = !$ip_match;
            }
        }

        if (!$should_replace && !$ua_allows && in_array($ua_mode, ['blacklist','whitelist'], true)) {
            $ua_keys_clean = array_filter((array)$ua_keys, function($v){ return (string)trim($v) !== ''; });
            $ua_patterns_clean = array_filter((array)$ua_patterns, function($v){ return (string)trim($v) !== ''; });
            if (!empty($ua_keys_clean) || !empty($ua_patterns_clean)) {
                $ua_match = $this->ua_matches_for_markdown($user_agent, $ua_keys_clean, $ua_patterns_clean);
                if ($ua_mode === 'blacklist' && $ua_match) {
                    $should_replace = true;
                } elseif ($ua_mode === 'whitelist' && !$ua_match) {
                    $should_replace = true;
                }
            }
        }

        if (!$should_replace && !$ip_allows && in_array($ip_mode, ['blacklist','whitelist'], true)) {
            $ip_keys_clean = array_filter((array)$ip_keys, function($v){ return (string)trim($v) !== ''; });
            $ip_keys_2_clean = array_filter((array)$ip_keys_2, function($v){ return (string)trim($v) !== ''; });
            if (!empty($ip_keys_clean) || !empty($ip_keys_2_clean)) {
                $ip_match = self::is_in_allowable_ip_range(array_merge($ip_keys_clean, $ip_keys_2_clean));
                if ($ip_mode === 'blacklist') {
                    // ブラックリスト: チェックしたIPは置換、それ以外は通常表示
                    $should_replace = $ip_match;
                } elseif ($ip_mode === 'whitelist') {
                    // ホワイトリスト: チェックしたIPだけ通常表示、それ以外は置換
                    $should_replace = !$ip_match;
                }
            }
        }

        if (!$should_replace) return ['should_replace' => false];


        // グローバル強制時は投稿ごとのタイトル・本文・画像を一切使わない
        if ($force_global_template) {
            // グローバルテンプレートだけを返すので、投稿固有の値は無視
            if ($using_global) {
                // テンプレートで指定された画像IDを使う（URLはテンプレート側で既に設定済み）
                $image_id = $template_image_id;
                if ($image_id > 0) {
                    // 明示的にURLを空にしてID優先に
                    $image_url = '';
                }
            } else {
                // デフォルト値のまま（0）
                $image_id = 0;
            }
        } else {
            $post_title = get_post_meta($post_id, '_ggc_md_replace_title', true);
            $has_post_title = is_string($post_title) && $post_title !== '';
            // original code prevented title overrides when either UA/IP mode was still set to
            // "global" (meaning the post inherited the global evaluation list). this was too
            // strict: admins expect a custom title to take effect even if they haven't
            // explicitly chosen a UA/IP mode on the post. only completely global-template
            // mode should block per-post titles.
            $allow_title_override = !$force_global_template;
            if ((empty($title) && $has_post_title) || ($using_global && $allow_title_override && $has_post_title)) {
                $title = $post_title;
            } elseif (empty($title)) {
                $title = '';
            }

            $post_image_id = intval(get_post_meta($post_id, '_ggc_md_replace_image_id', true));
            $has_post_image = $post_image_id > 0;

            // 画像IDがあればそちらを優先、なければカスタム画像URL
            $custom_image_url = get_post_meta($post_id, '_ggc_md_replace_image_url', true);
            if ($has_post_image) {
                $image_id = $post_image_id;
                $image_url = '';
            } else if (!empty($custom_image_url)) {
                $image_url = $custom_image_url;
                $image_id = 0;
            } else if ($using_global) {
                $image_id = $template_image_id;
            } else {
                $image_id = 0;
            }
        }

        return [
            'should_replace' => true,
            'markdown' => $markdown,
            'title' => $title,
            'image_id' => $image_id,
            'image_url' => $image_url,
            'render_as_raw' => $render_as_raw,
        ];
    }

    private function is_markdown_preview_request($post_id) {
        if (empty($post_id)) return false;
        if (!isset($_REQUEST['ggc_md_preview']) || (string) $_REQUEST['ggc_md_preview'] !== '1') return false;
        $nonce = isset($_REQUEST['ggc_md_preview_nonce']) ? wp_unslash($_REQUEST['ggc_md_preview_nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ggc_md_preview_' . $post_id)) return false;
        if (!current_user_can('edit_post', $post_id)) return false;
        return true;
    }

    private function get_markdown_preview_context($post_id) {
        if (!$this->is_markdown_preview_request($post_id)) {
            return null;
        }

        $md_mode = isset($_REQUEST['ggc_md_replace_mode']) ? sanitize_text_field(wp_unslash($_REQUEST['ggc_md_replace_mode'])) : 'manual';
        $render_as_raw = in_array($md_mode, ['manual_raw', 'template_raw', 'template_random_raw'], true);
        if ($md_mode === 'none') {
            return ['should_replace' => false];
        }

        // [共通化③] グローバルマークダウン設定を GGC_Options から取得
        $md_global = GGC_Options::get_markdown_global_context();
        $force_global_template = $md_global['force_global_template'];
        if ($force_global_template) {
            // force template mode and ignore any user-entered markdown/title/image
            $md_mode = 'template';
            $global_template_mode = get_option('ggc_markdown_global_template_mode', 'select');
            if (in_array($global_template_mode, ['select_raw', 'random_raw'], true)) {
                $render_as_raw = true;
            }
        }

        $markdown = isset($_REQUEST['ggc_md_replace_text']) ? wp_unslash($_REQUEST['ggc_md_replace_text']) : '';
        $title = isset($_REQUEST['ggc_md_replace_title']) ? sanitize_text_field(wp_unslash($_REQUEST['ggc_md_replace_title'])) : '';
        $image_id = isset($_REQUEST['ggc_md_replace_image_id']) ? absint($_REQUEST['ggc_md_replace_image_id']) : 0;
        $override_image = false;

        if ($force_global_template) {
            $markdown = '';
            $title = '';
            $image_id = 0;
        }

        $image_url = '';
        $template_image_id = 0;
        $using_template = false;

        $actual_mode = preg_replace('/_raw$/', '', $md_mode);
        if (in_array($actual_mode, ['template', 'template_random'], true) || !is_string($markdown) || trim($markdown) === '') {
            $templates_option = get_option('ggc_markdown_templates', []);
            $templates = is_array($templates_option) ? $templates_option : [];

            if (!empty($force_global_template)) {
                // global template selection overrides request values
                $global_template_mode = get_option('ggc_markdown_global_template_mode', 'select');
                $mode_no_raw = preg_replace('/_raw$/', '', $global_template_mode);
                if ($mode_no_raw === 'random') {
                    $template_key = '';
                } else {
                    $template_key = get_option('ggc_markdown_global_template_key', '');
                    $template_key = is_string($template_key) ? sanitize_key($template_key) : '';
                }
            } else {
                $template_key = isset($_REQUEST['ggc_md_template_key']) ? sanitize_key(wp_unslash($_REQUEST['ggc_md_template_key'])) : '';
                if ($actual_mode === 'template_random') {
                    $template_key = '';
                }
            }

            if (empty($template_key) || !isset($templates[$template_key])) {
                $random_pool = [];
                foreach ($templates as $tkey => $tpl) {
                    if (!empty($tpl['random_enabled'])) {
                        $random_pool[] = $tkey;
                    }
                }

                if (!empty($random_pool)) {
                    $template_key = $random_pool[array_rand($random_pool)];
                } else {
                    $keys = array_keys($templates);
                    $template_key = !empty($keys) ? $keys[0] : '';
                }
            }

            if (!empty($template_key) && isset($templates[$template_key])) {
                $markdown = $templates[$template_key]['markdown'] ?? '';
                $title = $templates[$template_key]['title'] ?? '';
                $image_url = $templates[$template_key]['image_url'] ?? '';
                $template_image_id = absint($templates[$template_key]['image_id'] ?? 0);
                $using_template = true;
            }
        }

        if (!is_string($markdown) || trim($markdown) === '') {
            return ['should_replace' => false];
        }

        if ($using_template && empty($force_global_template)) {
            $post_title = isset($_REQUEST['ggc_md_replace_title']) ? sanitize_text_field(wp_unslash($_REQUEST['ggc_md_replace_title'])) : '';
            if ($post_title !== '') {
                $title = $post_title;
            }
        }

        if ($using_template) {
            if ($image_id > 0) {
                $image_url = '';
            } else {
                $image_id = $template_image_id;
            }
        }

        return [
            'should_replace' => true,
            'markdown' => $markdown,
            'title' => $title,
            'image_id' => $image_id,
            'image_url' => $image_url,
            'render_as_raw' => $render_as_raw,
        ];
    }

    private function ua_matches_for_markdown($user_agent, $ua_keys, $pattern_keys) {
        if (empty($user_agent)) return false;

        // GGC_Eval_Utils::matches_user_agent() と同一ロジックに統一。
        // 以前の実装では Bot 系 UA の照合が「前方一致 (=== 0)」になっており、
        // ページ評価（部分一致 !== false）と動作が異なっていました。
        // 同じ設定でも評価結果が変わるバグを防ぐため、両者を部分一致に統一します。
        return GGC_Eval_Utils::matches_user_agent($user_agent, $ua_keys, $pattern_keys);
    }

    public static function render_markdown_to_html($markdown) {
        $markdown = trim((string) $markdown);
        if ($markdown === '') return '';

        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Extract fenced code blocks
        $code_blocks = [];
        $markdown = preg_replace_callback('/```([\s\S]*?)```/', function($m) use (&$code_blocks) {
            $key = '%%GGC_CODEBLOCK_' . count($code_blocks) . '%%';
            $code_blocks[$key] = '<pre><code>' . esc_html($m[1]) . '</code></pre>';
            return $key;
        }, $markdown);

        // Inline code
        $markdown = preg_replace_callback('/`([^`]+)`/', function($m) {
            return '<code>' . esc_html($m[1]) . '</code>';
        }, $markdown);

        // Headings and lists
        $lines = explode("\n", $markdown);
        $out = [];
        $in_list = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*#{1,6}\s+(.+)$/', $line, $m)) {
                if ($in_list) {
                    $out[] = '</ul>';
                    $in_list = false;
                }
                $level = min(6, max(1, strlen(strtok(trim($line), ' '))));
                $text = trim($m[1]);
                $out[] = sprintf('<h%d>%s</h%d>', $level, esc_html($text), $level);
                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
                if (!$in_list) {
                    $out[] = '<ul>';
                    $in_list = true;
                }
                $out[] = '<li>' . esc_html(trim($m[1])) . '</li>';
                continue;
            }

            if ($in_list) {
                $out[] = '</ul>';
                $in_list = false;
            }

            $out[] = $line;
        }

        if ($in_list) {
            $out[] = '</ul>';
        }

        $html = implode("\n", $out);

        // Images, links, bold, italic
        $html = preg_replace('/!\[(.*?)\]\((https?:\/\/[^\s)]+)\)/', '<img src="$2" alt="$1" style="max-width:100%;height:auto;" />', $html);
        $html = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" rel="nofollow noopener" target="_blank">$1</a>', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);

        // Restore code blocks
        if (!empty($code_blocks)) {
            $html = strtr($html, $code_blocks);
        }

        $html = wpautop($html);
        $allowed = wp_kses_allowed_html('post');
        return wp_kses($html, $allowed);
    }

    public function perform_blocking() {

        if ( is_admin() ) return;
        if ( defined('REST_REQUEST') && REST_REQUEST ) return;
        if ( defined('WP_CLI') && WP_CLI ) return;
        if ( in_array($_SERVER['REQUEST_METHOD'], ['HEAD','OPTIONS'], true) ) return;
        if ( is_404() || is_feed() ) return;

        // respect global page-eval mode: if disabled, skip entirely
        $global_page_mode = get_option('ggc_global_page_eval_mode', 'none');
        if ($global_page_mode === 'none') {
            return;
        }

        $post_id = get_queried_object_id();
        if (is_user_logged_in()) {
            return;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // 1. Determine Modes
        $eval_context = GGC_Eval_Utils::get_page_eval_context($post_id);
        $global_ua_option = $eval_context['global_ua_option'];
        $global_ip_option = $eval_context['global_ip_option'];
        $force_global_ua = $eval_context['force_global_ua'];
        $force_global_ip = $eval_context['force_global_ip'];

        // determine modes including respect for the per-post redirect setting
        $modes = $this->get_page_eval_modes($post_id);
        $ua_mode = $modes['ua_mode'];
        $ip_mode = $modes['ip_mode'];

        // debug information about resolved modes and context
        ggc_debug_log("perform_blocking post={$post_id} global_page_mode={$global_page_mode} ua_mode={$ua_mode} ip_mode={$ip_mode} force_global_ua=" . (
                $force_global_ua ? '1' : '0') . " force_global_ip=" . (
                $force_global_ip ? '1' : '0'));
        ggc_debug_log("perform_blocking context=" . wp_json_encode($eval_context));
        // also log redirect modes for troubleshooting
        $ua_redirect = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'global';
        $ip_redirect = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'global';
        ggc_debug_log("perform_blocking redirect_modes ua={$ua_redirect} ip={$ip_redirect}");



        // 2. User-Agent Evaluation
        $should_block_ua = false;
        $message_ua = '';

        if ($ua_mode === 'deny_all') {
            $should_block_ua = true;
            $message_ua = 'アクセス禁止：このページはすべてのUser-Agentを拒否しています。';
        } elseif ($ua_mode === 'blacklist' || $ua_mode === 'whitelist') {

            $selected_crawlers = GGC_Eval_Utils::get_page_selected_crawlers_for_match($post_id, $global_ua_option, $force_global_ua);
            $selected_page_pattern_keys = GGC_Eval_Utils::get_page_selected_patterns_for_match($post_id, $global_ua_option, $force_global_ua);
            $is_match = GGC_Eval_Utils::matches_user_agent($user_agent, $selected_crawlers, $selected_page_pattern_keys);

            if ($ua_mode === 'blacklist') {
                if ($is_match) {
                    $should_block_ua = true;
                    $message_ua = 'アクセス禁止：ブラックリストに登録されたUser-Agentです。';
                }
            } elseif ($ua_mode === 'whitelist') {
                if (!$is_match) {
                    $should_block_ua = true;
                    $message_ua = 'アクセス禁止：許可されていないUser-Agentです。';
                }
            }
        }

        if ($should_block_ua) {
            ggc_debug_log("UA block triggered post={$post_id} message={$message_ua}");
            $this->handle_block_action('ua', $message_ua, $post_id);
            // execution will end there via die_forbidden
        }

        // 3. IP Address Evaluation
        $should_block_ip = false;
        $message_ip = '';

        if ($ip_mode === 'deny_all') {
            $should_block_ip = true;
            $message_ip = 'アクセス禁止：このページはすべてのIPアドレスを拒否しています。';
        } elseif ($ip_mode === 'blacklist' || $ip_mode === 'whitelist') {
            $all_selected_ips = GGC_Eval_Utils::get_page_selected_ips_for_match($post_id, $global_ip_option, $force_global_ip);
            $is_in_range = GGC_Eval_Utils::is_ip_in_ranges($all_selected_ips);

            if ($ip_mode === 'blacklist') {
                if ($is_in_range) {
                    $should_block_ip = true;
                    $message_ip = 'アクセス禁止：ブラックリストに登録されたIPアドレスです。';
                }
            } elseif ($ip_mode === 'whitelist') {
                if (!$is_in_range) {
                    $should_block_ip = true;
                    $message_ip = 'アクセス禁止：許可されていないIPアドレスです。';
                }
            }
        }

        if ($should_block_ip) {
            ggc_debug_log("IP block triggered post={$post_id} message={$message_ip}");
            $this->handle_block_action('ip', $message_ip, $post_id);
        }

    }

    /**
     * Helper for tests: determines whether handle_block_action would consult
     * the global message configuration for a given post+type.  This mirrors the
     * logic used above so unit tests can assert correctness without
     * triggering a die_forbidden.
     *
     * @param string $type 'ua' or 'ip'
     * @param int $post_id
     * @return bool
     */
    public function should_use_global_message($type, $post_id) {
        $global_page_mode = get_option('ggc_global_page_eval_mode', 'none');
        // when page eval mode is 'none' or 'apply_new_posts' we should not
        // consult global block messages for individual posts.
        $ignore_global_messages = in_array($global_page_mode, ['apply_new_posts','none'], true);

        if ($type === 'ua') {
            $post_action = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'global';
            $global_ua_control = get_option('ggc_global_user_agent_control', 'none');
            $global_page_ua_control = get_option('ggc_global_page_user_agent_control', 'none');
            $use_global_ua = in_array($global_ua_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true)
                || in_array($global_page_ua_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true);
            if ($ignore_global_messages) {
                return false;
            }
            return ($use_global_ua || $post_action === 'global');
        } else {
            $post_action = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'global';
            $global_ip_control = get_option('ggc_global_ip_evaluation', 'none');
            $global_page_ip_control = get_option('ggc_global_page_ip_control', 'none');
            $use_global_ip = in_array($global_ip_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true)
                || in_array($global_page_ip_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true);
            if ($ignore_global_messages) {
                return false;
            }
            return ($use_global_ip || $post_action === 'global');
        }
    }

    private function handle_block_action($type, $message, $post_id) {
        ggc_debug_log("handle_block_action start type={$type} post={$post_id}");

        $global_ua_control = get_option('ggc_global_user_agent_control', 'none');
        $global_ip_control = get_option('ggc_global_ip_evaluation', 'none');

        $global_page_ua_control = get_option('ggc_global_page_user_agent_control', 'none');
        $global_page_ip_control = get_option('ggc_global_page_ip_control', 'none');
        $use_global_ua = in_array($global_ua_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true)
            || in_array($global_page_ua_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true);
        $use_global_ip = in_array($global_ip_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true)
            || in_array($global_page_ip_control, ['global_blacklist','global_whitelist','deny_all','allow_all'], true);

        $action = 'block';
        $redirect_url = '';
        $custom_message = '';
        $custom_status_code = null;
        $message_key = '';

        $global_page_mode = get_option('ggc_global_page_eval_mode', 'none');
        $ignore_global_messages = ($global_page_mode === 'apply_new_posts');

        // 投稿・固定ページ個別設定の場合はグローバルのリダイレクト/ブロック設定を
        // 一切使わず、投稿ごとの設定のみを使用する。
        if ($global_page_mode === 'apply_new_posts') {
            if ($type === 'ua') {
                $post_action = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'block';
                $action       = $post_action;
                $redirect_url = get_post_meta($post_id, '_ggc_ua_redirect_url', true);
                $message_key  = get_post_meta($post_id, '_ggc_ua_block_message_key', true);
                $custom_message     = get_post_meta($post_id, '_ggc_ua_block_message_custom', true);
                $custom_status_code = get_post_meta($post_id, '_ggc_ua_block_status_code', true);
            } else {
                $post_action = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'block';
                $action       = $post_action;
                $redirect_url = get_post_meta($post_id, '_ggc_ip_redirect_url', true);
                $message_key  = get_post_meta($post_id, '_ggc_ip_block_message_key', true);
                $custom_message     = get_post_meta($post_id, '_ggc_ip_block_message_custom', true);
                $custom_status_code = get_post_meta($post_id, '_ggc_ip_block_status_code', true);
            }
        } elseif ($type === 'ua') {
            // empty meta should fall back to global option rather than immediate block
            $post_action = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'global';
            if ($use_global_ua || $post_action === 'global') {
                $action = get_option('ggc_global_ua_redirect_mode', 'block');
                $redirect_url = get_option('ggc_global_ua_redirect_url', '');
                if ($ignore_global_messages) {
                    // prefer whatever the post-level meta contains, even if
                    // redirect mode is "global" (might be empty)
                    $message_key = get_post_meta($post_id, '_ggc_ua_block_message_key', true);
                    $custom_message = get_post_meta($post_id, '_ggc_ua_block_message_custom', true);
                    $custom_status_code = get_post_meta($post_id, '_ggc_ua_block_status_code', true);
                } else {
                    $message_key = get_option('ggc_global_ua_block_message_key', '');
                    $custom_message = get_option('ggc_global_ua_block_message', '');
                }
            } else {
                $action = $post_action;
                $redirect_url = get_post_meta($post_id, '_ggc_ua_redirect_url', true);
                $message_key = get_post_meta($post_id, '_ggc_ua_block_message_key', true);
                $custom_message = get_post_meta($post_id, '_ggc_ua_block_message_custom', true);
                $custom_status_code = get_post_meta($post_id, '_ggc_ua_block_status_code', true);
            }
        } else {
            $post_action = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'global';
            if ($use_global_ip || $post_action === 'global') {
                $action = get_option('ggc_global_ip_redirect_mode', 'block');
                $redirect_url = get_option('ggc_global_ip_redirect_url', '');
                if ($ignore_global_messages) {
                    $message_key = get_post_meta($post_id, '_ggc_ip_block_message_key', true);
                    $custom_message = get_post_meta($post_id, '_ggc_ip_block_message_custom', true);
                    $custom_status_code = get_post_meta($post_id, '_ggc_ip_block_status_code', true);
                } else {
                    $message_key = get_option('ggc_global_ip_block_message_key', '');
                    $custom_message = get_option('ggc_global_ip_block_message', '');
                }
            } else {
                $action = $post_action;
                $redirect_url = get_post_meta($post_id, '_ggc_ip_redirect_url', true);
                $message_key = get_post_meta($post_id, '_ggc_ip_block_message_key', true);
                $custom_message = get_post_meta($post_id, '_ggc_ip_block_message_custom', true);
                $custom_status_code = get_post_meta($post_id, '_ggc_ip_block_status_code', true);
            }
        }

        if (!in_array($action, ['block','redirect'], true)) {
            $action = 'block';
        }

        // always send cache-control headers for blocking/redirects so caches don't retain the previous page
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        if ($action === 'redirect' && !empty($redirect_url)) {
            $scheme = is_ssl() ? 'https' : 'http';
            $current_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            if (rtrim($current_url, '/') !== rtrim($redirect_url, '/')) {
                $redirect_url = esc_url_raw($redirect_url);
                if (empty($redirect_url)) {
                    wp_safe_redirect(home_url('/'), 302);
                    exit;
                }

                $redirect_host = wp_parse_url($redirect_url, PHP_URL_HOST);
                $current_host = wp_parse_url($current_url, PHP_URL_HOST);

                if (!empty($redirect_host) && $redirect_host !== $current_host) {
                    if (wp_http_validate_url($redirect_url)) {
                        wp_redirect($redirect_url, 302);
                    } else {
                        wp_safe_redirect(home_url('/'), 302);
                    }
                } else {
                    $validated = wp_validate_redirect($redirect_url, home_url('/'));
                    wp_safe_redirect($validated, 302);
                }
                exit;
            }
        }

        // Fallback to global message definition when post-level is empty
        // only apply when global_page_mode is 'all' (full override).  under
        // apply_new_posts, global messages are intentionally ignored even if
        // the post has no specific definition.
        if ($global_page_mode === 'all' && empty($message_key) && empty($custom_message)) {
            if ($type === 'ua') {
                $message_key = get_option('ggc_global_ua_block_message_key', '');
                if (empty($custom_message)) {
                    $custom_message = get_option('ggc_global_ua_block_message', '');
                }
            } else {
                $message_key = get_option('ggc_global_ip_block_message_key', '');
                if (empty($custom_message)) {
                    $custom_message = get_option('ggc_global_ip_block_message', '');
                }
            }
        }

        $status_code = 403;
        if (!empty($custom_status_code)) {
            $status_code = intval($custom_status_code);
        }

        $message_def = $this->get_page_eval_message_by_key($message_key);
        if (!empty($message_def)) {
            if (empty($custom_message) && !empty($message_def['message'])) {
                $message = $message_def['message'];
            }
            if (empty($custom_status_code) && !empty($message_def['status_code'])) {
                $status_code = intval($message_def['status_code']);
            }
        }

        if (!empty($custom_message)) {
            $message = $custom_message;
        }
        ggc_debug_log("handle_block_action final action={$action} type={$type} status={$status_code} message={$message}");
        self::die_forbidden($message, $status_code);
    }

    private function get_page_eval_messages() {
        $messages = get_option('ggc_page_eval_messages', null);
        if ($messages === null) {
            $cleared = get_option('ggc_clear_all_done', '0') === '1';
            return $cleared ? [] : ggc_get_default_page_eval_messages();
        }
        return is_array($messages) ? $messages : [];
    }

    private function get_page_eval_message_by_key($key) {
        if (empty($key) || $key === 'custom') {
            return null;
        }
        $messages = $this->get_page_eval_messages();
        return isset($messages[$key]) ? $messages[$key] : null;
    }


    /**
     * Determine the actual evaluation modes for a post, taking into account both
     * the control settings and the "method" selectors (block/redirect/global).
     *
     * The dropdown labelled "評価方法-ページ" is often interpreted by users as
     * a way to disable page evaluation entirely when set to "設定しない".  In
     * the existing code only the redirect action was influenced by that value –
     * the control mode (blacklist/whitelist/etc) was left untouched.  That meant
     * a post could still be blocked by UA/IP rules even though the admin thought
     * evaluation was turned off.
     *
     * To make behaviour more intuitive we override the resolved mode to
     * 'allow_all' whenever the corresponding redirect/method selector is
     * 'global'.  This effectively disables evaluation of that dimension for the
     * post, regardless of any leftover control-mode meta or global page settings.
     *
     * This helper is public to make it usable from unit tests.
     *
     * @param int $post_id
     * @return array{ua_mode:string,ip_mode:string}
     */
    public function get_page_eval_modes($post_id) {
        $global_page_mode = get_option('ggc_global_page_eval_mode', 'none');

        // 投稿・固定ページ個別設定：グローバル設定を完全に無視し
        // 投稿ごとのリダイレクト/ブロック設定のみで動作する。
        // get_page_eval_context 内で global_ua_option/global_ip_option を
        // 'none' に固定済みなので、ここでは post 側の redirect 設定を
        // そのまま resolve_control_mode に渡すだけでよい。
        if ($global_page_mode === 'apply_new_posts') {
            $ctx = GGC_Eval_Utils::get_page_eval_context($post_id);
            // apply_new_posts では context 内ですでに global を none にして
            // post_ua_mode / post_ip_mode を投稿値に解決済み。
            // resolve_control_mode は global='none' で即座に 'none' を返すため
            // 投稿側の設定が無視されてしまう。投稿のモードを直接使用する。
            $ua_mode = $ctx['post_ua_mode'];
            $ip_mode = $ctx['post_ip_mode'];
            // 未設定/none/global は allow_all として扱う
            if (empty($ua_mode) || $ua_mode === 'none' || $ua_mode === 'global') {
                $ua_mode = 'allow_all';
            }
            if (empty($ip_mode) || $ip_mode === 'none' || $ip_mode === 'global') {
                $ip_mode = 'allow_all';
            }
            // 投稿・固定ページ個別設定モードでは、「評価方法-ページ」（redirect mode）が
            // 「設定しない（global）」の場合、評価を無効化する。
            // 「ブロック」または「リダイレクト」に明示的に設定された場合のみ評価が動作する。
            $ua_redirect = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'global';
            $ip_redirect = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'global';
            if ($ua_redirect === 'global') {
                $ua_mode = 'allow_all';
            }
            if ($ip_redirect === 'global') {
                $ip_mode = 'allow_all';
            }
            ggc_debug_log("get_page_eval_modes(apply_new_posts) ua_mode={$ua_mode} ip_mode={$ip_mode} ua_redirect={$ua_redirect} ip_redirect={$ip_redirect}");
            return [
                'ua_mode' => $ua_mode,
                'ip_mode' => $ip_mode,
            ];
        }

        $ctx = GGC_Eval_Utils::get_page_eval_context($post_id);

        $ua_redirect = get_post_meta($post_id, '_ggc_ua_redirect_mode', true) ?: 'global';
        $ip_redirect = get_post_meta($post_id, '_ggc_ip_redirect_mode', true) ?: 'global';

        // 全ページ強制の場合は必ず force_global を true にする
        if ($global_page_mode === 'all') {
            $ctx['force_global_ua'] = true;
            $ctx['force_global_ip'] = true;
        } else {
            if ($ua_redirect !== 'global') {
                $ctx['force_global_ua'] = false;
            }
            if ($ip_redirect !== 'global') {
                $ctx['force_global_ip'] = false;
            }
        }

        if (!isset($ctx['post_ua_mode'])) { $ctx['post_ua_mode'] = 'none'; }
        if (!isset($ctx['post_ip_mode'])) { $ctx['post_ip_mode'] = 'none'; }

        $ua_mode = GGC_Eval_Utils::resolve_control_mode($ctx['global_ua_option'], $ctx['post_ua_mode'], $ctx['force_global_ua']);
        $ip_mode = GGC_Eval_Utils::resolve_control_mode($ctx['global_ip_option'], $ctx['post_ip_mode'], $ctx['force_global_ip']);

        if ($ua_redirect === 'global' && $global_page_mode !== 'all' && empty($ctx['force_global_ua'])) {
            // when redirect mode is set to global and page mode is not 'all',
            // disable evaluation (treat as allow_all) unless the post is
            // already under a forced global policy.
            $ua_mode = 'allow_all';
        }
        if ($ip_redirect === 'global' && $global_page_mode !== 'all' && empty($ctx['force_global_ip'])) {
            $ip_mode = 'allow_all';
        }

        ggc_debug_log("get_page_eval_modes resolved ua_mode={$ua_mode} ip_mode={$ip_mode} global_ua_option={$ctx['global_ua_option']} global_ip_option={$ctx['global_ip_option']}");

        return [
            'ua_mode' => $ua_mode,
            'ip_mode' => $ip_mode,
        ];
    }

    public function resolve_page_control_mode($global_option, $post_mode, $force_global) {
        // 単体テストや一部旧コードが呼び出すラッパー。
        // 実際の判定は共通ユーティリティへ委譲する。
        $mode = GGC_Eval_Utils::resolve_control_mode($global_option, $post_mode, $force_global);
        if ($mode === '' || $mode === null || $mode === 'none') {
            // ページ評価では空/none を許可扱いにする
            return 'allow_all';
        }
        return $mode;
    }


    /**
     * Option update callback used to migrate existing posts when the global
     * page evaluation mode changes.
     *
     * If the old value was "all" and the new value is "apply_new_posts", any
     * post or page whose UA/IP mode is still stored as "global" should no longer
     * be forced to use the global lists. We simply delete the meta so the post
     * falls back to allow_all and becomes editable via the UI.
     *
     * @param string $old_value Previous option value.
     * @param string $value     New option value.
     * @param string $option    Option name (ignored).
     */
    public function maybe_migrate_page_eval_mode($old_value, $value, $option) {
        if ($old_value === 'all' && $value === 'apply_new_posts') {
            $this->perform_page_eval_migration();
        }
    }

    /**
     * Called when a relevant global option is updated. If the current page-eval
     * mode is apply_new_posts or all we want to clear out any stored per-page
     * lists so that the new global value takes effect immediately.
     *
     * @param mixed $old_value
     * @param mixed $value
     * @param string $option
     */
    public function maybe_clear_lists_on_global_change($old_value, $value, $option) {
        $mode = get_option('ggc_global_page_eval_mode', 'none');
        if ($mode === 'apply_new_posts' || $mode === 'all') {
            ggc_debug_log("global option '{$option}' changed, running page eval migration");
            $this->perform_page_eval_migration();
        }
    }

    /**
     * Common cleanup routine used by both the option update hook and the one-time
     * initializer. Deletes UA/IP mode metadata set to "global" so that posts
     * revert to allow_all and can be configured individually.
     */
    private function perform_page_eval_migration() {
        $args = [
            'post_type'      => [ 'post', 'page' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_ggc_ua_control_mode',
                    'value'   => 'global',
                    'compare' => '=',
                ],
                [
                    'key'     => '_ggc_ip_control_mode',
                    'value'   => 'global',
                    'compare' => '=',
                ],
            ],
        ];
        $q = new WP_Query($args);
        $cleared = 0;
        if ($q->have_posts()) {
            foreach ($q->posts as $pid) {
                delete_post_meta($pid, '_ggc_ua_control_mode');
                delete_post_meta($pid, '_ggc_ip_control_mode');
                // also remove any stored lists that may have been copied when the
                // global "all" mode was active; they would otherwise persist and
                // cause old rules to be evaluated even after the mode change.
                delete_post_meta($pid, '_ggc_selected_crawlers');
                delete_post_meta($pid, '_ggc_selected_page_browser_patterns');
                delete_post_meta($pid, '_ggc_selected_ips');
                delete_post_meta($pid, '_ggc_selected_ips_2');
                $cleared++;
            }
        }
        wp_reset_postdata();
        // Debug logging for investigation
        ggc_debug_log("page eval migration ran, cleared settings on {$cleared} posts/pages");
        return $cleared;
    }

    /**
     * Initialization handler that runs the migration once if we haven't
     * already and the current global mode is apply_new_posts.
     */
    public function maybe_run_pending_page_eval_migration() {
        $flag = get_option('ggc_page_eval_mode_migration_done', '0');
        if ($flag !== '1') {
            ggc_debug_log('maybe_run_pending_page_eval_migration called');
        }
        if ($flag === '1') {
            return;
        }
        $current = get_option('ggc_global_page_eval_mode', 'none');
        if ($current === 'apply_new_posts') {
            $this->perform_page_eval_migration();
        }
        update_option('ggc_page_eval_mode_migration_done', '1');
    }

}
