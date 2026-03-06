<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-debug-logger.php';
require_once __DIR__ . '/../public/class-eval-utils.php';
require_once __DIR__ . '/../public/class-crawler-core.php';
require_once __DIR__ . '/../admin/class-media-meta.php';

// quick sanity checks for the shared UA matcher (covers the new commonized logic)
$ua_google = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
if (!GGC_Eval_Utils::matches_user_agent($ua_google, ['Google_Core_Search'], [])) {
    printf("ERROR: UA matcher failed to recognise Google_Core_Search\n");
}
$ua_curl = 'curl/7.81.0';
if (!GGC_Eval_Utils::matches_user_agent($ua_curl, [], ['curl_fake'])) {
    printf("ERROR: UA matcher failed to match curl_fake pattern\n");
}
// negative cases
if (GGC_Eval_Utils::matches_user_agent('random-agent', ['Google_Core_Search'], [])) {
    printf("ERROR: UA matcher incorrectly matched unrelated UA\n");
}

// UA list helper tests
update_option('ggc_global_selected_crawlers', []);
update_option('ggc_global_media_selected_crawlers', []);
$test_post = 11111;
update_post_meta($test_post, '_ggc_selected_crawlers', ['Google_Core_Search']);
$uac = GGC_Eval_Utils::get_page_selected_crawlers_for_match($test_post, 'none', false);
if ($uac !== ['Google_Core_Search']) {
    printf("ERROR: page crawler helper failed\n");
}
$uam = GGC_Eval_Utils::get_media_selected_crawlers_for_match($test_post, 'none', false);
if ($uam !== ['Google_Core_Search']) {
    printf("ERROR: media crawler helper failed\n");
}

update_option('ggc_global_selected_patterns', ['curl_fake']);
$ups = GGC_Eval_Utils::get_page_selected_patterns_for_match($test_post, 'global_blacklist', true);
if ($ups !== ['curl_fake']) {
    printf("ERROR: page pattern helper failed\n");
}
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
$ums = GGC_Eval_Utils::get_media_selected_patterns_for_match($test_post, 'global_blacklist', true);
if ($ums !== ['curl_fake']) {
    printf("ERROR: media pattern helper failed\n");
}

// simple IP range tests (uses defaults from definitions)
$sample_ranges = array_keys(ggc_get_default_ip_ranges());
if (!GGC_Eval_Utils::is_ip_in_ranges($sample_ranges)) {
    printf("ERROR: IP range helper failed to match default ranges\n");
}
if (GGC_Eval_Utils::is_ip_in_ranges([])) {
    printf("ERROR: IP range helper incorrectly matched empty list\n");
}

// verify page helper falls back to defaults when globals empty
update_option('ggc_global_selected_ips', []);
update_option('ggc_global_selected_ips_2', []);
$ips = GGC_Eval_Utils::get_page_selected_ips_for_match($post_id, 'global_blacklist', true);
if (empty($ips)) {
    printf("ERROR: page IP selection helper did not fallback to defaults\n");
}

// verify media helper uses post meta when not forced global
$post_id = 777;
update_post_meta($post_id, '_ggc_selected_media_ips', ['1.2.3.4']);
update_post_meta($post_id, '_ggc_selected_media_ips_2', []);
$ips = GGC_Eval_Utils::get_media_selected_ips_for_match($post_id, 'none', false);
if ($ips !== ['1.2.3.4']) {
    printf("ERROR: media IP selection helper failed to read post meta\n");
}

// ----------------------------------------------------------------
// spec: 評価方法=設定しない → 通常表示する（STOP）
// UA/IP が設定されていても eval_mode=none の場合、表示モードは適用されない。
// ----------------------------------------------------------------
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

// content image expectation: eval_mode=none → 通常表示（置換しない）
$content = '<p><img src="foo.jpg" alt="foo" /></p>';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$result = $core_media->filter_post_content_for_media($content);
printf("whitelist-empty none-mode alt_replace content = %s\n", $result);
if ($result !== $content) {
    printf("ERROR: eval_mode=none should show content normally (no replacement)\n");
}

// featured image: eval_mode=none → 通常表示
$post = (object) [ 'ID' => 999 ];
$GLOBALS['post'] = $post;
$media_meta = Custom_Media_Meta::get_instance();
$html = $media_meta->filter_post_thumbnail('<img src="bar.jpg" />', 999, 123, 'thumbnail', []);
printf("whitelist-empty none-mode featured html = %s\n", $html);
if ($html !== '<img src="bar.jpg" />') {
    printf("ERROR: eval_mode=none should show featured image normally\n");
}

