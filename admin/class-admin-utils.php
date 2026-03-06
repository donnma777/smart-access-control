<?php
// admin/class-admin-utils.php
if (! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Ajax helpers for standardized JSON responses
if (! class_exists('Ajax_Utils')) {
    require_once dirname(__DIR__) . '/includes/class-ajax-utils.php';
}

class Admin_Utils {
    /**
     * Check AJAX nonce and capability; on failure respond with JSON error and exit.
     * @param string $nonce_action
     * @param string $nonce_field
     * @param string|null $capability
     * @param array $cap_args
     */
    public static function ajax_require_nonce_and_cap($nonce_action, $nonce_field = 'nonce', $capability = null, $cap_args = []) {
        check_ajax_referer($nonce_action, $nonce_field);
        if ($capability) {
            $ok = empty($cap_args) ? current_user_can($capability) : current_user_can($capability, ...$cap_args);
            if (! $ok) {
                Ajax_Utils::error(['message' => 'Permission denied'], 403);
            }
        }
    }

    /**
     * Verify save-post nonce and capability for post saves. Returns boolean.
     */
    public static function verify_post_save_nonce_and_cap($nonce_field, $nonce_action, $post_id) {
        if (! isset($_POST[$nonce_field]) || ! wp_verify_nonce($_POST[$nonce_field], $nonce_action)) {
            return false;
        }
        if (( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )) {
            return false;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return false;
        }
        return true;
    }

    public static function require_manage_options_or_json() {
        if (! current_user_can('manage_options')) {
            Ajax_Utils::error(['message' => 'Permission denied'], 403);
        }
    }

    public static function require_manage_options_or_die() {
        if (! current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
    }

    /**
     * Boolean check wrapper for manage_options capability.
     * Use when original behavior early-returns instead of dying.
     */
    public static function current_user_can_manage_options() {
        return current_user_can('manage_options');
    }

    /**
     * Boolean wrapper for edit_post capability.
     */
    public static function current_user_can_edit_post($post_id) {
        return current_user_can('edit_post', $post_id);
    }

    /**
     * Require edit_post capability or die (useful for non-AJAX endpoints).
     */
    public static function require_edit_post_or_die($post_id) {
        if (! self::current_user_can_edit_post($post_id)) {
            wp_die('Permission denied');
        }
    }

    /**
     * Boolean wrapper for edit_posts capability (plural).
     */
    public static function current_user_can_edit_posts() {
        return current_user_can('edit_posts');
    }

    /**
     * Require GET nonce (from query string) and optional capability, or die.
     * Common use for admin_action handlers invoked via GET links.
     * @param string $nonce_action
     * @param string $nonce_field
     * @param string|null $capability
     * @return void
     */
    public static function require_get_nonce_and_cap($nonce_action, $nonce_field = '_wpnonce', $capability = null) {
        if (! isset($_GET[$nonce_field]) || ! wp_verify_nonce(sanitize_key(wp_unslash($_GET[$nonce_field])), $nonce_action)) {
            wp_die('セキュリティチェックに失敗しました。');
        }
        if ($capability && ! current_user_can($capability)) {
            wp_die('Permission denied');
        }
    }

    /**
     * Verify GET nonce and capability, return boolean.
     * @param string $nonce_action
     * @param string $nonce_field
     * @param string|null $capability
     * @return bool
     */
    public static function verify_get_nonce_and_cap($nonce_action, $nonce_field = '_wpnonce', $capability = null) {
        if (! isset($_GET[$nonce_field]) || ! wp_verify_nonce(sanitize_key(wp_unslash($_GET[$nonce_field])), $nonce_action)) {
            return false;
        }
        if ($capability && ! current_user_can($capability)) {
            return false;
        }
        return true;
    }
}
