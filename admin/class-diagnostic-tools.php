<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// separate trait for diagnostic tools UI so the huge admin class can stay focused
// on option handling.  similar pattern to Custom_Admin_Usage.

trait Custom_Admin_Diagnostic {
    /**
     * User-Agent定義・パターン共通のサニタイズ処理
     * @param array $input 入力配列
     * @param array $default デフォルト定義配列（省略可）
     * @param array $fields サポートするフィールド名リスト
     * @return array サニタイズ済み配列
     */
    public function sanitize_ua_definitions_common($input, $default = [], $fields = ['key','label','group_label','description','pattern','uas']) {
        if (!is_array($input)) return [];
        $new_input = [];
        $default_key_map = [];
        foreach ($default as $dkey => $_) {
            $default_key_map[strtolower($dkey)] = $dkey;
        }
        foreach ($input as $key => $row) {
            // フォーム送信時は $row['key'] に入力値が入る。
            // update_option() の直接呼び出し（インポート等）では $row['key'] が
            // 存在しない場合があるため、配列キーをフォールバックとして使用する。
            $raw_key = isset($row['key']) && $row['key'] !== '' ? $row['key'] : $key;
            $lk = strtolower($raw_key);
            // デフォルト定義に存在するキーは元の大文字小文字を維持する
            if (isset($default_key_map[$lk])) {
                $new_key = $default_key_map[$lk];
            } else {
                $new_key = sanitize_key($raw_key);
            }
            if (empty($new_key)) continue;
            $entry = [];
            foreach ($fields as $f) {
                if ($f === 'uas') {
                    $uas = isset($row['uas']) ? $row['uas'] : '';
                    if (is_string($uas)) {
                        $uas = array_map('trim', explode(',', $uas));
                    }
                    $entry['uas'] = is_array($uas) ? array_map('sanitize_text_field', array_filter($uas)) : [];
                } elseif ($f === 'pattern') {
                    $entry['pattern'] = sanitize_textarea_field($row['pattern'] ?? '');
                } elseif ($f === 'label') {
                    $entry['label'] = sanitize_text_field($row['label'] ?? '');
                } elseif ($f === 'group_label') {
                    $entry['group_label'] = sanitize_text_field($row['group_label'] ?? 'その他');
                } elseif ($f === 'description') {
                    $entry['description'] = sanitize_textarea_field($row['description'] ?? '');
                } else {
                    $entry[$f] = sanitize_text_field($row[$f] ?? '');
                }
            }
            // 完全に空のカスタムエントリーは排除
            $is_default = isset($default[$new_key]);
            if (!$is_default && empty($entry['pattern']) && empty($entry['uas']) && empty($entry['label'])) {
                continue;
            }
            $entry['is_default'] = $is_default;
            $new_input[$new_key] = $entry;
        }
        return $new_input;
    }

