<?php
if (!defined('ABSPATH')) exit;

require_once dirname(__DIR__) . '/includes/class-option-utils.php';
// display helpers
require_once dirname(__DIR__) . '/includes/class-display-utils.php';
if (!class_exists('IP_Utils')) {
    require_once dirname(__DIR__) . '/includes/class-ip-utils.php';
}

class Admin_IP_Ranges {

    public static function render_section_1() {
        $ip_ranges = GGC_Options::get_ip_range_definitions_1() ?: [];
        $default_ip_ranges = ggc_get_default_ip_ranges();
        self::render_intro();
        self::render_update_controls('ggc-run-ip-update');
        self::render_table($ip_ranges, $default_ip_ranges, 'ggc_ip_range_definitions', 'ggc-ip-ranges-table', 'ggc-ip-ranges-tbody');
        self::render_template('ggc_ip_range_definitions', 'ggc-ip-row-template', 'ggc-add-ip');
    }

    public static function render_section_2() {
        $ip_ranges = GGC_Options::get_ip_range_definitions_2() ?: [];
        $default_ip_ranges = ggc_get_default_ip_ranges_2();
        self::render_intro();
        self::render_update_controls('ggc-run-ip-update-2');
        self::render_table($ip_ranges, $default_ip_ranges, 'ggc_ip_range_definitions_2', 'ggc-ip-ranges-table-2', 'ggc-ip-ranges-tbody-2');
        self::render_template('ggc_ip_range_definitions_2', 'ggc-ip-row-template-2', 'ggc-add-ip-2');
    }

    public static function render_intro() {
        ?>
        <p class="description">
            投稿ごとの制御機能で使用するIPアドレス範囲のリストです。カスタムのIP範囲を追加・編集できます。
        </p>
        <p class="description">
            - 定義キー : システム用、一意のキー。必須項目。英数字のみ登録可能。<br/>
            - グローバルラベル : 管理画面でのグループ分けに使用されます。<br/>
            - プレースホルダを許可 : （形式チェックに失敗してもこの値を保持します）<br/>
            - 自動更新 : チェックすると保存時にURLから自動取得されます<br/>
            - 説明文 : 管理画面での説明文。<br/>
            - 表示ラベル : 管理画面での表示ラベル。<br/>
            - 取得元URL (自動更新用) : IPアドレス範囲を定期的に自動取得するためのURLを指定します。<br/>
            IPアドレス範囲 (CIDR形式) : 1行にIPv4またはIPv6の範囲を1つのCIDR形式で入力してください。例: <code>192.168.0.0/16</code><br/>
        </p>
        <?php
    }

    public static function render_update_controls($button_id) {
        ?>
        <p>
            <?php $manual_url = wp_nonce_url( admin_url('admin-post.php?action=run_ggc_ip_update'), 'ggc_manual_ip_update_nonce' ); ?>
            <button type="button" id="<?php echo esc_attr($button_id); ?>" class="button button-secondary ggc-run-ip-update-btn" data-nonce="<?php echo esc_attr(wp_create_nonce('ggc_run_update_nonce')); ?>" data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-manual-url="<?php echo esc_url($manual_url); ?>">
                <span class="ggc-btn-text">今すぐ IP 更新を強制実行する</span>
            </button>
            <span class="description ggc-inline-spacer">(前回更新: <?php 
                $lt = GGC_Options::get_last_ip_update_time(); 
                if ($lt) {
                    echo wp_date('Y/m/d H:i:s', $lt);
                } else {
                    echo '未実行';
                }
            ?>)</span>
            <noscript><a href="<?php echo esc_url($manual_url); ?>" class="button ggc-inline-spacer">JavaScript無効時に更新を実行</a></noscript>
        </p>
        <?php
    }