// clear everything and ensure no replacement occurs
update_option('ggc_global_media_user_agent_control', 'none');
update_option('ggc_global_media_ip_evaluation', 'none');
update_option('ggc_alt_fixed_text', 'foo');
$result2 = $core_media->filter_post_content_for_media($content);
printf("all-disabled none-mode result = %s\n", $result2);
if (strpos($result2, 'ggc-alt-replacement') !== false) {
    printf("ERROR: replacement occurred when UA/IP set to none\n");
}


function dump_context($post_id) {
    $core = Custom_Crawler_Core::get_instance();
    $ctx = GGC_Eval_Utils::get_page_eval_context($post_id);
    printf("post=%d context=%s\n", $post_id, wp_json_encode($ctx));

    // also show what resolve_page_control_mode does with the same inputs
    $ua_mode = $core->resolve_page_control_mode($ctx['global_ua_option'], $ctx['post_ua_mode'], $ctx['force_global_ua']);
    $ip_mode = $core->resolve_page_control_mode($ctx['global_ip_option'], $ctx['post_ip_mode'], $ctx['force_global_ip']);
    printf("    resolved ua_mode=%s ip_mode=%s\n", $ua_mode, $ip_mode);
}

// simulate
// verify mode 'none' disables evaluation entirely
update_option('ggc_global_page_eval_mode', 'none');
update_post_meta($post_id, '_ggc_ua_control_mode', 'blacklist');
update_post_meta($post_id, '_ggc_ip_control_mode', 'whitelist');
$none_modes = $core->get_page_eval_modes($post_id);
printf("    mode none -> ua_mode=%s ip_mode=%s\n", $none_modes['ua_mode'], $none_modes['ip_mode']);
if ($none_modes['ua_mode'] !== 'allow_all' || $none_modes['ip_mode'] !== 'allow_all') {
    printf("ERROR: 'none' mode should return allow_all for both\n");
}
$use_ua = $core->should_use_global_message('ua', $post_id);
$use_ip = $core->should_use_global_message('ip', $post_id);
printf("    mode none use_global_message ua=%s ip=%s\n", $use_ua ? 'Y' : 'N', $use_ip ? 'Y' : 'N');
if ($use_ua || $use_ip) {
    printf("ERROR: 'none' mode should not use global messages\n");
}

update_option('ggc_global_page_eval_mode', 'apply_new_posts');
update_option('ggc_global_page_user_agent_control', 'global_blacklist');
update_option('ggc_global_page_ip_control', 'global_whitelist');

// regression guard: ensure the helper used by the admin renderer returns the
// page-specific fields rather than accidentally echoing the global UA/IP
// controls.  prior to the fix this check would have read "none" and the UI
// displayed the wrong value.
$opts = GGC_Options::get_page_eval_global_options();
if ($opts['global_page_ua_control'] !== 'global_blacklist' ||
    $opts['global_page_ip_control'] !== 'global_whitelist') {
    printf("ERROR: page eval globals not returned correctly ua=%s ip=%s\n",
        $opts['global_page_ua_control'], $opts['global_page_ip_control']);
}

$post_id = 999999; // dummy
// case: no meta
delete_post_meta($post_id, '_ggc_ua_control_mode');
delete_post_meta($post_id, '_ggc_ip_control_mode');
dump_context($post_id);
// set explicit individual
update_post_meta($post_id, '_ggc_ua_control_mode', 'individual');
update_post_meta($post_id, '_ggc_ip_control_mode', 'deny_all');
dump_context($post_id);

// switch to all
update_option('ggc_global_page_eval_mode', 'all');
dump_context($post_id);

// switch back to apply_new_posts
update_option('ggc_global_page_eval_mode', 'apply_new_posts');
dump_context($post_id);

// change post back to global
update_post_meta($post_id, '_ggc_ua_control_mode', 'global');
update_post_meta($post_id, '_ggc_ip_control_mode', 'global');
dump_context($post_id);

// now verify that a post with deny_all is not overridden by a non-force global list
update_option('ggc_global_page_user_agent_control', 'global_whitelist');
update_post_meta($post_id, '_ggc_ua_control_mode', 'deny_all');
update_post_meta($post_id, '_ggc_ip_control_mode', 'whitelist');
dump_context($post_id);

