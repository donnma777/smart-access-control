<?php
if (! defined('ABSPATH')) exit;

/**
 * Small AJAX response helpers to standardize JSON format.
 */
class Ajax_Utils {
    public static function success($data = [], $status = 200) {
        if ($status) status_header(intval($status));
        wp_send_json_success($data, $status);
    }

    public static function error($data = ['message' => 'Error'], $status = 400) {
        if ($status) status_header(intval($status));
        wp_send_json_error($data, $status);
    }
}
