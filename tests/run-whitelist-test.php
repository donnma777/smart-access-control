<?php
require __DIR__ . '/../includes/class-option-utils.php';
require __DIR__ . '/../public/class-eval-utils.php';
require __DIR__ . '/../public/class-crawler-core.php';
require __DIR__ . '/../admin/class-media-meta.php';

update_option('ggc_global_media_eval_mode', 'all');
update_option('ggc_global_media_user_agent_control', 'global_whitelist');
update_option('ggc_global_media_selected_crawlers', []);
update_option('ggc_global_media_selected_patterns', ['curl_fake']);
update_option('ggc_alt_mode', 'alt_replace');

$post_id = 12345;
$GLOBALS['post'] = (object)[ 'ID' => $post_id, 'post_type' => 'post' ];

$content = '<p><img src="foo.jpg" alt="foo" /></p>';

$_SERVER['HTTP_USER_AGENT'] = 'curl/7.81.0';
$core_media = Custom_Media_Meta::get_instance();
$result = $core_media->filter_post_content_for_media($content);
printf("OUT: %s\n", $result);
?>