// -----------------------------------------------------------------
// regression test for "設定しない" on page evaluation dropdown
// when the redirect/method selector is global we should disable _all_
// evaluation regardless of previously configured control modes.  this
// behaviour should only apply when the post is not already under a
// forced global policy (force_global flag).
update_post_meta($post_id, '_ggc_ua_control_mode', 'whitelist');
update_post_meta($post_id, '_ggc_ip_control_mode', 'blacklist');
update_post_meta($post_id, '_ggc_ua_redirect_mode', 'global');
update_post_meta($post_id, '_ggc_ip_redirect_mode', 'global');
dump_context($post_id);
$modes = $core->get_page_eval_modes($post_id);
printf("    redirect-global override -> ua_mode=%s ip_mode=%s\n", $modes['ua_mode'], $modes['ip_mode']);
// expected both allow_all when not forced
if ($modes['ua_mode'] !== 'allow_all' || $modes['ip_mode'] !== 'allow_all') {
    printf("ERROR: redirect-global did not disable modes\n");
}

// repeat the earlier override test when global_page_eval_mode = 'apply_new_posts'
// to ensure explicit dropdown still disables evaluation even though a
// migration would ordinarily force the global list.
update_option('ggc_global_page_eval_mode', 'apply_new_posts');

// first case: user toggled method selector but also chose explicit lists
update_post_meta($post_id, '_ggc_ua_control_mode', 'whitelist');
update_post_meta($post_id, '_ggc_ip_control_mode', 'whitelist');
update_post_meta($post_id, '_ggc_ua_redirect_mode', 'global');
update_post_meta($post_id, '_ggc_ip_redirect_mode', 'global');
dump_context($post_id);
$modes = $core->get_page_eval_modes($post_id);
printf("    apply_new_posts with redirect-global -> ua_mode=%s ip_mode=%s\n", $modes['ua_mode'], $modes['ip_mode']);
if ($modes['ua_mode'] !== 'allow_all' || $modes['ip_mode'] !== 'allow_all') {
    printf("ERROR: apply_new_posts override did not disable modes\n");
}
// ensure that global message usage is disabled under apply_new_posts
$use_ua = $core->should_use_global_message('ua', $post_id);
$use_ip = $core->should_use_global_message('ip', $post_id);
printf("    apply_new_posts use_global_message ua=%s ip=%s\n", $use_ua ? 'Y' : 'N', $use_ip ? 'Y' : 'N');
if ($use_ua || $use_ip) {
    printf("ERROR: expected NO global messages under apply_new_posts\n");
}

// second case: user only toggles method selector but leaves control-mode at global
// should behave the same (no global lists applied)
update_post_meta($post_id, '_ggc_ua_control_mode', 'global');
update_post_meta($post_id, '_ggc_ip_control_mode', 'global');
update_post_meta($post_id, '_ggc_ua_redirect_mode', 'block');
update_post_meta($post_id, '_ggc_ip_redirect_mode', 'block');
dump_context($post_id);
$modes = $core->get_page_eval_modes($post_id);
printf("    apply_new_posts redirect-block with global controls -> ua_mode=%s ip_mode=%s\n", $modes['ua_mode'], $modes['ip_mode']);
if ($modes['ua_mode'] !== 'allow_all' || $modes['ip_mode'] !== 'allow_all') {
    printf("ERROR: override did not disable global under redirect-block\n");
}
$use_ua = $core->should_use_global_message('ua', $post_id);
$use_ip = $core->should_use_global_message('ip', $post_id);
printf("    apply_new_posts redirect-block global-controls use_global_message ua=%s ip=%s\n", $use_ua ? 'Y' : 'N', $use_ip ? 'Y' : 'N');
if ($use_ua || $use_ip) {
    printf("ERROR: expected NO global messages for redirect-block case\n");
}

// now verify that when global_page_eval_mode = 'all' the override does NOT
// apply: global setting should win even if per-page dropdown is "設定しない".
update_option('ggc_global_page_eval_mode', 'all');
update_post_meta($post_id, '_ggc_ua_control_mode', 'blacklist');
update_post_meta($post_id, '_ggc_ip_control_mode', 'whitelist');
update_post_meta($post_id, '_ggc_ua_redirect_mode', 'global');
update_post_meta($post_id, '_ggc_ip_redirect_mode', 'global');
dump_context($post_id);
$modes = $core->get_page_eval_modes($post_id);
printf("    global-all with redirect-global -> ua_mode=%s ip_mode=%s\n", $modes['ua_mode'], $modes['ip_mode']);
// here we expect modes not equal allow_all because force_global is true
if ($modes['ua_mode'] === 'allow_all' || $modes['ip_mode'] === 'allow_all') {
    printf("ERROR: global-all was incorrectly overridden by post dropdown\n");
}

