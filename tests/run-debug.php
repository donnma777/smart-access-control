<?php
require __DIR__ . '/../public/class-eval-utils.php';

$test_post = 11111;
update_post_meta($test_post, '_ggc_selected_crawlers', ['Google_Core_Search']);
var_export(get_post_meta($test_post, '_ggc_selected_crawlers', true));
echo "\n";
var_export(GGC_Eval_Utils::get_page_selected_crawlers_for_match($test_post, 'none', false));
echo "\n";
?>