    /**
     * User-Agent定義・パターン共通のテーブル描画（簡易版）
     * @param array $defs 定義配列
     * @param array $default_defs デフォルト定義
     * @param string $type 'bot' or 'pattern'
     */
    public function render_ua_definitions_table_common($defs, $default_defs, $type = 'bot') {
        $is_bot = ($type === 'bot');
        ?>
        <table class="wp-list-table widefat fixed striped" id="ggc-<?php echo $is_bot ? 'bots' : 'patterns'; ?>-table">
            <thead>
                <tr>
                    <th class="ggc-th-20">定義キー (システム用) / グループ</th>
                    <th class="ggc-th-25">表示ラベル / 説明文</th>
                    <th class="ggc-th-45"><?php echo $is_bot ? 'User-Agent 文字列' : 'User-Agent パターン文字列'; ?></th>
                    <th class="ggc-th-10">操作</th>
                </tr>
            </thead>
            <tbody id="ggc-<?php echo $is_bot ? 'bots' : 'patterns'; ?>-tbody">
                <?php foreach ($defs as $key => $def) :
                    $is_default = isset($default_defs[$key]);
                    $val = $is_bot ? implode(', ', $def['uas'] ?? []) : ($def['pattern'] ?? '');
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p class="ggc-field-label"><strong>定義キー:</strong></p>
                        <input type="text" name="<?php echo $is_bot ? 'ggc_crawler_definitions' : 'ggc_browser_block_patterns'; ?>[<?php echo esc_attr($key); ?>][key]" value="<?php echo esc_attr($key); ?>" class="regular-text ggc-<?php echo $is_bot ? 'bot' : 'pattern'; ?>-key ggc-field-full" />
                        <p class="ggc-field-label"><strong>グループラベル:</strong></p>
                        <input type="text" name="<?php echo $is_bot ? 'ggc_crawler_definitions' : 'ggc_browser_block_patterns'; ?>[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($def['group_label'] ?? ''); ?>" class="regular-text ggc-field-full" />
                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="<?php echo $is_bot ? 'ggc_crawler_definitions' : 'ggc_browser_block_patterns'; ?>[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($def['label'] ?? ''); ?>" class="regular-text ggc-field-full" />
                        <p class="ggc-field-label"><strong>説明文:</strong></p>
                        <input type="text" name="<?php echo $is_bot ? 'ggc_crawler_definitions' : 'ggc_browser_block_patterns'; ?>[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($def['description'] ?? ''); ?>" class="regular-text ggc-field-full" />
                    </td>
                    <td>
                        <?php if ($is_bot): ?>
                            <textarea name="ggc_crawler_definitions[<?php echo esc_attr($key); ?>][uas]" rows="4" cols="50" class="large-text code ggc-field-full"><?php echo esc_textarea($val); ?></textarea>
                        <?php else: ?>
                            <textarea name="ggc_browser_block_patterns[<?php echo esc_attr($key); ?>][pattern]" rows="4" cols="50" class="large-text code ggc-field-full"><?php echo esc_textarea($val); ?></textarea>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-<?php echo $is_bot ? 'bot' : 'pattern'; ?>">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button button-primary" id="ggc-add-<?php echo $is_bot ? 'bot' : 'pattern'; ?>">新しい<?php echo $is_bot ? 'ボット定義' : '不正UAパターン'; ?>を追加</button></p>
        <?php
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
        $ip_ranges_1 = get_option('ggc_ip_range_definitions', []);
        $ip_ranges_2 = get_option('ggc_ip_range_definitions_2', []);

        // Merge defaults if empty
        if (empty($ip_ranges_1)) $ip_ranges_1 = ggc_get_default_ip_ranges();
        if (empty($ip_ranges_2)) $ip_ranges_2 = ggc_get_default_ip_ranges_2();

        // Custom_Crawler_Coreのip_in_cidrメソッドが存在するかをチェック
        $is_core_check_available = class_exists('Custom_Crawler_Core') && method_exists('Custom_Crawler_Core', 'ip_in_cidr');

        ?>
        <div id="ggc-diagnostic-tools">
            <h2>診断ツール</h2>
            <p class="description">現在のアクセス情報やCronスケジュールの確認、特定のIPアドレスのチェックができます。</p>

            <?php
            $this->render_diagnostic_access_info($client_ip, $user_agent);
            $this->render_diagnostic_schedule($frequency, $next_run, $next_schedule);
            $this->render_diagnostic_ip_test_form($client_ip);
            $this->render_diagnostic_ip_test_results($ip_ranges_1, $ip_ranges_2, $is_core_check_available);
            ?>
        </div>
        <?php
    }

    private function render_diagnostic_access_info($client_ip, $user_agent) {
        ?>
        <h3 class="ggc-heading-spaced">現在のアクセス情報</h3>
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
        <?php
    }

    /**
     * IPアドレス更新スケジュール共通テーブル出力
     * @param string $frequency
     * @param string $next_run
     * @param int|null $next_schedule
     * @param bool $show_schedule_info
     */
    private function render_ip_update_schedule_table($frequency, $next_run = '', $next_schedule = null, $show_schedule_info = true) {
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
        $last_update_time = GGC_Options::get_last_ip_update_time();
        $last_update_text = $last_update_time
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_update_time)
            : '未実行';
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">設定頻度</th>
                <td><?php echo esc_html($frequency_labels[$frequency] ?? '毎日'); ?></td>
            </tr>
            <tr>
                <th scope="row">前回の更新時刻</th>
                <td>
                    <?php echo esc_html($last_update_text); ?>
                    <?php if ($last_update_time): ?>
                        <p class="description">（<?php echo esc_html(human_time_diff($last_update_time, time())); ?>前）</p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($show_schedule_info): ?>
            <tr>
                <th scope="row">次回の実行予定時刻</th>
                <td>
                    <?php echo esc_html($next_run); ?>
                    <?php if ($next_schedule): ?>
                        <?php if (! class_exists('Display_Utils')) { require_once dirname(__DIR__) . '/includes/class-display-utils.php'; } ?>
                        <p class="description">（<?php echo Display_Utils::human_time_diff($next_schedule) . 'に実行予定'; ?>）</p>
                    <?php else: ?>
                        <p class="description ggc-desc-error">⚠️ スケジュールが設定されていません。グローバル設定に戻って保存し直してください。</p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    private function render_diagnostic_schedule($frequency, $next_run, $next_schedule) {
        ?>
        <h3 class="ggc-heading-spaced">IPアドレス更新スケジュール</h3>
        <?php $this->render_ip_update_schedule_table($frequency, $next_run, $next_schedule, true); ?>
        <?php
    }