// and confirm that should_use_global_message reflects the same behavior
$use_ua = $core->should_use_global_message('ua', $post_id);
$use_ip = $core->should_use_global_message('ip', $post_id);
printf("    global-all use_global_message ua=%s ip=%s\n", $use_ua ? 'Y' : 'N', $use_ip ? 'Y' : 'N');
if (!$use_ua || !$use_ip) {
    printf("ERROR: expected global messages under mode 'all'\n");
}

// -----------------------------------------------------------------
// simple media evaluation regression to verify pattern logic works
// when the global media UA control is active.  this reproduces the
// symptom where only 定義2 (patterns) were selected and the match was
// erroneously ignored, causing blacklist/whitelist behaviour to appear
// reversed.
//
update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
update_option('ggc_alt_mode', 'alt_replace');

// dummy post context
$post_id = 12345;
$GLOBALS['post'] = (object)[ 'ID' => $post_id ];

$content = '<p><img src="foo.jpg" alt="foo" /></p>';

// UA that should match the curl_fake pattern
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
$result = $core_media = Custom_Media_Meta::get_instance();
$result = $core_media->filter_post_content_for_media($content);
printf("media filter output (blacklist+pattern) = %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: image should have been removed for blacklist pattern\n");
}

// flip to whitelist and ensure opposite behaviour
update_option('ggc_global_media_user_agent_control', 'global_whitelist');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
$result = $core_media->filter_post_content_for_media($content);
printf("media filter output (whitelist+pattern) = %s\n", $result);
if (strpos($result, '<img') === false) {
    printf("ERROR: image should remain for whitelist pattern\n");
}

// -----------------------------------------------------------------
// new coverage: global media display mode dropdown should force the
// replacement/hide behaviour regardless of per-post metadata.
update_option('ggc_global_media_display_mode', 'alt_replace');
// choose a fixed text so we can inspect output easily
update_option('ggc_alt_mode', 'eval_fixed');
update_option('ggc_alt_fixed_text', 'GDMODE');
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
$result = $core_media->filter_post_content_for_media($content);
printf("display_mode alt_replace -> %s\n", $result);
if (strpos($result, 'GDMODE') === false) {
    printf("ERROR: display_mode alt_replace did not replace image with alt text\n");
}
// switch to hide mode and ensure the image is removed
update_option('ggc_global_media_display_mode', 'hide');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
$result = $core_media->filter_post_content_for_media($content);
printf("display_mode hide -> %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: display_mode hide did not remove image\n");
}

// -----------------------------------------------------------------
// alt text / fixed text behaviour should work even with UA/IP evaluation off
update_option('ggc_global_media_user_agent_control', 'none');
update_option('ggc_global_media_ip_evaluation', 'none');
// use eval_fixed mode so alt_fixed_text always applies
update_option('ggc_alt_mode', 'eval_fixed');
update_option('ggc_alt_fixed_text', 'GLOBALFIX');

// enable debug output for media evaluation
update_option('ggc_debug_media_eval', '1');

$content2 = '<p><img src="foo.jpg" /></p>';
$_SERVER['HTTP_USER_AGENT'] = '';
// print evaluation state manually
$ctx = GGC_Eval_Utils::get_media_eval_context($post_id);
printf("state before alt_fixed: ua=%s ip=%s media_mode=%s featured_mode=%s\n",
    $ctx['global_ua_option'], $ctx['global_ip_option'],
    get_post_meta($post_id,'_ggc_media_mode',true),
    get_post_meta($post_id,'_ggc_featured_mode',true)
);
$result = $core_media->filter_post_content_for_media($content2);
printf("alt_fixed content output = %s\n", $result);
// spec: UA/IP=none → 通常表示STOP。eval_fixedも適用されない
if ($result !== $content2) {
    printf("ERROR: UA/IP=none should show content normally even with eval_fixed\n");
}

// thumbnail replacement: UA/IP=none → 通常表示
$post_id = 54321;
update_post_meta($post_id, '_ggc_featured_image_alt_text', 'THUMBALT');
$html = $core_media->maybe_override_markdown_thumbnail_html('<img src="x" />', $post_id, 0, '', '');
printf("thumbnail output = %s\n", $html);
// UA/IP=none → featured image表示モード不適用
if ($html !== '<img src="x" />') {
    printf("ERROR: UA/IP=none should show thumbnail normally\n");
}

