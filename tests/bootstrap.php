<?php
// Minimal test bootstrap to stub required WP functions/constants for running
// the repository's lightweight tests outside a full WP environment.

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('WP_DEBUG')) define('WP_DEBUG', false);

$GGC_TEST_OPTIONS = [];
$GGC_TEST_OPTIONS['ggc_debug_media_eval'] = '1';
$GGC_TEST_POSTMETA = [];

class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_messages() { return [$this->message]; }
}

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function apply_filters($tag, $value) { return $value; }

function sanitize_text_field($str) { return is_string($str) ? trim(strip_tags($str)) : $str; }
function esc_html($s) { return $s; }
function esc_attr($s) { return $s; }
function esc_url($s) { return $s; }
function esc_url_raw($s) { return $s; }
function wp_json_encode($v) { return json_encode($v); }

function get_option($key, $default = null) { global $GGC_TEST_OPTIONS; return array_key_exists($key, $GGC_TEST_OPTIONS) ? $GGC_TEST_OPTIONS[$key] : $default; }
function update_option($key, $value) { global $GGC_TEST_OPTIONS; $GGC_TEST_OPTIONS[$key] = $value; return true; }
function delete_option($key) { global $GGC_TEST_OPTIONS; if (isset($GGC_TEST_OPTIONS[$key])) unset($GGC_TEST_OPTIONS[$key]); }

function get_post_meta($post_id, $key = '', $single = true) { global $GGC_TEST_POSTMETA; $post_id = (string)$post_id; if (!isset($GGC_TEST_POSTMETA[$post_id])) return $single ? '' : []; if (!isset($GGC_TEST_POSTMETA[$post_id][$key])) return $single ? '' : []; return $GGC_TEST_POSTMETA[$post_id][$key]; }
function update_post_meta($post_id, $key, $value) { global $GGC_TEST_POSTMETA; $post_id = (string)$post_id; if (!isset($GGC_TEST_POSTMETA[$post_id])) $GGC_TEST_POSTMETA[$post_id] = []; $GGC_TEST_POSTMETA[$post_id][$key] = $value; return true; }
function delete_post_meta($post_id, $key) { global $GGC_TEST_POSTMETA; $post_id = (string)$post_id; if (isset($GGC_TEST_POSTMETA[$post_id][$key])) unset($GGC_TEST_POSTMETA[$post_id][$key]); }

function is_user_logged_in() { return false; }
function is_singular($types = []) { return true; }
function is_feed() { return false; }
function is_admin() { return false; }

function get_post($id = null) {
    global $post, $post_id;
    if (!empty($id)) {
        return (object)['ID' => $id, 'post_type' => 'post'];
    }
    if (isset($post) && is_object($post)) return $post;
    $p = (object)['ID' => (isset($post_id) ? $post_id : 0), 'post_type' => 'post'];
    // ensure post_type exists for code checking
    if (!isset($p->post_type)) $p->post_type = 'post';
    return $p;
}

// ensure a default client IP so IP range helpers can run in tests
if (!isset($_SERVER['REMOTE_ADDR']) || $_SERVER['REMOTE_ADDR'] === '') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

function attachment_url_to_postid($url) {
    // simple mapping for test fixtures
    if (strpos($url, 'foo.jpg') !== false) return 1;
    if (strpos($url, 'bar.jpg') !== false) return 2;
    if (strpos($url, 'x') !== false) return 3;
    return 0;
}

function wp_get_attachment_image($id, $size = 'thumbnail', $icon = false, $attr = []) {
    return '<img src="attachment-' . intval($id) . '.jpg" />';
}

function wp_remote_get($url, $args = []) { return new WP_Error('not_available','http not available in bootstrap'); }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return ''; }

// minimal helpers used by other code
function wp_strip_all_tags($s) { return is_string($s) ? strip_tags($s) : $s; }
function wp_unslash($s) { return $s; }
function wp_validate_redirect($url, $fallback) { return $url; }
function wp_safe_redirect($url, $status = 302) { echo "REDIRECT:" . $url; exit; }
function wp_redirect($url, $status = 302) { echo "REDIRECT:" . $url; exit; }

// minimal Ajax/test helpers
function add_action($a, $b) { return true; }

// provide a basic current_user_can check used in admin utils
function current_user_can($cap) { return true; }

// provide minimal nonce verification functions used in tests
function wp_create_nonce($s) { return 'nonce'; }
function wp_verify_nonce($n, $s) { return true; }

// allow tests to require this bootstrap directly
// Provide minimal defaults used by the code under test
function ggc_get_default_bots() {
    return [ 'Google_Core_Search' => ['uas' => ['Googlebot']] ];
}
function ggc_get_default_browser_patterns() {
    return [ 'curl_fake' => ['pattern' => '^curl'] ];
}
function ggc_get_default_ip_ranges() { return [ 'local' => ['ranges' => ['127.0.0.1']] ]; }
function ggc_get_default_ip_ranges_2() { return []; }
function ggc_get_default_markdown_templates() { return []; }

function get_queried_object_id() {
    global $post;
    return isset($post->ID) ? intval($post->ID) : 0;
}

// Provide a default post_id used by tests
$post_id = 1;

return;