    private function render_diagnostic_ip_test_form($client_ip) {
        ?>
        <h3 class="ggc-heading-spaced">IPアドレス範囲チェック（テスト）</h3>
        <form method="post" action="" id="ggc-ip-test-form">
            <?php wp_nonce_field('ggc_ip_range_test_nonce', 'ggc_ip_range_test_nonce_field'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="ggc_ip_to_test">テストするIPアドレス</label></th>
                    <td>
                        <input type="text" id="ggc_ip_to_test" name="ggc_ip_to_test" value="<?php echo esc_attr($client_ip); ?>" class="regular-text code ggc-input-compact" placeholder="例: 66.249.66.1" />
                        <input type="submit" class="button button-secondary" value="チェックを実行" />
                        <p class="description">指定したIPアドレスが、登録されているどのIP範囲定義に該当するかをチェックします。</p>
                    </td>
                </tr>
            </table>
        </form>
        <?php
    }

    private function render_diagnostic_ip_test_results($ip_ranges_1, $ip_ranges_2, $is_core_check_available) {
        if (!isset($_POST['ggc_ip_to_test']) || !check_admin_referer('ggc_ip_range_test_nonce', 'ggc_ip_range_test_nonce_field')) {
            return;
        }

        $ip_to_test = sanitize_text_field(wp_unslash($_POST['ggc_ip_to_test']));

        if (!filter_var($ip_to_test, FILTER_VALIDATE_IP)) {
            echo '<div class="notice notice-error"><p><strong>IPチェック結果:</strong> 入力された値は有効なIPアドレスではありません。</p></div>';
            return;
        }

        if (!$is_core_check_available) {
            echo '<div class="notice notice-info"><p><strong>IPチェック情報:</strong> IPアドレスチェックを実行するコア機能が利用できません。プラグインのコアファイル (class-crawler-core.php) が正しく読み込まれているか確認してください。</p></div>';
            return;
        }

        $matched_results = [];

        foreach ($ip_ranges_1 as $key => $ip_def) {
            $ranges = $ip_def['validated_ranges'] ?? $ip_def['ranges'] ?? [];
            if (is_array($ranges)) {
                foreach ($ranges as $cidr) {
                    if (Custom_Crawler_Core::ip_in_cidr($ip_to_test, $cidr)) {
                        $matched_results[] = [
                            'list' => 'IPアドレス範囲1',
                            'key' => $key,
                            'label' => $ip_def['label'] ?? $key
                        ];
                        break;
                    }
                }
            }
        }

        foreach ($ip_ranges_2 as $key => $ip_def) {
            $ranges = $ip_def['validated_ranges'] ?? $ip_def['ranges'] ?? [];
            if (is_array($ranges)) {
                foreach ($ranges as $cidr) {
                    if (Custom_Crawler_Core::ip_in_cidr($ip_to_test, $cidr)) {
                        $matched_results[] = [
                            'list' => 'IPアドレス範囲2',
                            'key' => $key,
                            'label' => $ip_def['label'] ?? $key
                        ];
                        break;
                    }
                }
            }
        }

        if (!empty($matched_results)) {
            echo '<div class="notice notice-success"><p><strong>IPチェック結果:</strong> IPアドレス <code>' . esc_html($ip_to_test) . '</code> は以下の定義範囲に一致しました。</p>';
            echo '<ul>';
            foreach ($matched_results as $match) {
                echo '<li><strong>' . esc_html($match['list']) . '</strong> - <strong>' . esc_html($match['label']) . '</strong> (キー: <code>' . esc_html($match['key']) . '</code>)</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>IPチェック結果:</strong> IPアドレス <code>' . esc_html($ip_to_test) . '</code> は、登録されている<strong>どのIP範囲にも一致しませんでした</strong>。</p></div>';
        }
    }

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
}