// -----------------------------------------------------------------
// new global featured settings behaviour
// UA/IP をブラックリストに設定して評価をトリガーさせる
update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_featured_display_mode', 'hide');
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
update_option('ggc_global_media_ip_evaluation', 'none');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0';
// simulate a post that would hide featured image
$post_id = 55555;
$html = $core_media->maybe_override_markdown_thumbnail_html('<img src="x" />', $post_id, 0, '', '');
printf("global featured hide = %s\n", $html);
if ($html !== '') {
    printf("ERROR: global featured hide did not suppress thumbnail\n");
}
// alt_replace mode should return alt text
// 'all' モードではグローバル固定テキストを使用（per-postメタは無視される）
update_option('ggc_global_featured_display_mode', 'alt_replace');
update_option('ggc_alt_fixed_text_featured', 'GLOBAL_ALT');
$html = $core_media->maybe_override_markdown_thumbnail_html('<img src="x" />', $post_id, 0, '', '');
printf("global featured alt_replace = %s\n", $html);
if (strpos($html, 'GLOBAL_ALT') === false) {
    printf("ERROR: global featured alt_replace did not apply alt text\n");
}

// -----------------------------------------------------------------
// ensure per-post featured_mode="alt_replace" respects alt text
// UA/IP ブラックリスト設定で評価をトリガー（per-post UA/IPメタ設定必要）
update_option('ggc_global_media_eval_mode', 'apply_new_posts');
$post_id = 66666;
update_post_meta($post_id, '_ggc_media_mode', 'individual');
update_post_meta($post_id, '_ggc_featured_mode', 'alt_replace');
update_post_meta($post_id, '_ggc_featured_image_alt_text', 'REPLACE_THIS');
// per-post UA評価を明示的に設定
update_post_meta($post_id, '_ggc_media_ua_action', 'blacklist');
update_post_meta($post_id, '_ggc_selected_media_crawlers', []);
update_post_meta($post_id, '_ggc_selected_media_page_browser_patterns', ['curl_fake']);
update_post_meta($post_id, '_ggc_media_ip_action', 'allow_all');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0';
$html = $core_media->maybe_override_markdown_thumbnail_html('<img src="y" />', $post_id, 0, '', '');
printf("per-post featured alt_replace = %s\n", $html);
if (strpos($html, 'REPLACE_THIS') === false) {
    printf("ERROR: per-post featured alt_replace did not apply alt text\n");
}


// -----------------------------------------------------------------
// UA none should defer to IP rules
// eval_mode=all で UA/IP 評価ロジック自体をテスト
update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_media_display_mode', 'hide');
update_option('ggc_global_media_user_agent_control', 'none');
update_option('ggc_global_media_ip_evaluation', 'global_blacklist');
update_option('ggc_global_media_selected_ips', ['127.0.0.1']);
update_option('ggc_global_media_selected_ips_2', []);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

// debug state
$ip_test_post = 88888;
$GLOBALS['post'] = (object) [ 'ID' => $ip_test_post, 'post_type' => 'post' ];
$ctx = GGC_Eval_Utils::get_media_eval_context($ip_test_post);
printf("state UA none + IP blacklist: ua=%s ip=%s selected_ips=%s\n",
    $ctx['global_ua_option'], $ctx['global_ip_option'], json_encode(get_option('ggc_global_media_selected_ips'))
);
$result = $core_media->filter_post_content_for_media($content);
printf("UA none + IP blacklist = %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: IP blacklist did not remove image when UA none\n");
}

// regression: empty UA blacklist should not remove anything
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', []);
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0';
// debug UA list contents
printf("selected crawlers=%s patterns=%s\n",
    json_encode(get_option('ggc_global_media_selected_crawlers')),
    json_encode(get_option('ggc_global_media_selected_patterns'))
);
$result = $core_media->filter_post_content_for_media($content);
printf("empty UA blacklist result = %s\n", $result);
if (strpos($result, '<img') === false) {
    printf("ERROR: empty blacklist removed image\n");
}

// regression: empty IP blacklist should not remove anything
update_option('ggc_global_media_user_agent_control', 'none');
update_option('ggc_global_media_ip_evaluation', 'global_blacklist');
update_option('ggc_global_media_selected_ips', []);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
printf("selected ips=%s\n", json_encode(get_option('ggc_global_media_selected_ips')));
$result = $core_media->filter_post_content_for_media($content);
printf("empty IP blacklist result = %s\n", $result);
if (strpos($result, '<img') === false) {
    printf("ERROR: empty IP blacklist removed image\n");
}

// regression: global whitelist + hide mode should respect whitelist
// eval_mode=all で $GLOBALS['post'] を正しく設定
update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_media_display_mode', 'hide');
update_option('ggc_global_media_user_agent_control', 'global_whitelist');
update_option('ggc_global_media_ip_evaluation', 'none');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);

