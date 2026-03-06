<?php
// scenario reproducer
require_once __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-option-utils.php';
require __DIR__ . '/../public/class-eval-utils.php';
require __DIR__ . '/../public/class-crawler-core.php';
require __DIR__ . '/../admin/class-media-meta.php';

// set options as test
update_option('ggc_global_media_eval_mode', 'none');
update_option('ggc_global_media_display_mode', 'alt_replace');
update_option('ggc_global_media_user_agent_control', 'global_whitelist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', []);
update_option('ggc_global_media_ip_evaluation', 'global_whitelist');
update_option('ggc_global_media_selected_ips', []);
update_option('ggc_global_media_selected_ips_2', []);
update_option('ggc_alt_fixed_text_featured', 'テスト');
update_option('ggc_alt_fixed_text', 'テスト');

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

$content = '<p><img src="foo.jpg" alt="foo" /></p>';
$GLOBALS['post'] = (object)['ID' => 1, 'post_type' => 'post'];
$media_meta = Custom_Media_Meta::get_instance();
// Debug: inspect evaluation context
$ctx = GGC_Eval_Utils::get_media_eval_context($GLOBALS['post']->ID);
echo "ctx=" . wp_json_encode($ctx) . "\n";
$global_display = GGC_Options::get_global_media_display_mode();
echo "global_display={$global_display}\n";
$ua_action = GGC_Eval_Utils::resolve_control_mode($ctx['global_ua_option'], $ctx['post_ua_meta'], $ctx['force_global_ua']);
$ip_action = GGC_Eval_Utils::resolve_control_mode($ctx['global_ip_option'], $ctx['post_ip_meta'], $ctx['force_global_ip']);
echo "ua_action={$ua_action} ip_action={$ip_action}\n";
$crawlers = GGC_Eval_Utils::get_media_selected_crawlers_for_match($GLOBALS['post']->ID, $ctx['global_ua_option'], $ctx['force_global_ua']);
$patterns = GGC_Eval_Utils::get_media_selected_patterns_for_match($GLOBALS['post']->ID, $ctx['global_ua_option'], $ctx['force_global_ua']);
echo "crawlers=" . wp_json_encode($crawlers) . " patterns=" . wp_json_encode($patterns) . "\n";
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$ua_is_match = GGC_Eval_Utils::matches_user_agent($user_agent, $crawlers, $patterns);
echo "ua_is_match=" . ($ua_is_match ? '1' : '0') . "\n";
$ips = GGC_Eval_Utils::get_media_selected_ips_for_match($GLOBALS['post']->ID, $ctx['global_ip_option'], $ctx['force_global_ip']);
echo "ips=" . wp_json_encode($ips) . "\n";
$ip_is_in_range = GGC_Eval_Utils::is_ip_in_ranges($ips);
echo "ip_is_in_range=" . ($ip_is_in_range ? '1' : '0') . "\n";

$out = $media_meta->filter_post_content_for_media($content);
var_export($out);

?>