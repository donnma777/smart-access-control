<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared helpers for meta update/delete semantics used by admin and public code.
 */
class GGC_Meta_Utils {

    /**
     * Update or delete post meta using unified semantics.
     * - null => delete
     * - empty array => delete unless $save_empty_array true
     * - '' or false => delete
     * - otherwise update
     * Optional sanitization callback may be provided.
     *
     * @param int $post_id
     * @param string $meta_key
     * @param mixed $value
     * @param callable|null $sanitize_cb
     * @param bool $save_empty_array
     * @return void
     */
    public static function update_or_delete_meta($post_id, $meta_key, $value, $sanitize_cb = null, $save_empty_array = false) {
        if ($sanitize_cb && is_callable($sanitize_cb)) {
            $value = call_user_func($sanitize_cb, $value);
        }
        if ($value === null) {
            delete_post_meta($post_id, $meta_key);
            return;
        }
        if (is_array($value)) {
            if (empty($value) && ! $save_empty_array) {
                delete_post_meta($post_id, $meta_key);
                return;
            }
            update_post_meta($post_id, $meta_key, $value);
            return;
        }
        if ($value === '' || $value === false) {
            delete_post_meta($post_id, $meta_key);
            return;
        }
        update_post_meta($post_id, $meta_key, $value);
    }
}