$wl_post_id = 77700;
$GLOBALS['post'] = (object) [ 'ID' => $wl_post_id, 'post_type' => 'post' ];

$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
printf("selected patterns=%s\n", json_encode(get_option('ggc_global_media_selected_patterns')));
$result = $core_media->filter_post_content_for_media($content);
printf("global whitelist + hide (matching UA) = %s\n", $result);
if (strpos($result, '<img') === false) {
    printf("ERROR: media hidden despite UA in whitelist\n");
}

// the same with non-matching UA should hide
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$result = $core_media->filter_post_content_for_media($content);
printf("global whitelist + hide (nonmatching UA) = %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: media not hidden when UA not in whitelist\n");
}

// additional regression: apply_new_posts + per-post individual モードで選択メディアのみ対象
// ※ selected_media は apply_new_posts + individual モード + per-post UA設定で機能
update_option('ggc_global_media_eval_mode', 'apply_new_posts');
$sel_post_id = 77701;
$GLOBALS['post'] = (object) [ 'ID' => $sel_post_id, 'post_type' => 'post' ];
update_post_meta($sel_post_id, '_ggc_media_mode', 'individual');
// per-post UA whitelist に設定（apply_new_postsでは per-post UA/IP メタが必要）
update_post_meta($sel_post_id, '_ggc_media_ua_action', 'whitelist');
update_post_meta($sel_post_id, '_ggc_media_ip_action', 'allow_all');
// per-post クローラーリストを設定（空→パターンのみ使用）
update_post_meta($sel_post_id, '_ggc_selected_media_crawlers', []);
update_post_meta($sel_post_id, '_ggc_selected_media_page_browser_patterns', ['curl_fake']);
// pretend only img with ID=1 selected
$selected_media = [1];
update_post_meta($sel_post_id, '_ggc_selected_media', $selected_media);
// UA whitelist matches → 通常表示
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
printf("selected_media=%s\n", json_encode(get_post_meta($sel_post_id,'_ggc_selected_media',true)));
$result = $core_media->filter_post_content_for_media($content);
printf("whitelist+individual with selection (matching UA) = %s\n", $result);
if (strpos($result, '<img') === false) {
    printf("ERROR: selected media hidden despite whitelist match\n");
}

// if UA mismatches, selected media should be replaced
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$result = $core_media->filter_post_content_for_media($content);
printf("whitelist+individual with selection (UA miss) = %s\n", $result);
if ($result === $content) {
    printf("ERROR: selected media not modified when UA miss\n");
}

// UA none + IP whitelist (no match) → 画像非表示
update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_media_user_agent_control', 'none');
update_option('ggc_global_media_ip_evaluation', 'global_whitelist');
update_option('ggc_global_media_selected_ips', []);
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$GLOBALS['post'] = (object) [ 'ID' => 88889, 'post_type' => 'post' ];
$result = $core_media->filter_post_content_for_media($content);
printf("UA none + IP whitelist (no match) = %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: IP whitelist should remove when IP not in list\n");
}

// IP none should defer to UA rules
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
update_option('ggc_global_media_ip_evaluation', 'none');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0';

$result = $core_media->filter_post_content_for_media($content);
printf("IP none + UA blacklist = %s\n", $result);
if (strpos($result, '<img') !== false) {
    printf("ERROR: UA blacklist did not remove image when IP none\n");
}

// ------------------------------------------------------------------
// regression: filter should only apply on posts/pages
// ------------------------------------------------------------------
// simulate an attachment context by forcing global $post post_type
$post_backup = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
$GLOBALS['post'] = (object) [ 'ID' => 555, 'post_type' => 'attachment' ];

// with an attachment, the filter should not touch the content
$result = $core_media->filter_post_content_for_media($content);
printf("attachment context result = %s\n", $result);
if ($result !== $content) {
    printf("ERROR: media filter ran on non-post/page (attachment)\n");
}

// switch back to a normal post type and verify it runs again
$GLOBALS['post']->post_type = 'post';
$result = $core_media->filter_post_content_for_media($content);
printf("post context result = %s\n", $result);
if ($result === $content) {
    printf("ERROR: media filter did not run on post type\n");
}

// restore original global post
if ($post_backup !== null) {
    $GLOBALS['post'] = $post_backup;
} else {
    unset($GLOBALS['post']);
}

// also ensure the block renderer obeys the same restriction
$block = [ 'blockName' => 'core/image', 'innerHTML' => '<img src="foo.jpg">', 'attrs' => [] ];

