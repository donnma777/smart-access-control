<?php
if (! defined('ABSPATH')) exit;

/**
 * Small display helpers for dates and times used in admin UI.
 */
class Display_Utils {
    /**
     * Wrapper around human_time_diff that appends the Japanese suffix.
     * Accepts either a timestamp or null.
     */
    public static function human_time_diff($from, $to = 0) {
        if (empty($from)) return '未実行';
        if (empty($to)) $to = current_time('timestamp');
        $from = intval($from);
        $to = intval($to);
        $diff = human_time_diff($from, $to);
        if ($from <= $to) {
            return $diff . '前';
        }
        return $diff . '後';
    }

    /**
     * Format a timestamp for display using wp_date (locale-aware).
     */
    public static function format_datetime($timestamp, $format = 'Y-m-d H:i') {
        if (empty($timestamp)) return '';
        return wp_date($format, intval($timestamp));
    }
}
