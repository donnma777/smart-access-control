<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Media_Meta {
    protected static $instance = null;
    private $force_preview = false;

    private function __construct() {
        // Add field in media modal / edit
        add_filter('attachment_fields_to_edit', [ $this, 'attachment_fields_to_edit' ], 10, 2);
        add_filter('attachment_fields_to_save', [ $this, 'attachment_fields_to_save' ], 10, 2);

        // Filter content to remove tags for selected media when appropriate
        add_filter('the_content', [ $this, 'filter_post_content_for_media' ], 20);
        
        // Gutenbergブロックの代替テキスト処理
        add_filter('render_block', [ $this, 'render_block_with_alt_text' ], 10, 2);

        // アイキャッチ画像の代替テキスト処理
        add_filter('post_thumbnail_html', [ $this, 'filter_post_thumbnail' ], 10, 5);

        // 代替テキスト置換プレビュー（別タブ表示）
        add_action('admin_post_ggc_preview_filtered', [ $this, 'handle_preview_filtered' ]);
    }

    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function attachment_fields_to_edit($form_fields, $post) {
        // メディアライブラリの個別設定は不要になったため、フィールドを表示しません。
        // 代替テキストはGutenbergブロックの属性（ggcAltText）から取得します。
        return $form_fields;
    }

    public function attachment_fields_to_save($post, $attachment) {
        // メディアライブラリの個別設定は不要になったため、保存処理を行いません。
        return $post;
    }

    public function filter_post_content_for_media($content) {
        if (!$this->force_preview) {
            if (!is_singular()) return $content;
            if (is_user_logged_in()) return $content;
        }
        global $post;
        if (empty($post->ID)) return $content;

        $selected_media = get_post_meta($post->ID, '_ggc_selected_media', true);
        if (!is_array($selected_media)) $selected_media = [];
        $selected_media = array_map('intval', $selected_media);

        // グローバル設定を優先する
        $global_ua_option = get_option('ggc_global_media_user_agent_control', 'apply_new_posts');
        $global_ip_option = get_option('ggc_global_media_ip_evaluation', 'apply_new_posts');
        $alt_fixed = get_option('ggc_alt_fixed_text', '');
        
        // Determine final UA action (設定画面優先)
        if ($global_ua_option === 'none') {
            $ua_action = 'none';
        } elseif ($global_ua_option === 'global_blacklist') {
            $ua_action = 'blacklist';
        } elseif ($global_ua_option === 'global_whitelist') {
            $ua_action = 'whitelist';
        } else {
            // 'apply_new_posts' の場合のみ投稿メタを使用
            $ua_action = get_post_meta($post->ID, '_ggc_media_ua_action', true) ?: 'global';
            if ($ua_action === 'global') {
                $ua_action = 'allow_all';
            }
        }
        
        // Determine final IP action (設定画面優先)
        if ($global_ip_option === 'none') {
            $ip_action = 'none';
        } elseif ($global_ip_option === 'global_blacklist') {
            $ip_action = 'blacklist';
        } elseif ($global_ip_option === 'global_whitelist') {
            $ip_action = 'whitelist';
        } else {
            // 'apply_new_posts' の場合のみ投稿メタを使用
            $ip_action = get_post_meta($post->ID, '_ggc_media_ip_action', true) ?: 'global';
            if ($ip_action === 'global') {
                $ip_action = 'allow_all';
            }
        }

        // If both actions are allow_all or none (no evaluation), do nothing
        if ( ( $ua_action === 'allow_all' || $ua_action === 'none' ) && ( $ip_action === 'allow_all' || $ip_action === 'none' ) ) {
            return $content;
        }

        // Prepare UA/IP match decisions so we can apply them per-image efficiently later
        // UA match
        $ua_is_match = false;
        if ($ua_action === 'blacklist' || $ua_action === 'whitelist') {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_media_crawlers_for_match = get_option('ggc_global_media_selected_crawlers', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_media_crawlers_for_match = get_post_meta($post->ID, '_ggc_selected_media_crawlers', true) ?: [];
            }

            $all_bots = Custom_Crawler_Core::get_allowable_bots();
            foreach ($selected_media_crawlers_for_match as $key) {
                if (isset($all_bots[$key]['uas'])) {
                    foreach ($all_bots[$key]['uas'] as $pattern) {
                        if ($pattern !== '' && stripos($user_agent, $pattern) !== false) {
                            $ua_is_match = true;
                            break 2;
                        }
                    }
                }
            }

            // Check page pattern definitions
            if (!$ua_is_match) {
                if (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)) {
                    // グローバル設定優先の場合はパターン評価をスキップ
                    $selected_page_pattern_keys_for_match = [];
                } else {
                    $selected_page_pattern_keys_for_match = get_post_meta($post->ID, '_ggc_selected_media_page_browser_patterns', true) ?: [];
                }
                
                if (!empty($selected_page_pattern_keys_for_match)) {
                    $all_global_patterns_structured = Custom_Crawler_Core::get_browser_block_patterns();
                    foreach ($selected_page_pattern_keys_for_match as $key) {
                        if (isset($all_global_patterns_structured[$key]['pattern'])) {
                            $patterns = preg_split('/[\r\n,]+/', $all_global_patterns_structured[$key]['pattern']);
                            foreach ($patterns as $pattern) {
                                $pattern = trim($pattern);
                                if ($pattern === '') continue;
                                if (stripos($user_agent, $pattern) !== false) {
                                    $ua_is_match = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // IP match
        $ip_is_in_range = false;
        if ($ip_action === 'blacklist' || $ip_action === 'whitelist') {
            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ip_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_ips_for_match = get_option('ggc_global_media_selected_ips', []) ?: [];
                $selected_ips_2_for_match = get_option('ggc_global_media_selected_ips_2', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_ips_for_match = get_post_meta($post->ID, '_ggc_selected_media_ips', true) ?: [];
                $selected_ips_2_for_match = get_post_meta($post->ID, '_ggc_selected_media_ips_2', true) ?: [];
            }
            
            $all_selected_ips_for_match = array_merge($selected_ips_for_match, $selected_ips_2_for_match);
            if (!empty($all_selected_ips_for_match) && Custom_Crawler_Core::is_in_allowable_ip_range($all_selected_ips_for_match)) {
                $ip_is_in_range = true;
            }
        }

        $featured_id = get_post_thumbnail_id($post->ID);
        $apply_to_all_media = in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)
            || in_array($global_ip_option, ['global_blacklist','global_whitelist'], true);

        $alt_mode = get_option('ggc_alt_mode', 'none'); // none | individual | eval_fixed
        $alt_fixed = get_option('ggc_alt_fixed_text', '');
        $alt_mode_effective = $alt_mode;
        if (!empty($alt_fixed) && $apply_to_all_media) {
            // グローバル設定でブラックリスト/ホワイトリスト時は固定テキストを優先
            $alt_mode_effective = 'eval_fixed';
        }

        // グローバル設定でブラック/ホワイトリスト使用時、固定テキストが空なら置換しない
        if ($apply_to_all_media && empty($alt_fixed)) {
            return $content;
        }

        // Decide per-image whether to remove it based on actions (either action can cause removal)
        $content = preg_replace_callback('/<img[^>]+>/i', function($matches) use ($selected_media, $apply_to_all_media, $ua_action, $ip_action, $featured_id, $alt_mode_effective, $alt_fixed, $ua_is_match, $ip_is_in_range) {
            $img = $matches[0];
            if (preg_match('/src=["\']([^"\']+)["\']/', $img, $m)) {
                $src = $m[1];
                $aid = attachment_url_to_postid($src);
                if (!$aid) return $img; // not an attachment

                $is_target_media = $apply_to_all_media ? true : in_array(intval($aid), $selected_media, true);

                $should_remove = false;

                // deny_all の処理 - すべてメディアを削除
                if ($ua_action === 'deny_all' || $ip_action === 'deny_all') {
                    $should_remove = true;
                } else {
                    // Evaluate removal per-image using UA/IP match results computed above
                    // UA-based removal: blacklist -> remove if UA matches and attachment is selected
                    if ($ua_action === 'blacklist' && $ua_is_match) {
                        if ($is_target_media) {
                            $should_remove = true;
                        }
                    } elseif ($ua_action === 'whitelist' && !$ua_is_match) {
                        if ($is_target_media) {
                            $should_remove = true;
                        }
                    }

                    // IP-based removal: blacklist -> remove if IP in selected ranges and attachment is selected
                    if ($ip_action === 'blacklist' && $ip_is_in_range) {
                        if ($is_target_media) {
                            $should_remove = true;
                        }
                    } elseif ($ip_action === 'whitelist' && !$ip_is_in_range) {
                        if ($is_target_media) {
                            $should_remove = true;
                        }
                    }
                }

                $actions = [$ua_action, $ip_action];
                /* legacy per-action loop disabled - replaced by consolidated UA/IP matching above */
                if (false) { foreach ($actions as $action) {
                    if ($action === 'allow_all' || $action === 'global') continue;



                    if ($action === 'blacklist') {
                        if (in_array(intval($aid), $selected_media, true)) {
                            $should_remove = true;
                            break;
                        }
                        continue;
                    }

                    if ($action === 'whitelist') {
                        if (!in_array(intval($aid), $selected_media, true)) {
                            $should_remove = true;
                            break;
                        }
                        continue;
                    }
                }
                }

                if ($this->force_preview) {
                    // プレビュー時は評価を無視し、対象メディアは常に置換
                    if ($is_target_media) {
                        $should_remove = true;
                    }
                }

                if ($should_remove) {
                    // deny_all の場合は常にテキスト置換（固定テキスト優先）
                    if ($ua_action === 'deny_all' || $ip_action === 'deny_all') {
                        $replacement_text = '';
                        if (!empty($alt_fixed)) {
                            $replacement_text = $alt_fixed;
                        } else {
                            $ggc_alt_text = get_post_meta($aid, '_ggc_attachment_alt_text', true);
                            $wp_alt_text = get_post_meta($aid, '_wp_attachment_image_alt', true);
                            if (!empty($ggc_alt_text)) {
                                $replacement_text = $ggc_alt_text;
                            } elseif (!empty($wp_alt_text)) {
                                $replacement_text = $wp_alt_text;
                            }
                        }

                        return '<span class="ggc-alt-replacement">' . esc_html($replacement_text) . '</span>';
                    }

                    // Replacement policy based on alt_mode setting:
                    // - none: 代替テキストを設定しない（空）
                    // - individual: メディア毎の代替テキストのみ使用
                    // - eval_fixed: 評価に従って固定テキストを使用
                    
                    $replacement_text = '';
                    
                    if ($alt_mode_effective === 'none') {
                        // 代替テキストを設定しない
                        $replacement_text = '';
                    } elseif ($alt_mode_effective === 'individual') {
                        // メディア毎の代替テキストのみ（評価なし）
                        $ggc_alt_text = get_post_meta($aid, '_ggc_attachment_alt_text', true);
                        if (!empty($ggc_alt_text)) {
                            $replacement_text = $ggc_alt_text;
                        }
                    } elseif ($alt_mode_effective === 'eval_fixed') {
                        // 評価に従って固定テキストを使用
                        if (!empty($alt_fixed)) {
                            $replacement_text = $alt_fixed;
                        }
                    }

                    // Return a placeholder span (may be empty) so layout is preserved and screen readers get alt text if available
                    return '<span class="ggc-alt-replacement">' . esc_html($replacement_text) . '</span>';
                }
            }
            return $img;
        }, $content);

        return $content;
    }

    public function handle_preview_filtered() {
        $post_id = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
        if (!$post_id) {
            wp_die('Invalid post ID.');
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Forbidden');
        }
        if (!isset($_REQUEST['ggc_preview_nonce']) || !wp_verify_nonce($_REQUEST['ggc_preview_nonce'], 'ggc_preview_filtered')) {
            wp_die('Invalid nonce.');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_die('Post not found.');
        }

        $this->force_preview = true;
        setup_postdata($post);
        $raw_content = '';
        if (isset($_POST['content'])) {
            $raw_content = wp_unslash($_POST['content']);
        }
        if ($raw_content === '') {
            $raw_content = $post->post_content;
        }
        $content = apply_filters('the_content', $raw_content);
        wp_reset_postdata();
        $this->force_preview = false;

        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        echo '<!doctype html><html><head><meta charset="' . esc_attr(get_option('blog_charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html(get_the_title($post_id)) . '</title>';
        echo '</head><body>';
        echo $content;
        echo '</body></html>';
        exit;
    }

    /**
     * Gutenbergブロックの代替テキスト処理
     * ggcAltText 属性が設定されている場合、評価に従ってメディアを代替テキストに置き換える
     */
    public function render_block_with_alt_text($block_content, $block) {
        $preview_override = is_user_logged_in() && isset($_GET['ggc_preview']) && $_GET['ggc_preview'] === '1';

        // ログイン中またはシングル投稿以外は処理しない（プレビュー時は除外）
        if (!$this->force_preview && !$preview_override) {
            if (is_user_logged_in() || !is_singular()) {
                return $block_content;
            }
        }

        // 対象ブロック: core/image, core/gallery, core/cover
        $target_blocks = ['core/image', 'core/gallery', 'core/cover'];
        if (!in_array($block['blockName'], $target_blocks, true)) {
            return $block_content;
        }

        // ggcAltText 属性が空の場合、ポストメタから読み込みを試行
        $alt_text = isset($block['attrs']['ggcAltText']) ? trim($block['attrs']['ggcAltText']) : '';
        
        global $post;
        if (empty($post->ID)) {
            return $block_content;
        }

        // ポストメタに保存されたブロック属性から読み込み
        if (empty($alt_text)) {
            $block_attrs = get_post_meta($post->ID, '_ggc_block_attrs', true);
            
            // JSON 文字列の場合はデコード
            if (is_string($block_attrs)) {
                $block_attrs = json_decode($block_attrs, true);
            }
            
            if (is_array($block_attrs) && isset($block['attrs']['id'])) {
                $image_id = intval($block['attrs']['id']);
                if (isset($block_attrs[$image_id])) {
                    $alt_text = trim($block_attrs[$image_id]);
                }
            }
        }
        
        // グローバル設定を優先する
        $global_ua_option = get_option('ggc_global_media_user_agent_control', 'apply_new_posts');
        $global_ip_option = get_option('ggc_global_media_ip_evaluation', 'apply_new_posts');
        
        // グローバル設定が 'apply_new_posts' の場合のみ投稿メタを確認
        if ($global_ua_option === 'apply_new_posts') {
            $ua_action = get_post_meta($post->ID, '_ggc_media_ua_action', true) ?: 'allow_all';
        } else {
            if ($global_ua_option === 'none') {
                $ua_action = 'none';
            } elseif ($global_ua_option === 'global_blacklist') {
                $ua_action = 'blacklist';
            } elseif ($global_ua_option === 'global_whitelist') {
                $ua_action = 'whitelist';
            } else {
                $ua_action = 'blacklist';
            }
        }
        
        if ($global_ip_option === 'apply_new_posts') {
            $ip_action = get_post_meta($post->ID, '_ggc_media_ip_action', true) ?: 'allow_all';
        } else {
            if ($global_ip_option === 'none') {
                $ip_action = 'none';
            } elseif ($global_ip_option === 'global_blacklist') {
                $ip_action = 'blacklist';
            } elseif ($global_ip_option === 'global_whitelist') {
                $ip_action = 'whitelist';
            } else {
                $ip_action = 'blacklist';
            }
        }

        // 両方とも評価なしの場合は何もしない
        if (($ua_action === 'allow_all' || $ua_action === 'none') && ($ip_action === 'allow_all' || $ip_action === 'none')) {
            return $block_content;
        }

        $force_deny_all = ($ua_action === 'deny_all' || $ip_action === 'deny_all');
        $alt_fixed = get_option('ggc_alt_fixed_text', '');
        $alt_fixed_featured = get_option('ggc_alt_fixed_text_featured', '');
        $alt_fixed_for_featured = !empty($alt_fixed_featured) ? $alt_fixed_featured : $alt_fixed;
        $global_has_list = in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)
            || in_array($global_ip_option, ['global_blacklist','global_whitelist'], true);

        $alt_mode = get_option('ggc_alt_mode', 'none');
        $alt_fixed = get_option('ggc_alt_fixed_text', '');
        $global_has_list = in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)
            || in_array($global_ip_option, ['global_blacklist','global_whitelist'], true);

        $alt_mode_effective = $alt_mode;
        if (!empty($alt_fixed)
            && (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)
                || in_array($global_ip_option, ['global_blacklist','global_whitelist'], true))) {
            // グローバル設定でブラックリスト/ホワイトリスト時は固定テキストを優先
            $alt_mode_effective = 'eval_fixed';
        }

        // グローバル設定でブラック/ホワイトリスト使用時、固定テキストが空なら置換しない
        if ($global_has_list && empty($alt_fixed) && !$force_deny_all) {
            return $block_content;
        }

        // eval_fixed モードでない場合、代替テキストが空ならスキップ（deny_all は除外）
        if (empty($alt_text) && $alt_mode_effective !== 'eval_fixed' && !$force_deny_all) {
            return $block_content;
        }

        // UA評価
        $ua_is_match = false;
        if ($ua_action === 'blacklist' || $ua_action === 'whitelist') {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_media_crawlers_for_match = get_option('ggc_global_media_selected_crawlers', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_media_crawlers_for_match = get_post_meta($post->ID, '_ggc_selected_media_crawlers', true) ?: [];
            }

            $all_bots = Custom_Crawler_Core::get_allowable_bots();
            foreach ($selected_media_crawlers_for_match as $key) {
                if (isset($all_bots[$key]['uas'])) {
                    foreach ($all_bots[$key]['uas'] as $pattern) {
                        if ($pattern !== '' && stripos($user_agent, $pattern) !== false) {
                            $ua_is_match = true;
                            break 2;
                        }
                    }
                }
            }

            // Check page pattern definitions
            if (!$ua_is_match) {
                if (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)) {
                    // グローバル設定優先の場合はパターン評価をスキップ
                    $selected_page_pattern_keys_for_match = [];
                } else {
                    $selected_page_pattern_keys_for_match = get_post_meta($post->ID, '_ggc_selected_media_page_browser_patterns', true) ?: [];
                }

                if (!empty($selected_page_pattern_keys_for_match)) {
                    $all_global_patterns_structured = Custom_Crawler_Core::get_browser_block_patterns();
                    foreach ($selected_page_pattern_keys_for_match as $key) {
                        if (isset($all_global_patterns_structured[$key]['pattern'])) {
                            $patterns = preg_split('/[\r\n,]+/', $all_global_patterns_structured[$key]['pattern']);
                            foreach ($patterns as $pattern) {
                                $pattern = trim($pattern);
                                if ($pattern === '') continue;
                                if (stripos($user_agent, $pattern) !== false) {
                                    $ua_is_match = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // IP評価
        $ip_is_in_range = false;
        if ($ip_action === 'blacklist' || $ip_action === 'whitelist') {
            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ip_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_ips_for_match = get_option('ggc_global_media_selected_ips', []) ?: [];
                $selected_ips_2_for_match = get_option('ggc_global_media_selected_ips_2', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_ips_for_match = get_post_meta($post->ID, '_ggc_selected_media_ips', true) ?: [];
                $selected_ips_2_for_match = get_post_meta($post->ID, '_ggc_selected_media_ips_2', true) ?: [];
            }
            $all_selected_ips_for_match = array_merge($selected_ips_for_match, $selected_ips_2_for_match);
            if (!empty($all_selected_ips_for_match) && Custom_Crawler_Core::is_in_allowable_ip_range($all_selected_ips_for_match)) {
                $ip_is_in_range = true;
            }
        }

        // 代替テキストを表示するべきか判定
        $should_replace = false;

        // deny_all は常に置換（テキストが空なら固定テキスト or メディアの alt を使用）
        if ($force_deny_all) {
            $should_replace = true;
            if (empty($alt_text)) {
                $fixed_text = get_option('ggc_alt_fixed_text', '');
                if (!empty($fixed_text)) {
                    $alt_text = $fixed_text;
                } elseif (isset($block['attrs']['id'])) {
                    $aid = intval($block['attrs']['id']);
                    $ggc_alt_text = get_post_meta($aid, '_ggc_attachment_alt_text', true);
                    $wp_alt = get_post_meta($aid, '_wp_attachment_image_alt', true);
                    if (!empty($ggc_alt_text)) {
                        $alt_text = $ggc_alt_text;
                    } elseif (!empty($wp_alt)) {
                        $alt_text = $wp_alt;
                    }
                }
            }
        }

        // プレビュー時は評価を無視して常に置換
        if ($this->force_preview || $preview_override) {
            $should_replace = true;
        } else {
            // alt_mode の確認
            if ($alt_mode_effective === 'none') {
                return $block_content;
            }
            
            // 投稿メタが「グローバル設定に従う」かどうか確認
            $post_ua_meta = get_post_meta($post->ID, '_ggc_media_ua_action', true);
            $post_ip_meta = get_post_meta($post->ID, '_ggc_media_ip_action', true);
            $post_uses_global = ($post_ua_meta === 'global' || $post_ip_meta === 'global');
            
            // グローバル設定が優先される場合は、alt_mode=individual でも評価に従う
            $global_ua_is_strict = $global_ua_option !== 'apply_new_posts';
            $global_ip_is_strict = $global_ip_option !== 'apply_new_posts';
            
            // モード別の処理
            if ($alt_mode_effective === 'individual' && !$global_ua_is_strict && !$global_ip_is_strict && !$post_uses_global) {
                // グローバル設定が優先されず、投稿メタが「グローバル設定に従う」でない場合のみ、individual モードで常に表示
                $should_replace = true;
            } elseif ($alt_mode_effective === 'eval_fixed' || $global_ua_is_strict || $global_ip_is_strict || $post_uses_global) {
                // 評価に従って処理（UA/IP評価を続行）
            }
        }

        // UA評価による判定
        if (!$should_replace) {
            if ($ua_action === 'blacklist' && $ua_is_match) {
                $should_replace = true;
            } elseif ($ua_action === 'whitelist' && !$ua_is_match) {
                $should_replace = true;
            }
        }

        // IP評価による判定
        if (!$should_replace) {
            if ($ip_action === 'blacklist' && $ip_is_in_range) {
                $should_replace = true;
            } elseif ($ip_action === 'whitelist' && !$ip_is_in_range) {
                $should_replace = true;
            }
        }

        // 代替テキストに置き換える
        if ($should_replace) {
            // eval_fixed モードの場合は固定テキストを使用
            if ($alt_mode_effective === 'eval_fixed') {
                if (!empty($alt_fixed)) {
                    $alt_text = $alt_fixed;
                }
            }
            
            // プレビュー時で ggcAltText が空の場合は、メディアの alt から代替テキストを補完
            if (($this->force_preview || $preview_override) && empty($alt_text)) {
                if ($block['blockName'] === 'core/image') {
                    if (preg_match('/data-id=["\"]?(\d+)["\"]?/', $block_content, $idMatch)) {
                        $aid = intval($idMatch[1]);
                        $ggc_alt_text = get_post_meta($aid, '_ggc_attachment_alt_text', true);
                        $wp_alt = get_post_meta($aid, '_wp_attachment_image_alt', true);
                        if (!empty($ggc_alt_text)) {
                            $alt_text = $ggc_alt_text;
                        } elseif (!empty($wp_alt)) {
                            $alt_text = $wp_alt;
                        }
                    }
                } elseif ($block['blockName'] === 'core/gallery') {
                    $map = [];
                    if (preg_match_all('/data-id=["\"]?(\d+)["\"]?/', $block_content, $ids)) {
                        foreach ($ids[1] as $id) {
                            $aid = intval($id);
                            $ggc_alt_text = get_post_meta($aid, '_ggc_attachment_alt_text', true);
                            $wp_alt = get_post_meta($aid, '_wp_attachment_image_alt', true);
                            if (!empty($ggc_alt_text)) {
                                $map[$aid] = $ggc_alt_text;
                            } elseif (!empty($wp_alt)) {
                                $map[$aid] = $wp_alt;
                            }
                        }
                    }
                    if (!empty($map)) {
                        $alt_text = wp_json_encode($map);
                    }
                }
            }

            if ($block['blockName'] === 'core/gallery') {
                $map = json_decode($alt_text, true);
                if (is_array($map)) {
                    return preg_replace_callback('/<img[^>]+>/i', function ($m) use ($map) {
                        $img = $m[0];
                        if (preg_match('/data-id=["\"]?(\d+)["\"]?/', $img, $idMatch)) {
                            $id = $idMatch[1];
                            if (isset($map[$id]) && $map[$id] !== '') {
                                return '<span class="ggc-alt-replacement">' . esc_html($map[$id]) . '</span>';
                            }
                        }
                        return $img;
                    }, $block_content);
                }
            }
            
            // URLかどうかを判定
            $is_url = preg_match('/^https?:\/\/.+/i', $alt_text);
            
            if ($is_url) {
                // URLの場合はpタグ内にリンクタグで表示
                return '<p><a href="' . esc_url($alt_text) . '" target="_blank" rel="noopener">' . esc_html($alt_text) . '</a></p>';
            } else {
                // テキストの場合はpタグで表示
                return '<p>' . esc_html($alt_text) . '</p>';
            }
        }

        return $block_content;
    }

    /**
     * アイキャッチ画像を代替テキストに置き換える
     */
    public function filter_post_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        $preview_override = is_user_logged_in() && isset($_GET['ggc_preview']) && $_GET['ggc_preview'] === '1';

        // ログイン中またはシングル投稿以外は処理しない（プレビュー時は除外）
        if (!$this->force_preview && !$preview_override) {
            if (is_user_logged_in() || !is_singular()) {
                return $html;
            }
        }

        // 代替テキストを取得
        $alt_text = get_post_meta($post_id, '_ggc_featured_image_alt_text', true);

        // 評価判定（グローバル設定を優先）
        $global_ua_option = get_option('ggc_global_media_user_agent_control', 'apply_new_posts');
        $global_ip_option = get_option('ggc_global_media_ip_evaluation', 'apply_new_posts');
        $alt_fixed = get_option('ggc_alt_fixed_text', '');
        $alt_fixed_featured = get_option('ggc_alt_fixed_text_featured', '');
        $alt_fixed_for_featured = !empty($alt_fixed_featured) ? $alt_fixed_featured : $alt_fixed;
        $global_has_list = in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)
            || in_array($global_ip_option, ['global_blacklist','global_whitelist'], true);
        
        // グローバル設定が 'apply_new_posts' の場合のみ投稿メタを確認
        if ($global_ua_option === 'apply_new_posts') {
            $ua_action = get_post_meta($post_id, '_ggc_media_ua_action', true) ?: 'global';
            if ($ua_action === 'global') {
                $ua_action = 'allow_all';
            }
        } else {
            if ($global_ua_option === 'none') {
                $ua_action = 'none';
            } elseif ($global_ua_option === 'global_blacklist') {
                $ua_action = 'blacklist';
            } elseif ($global_ua_option === 'global_whitelist') {
                $ua_action = 'whitelist';
            } else {
                $ua_action = 'blacklist';
            }
        }
        
        if ($global_ip_option === 'apply_new_posts') {
            $ip_action = get_post_meta($post_id, '_ggc_media_ip_action', true) ?: 'global';
            if ($ip_action === 'global') {
                $ip_action = 'allow_all';
            }
        } else {
            if ($global_ip_option === 'none') {
                $ip_action = 'none';
            } elseif ($global_ip_option === 'global_blacklist') {
                $ip_action = 'blacklist';
            } elseif ($global_ip_option === 'global_whitelist') {
                $ip_action = 'whitelist';
            } else {
                $ip_action = 'blacklist';
            }
        }

        // 両方とも評価なしの場合は何もしない
        if (($ua_action === 'allow_all' || $ua_action === 'none') && ($ip_action === 'allow_all' || $ip_action === 'none')) {
            return $html;
        }

        $force_deny_all = ($ua_action === 'deny_all' || $ip_action === 'deny_all');

        if ($global_has_list && !$force_deny_all) {
            // グローバル設定が優先：投稿側のテキストは無視して固定テキストを使用
            if (!empty($alt_fixed_for_featured)) {
                $alt_text = $alt_fixed_for_featured;
            } else {
                // グローバル設定でブラック/ホワイトリスト使用時、固定テキストが空なら置換しない
                return $html;
            }
        } else {
            if (empty($alt_text)) {
                if ($force_deny_all) {
                    if (!empty($alt_fixed_for_featured)) {
                        $alt_text = $alt_fixed_for_featured;
                    } else {
                        $wp_alt = get_post_meta($post_thumbnail_id, '_wp_attachment_image_alt', true);
                        if (!empty($wp_alt)) {
                            $alt_text = $wp_alt;
                        }
                    }
                }

                if (empty($alt_text)) {
                    return $html;
                }
            }
        }

        $should_replace = false;

        // プレビュー時は評価を無視して常に置換
        if ($this->force_preview || $preview_override) {
            $should_replace = true;
        } else {
            // alt_mode が none の場合は置換しない（deny_all は除外）
            $alt_mode = get_option('ggc_alt_mode', 'none');
            if ($alt_mode === 'none' && !$force_deny_all) {
                return $html;
            }
        }

        if (!$should_replace && $force_deny_all) {
            $should_replace = true;
        }

        // UA評価
        $ua_is_match = false;
        if ($ua_action === 'blacklist' || $ua_action === 'whitelist') {
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ua_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_media_crawlers = get_option('ggc_global_media_selected_crawlers', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_media_crawlers = get_post_meta($post_id, '_ggc_selected_media_crawlers', true) ?: [];
            }

            $all_bots = Custom_Crawler_Core::get_allowable_bots();
            foreach ($selected_media_crawlers as $key) {
                if (isset($all_bots[$key]['uas'])) {
                    foreach ($all_bots[$key]['uas'] as $pattern) {
                        if ($pattern !== '' && stripos($user_agent, $pattern) !== false) {
                            $ua_is_match = true;
                            break 2;
                        }
                    }
                }
            }
        }

        // IP評価
        $ip_is_in_range = false;
        if ($ip_action === 'blacklist' || $ip_action === 'whitelist') {
            // 設定画面のグローバル設定が優先される場合、グローバルの選択リストを使用
            if (in_array($global_ip_option, ['global_blacklist','global_whitelist'], true)) {
                $selected_ips = get_option('ggc_global_media_selected_ips', []) ?: [];
                $selected_ips_2 = get_option('ggc_global_media_selected_ips_2', []) ?: [];
            } else {
                // apply_new_posts の場合、投稿メタの選択リストを使用
                $selected_ips = get_post_meta($post_id, '_ggc_selected_media_ips', true) ?: [];
                $selected_ips_2 = get_post_meta($post_id, '_ggc_selected_media_ips_2', true) ?: [];
            }
            $all_selected_ips = array_merge($selected_ips, $selected_ips_2);
            if (!empty($all_selected_ips) && Custom_Crawler_Core::is_in_allowable_ip_range($all_selected_ips)) {
                $ip_is_in_range = true;
            }
        }

        // UA評価による判定
        if (!$should_replace) {
            if ($ua_action === 'blacklist' && $ua_is_match) {
                $should_replace = true;
            } elseif ($ua_action === 'whitelist' && !$ua_is_match) {
                $should_replace = true;
            }
        }

        // IP評価による判定
        if (!$should_replace) {
            if ($ip_action === 'blacklist' && $ip_is_in_range) {
                $should_replace = true;
            } elseif ($ip_action === 'whitelist' && !$ip_is_in_range) {
                $should_replace = true;
            }
        }

        if ($should_replace) {
            return '<span class="ggc-alt-replacement">' . esc_html($alt_text) . '</span>';
        }

        return $html;
    }
}

// Initialize (both admin and frontend for render_block filter)
Custom_Media_Meta::get_instance();