// attachment context: no change
$GLOBALS['post'] = (object) [ 'ID' => 556, 'post_type' => 'attachment' ];
$rb = $core_media->render_block_with_alt_text('<img src="foo.jpg">', $block);
printf("attachment block result = %s\n", $rb);
if ($rb !== '<img src="foo.jpg">') {
    printf("ERROR: block filter ran on attachment\n");
}

// post context: filter should run (content may change depending on rules)
$GLOBALS['post']->post_type = 'post';
$rb2 = $core_media->render_block_with_alt_text('<img src="foo.jpg">', $block);
printf("post block result = %s\n", $rb2);
if ($rb2 === '<img src="foo.jpg">') {
    printf("ERROR: block filter did not run on post\n");
}

// new: individual mode should honour per-block settings
// apply_new_posts + per-post individual モードでテスト
// eval_fixed が干渉しないようリセット
update_option('ggc_global_media_eval_mode', 'apply_new_posts');
update_option('ggc_alt_mode', 'alt_attr');
update_option('ggc_alt_fixed_text', '');
// UA を blacklist + curl_fake でトリガー可能にしておく
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
update_option('ggc_global_media_ip_evaluation', 'none');

$post_id = 77777;
// ensure post object is set so the renderer picks up our metadata
$GLOBALS['post'] = (object) [ 'ID' => $post_id, 'post_type' => 'post' ];
update_post_meta($post_id, '_ggc_media_mode', 'individual');
// per-post UA を blacklist に設定し、UA/IP評価を有効にする
update_post_meta($post_id, '_ggc_media_ua_action', 'blacklist');
update_post_meta($post_id, '_ggc_selected_media_crawlers', []);
update_post_meta($post_id, '_ggc_selected_media_page_browser_patterns', ['curl_fake']);
update_post_meta($post_id, '_ggc_media_ip_action', 'allow_all');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0';
$core_media->set_force_preview(true); // simulate evaluation trigger
// normal block (no attrs) -> unchanged
$blk1 = [ 'blockName' => 'core/image', 'innerHTML' => '<img src="foo.jpg">', 'attrs' => [] ];
$rb3 = $core_media->render_block_with_alt_text('<img src="foo.jpg">', $blk1);
printf("individual normal block = %s\n", $rb3);
if ($rb3 !== '<img src="foo.jpg">') {
    printf("ERROR: normal individual block modified\n");
}
// hide mode should remove
$blk2 = $blk1;
$blk2['attrs'] = ['ggcMediaMode' => 'hide'];
$rb4 = $core_media->render_block_with_alt_text('<img src="foo.jpg">', $blk2);
printf("individual hide block = %s\n", $rb4);
if ($rb4 !== '') {
    printf("ERROR: individual hide block not removed\n");
}
// replace mode should show alt text
$blk3 = $blk1;
$blk3['attrs'] = ['ggcMediaMode' => 'replace', 'ggcAltText' => 'XYZ'];
$rb5 = $core_media->render_block_with_alt_text('<img src="foo.jpg">', $blk3);
printf("individual replace block = %s\n", $rb5);
if (strpos($rb5, 'XYZ') === false) {
    printf("ERROR: individual replace block did not use alt text\n");
}
$core_media->set_force_preview(false);

// -----------------------------------------------------------------
// Bug test: per-post UA/IP が「設定しない」「全許可」の場合、
// テキスト置換テキストがあっても通常表示されることを確認
// -----------------------------------------------------------------
printf("\n=== UA/IP allow_all/global → 通常表示テスト ===\n");
update_option('ggc_global_media_eval_mode', 'apply_new_posts');
update_option('ggc_global_media_user_agent_control', 'global_blacklist');
update_option('ggc_global_media_selected_crawlers', ['googlebot']);
update_option('ggc_global_media_selected_patterns', []);
update_option('ggc_global_media_ip_evaluation', 'none');
update_option('ggc_alt_mode', 'alt_attr');
update_option('ggc_alt_fixed_text', '');

$test_pid = 88888;
$GLOBALS['post'] = (object) ['ID' => $test_pid, 'post_type' => 'post'];
update_post_meta($test_pid, '_ggc_media_mode', 'individual');
update_post_meta($test_pid, '_ggc_featured_mode', 'alt_replace');
update_post_meta($test_pid, '_ggc_featured_image_alt_text', 'SHOULD_NOT_SHOW');
$_SERVER['HTTP_USER_AGENT'] = 'curl/7.0'; // BLリストにないUA

// テスト1: UA/IP = 「設定しない」(global) → allow_all変換 → 通常表示
update_post_meta($test_pid, '_ggc_media_ua_action', 'global');
update_post_meta($test_pid, '_ggc_media_ip_action', 'global');

