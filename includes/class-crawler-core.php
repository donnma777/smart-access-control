<?php
// custom-crawler-control\includes\class-crawler-core.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Crawler_Core {

    protected static $instance = null;
    // HTTP fetch details for last update run
    private $last_update_fetch_details = [];

    private function __construct() {
        // Cron スケジュールフック
        add_filter('cron_schedules', [ $this, 'custom_cron_schedules' ]);
        // Cron イベントフック
        add_action('ggc_daily_ip_update', [ $this, 'update_all_ip_ranges' ]);
        // アクセス制御実行フック
        add_action('template_redirect', [ $this, 'perform_blocking' ]);
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
        $bots = get_option('ggc_crawler_definitions', []);
        return is_array($bots) ? $bots : [];
    }

    public static function get_allowable_ip_ranges() {
        $ip_ranges = get_option('ggc_ip_range_definitions', []);
        return is_array($ip_ranges) ? $ip_ranges : [];
    }
    
    public static function get_browser_block_patterns() {
        $patterns = get_option('ggc_browser_block_patterns', []);
        return is_array($patterns) ? $patterns : [];
    }

    public static function get_default_control_active() {
        return get_option('ggc_default_control_active', 'no');
    }

    private static function get_client_ip() {
        $keys = [
            'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'
        ];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = filter_var(wp_unslash($_SERVER[$key]), FILTER_SANITIZE_STRING);
                $ip_parts = explode(',', $ip);
                $ip = trim(end($ip_parts));
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    private static function ip_in_cidr($ip, $cidr) {
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

    private static function die_forbidden($message) {
        $title = 'アクセス禁止';
        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            wp_send_json_error(['code' => 'crawler_forbidden', 'message' => $message], 403);
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            wp_send_json_error(['code' => 'crawler_forbidden', 'message' => $message], 403);
        }
        
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false || stripos(wp_unslash($accept), 'text/json') !== false) {
             header('Content-Type: application/json', true, 403);
             echo json_encode(['code' => 'crawler_forbidden', 'message' => $message]);
             exit; 
        } 
        
        wp_die($message, $title, ['response' => 403]);
    }


    // --------------------------------------------------------
    // B. CronとIPアドレス更新
    // --------------------------------------------------------

    public function custom_cron_schedules($schedules) {
        error_log('GGC Debug: custom_cron_schedules called.');
        if (!isset($schedules['hourly'])) {
            $schedules['hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => 'Once Hourly'];
            error_log('GGC Debug: Added custom hourly schedule.');
        }
        if (!isset($schedules['twicedaily'])) {
            $schedules['twicedaily'] = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Twice Daily'];
            error_log('GGC Debug: Added custom twicedaily schedule.');
        }
        if (!isset($schedules['daily'])) {
            $schedules['daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'];
            error_log('GGC Debug: Added custom daily schedule.');
        }
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => 'Once Weekly'];
            error_log('GGC Debug: Added custom weekly schedule.');
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Once Monthly']; // Approximately 30 days
            error_log('GGC Debug: Added custom monthly schedule.');
        }
        if (!isset($schedules['biannually'])) {
            $schedules['biannually'] = ['interval' => 6 * 30 * DAY_IN_SECONDS, 'display' => 'Once Biannually']; // Approximately 6 months
            error_log('GGC Debug: Added custom biannually schedule.');
        }
        if (!isset($schedules['annually'])) {
            $schedules['annually'] = ['interval' => 365 * DAY_IN_SECONDS, 'display' => 'Once Annually']; // Approximately 365 days
            error_log('GGC Debug: Added custom annually schedule.');
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
        $original_ip_ranges = self::get_allowable_ip_ranges();
        $final_ip_ranges = $original_ip_ranges; // Start with a full copy.

        $this->last_update_fetch_details = [];
        $results_list = [];
        $has_success = false;

        // Loop over the original, but update the copy.
        foreach ($original_ip_ranges as $key => $def) {
            if (empty($def['is_auto']) || empty($def['source_url'])) {
                continue; // Skip if not auto-update.
            }
            
            $log_key = strtolower($key);
            $log_entry = [];
            $new_ips = $this->process_ip_range_update($def['source_url'], $log_entry);
            
            $this->last_update_fetch_details[$log_key] = $log_entry;

            if ($new_ips !== false) {
                // Update the corresponding entry in our final array
                $final_ip_ranges[$key]['ranges'] = $new_ips;
                $final_ip_ranges[$key]['validated_ranges'] = $new_ips;
                $final_ip_ranges[$key]['last_parse_error'] = null;
                $final_ip_ranges[$key]['last_parse_time'] = time();
                $final_ip_ranges[$key]['last_parse_count'] = count($new_ips);
                
                $results_list[] = ['key' => $key, 'label' => $def['label'] ?? $key, 'count' => count($new_ips)];
                $has_success = true;
            } else {
                // Even on failure, update the status in our final array
                $final_ip_ranges[$key]['last_parse_error'] = $log_entry['error'] ?? 'Unknown error';
                $final_ip_ranges[$key]['last_parse_time'] = time();
                $final_ip_ranges[$key]['last_parse_count'] = 0;
                
                $results_list[] = ['key' => $key, 'label' => $def['label'] ?? $key, 'count' => false];
            }
        }

        // After the loop, if any update was successful, save the complete, modified array.
        if ($has_success) {
            update_option('ggc_ip_range_definitions', $final_ip_ranges);
        }

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

    public function perform_blocking() {

        if ( is_admin() ) return;
        if ( defined('REST_REQUEST') && REST_REQUEST ) return;
        if ( defined('WP_CLI') && WP_CLI ) return;
        if ( in_array($_SERVER['REQUEST_METHOD'], ['HEAD','OPTIONS'], true) ) return;
        if ( is_404() || is_feed() ) return;

        $post_id = get_queried_object_id();
        if (is_user_logged_in()) {
            return;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // Determine control_active state for the current request
        $control_active = 'no'; // Default to no control
        if ($post_id && is_singular()) {
            $post_control_active = get_post_meta($post_id, '_ggc_control_active', true);
            if ($post_control_active === '') {
                // If post meta not set, use global default
                $control_active = self::get_default_control_active();
            } else {
                // Use post meta value
                $control_active = $post_control_active;
            }
        }

        // 1. 不正ブラウザパターンチェック (Now conditional)
        if ($control_active === 'yes') {
            $all_global_patterns_structured = self::get_browser_block_patterns();
            $blocking_patterns_strings = [];
            
            foreach ($all_global_patterns_structured as $key => $pattern_def) {
                 if (isset($pattern_def['pattern']) && !empty($pattern_def['pattern'])) {
                     $blocking_patterns_strings[$key] = $pattern_def['pattern']; 
                 }
            }
            
            if ($post_id && is_singular()) {
                $selected_page_pattern_keys = get_post_meta($post_id, '_ggc_selected_page_browser_patterns', true);
                if (is_array($selected_page_pattern_keys)) {
                    foreach ($selected_page_pattern_keys as $key) {
                        if (isset($all_global_patterns_structured[$key]) && isset($all_global_patterns_structured[$key]['pattern'])) {
                            if (!isset($blocking_patterns_strings[$key])) {
                                $blocking_patterns_strings[$key] = $all_global_patterns_structured[$key]['pattern'];
                            }
                        }
                    }
                }
            }
            
            $blocking_patterns = array_values($blocking_patterns_strings); 
            $should_block_pattern = false;

            if (!empty($blocking_patterns)) {
                foreach ($blocking_patterns as $pattern) {
                    if (stripos($user_agent, $pattern) !== false) {
                        $should_block_pattern = true;
                        break;
                    }
                }
            }

            if ($should_block_pattern) {
                self::die_forbidden('アクセス禁止：悪意のある、または不正なクローラー/ブラウザとして検出されました。');
            }
        }

        // 2. クローラー制御チェック
        if ($control_active !== 'yes') {
            return;
        }

        $control_mode = get_post_meta($post_id, '_ggc_control_mode', true) ?: 'blacklist'; 
        $selected_crawlers = get_post_meta($post_id, '_ggc_selected_crawlers', true);
        $selected_ips = get_post_meta($post_id, '_ggc_selected_ips', true); 

        if (!is_array($selected_crawlers)) $selected_crawlers = [];
        if (!is_array($selected_ips)) $selected_ips = [];

        $all_bots = self::get_allowable_bots();
        $all_crawler_uas = [];

        foreach ($all_bots as $bot) {
            if (is_array($bot['uas'])) {
                $all_crawler_uas = array_merge($all_crawler_uas, $bot['uas']);
            }
        }
        $all_crawler_uas = array_filter(array_unique($all_crawler_uas));

        $is_a_defined_crawler = false;
        if (!empty($user_agent)) {
            foreach ($all_crawler_uas as $ua_pattern) {
                if (stripos($user_agent, $ua_pattern) !== false) {
                    $is_a_defined_crawler = true;
                    break;
                }
            }
        }

        if (!$is_a_defined_crawler) {
            return;
        }

        $should_block_ua = false;
        $should_block_ip = false;
        $message_ua = '';

        if ($control_mode === 'blacklist') {
            $is_selected_for_blocking = false;
            foreach ($selected_crawlers as $key_to_block) {
                if (isset($all_bots[$key_to_block]) && is_array($all_bots[$key_to_block]['uas'])) {
                    foreach ($all_bots[$key_to_block]['uas'] as $ua_pattern) {
                        if (stripos($user_agent, $ua_pattern) !== false) {
                            $is_selected_for_blocking = true;
                            break 2;
                        }
                    }
                }
            }
            if ($is_selected_for_blocking) {
                $should_block_ua = true;
                $message_ua = 'アクセス禁止：ブラックリストに登録されたクローラーUser-Agentです。';
            }
        } else {
            $is_selected_for_allowing = false;
            foreach ($selected_crawlers as $key_to_allow) {
                if (isset($all_bots[$key_to_allow]) && is_array($all_bots[$key_to_allow]['uas'])) {
                    foreach ($all_bots[$key_to_allow]['uas'] as $ua_pattern) {
                        if (stripos($user_agent, $ua_pattern) !== false) {
                            $is_selected_for_allowing = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$is_selected_for_allowing) {
                $should_block_ua = true;
                $message_ua = 'アクセス禁止：ホワイトリストに登録されていないクローラーUser-Agentです。';
            }
        }

        if (!$should_block_ua && !empty($selected_ips)) {
            $is_ip_in_range = self::is_in_allowable_ip_range($selected_ips);
            if (!$is_ip_in_range) {
                $should_block_ip = true;
                $message_ip = 'アクセス禁止：User-Agentは許可されていますが、IPアドレスが許可範囲外です。';
            }
        }

        if ($should_block_ua) {
            self::die_forbidden($message_ua);
        }
        if ($should_block_ip) {
            self::die_forbidden($message_ip);
        }

    }
}