    public static function render_table($ip_ranges, $default_ip_ranges, $field_prefix, $table_id, $tbody_id) {
        ?>
        <table class="wp-list-table widefat fixed striped" id="<?php echo esc_attr($table_id); ?>">
            <thead>
                <tr>
                    <th class="ggc-th-25">定義キー / グループ</th>
                    <th class="ggc-th-30">表示ラベル / 説明文 / 取得元URL</th>
                    <th class="ggc-th-35">IPアドレス範囲 (CIDR形式)</th>
                    <th class="ggc-th-10">操作</th>
                </tr>
            </thead>
            <tbody id="<?php echo esc_attr($tbody_id); ?>">
                <?php foreach ($ip_ranges as $key => $ip_def) :
                    $is_default = isset($default_ip_ranges[$key]);
                    $ranges_str = implode("\n", $ip_def['ranges'] ?? []);
                    $source_url = $ip_def['source_url'] ?? '';
                ?>
                <tr data-key="<?php echo esc_attr($key); ?>">
                    <td>
                        <p class="ggc-field-label"><strong>定義キー:</strong></p>
                        <input type="text"
                               name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][key]"
                               value="<?php echo esc_attr($key); ?>"
                               class="regular-text ggc-ip-key ggc-field-full" />
                        <p class="ggc-field-label"><strong>グループラベル:</strong></p>
                        <input type="text" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][group_label]" value="<?php echo esc_attr($ip_def['group_label'] ?? ''); ?>" class="regular-text ggc-field-full" />
                        <p class="ggc-field-label ggc-field-label--spaced"><label><input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][allow_placeholder]" value="1" <?php checked(!empty($ip_def['allow_placeholder']), true); ?> /> <strong>プレースホルダを許可</strong></label></p>
                        <p class="ggc-field-label ggc-field-label--tight"><label><input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][is_auto]" value="1" <?php checked(!empty($ip_def['is_auto']), true); ?> /> 自動更新 </label></p>
                    </td>
                    <td>
                        <p><strong>表示ラベル:</strong></p>
                        <input type="text" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($ip_def['label'] ?? ''); ?>" class="regular-text ggc-field-full" />
                        <p class="ggc-field-label"><strong>説明文:</strong></p>
                        <input type="text" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][description]" value="<?php echo esc_attr($ip_def['description'] ?? ''); ?>" class="regular-text ggc-field-full" />
                        <p class="ggc-field-label ggc-field-label--tight"><strong>取得元URL (自動更新用):</strong></p>
                        <input type="text" name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][source_url]" value="<?php echo esc_url($source_url); ?>" class="regular-text ggc-field-source" placeholder="https://..." />
                    </td>
                    <td>
                        <textarea name="<?php echo esc_attr($field_prefix); ?>[<?php echo esc_attr($key); ?>][ranges]" rows="6" cols="50" class="large-text code ggc-field-full" <?php disabled($ip_def['is_auto'] ?? false, true); ?>><?php echo esc_textarea($ranges_str); ?></textarea>
                        <p class="description">IPv4またはIPv6のCIDR形式。</p>
                        <div class="ggc-parse-status">
                            <?php if (!empty($ip_def['last_parse_error'])): ?>
                                <p class="ggc-status-error"><strong>解析エラー:</strong> <?php echo esc_html($ip_def['last_parse_error']); ?> (<?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>)</p>
                            <?php elseif (!empty($ip_def['last_parse_time'])): ?>
                                <p class="ggc-status-success"><strong>最終解析成功:</strong> <?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($ip_def['last_parse_time'] ?? 0)); ?>
                                <?php if (isset($ip_def['last_parse_count'])): ?>
                                    (<?php echo esc_html(number_format($ip_def['last_parse_count'])); ?> 件)
                                <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ip_def['is_auto'])): ?>
                            <p class="ggc-status-auto">✅ 自動更新対象 (テキスト入力は無視されます)</p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function render_template($field_prefix, $template_id, $add_button_id) {
        ?>
        <p><button type="button" class="button button-primary" id="<?php echo esc_attr($add_button_id); ?>">新しいIP範囲定義を追加</button></p>

        <script type="text/template" id="<?php echo esc_attr($template_id); ?>">
            <tr class="ggc-ip-row new-row" data-key="__KEY__">
                <td>
                    <p class="ggc-field-label"><strong>定義キー:</strong></p>
                    <input type="text" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][key]" value="__KEY__" class="regular-text ggc-ip-key ggc-field-full" />
                    <p class="ggc-field-label"><strong>グループラベル:</strong></p>
                    <input type="text" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][group_label]" value="カスタム" class="regular-text ggc-field-full" />
                    <p class="ggc-field-label ggc-field-label--spaced"><label><input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][allow_placeholder]" value="1" checked="checked" /> <strong>プレースホルダを許可</strong></label></p>
                    <p class="ggc-field-label ggc-field-label--tight"><label><input type="checkbox" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][is_auto]" value="1" checked="checked" /> 自動更新 (チェックすると保存時にURLから自動取得されます)</label></p>
                </td>
                <td>
                    <p><strong>表示ラベル:</strong></p>
                    <input type="text" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][label]" value="カスタムIP範囲" class="regular-text ggc-field-full" />
                    <p class="ggc-field-label"><strong>説明文:</strong></p>
                    <input type="text" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][description]" value="" class="regular-text ggc-field-full" />
                    <p class="ggc-field-label ggc-field-label--tight"><strong>取得元URL (自動更新用):</strong></p>
                    <input type="text" name="<?php echo esc_attr($field_prefix); ?>[__KEY__][source_url]" value="" class="regular-text ggc-field-source" placeholder="https://..." />
                </td>
                <td>
                    <textarea name="<?php echo esc_attr($field_prefix); ?>[__KEY__][ranges]" rows="4" cols="50" class="large-text code ggc-field-full"></textarea>
                    <p class="description">IPv4またはIPv6のCIDR形式。</p>
                </td>
                <td>
                    <button type="button" class="button button-secondary ggc-remove-row ggc-remove-ip">削除</button>
                </td>
            </tr>
        </script>
        <?php
    }

    public static function sanitize_definitions_1($input) {
        // copy of previous sanitize_ip_range_definitions implementation
        $current = GGC_Options::get_ip_range_definitions_1() ?: [];
        $defaults = ggc_get_default_ip_ranges();
        $default_keys_map = [];
        foreach ($defaults as $def_key => $def_val) {
            $default_keys_map[strtolower($def_key)] = $def_key;
        }

        if (!is_array($input)) return [];

        $new_input = [];

        $simple_validate_ip_cidr = function ($range) {
            $range = trim($range);
            if (empty($range)) return false;
            if (IP_Utils::is_valid_ip_or_cidr($range)) return sanitize_text_field($range);
            return false;
        };

        foreach ($input as $key => $ip_def) {
            $raw_key = $ip_def['key'] ?? $key;
            if (empty(trim($raw_key))) continue;

            $lower_key = strtolower($raw_key);
            $new_key = '';
            $is_default_key = false;

            if (array_key_exists($lower_key, $default_keys_map)) {
                $new_key = $default_keys_map[$lower_key];
                $is_default_key = true;
            } else {
                $sanitized_key = sanitize_key($raw_key);
                $new_key = !empty($sanitized_key) ? $sanitized_key : sanitize_key($key);
            }

            $ranges_input = '';
            if (isset($ip_def['ranges'])) {
                $ranges_input = is_array($ip_def['ranges']) ? implode("\n", array_map('strval', $ip_def['ranges'])) : (string) $ip_def['ranges'];
            }

            if (!$is_default_key && empty($ranges_input) && empty($ip_def['label']) && empty($ip_def['source_url'])) {
                continue;
            }

            // Initialize variables for this loop iteration
            $loop_last_parse_error = null;
            $loop_last_parse_time = null;
            $loop_last_parse_count = 0;

            $ranges = preg_split('/[\r\n,]+/', $ranges_input, -1, PREG_SPLIT_NO_EMPTY);
            $ranges = array_map('trim', $ranges);
            $sanitized_ranges_raw = array_values(array_map('sanitize_text_field', array_filter($ranges)));
            $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $ranges)));

            $is_auto = !empty($ip_def['is_auto']);
            $default_def = $current[$new_key] ?? ['source_url' => ''];
            $source_url_to_save = isset($ip_def['source_url']) ? esc_url_raw(trim($ip_def['source_url'])) : $default_def['source_url'];
            if (empty($source_url_to_save) && isset($defaults[$new_key]['source_url'])) {
                $source_url_to_save = $defaults[$new_key]['source_url'];
            }

            if ($is_auto && !empty($source_url_to_save)) {
                $meta = [];
                $parsed = IP_Utils::parse_ip_list_from_url($source_url_to_save, $meta);
                $loop_last_parse_time = time();

                if (is_wp_error($parsed)) {
                    $loop_last_parse_error = $parsed->get_error_message();
                    $loop_last_parse_count = isset($meta['count']) ? intval($meta['count']) : 0;
                    add_settings_error('ggc_ips_option_group', 'ggc_parse_failed_' . $new_key, sprintf('%s の自動解析に失敗しました: %s', esc_html($new_key), esc_html($loop_last_parse_error)), 'error');
                } else {
                    $parsed_clean = array_values(array_map('sanitize_text_field', $parsed));
                    $sanitized_ranges_raw = $parsed_clean;
                    $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $parsed_clean)));
                    $loop_last_parse_count = count($sanitized_ranges_raw);
                }
            } else {
                // If not auto-updating on this save, preserve the last known status.
                $loop_last_parse_error = $current[$new_key]['last_parse_error'] ?? null;
                $loop_last_parse_time = $current[$new_key]['last_parse_time'] ?? null;
                $loop_last_parse_count = $current[$new_key]['last_parse_count'] ?? 0;
            }

            $new_input[$new_key] = [
                'ranges' => $sanitized_ranges_raw,
                'validated_ranges' => empty($sanitized_ranges_valid) ? null : $sanitized_ranges_valid,
                'allow_placeholder' => !empty($ip_def['allow_placeholder']),
                'label' => sanitize_text_field($ip_def['label'] ?? ($current[$new_key]['label'] ?? '')),
                'group_label' => sanitize_text_field($ip_def['group_label'] ?? ($current[$new_key]['group_label'] ?? 'その他')),
                'description' => sanitize_textarea_field($ip_def['description'] ?? ($current[$new_key]['description'] ?? '')),
                'source_url' => $source_url_to_save,
                'is_default' => $is_default_key,
                'is_auto' => $is_auto,
                'last_parse_error' => $loop_last_parse_error,
                'last_parse_time' => $loop_last_parse_time,
                'last_parse_count' => $loop_last_parse_count,
            ];

            if ($is_auto && $is_default_key && isset($defaults[$new_key])) {
                $new_input[$new_key]['label'] = $defaults[$new_key]['label'];
                $new_input[$new_key]['group_label'] = $defaults[$new_key]['group_label'] ?? 'その他';
                $new_input[$new_key]['description'] = $defaults[$new_key]['description'];
            }
        }
        return $new_input;
    }

    public static function sanitize_definitions_2($input) {
        // copy of previous sanitize_ip_range_definitions_2 implementation
        $current = GGC_Options::get_ip_range_definitions_2() ?: [];
        $defaults = ggc_get_default_ip_ranges_2();
        $default_keys_map = [];
        foreach ($defaults as $def_key => $def_val) {
            $default_keys_map[strtolower($def_key)] = $def_key;
        }

        if (!is_array($input)) return [];

        $new_input = [];

        $simple_validate_ip_cidr = function ($range) {
            $range = trim($range);
            if (empty($range)) return false;
            if (IP_Utils::is_valid_ip_or_cidr($range)) return sanitize_text_field($range);
            return false;
        };

        foreach ($input as $key => $ip_def) {
            $raw_key = $ip_def['key'] ?? $key;
            if (empty(trim($raw_key))) continue;

            $lower_key = strtolower($raw_key);
            $new_key = '';
            $is_default_key = false;

            if (array_key_exists($lower_key, $default_keys_map)) {
                $new_key = $default_keys_map[$lower_key];
                $is_default_key = true;
            } else {
                $sanitized_key = sanitize_key($raw_key);
                $new_key = !empty($sanitized_key) ? $sanitized_key : sanitize_key($key);
            }

            $ranges_input = '';
            if (isset($ip_def['ranges'])) {
                $ranges_input = is_array($ip_def['ranges']) ? implode("\n", array_map('strval', $ip_def['ranges'])) : (string) $ip_def['ranges'];
            }

            if (!$is_default_key && empty($ranges_input) && empty($ip_def['label']) && empty($ip_def['source_url'])) {
                continue;
            }

            $ranges = preg_split('/[\r\n,]+/', $ranges_input, -1, PREG_SPLIT_NO_EMPTY);
            $ranges = array_map('trim', $ranges);
            $sanitized_ranges_raw = array_values(array_map('sanitize_text_field', array_filter($ranges)));
            $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $ranges)));

            $is_auto = !empty($ip_def['is_auto']);
            $default_def = $current[$new_key] ?? ['source_url' => ''];
            $source_url_to_save = isset($ip_def['source_url']) ? esc_url_raw(trim($ip_def['source_url'])) : $default_def['source_url'];

            // Initialize variables for this loop iteration
            $loop_last_parse_error = null;
            $loop_last_parse_time = null;
            $loop_last_parse_count = 0;

            if ($is_auto && !empty($source_url_to_save)) {
                $meta = [];
                $parsed = IP_Utils::parse_ip_list_from_url($source_url_to_save, $meta);
                $loop_last_parse_time = time();

                if (is_wp_error($parsed)) {
                    $loop_last_parse_error = $parsed->get_error_message();
                    $loop_last_parse_count = isset($meta['count']) ? intval($meta['count']) : 0;
                    add_settings_error('ggc_ips2_option_group', 'ggc_parse_failed_' . $new_key, sprintf('%s の自動解析に失敗しました: %s', esc_html($new_key), esc_html($loop_last_parse_error)), 'error');
                } else {
                    $parsed_clean = array_values(array_map('sanitize_text_field', $parsed));
                    $sanitized_ranges_raw = $parsed_clean;
                    $sanitized_ranges_valid = array_values(array_filter(array_map($simple_validate_ip_cidr, $parsed_clean)));
                    $loop_last_parse_count = count($sanitized_ranges_raw);
                }
            } else {
                // If not auto-updating on this save, preserve the last known status.
                $loop_last_parse_error = $current[$new_key]['last_parse_error'] ?? null;
                $loop_last_parse_time = $current[$new_key]['last_parse_time'] ?? null;
                $loop_last_parse_count = $current[$new_key]['last_parse_count'] ?? 0;
            }

            $new_input[$new_key] = [
                'ranges' => $sanitized_ranges_raw,
                'validated_ranges' => $sanitized_ranges_valid,
                'label' => sanitize_text_field($ip_def['label'] ?? ''),
                'group_label' => sanitize_text_field($ip_def['group_label'] ?? 'その他'),
                'description' => sanitize_textarea_field($ip_def['description'] ?? ''),
                'allow_placeholder' => !empty($ip_def['allow_placeholder']),
                'is_auto' => $is_auto,
                'source_url' => $source_url_to_save,
                'last_parse_error' => $loop_last_parse_error,
                'last_parse_time' => $loop_last_parse_time,
                'last_parse_count' => $loop_last_parse_count,
            ];
        }
        return $new_input;
    }

}