$blk_test = ['blockName' => 'core/image', 'innerHTML' => '<img src="test.jpg">', 'attrs' => ['ggcMediaMode' => 'replace', 'ggcAltText' => 'ALT_TEXT']];
$rb_t1 = $core_media->render_block_with_alt_text('<img src="test.jpg">', $blk_test);
printf("UA/IP=global block = %s\n", $rb_t1);
if ($rb_t1 !== '<img src="test.jpg">') {
    printf("ERROR: UA/IP=global should show normal but got replaced\n");
}

// アイキャッチ
$ft_t1 = $core_media->filter_post_thumbnail('<img src="thumb.jpg">', $test_pid, 0, '', '');
printf("UA/IP=global featured = %s\n", $ft_t1);
if ($ft_t1 !== '<img src="thumb.jpg">') {
    printf("ERROR: UA/IP=global featured should show normal but got replaced\n");
}

// テスト2: UA/IP = 「全許可」(allow_all) → 通常表示
update_post_meta($test_pid, '_ggc_media_ua_action', 'allow_all');
update_post_meta($test_pid, '_ggc_media_ip_action', 'allow_all');

$rb_t2 = $core_media->render_block_with_alt_text('<img src="test.jpg">', $blk_test);
printf("UA/IP=allow_all block = %s\n", $rb_t2);
if ($rb_t2 !== '<img src="test.jpg">') {
    printf("ERROR: UA/IP=allow_all should show normal but got replaced\n");
}

$ft_t2 = $core_media->filter_post_thumbnail('<img src="thumb.jpg">', $test_pid, 0, '', '');
printf("UA/IP=allow_all featured = %s\n", $ft_t2);
if ($ft_t2 !== '<img src="thumb.jpg">') {
    printf("ERROR: UA/IP=allow_all featured should show normal but got replaced\n");
}

// テスト3: プレビューでも同様に通常表示
$core_media->set_force_preview(true);
$rb_t3 = $core_media->render_block_with_alt_text('<img src="test.jpg">', $blk_test);
printf("UA/IP=allow_all preview block = %s\n", $rb_t3);
if ($rb_t3 !== '<img src="test.jpg">') {
    printf("ERROR: UA/IP=allow_all preview should show normal but got replaced\n");
}

$ft_t3 = $core_media->filter_post_thumbnail('<img src="thumb.jpg">', $test_pid, 0, '', '');
printf("UA/IP=allow_all preview featured = %s\n", $ft_t3);
if ($ft_t3 !== '<img src="thumb.jpg">') {
    printf("ERROR: UA/IP=allow_all preview featured should show normal but got replaced\n");
}
$core_media->set_force_preview(false);

// テスト4: crawler-core のアイキャッチも通常表示
$ft_t4 = $core->maybe_override_markdown_thumbnail_html('<img src="thumb.jpg">', $test_pid, 0, '', '');
printf("UA/IP=allow_all crawler-core featured = %s\n", $ft_t4);
if ($ft_t4 !== '<img src="thumb.jpg">') {
    printf("ERROR: UA/IP=allow_all crawler-core featured should show normal but got replaced\n");
}

// -----------------------------------------------------------------
// regression: global media eval = none でも、markdown置換の画像URL直指定は反映される
update_option('ggc_global_media_eval_mode', 'none');
update_option('ggc_markdown_replace_enabled', 'on');
$md_img_pid = 99991;
$GLOBALS['post'] = (object) ['ID' => $md_img_pid, 'post_type' => 'post'];
update_post_meta($md_img_pid, '_ggc_md_replace_mode', 'manual');
update_post_meta($md_img_pid, '_ggc_md_replace_text', '# test markdown');
update_post_meta($md_img_pid, '_ggc_md_ua_mode', 'deny_all');
update_post_meta($md_img_pid, '_ggc_md_ip_mode', 'allow_all');
update_post_meta($md_img_pid, '_ggc_md_replace_image_id', null);
update_post_meta($md_img_pid, '_ggc_md_replace_image_url', 'https://example.com/direct-image.jpg');
$md_img_html = $core->maybe_override_markdown_thumbnail_html('<img src="orig.jpg">', $md_img_pid, 0, '', []);
printf("markdown custom image url (media none) = %s\n", $md_img_html);
if (strpos($md_img_html, 'https://example.com/direct-image.jpg') === false) {
    printf("ERROR: markdown custom image URL was not applied when media eval mode is none\n");
}

// restore original global post again
if ($post_backup !== null) {
    $GLOBALS['post'] = $post_backup;
} else {
    unset($GLOBALS['post']);
}
