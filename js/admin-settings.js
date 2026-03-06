// custom-crawler-control\js\admin-settings.js

jQuery(document).ready(function ($) {
    // helper for displaying WordPress-style admin notices inside the settings page
    function showAdminNotice(msg, success) {
        // remove previous notices added by this helper
        $('.wrap .ggc-admin-notice').remove();
        var $notice = $('<div class="notice ggc-admin-notice ' + (success ? 'notice-success' : 'notice-error') + ' is-dismissible"><p>' + msg + '</p></div>');
        // if markdown template editor exists, insert after it; otherwise prepend to wrap
        // always insert directly below the main page title
        var $insertTarget = $('.wrap > h1');
        if ($insertTarget.length) {
            $insertTarget.after($notice);
        } else {
            // fallback to editor area or prepend
            $insertTarget = $('#ggc-md-template-editor');
            if ($insertTarget.length) {
                $insertTarget.after($notice);
            } else {
                $('.wrap').first().prepend($notice);
            }
        }
        // dismiss handler
        $notice.on('click', '.notice-dismiss', function () {
            $notice.remove();
        });
        // auto-remove after a few seconds
        setTimeout(function () { $notice.fadeOut(200, function () { $(this).remove(); }); }, 4000);
    }

    // グローバル設定: マークダウン画像UIの表示制御
    function updateGlobalMarkdownImageUiByMode() {
        var mode = $('#ggc_markdown_global_template_mode').val();
        var hideImageUi = (mode === 'template_raw' || mode === 'template_random_raw');
        // 画像URL欄・画像選択欄をまとめてラップしているdiv/labelを隠す
        // 画像URL欄は常に表示、画像選択・プレビューのみ制御
        var $imageUrlInput = $('#ggc-md-template-image-url').closest('label, .ggc-label-strong, .ggc-md-template-row, div');
        var $imageIdInput = $('#ggc-md-template-image-id').closest('label, .ggc-label-strong, .ggc-md-template-row, div');
        var $imagePreview = $('#ggc-md-template-image-preview').closest('div');
        var $imageSelect = $('#ggc-md-template-image-select').closest('button, div');
        var $imageRemove = $('#ggc-md-template-image-remove').closest('button, div');
        $imageUrlInput.show();
        if (hideImageUi) {
            $imageIdInput.hide();
            $imagePreview.hide();
            $imageSelect.hide();
            $imageRemove.hide();
        } else {
            $imageIdInput.show();
            $imagePreview.show();
            $imageSelect.show();
            $imageRemove.show();
        }
    }


    // ----------------------------------------------------------------------
    // グローバル設定: マークダウン画像UIの表示制御を初期化・変更時に実行
    updateGlobalMarkdownImageUiByMode();
    $('#ggc_markdown_global_template_mode').on('change', updateGlobalMarkdownImageUiByMode);
    // ヘルパー関数: ユニークなキーを生成
    // ----------------------------------------------------------------------
    function generateUniqueKey(prefix) {
        return prefix + Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
    }

    // ----------------------------------------------------------------------
    // 1. User-Agent 定義の追加・削除
    // ----------------------------------------------------------------------
    $('#ggc-add-bot').on('click', function () {
        const template = $('#ggc-bot-row-template').html();
        const newKey = generateUniqueKey('custom_bot_');
        const newRowHtml = template.replace(/__KEY__/g, newKey);
        $('#ggc-bots-tbody').append(newRowHtml);
    });

    $(document).on('click', '.ggc-remove-bot', function () {
        $(this).closest('tr').remove();
    });

    // ----------------------------------------------------------------------
    // 1-1. グローバルUser-Agentリスト: 展開/全選択
    // ----------------------------------------------------------------------
    $(document).on('click', '.ggc-settings-group-header', function (e) {
        if ($(e.target).hasClass('ggc-settings-toggle-all') || $(e.target).closest('.ggc-settings-toggle-all').length) return;

        const $header = $(this);
        const targetId = $header.data('target');
        const $content = $(targetId);
        const $arrow = $header.find('.ggc-settings-arrow');

        if ($content.length === 0) return;

        $content.toggleClass('open');
        const isTableGroup = $content.is('tbody');
        if ($content.hasClass('open')) {
            if (isTableGroup) {
                $content.show();
                $content.css('display', 'table-row-group');
            } else {
                $content.slideDown(200);
            }
        } else {
            if (isTableGroup) {
                $content.hide();
            } else {
                $content.slideUp(200);
            }
        }
        $arrow.toggleClass('rotated');
    });

    $(document).on('click', '.ggc-settings-toggle-all', function () {
        const groupId = $(this).data('group');
        if (!groupId) return;
        const $group = $('#' + groupId);
        const $checkboxes = $group.find('input[type="checkbox"]');
        if ($checkboxes.length === 0) return;

        const allChecked = $checkboxes.filter(':checked').length === $checkboxes.length;
        $checkboxes.prop('checked', !allChecked);
    });

    // ----------------------------------------------------------------------
    // 2. IP範囲 定義の追加・削除
    // ----------------------------------------------------------------------
    $('#ggc-add-ip').on('click', function () {
        const template = $('#ggc-ip-row-template').html();
        const newKey = generateUniqueKey('custom_ip_');
        const newRowHtml = template.replace(/__KEY__/g, newKey);
        $('#ggc-ip-ranges-tbody').append(newRowHtml);
    });

    // ----------------------------------------------------------------------
    // 2-2. IP範囲 定義2の追加・削除
    // ----------------------------------------------------------------------
    $('#ggc-add-ip-2').on('click', function () {
        const template = $('#ggc-ip-row-template-2').html();
        const newKey = generateUniqueKey('custom_ip2_');
        const newRowHtml = template.replace(/__KEY__/g, newKey);
        $('#ggc-ip-ranges-tbody-2').append(newRowHtml);
    });

    // ----------------------------------------------------------------------
    // 3. ページ評価メッセージ定義の追加・削除
    // ----------------------------------------------------------------------
    $('#ggc-add-page-eval').on('click', function () {
        const template = $('#ggc-page-eval-row-template').html();
        if (!template) return;
        const newKey = generateUniqueKey('page_eval_');
        const newRowHtml = template.replace(/__KEY__/g, newKey);
        $('#ggc-page-eval-tbody').append(newRowHtml);
    });

    $(document).on('click', '.ggc-remove-page-eval', function () {
        $(this).closest('tr').remove();
    });

    // ----------------------------------------------------------------------
    // 4. Global select change -> show/hide lists dynamically
    // ----------------------------------------------------------------------
    function updateGlobalListsVisibility() {
        // すべての変数宣言を先頭に移動
        const mediaEvalMode = $('#ggc_global_media_eval_mode_select').length ? $('#ggc_global_media_eval_mode_select').val() : null;
        const mediaDisplayMode = $('#ggc_global_media_display_mode_select').length ? $('#ggc_global_media_display_mode_select').val() : null;
        const featuredDisplayMode = $('#ggc_global_featured_display_mode_select').length ? $('#ggc_global_featured_display_mode_select').val() : null;
        const uaMode = $('#ggc_global_user_agent_control_select').val();
        const ipMode = $('#ggc_global_ip_evaluation_select').val();
        const mediaUaMode = $('#ggc_global_media_user_agent_control_select').length ? $('#ggc_global_media_user_agent_control_select').val() : null;
        const mediaIpMode = $('#ggc_global_media_ip_evaluation_select').length ? $('#ggc_global_media_ip_evaluation_select').val() : null;
        const mdMode = $('#ggc_markdown_replace_enabled').length ? $('#ggc_markdown_replace_enabled').val() : null;
        const mdTemplateMode = $('#ggc_markdown_global_template_mode').length ? $('#ggc_markdown_global_template_mode').val() : null;
        const uaRedirectMode = $('#ggc_global_ua_redirect_mode_select').length ? $('#ggc_global_ua_redirect_mode_select').val() : null;
        const ipRedirectMode = $('#ggc_global_ip_redirect_mode_select').length ? $('#ggc_global_ip_redirect_mode_select').val() : null;


        // グローバル評価が「全ページで設定」の場合にのみ表示モードと関連行を出す
        if (mediaEvalMode === 'all') {
            $('#ggc-global-media-display-mode-row').show();
            $('#ggc-global-featured-display-mode-row').show();
            // UA/IPリストはメディアまたはアイキャッチのいずれかが normal 以外のときに表示
            if (mediaDisplayMode !== 'normal' || featuredDisplayMode !== 'normal') {
                $('#ggc-global-media-ua-row').show();
                $('#ggc-global-media-ip-row').show();
                // User-Agentリスト部分
                if (mediaUaMode === 'global_blacklist' || mediaUaMode === 'global_whitelist') {
                    $('#ggc-global-media-ua-list').show();
                } else {
                    $('#ggc-global-media-ua-list').hide();
                }
                // IPリスト部分
                if (mediaIpMode === 'global_blacklist' || mediaIpMode === 'global_whitelist') {
                    $('#ggc-global-media-ip-list').show();
                } else {
                    $('#ggc-global-media-ip-list').hide();
                }
            } else {
                $('#ggc-global-media-ua-row').hide();
                $('#ggc-global-media-ip-row').hide();
                $('#ggc-global-media-ua-list').hide();
                $('#ggc-global-media-ip-list').hide();
            }
        } else {
            $('#ggc-global-media-display-mode-row').hide();
            $('#ggc-global-featured-display-mode-row').hide();
            $('#ggc-global-media-text-settings, #ggc-global-media-text-settings-2').hide();
            $('#ggc-global-media-ua-row').hide();
            $('#ggc-global-media-ip-row').hide();
            $('#ggc-global-media-ua-list').hide();
            $('#ggc-global-media-ip-list').hide();
        }
        // displayMode によるテキスト欄表示制御
        // メディアまたはアイキャッチのいずれかが alt_replace の場合に代替テキスト欄を表示
        if (mediaEvalMode === 'all') {
            // アイキャッチ画像の代替テキスト: アイキャッチが alt_replace の場合のみ
            if (featuredDisplayMode === 'alt_replace') {
                $('#ggc-global-media-text-settings').show();
            } else {
                $('#ggc-global-media-text-settings').hide();
            }
            // メディアの代替テキスト: メディアが alt_replace の場合のみ
            if (mediaDisplayMode === 'alt_replace') {
                $('#ggc-global-media-text-settings-2').show();
            } else {
                $('#ggc-global-media-text-settings-2').hide();
            }
        } else {
            $('#ggc-global-media-text-settings, #ggc-global-media-text-settings-2').hide();
        }
        if (mdMode === 'all') {
            $('#ggc-markdown-global-eval-wrapper').show();
        } else {
            $('#ggc-markdown-global-eval-wrapper').hide();
        }



        const updateGlobalList = function (mode, listSelector, whitelistText, blacklistText) {
            const $list = $(listSelector);
            if ($list.length === 0) return;
            if (mode === 'global_blacklist' || mode === 'global_whitelist') {
                $list.show();
                const $desc = $list.find('p:first');
                if ($desc.length) {
                    const text = mode === 'global_whitelist' ? whitelistText : blacklistText;
                    $desc.html('<strong>' + text + '</strong>');
                }
            } else {
                $list.hide();
            }
        };

        const toggleRows = function (show, selectors) {
            // When elements have helper classes that force display:none, make sure to
            // remove/add them so that jQuery.show()/hide() can take effect.  This was
            // causing the "置換テンプレート" dropdown to stay hidden when the page was
            // initially rendered with mode=random (class=gcc-hidden) and the user later
            // switched back to select/select_raw.
            if (show) {
                $(selectors).each(function () {
                    $(this).removeClass('ggc-hidden ggc-hidden-row');
                }).show();
            } else {
                $(selectors).each(function () {
                    $(this).addClass('ggc-hidden');
                }).hide();
            }
        };

        updateGlobalList(
            uaMode,
            '#ggc-global-ua-list',
            'ホワイトリスト : チェックしたUser-Agentをアクセス許可します。',
            'ブラックリスト : チェックしたUser-Agentをアクセス拒否します。'
        );
        toggleRows(uaMode === 'global_blacklist' || uaMode === 'global_whitelist', '#ggc-global-ua-redirect-row');

        updateGlobalList(
            ipMode,
            '#ggc-global-ip-list',
            'ホワイトリスト : チェックしたIP範囲をアクセス許可します。',
            'ブラックリスト : チェックしたIP範囲をアクセス拒否します。'
        );
        toggleRows(ipMode === 'global_blacklist' || ipMode === 'global_whitelist', '#ggc-global-ip-redirect-row');

        // ↑メディア用リスト・行の個別表示切り替えは「全ページで設定」時のみ上記で実施するため、ここでは不要


        // --- マークダウン新ロジック ---
        let uaEval = $('#ggc_markdown_ua_eval').length ? $('#ggc_markdown_ua_eval').val() : null;
        let ipEval = $('#ggc_markdown_ip_eval').length ? $('#ggc_markdown_ip_eval').val() : null;

        // テンプレート選択方法の表示制御
        // ・モードが'all'であること
        // ・かつ UA評価またはIP評価のいずれかが「設定しない(none)」以外であること
        // 両方 none のときはテンプレート選択が不要なので隠す。
        let showMdTemplate = false;
        if (mdMode === 'all') {
            showMdTemplate = (uaEval !== 'none' || ipEval !== 'none');
        }
        toggleRows(showMdTemplate, '#ggc-markdown-global-template-wrapper');

        // User-Agentリストの表示制御
        if (uaEval === 'blacklist' || uaEval === 'whitelist') {
            $('#ggc-markdown-global-ua-list').show();
            if (uaEval === 'whitelist') {
                $('#ggc-markdown-ua-description').text('ホワイトリスト : チェックしたUser-Agent以外をマークダウンに置換します。');
            } else {
                $('#ggc-markdown-ua-description').text('ブラックリスト : チェックしたUser-Agentをマークダウンに置換します。');
            }
        } else {
            $('#ggc-markdown-global-ua-list').hide();
            $('#ggc-markdown-ua-description').text('');
        }

        // IPリストの表示制御
        if (ipEval === 'blacklist' || ipEval === 'whitelist') {
            $('#ggc-markdown-global-ip-list').show();
            if (ipEval === 'whitelist') {
                $('#ggc-markdown-ip-description').text('ホワイトリスト : チェックしたIP範囲以外をマークダウンに置換します。');
            } else {
                $('#ggc-markdown-ip-description').text('ブラックリスト : チェックしたIP範囲をマークダウンに置換します。');
            }
        } else {
            $('#ggc-markdown-global-ip-list').hide();
            $('#ggc-markdown-ip-description').text('');
        }

        toggleRows(mdTemplateMode !== 'random' && mdTemplateMode !== 'random_raw', '#ggc-markdown-global-template-key');
        toggleRows(uaRedirectMode === 'block', '#ggc-global-ua-block-message');
        toggleRows(uaRedirectMode === 'redirect', '#ggc-global-ua-redirect-url');
        toggleRows(ipRedirectMode === 'block', '#ggc-global-ip-block-message');
        toggleRows(ipRedirectMode === 'redirect', '#ggc-global-ip-redirect-url');
    }

    // Bind events and run initially
    $(document).on('change', '#ggc_global_media_eval_mode_select, #ggc_global_media_display_mode_select, #ggc_global_featured_display_mode_select, #ggc_global_user_agent_control_select, #ggc_global_ip_evaluation_select, #ggc_global_media_user_agent_control_select, #ggc_global_media_ip_evaluation_select, #ggc_global_page_eval_mode_select, #ggc_global_page_user_agent_control_select, #ggc_global_page_ip_control_select, #ggc_markdown_replace_enabled, #ggc_markdown_global_template_mode, #ggc_global_ua_redirect_mode_select, #ggc_global_ip_redirect_mode_select, #ggc_markdown_ua_eval, #ggc_markdown_ip_eval', function () {
        updateGlobalListsVisibility();
    });
    // サブセレクトのchangeにも個別バインド（念のため）
    $('#ggc_markdown_ua_eval, #ggc_markdown_ip_eval').on('change', function () {
        updateGlobalListsVisibility();
    });
    // タイトル行保存前バリデーション
    $('form').on('submit', function (e) {
        const mdMode = $('#ggc_markdown_replace_enabled').val();
        const uaEval = $('#ggc_markdown_ua_eval').val();
        const ipEval = $('#ggc_markdown_ip_eval').val();
        const mdTemplateMode = $('#ggc_markdown_global_template_mode').val();
        const tplKey = $('#ggc_markdown_global_template_key').val();
        const modeNoRaw = mdTemplateMode ? mdTemplateMode.replace(/_raw$/, '') : '';

        if (mdMode === 'all' && (uaEval !== 'none' || ipEval !== 'none')) {
            if (modeNoRaw === 'select' && !tplKey) {
                e.preventDefault();
                showAdminNotice('テンプレートを選択してください。', false);
            }
        }
    });
    // 初期化時に必ず実行
    updateGlobalListsVisibility();

    // ----------------------------------------------------------------------
    // Markdown template select/load/save (settings page)
    // ----------------------------------------------------------------------
    function setMarkdownEditor(data) {
        $('#ggc-md-template-key').val(data.key || '');
        $('#ggc-md-template-title').val(data.title || '');
        $('#ggc-md-template-markdown').val(data.markdown || '');
        $('#ggc-md-template-image-id').val(data.image_id || '');
        $('#ggc-md-template-image-url').val(data.image_url || '');
        $('#ggc-md-template-random').prop('checked', !!data.random_enabled);

        // プレビュー画像はURL欄が優先、なければimage_idのURL、どちらもなければ未設定
        var url = data.image_url || '';
        if (url) {
            $('#ggc-md-template-image-preview').html('<img src="' + url + '" class="ggc-md-preview-img" />');
        } else if (data.preview_url) {
            $('#ggc-md-template-image-preview').html('<img src="' + data.preview_url + '" class="ggc-md-preview-img" />');
        } else {
            $('#ggc-md-template-image-preview').html('<span class="ggc-muted-text">未設定</span>');
        }
    }

    // load template data from server and populate editor
    function loadMarkdownTemplate(key) {
        if (!key) return;
        $.post(ggcSettings.ajax_url, {
            action: 'ggc_get_markdown_template',
            nonce: ggcSettings.markdown_nonce,
            key: key
        }).done(function (res) {
            if (res && res.success) {
                setMarkdownEditor(res.data || {});
            } else {
                alert('テンプレートの読み込みに失敗しました。');
            }
        }).fail(function () {
            alert('テンプレートの読み込みに失敗しました。');
        });
    }

    // Bind old button for backward compatibility (may not exist)
    $('#ggc-md-template-load').on('click', function () {
        const key = $('#ggc-md-template-select').val();
        loadMarkdownTemplate(key);
    });

    // ドロップダウンで選択すると自動的に読み込む
    $('#ggc-md-template-select').on('change', function () {
        const key = $(this).val();
        if (!key) {
            // 空値なら編集エリアをクリア
            setMarkdownEditor({ key: '', title: '', markdown: '', image_id: '', image_url: '', random_enabled: false, preview_url: '' });
            return;
        }
        loadMarkdownTemplate(key);
    });

    // generic key sanitizer now matches template-key rules: only alphanumeric + underscore
    // (applies to crawlers, IP ranges, patterns, page evals, etc.)
    $(document).on('input', 'input[name$="[key]"]', function () {
        const original = this.value;
        const sanitized = original.replace(/[^A-Za-z0-9_]/g, '');
        if (original !== sanitized) {
            this.value = sanitized;
            // show inline message
            let $msg = $(this).siblings('.ggc-key-msg');
            if ($msg.length === 0) {
                $msg = $('<span class="ggc-key-msg description" style="color:#b91c1c; display:block;">半角英数字とアンダーバーのみ有効です</span>');
                $(this).after($msg);
            }
            clearTimeout($msg.data('timeout'));
            const timeout = setTimeout(function () { $msg.fadeOut(200, function () { $(this).remove(); }); }, 2000);
            $msg.data('timeout', timeout);
        }
    });

    // template key has stricter rule: only alphanumeric plus underscore (uppercase allowed)
    $('#ggc-md-template-key').on('input', function () {
        const original = this.value;
        // do not lowercase so A-Z are preserved
        const sanitized = original.replace(/[^A-Za-z0-9_]/g, '');
        if (original !== sanitized) {
            this.value = sanitized;
            // show inline message
            let $msg = $('#ggc-md-template-key-msg');
            if ($msg.length === 0) {
                $msg = $('<span id="ggc-md-template-key-msg" class="description" style="color:#b91c1c; display:block;">半角英数字とアンダーバーのみ有効です</span>');
                $(this).after($msg);
            }
            clearTimeout($msg.data('timeout'));
            const timeout = setTimeout(function () { $msg.fadeOut(200, function () { $(this).remove(); }); }, 2000);
            $msg.data('timeout', timeout);
        }
    });

    // ----------------------------------------------------------------------
    // Markdownタブでは全てのフォーム要素変更で自動保存
    // ----------------------------------------------------------------------
    (function () {
        let saveTimer = null;
        function scheduleAutoSave() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(function () {
                $('form').submit();
            }, 500);
        }
        // only bind when markdown tab present
        if ($('#ggc-md-template-select').length) {
            // note: do not autosave when template selector changes, as that only loads a different
            // template via AJAX and should not trigger a full form submit.
            // exclude markdown content/title/image too – these are edited manually with the save button
            // exclude "random" checkbox and the key field; changing the key should not auto-submit either
            // only save when evaluation settings or global lists change
            $(document).on('change', '#ggc_markdown_ua_eval, #ggc_markdown_ip_eval, #ggc_global_markdown_selected_crawlers input, #ggc_global_markdown_selected_patterns input, #ggc_global_markdown_selected_ips input, #ggc_global_markdown_selected_ips_2 input', scheduleAutoSave);
        }
    })();

    $('#ggc-md-template-new').on('click', function () {
        setMarkdownEditor({ key: '', title: '', markdown: '', image_id: '', image_url: '', random_enabled: false, preview_url: '' });
    });

    function refreshTemplateSelect(callback) {
        $.post(ggcSettings.ajax_url, {
            action: 'ggc_list_markdown_templates',
            nonce: ggcSettings.markdown_nonce
        }).done(function (res) {
            if (res && res.success && Array.isArray(res.data)) {
                const $select = $('#ggc-md-template-select');
                $select.empty().append('<option value="">選択してください...</option>');
                res.data.forEach(function (item) {
                    $select.append('<option value="' + item.key + '">' + item.label + '</option>');
                });
                if (typeof callback === 'function') callback();
            }
        });
    }

    $('#ggc-md-template-save').on('click', function () {
        const payload = {
            action: 'ggc_save_markdown_template',
            nonce: ggcSettings.markdown_nonce,
            key: $('#ggc-md-template-key').val().trim(),
            title: $('#ggc-md-template-title').val(),
            markdown: $('#ggc-md-template-markdown').val(),
            image_id: $('#ggc-md-template-image-id').val(),
            image_url: $('#ggc-md-template-image-url').val(),
            random_enabled: $('#ggc-md-template-random').is(':checked') ? 1 : 0
        };

        // URL欄があればimage_idを空にする（URL優先保存）
        if (payload.image_url && payload.image_url.trim() !== '') {
            payload.image_id = '';
        }

        if (!payload.key) {
            alert('テンプレートキーを入力してください。');
            return;
        }

        $.post(ggcSettings.ajax_url, payload).done(function (res) {
            if (res && res.success) {
                const key = res.data && res.data.key ? res.data.key : payload.key;
                // refresh list so random flag and title are reflected, then select the current key
                refreshTemplateSelect(function () {
                    $('#ggc-md-template-select').val(key);
                });
                showAdminNotice('テンプレートを保存しました。', true);
            } else {
                showAdminNotice('テンプレートの保存に失敗しました。', false);
            }
        }).fail(function () {
            alert('テンプレートの保存に失敗しました。');
        });
    });

    $('#ggc-md-template-delete').on('click', function () {
        const key = $('#ggc-md-template-key').val().trim();
        if (!key) return;
        if (!confirm('このテンプレートを削除しますか？')) return;

        $.post(ggcSettings.ajax_url, {
            action: 'ggc_delete_markdown_template',
            nonce: ggcSettings.markdown_nonce,
            key: key
        }).done(function (res) {
            if (res && res.success) {
                // rebuild the select to make sure internal state is consistent
                refreshTemplateSelect(function () {
                    setMarkdownEditor({ key: '', title: '', markdown: '', image_id: '', image_url: '', random_enabled: false, preview_url: '' });
                });
                showAdminNotice('テンプレートを削除しました。', true);
            } else {
                showAdminNotice('テンプレートの削除に失敗しました。', false);
            }
        }).fail(function () {
            alert('テンプレートの削除に失敗しました。');
        });
    });

    $('#ggc-md-template-image-select').on('click', function (e) {
        e.preventDefault();
        const frame = wp.media({
            title: 'マークダウン用アイキャッチ画像を選択',
            button: { text: 'この画像を使用' },
            multiple: false
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            if (!attachment || !attachment.id) return;

            $('#ggc-md-template-image-id').val(attachment.id);
            // 画像選択時はURL欄を空にする（画像ID優先）
            $('#ggc-md-template-image-url').val('');
            const thumb = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
            $('#ggc-md-template-image-preview').html('<img src="' + thumb + '" class="ggc-md-preview-img" />');
        });

        frame.open();
    });

    $('#ggc-md-template-image-remove').on('click', function (e) {
        e.preventDefault();
        $('#ggc-md-template-image-id').val('');
        $('#ggc-md-template-image-url').val('');
        $('#ggc-md-template-image-preview').html('<span class="ggc-muted-text">未設定</span>');
    });

    // URL欄の入力でプレビューを即時更新
    $('#ggc-md-template-image-url').on('input', function () {
        var url = $(this).val();
        if (url) {
            $('#ggc-md-template-image-preview').html('<img src="' + url + '" class="ggc-md-preview-img" />');
        } else {
            // URLが空なら画像IDのプレビュー（なければ未設定）
            var imageId = $('#ggc-md-template-image-id').val();
            if (imageId) {
                // 画像IDがあればAjax等でURL取得してもよいが、ここでは未設定表示
                $('#ggc-md-template-image-preview').html('<span class="ggc-muted-text">未設定</span>');
            } else {
                $('#ggc-md-template-image-preview').html('<span class="ggc-muted-text">未設定</span>');
            }
        }
    });

    $(document).on('click', '.ggc-remove-ip', function () {
        $(this).closest('tr').remove();
    });

    // 非同期で IP 更新を実行し、結果をモーダルで表示
    $(document).on('click', '.ggc-run-ip-update-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const ajaxUrl = (typeof ggcSettings !== 'undefined' && ggcSettings.ajax_url) ? ggcSettings.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
        const nonce = (typeof ggcSettings !== 'undefined' && ggcSettings.run_update_nonce) ? ggcSettings.run_update_nonce : $btn.data('nonce');

        // Disable all update buttons and update text
        $('.ggc-run-ip-update-btn').prop('disabled', true).each(function () {
            const $textSpan = $(this).find('.ggc-btn-text');
            if ($textSpan.length) {
                $textSpan.text('更新中...');
            } else {
                $(this).text('更新中...');
            }
        });

        // show loading modal
        showLoadingModal();

        $.post(ajaxUrl, { action: 'ggc_run_ip_update', nonce: nonce }, function (resp) {
            // Re-enable all update buttons and restore text
            $('.ggc-run-ip-update-btn').prop('disabled', false).each(function () {
                const $textSpan = $(this).find('.ggc-btn-text');
                if ($textSpan.length) {
                    $textSpan.text('今すぐ IP 更新を強制実行する');
                } else {
                    $(this).text('今すぐ IP 更新を強制実行する');
                }
            });
            $('.ggc-ip-update-modal').remove();
            if (resp && resp.success) {
                try {
                    // 更新時刻の表示を更新
                    if (resp.data.result && resp.data.result.time) {
                        const updateTime = new Date(resp.data.result.time * 1000);
                        const year = updateTime.getFullYear();
                        const month = String(updateTime.getMonth() + 1).padStart(2, '0');
                        const day = String(updateTime.getDate()).padStart(2, '0');
                        const hours = String(updateTime.getHours()).padStart(2, '0');
                        const minutes = String(updateTime.getMinutes()).padStart(2, '0');
                        const seconds = String(updateTime.getSeconds()).padStart(2, '0');
                        const timeStr = year + '/' + month + '/' + day + ' ' + hours + ':' + minutes + ':' + seconds;
                        $('.ggc-inline-spacer').text('(前回更新: ' + timeStr + ')');
                    }
                    showIpUpdateModal(resp.data.result);
                } catch (err) {
                    alert('更新は完了しましたが、結果の表示に失敗しました。ブラウザのコンソールを確認してください。');
                    console.error(err);
                }
            } else {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message : '更新に失敗しました';
                showIpUpdateModal({ time: Math.floor(Date.now() / 1000), google_count: 0, openai_count: 0, details: null });
                alert(msg);
            }
        }).fail(function (jqxhr, textStatus, errorThrown) {
            $('.ggc-run-ip-update-btn').prop('disabled', false).each(function () {
                const $textSpan = $(this).find('.ggc-btn-text');
                if ($textSpan.length) {
                    $textSpan.text('今すぐ IP 更新を強制実行する');
                } else {
                    $(this).text('今すぐ IP 更新を強制実行する');
                }
            });
            $('.ggc-ip-update-modal').remove();
            alert('通信エラー: ' + (errorThrown || textStatus));
        });
    });



    // is_auto チェックボックス切り替えで textarea の有効/無効を切り替える
    $(document).on('change', 'input[type="checkbox"][name$="[is_auto]"]', function () {
        const $cb = $(this);
        const $row = $cb.closest('tr');
        const checked = $cb.prop('checked');
        $row.find('input[type="hidden"][name$="[is_auto]"]').val(checked ? 1 : 0);
        $row.find('textarea').prop('disabled', checked);
        // is_auto checkbox toggles textarea enabled/disabled
    });



    function showIpUpdateModal(result) {
        // remove existing
        $('.ggc-ip-update-modal').remove();
        const $overlay = $('<div class="ggc-ip-update-modal ggc-modal-overlay"></div>');
        const $box = $('<div class="ggc-modal-box ggc-modal-box--wide"></div>');
        const $close = $('<button class="button ggc-modal-close">閉じる</button>');
        $close.on('click', function () { $overlay.remove(); });
        $box.append($close);

        const $title = $('<h3>IP更新の結果</h3>');
        $box.append($title);
        if (result) {
            if (result.results && result.results.length > 0) {
                const $summary_p = $('<p>').append($('<strong>').text('結果: '));
                const result_parts = [];
                result.results.forEach(function (res) {
                    const part = $('<span>');
                    part.append($('<span>').text(res.label + ': '));
                    part.append($('<strong>').text(res.count !== false ? res.count + ' 件' : '取得失敗'));
                    result_parts.push(part);
                });

                for (let i = 0; i < result_parts.length; i++) {
                    $summary_p.append(result_parts[i]);
                    if (i < result_parts.length - 1) {
                        $summary_p.append(' / ');
                    }
                }
                $box.append($summary_p);
            } else {
                $box.append('<p><strong>結果:</strong> 更新対象のIP定義がありません。</p>');
            }

            $box.append($('<p>').text('実行時刻: ' + new Date(result.time * 1000).toLocaleString()));

            // 詳細情報（各フェッチのHTTPステータス・エラー）
            if (result.details && Object.keys(result.details).length > 0) {
                const details = result.details;
                $box.append('<h4 class="ggc-modal-subtitle">フェッチ詳細</h4>');
                const $tbl = $('<table class="ggc-modal-table"></table>');
                $tbl.append('<thead><tr><th class="ggc-modal-th">ソース</th><th class="ggc-modal-th">URL</th><th class="ggc-modal-th">HTTP</th><th class="ggc-modal-th">エラー</th></tr></thead>');
                const $tb = $('<tbody></tbody>');
                const displayed_keys = {};

                function rowFor(key, detail_obj) {
                    if (!detail_obj || typeof detail_obj.url === 'undefined') return;

                    const url = detail_obj.url || '（不明）';
                    const status = (typeof detail_obj.status !== 'undefined' && detail_obj.status !== null) ? detail_obj.status : 'N/A';
                    const err = detail_obj.error || '';
                    const $row = $('<tr>');
                    $row.append($('<td class="ggc-modal-td">').text(key));
                    $row.append($('<td class="ggc-modal-td ggc-modal-td--break">').text(url));
                    $row.append($('<td class="ggc-modal-td">').text(status));
                    $row.append($('<td class="ggc-modal-td">').text(err));
                    $tb.append($row);
                    displayed_keys[key] = true;
                }

                // 新しいキーをループ
                Object.keys(details).forEach(function (key) {
                    // 'google' や 'openai' のようなエイリアスはスキップ
                    if (key !== 'google' && key !== 'openai') {
                        rowFor(key, details[key]);
                    }
                });

                $tbl.append($tb);
                $box.append($tbl);
            }
        } else {
            $box.append('<p>更新の結果は利用できません。</p>');
        }

        $overlay.append($box);
        $('body').append($overlay);
        // focus
        $box.focus();
    }

    function showLoadingModal() {
        $('.ggc-ip-update-modal').remove();
        const $overlay = $('<div class="ggc-ip-update-modal ggc-modal-overlay"></div>');
        const $box = $('<div class="ggc-modal-box ggc-modal-box--compact"></div>');
        $box.append('<p class="ggc-modal-loading"><strong>更新中...</strong><br/>しばらくお待ちください。</p>');
        $overlay.append($box);
        $('body').append($overlay);
        $box.focus();
        return $overlay;
    }

    // ----------------------------------------------------------------------
    // 3. 不正UAパターン の追加・削除
    // ----------------------------------------------------------------------
    $('#ggc-add-pattern').on('click', function () {
        const template = $('#ggc-pattern-row-template').html();
        const newKey = generateUniqueKey('custom_pattern_');
        const newRowHtml = template.replace(/__KEY__/g, newKey);
        $('#ggc-patterns-tbody').append(newRowHtml);
    });

    $(document).on('click', '.ggc-remove-pattern', function () {
        $(this).closest('tr').remove();
    });

    // テキストボックスを常に有効化
    const $textbox = $('#ggc_alt_fixed_text');
    if ($textbox.length) {
        $textbox.prop('disabled', false);
    }

    // メッセージプレビュー更新関数
    function updateMessagePreview($selectElement) {
        const selectedValue = $selectElement.val();
        const previewId = $selectElement.attr('id').replace('_select--full', '').replace('_key', '') + '-preview';
        const $preview = $('#' + previewId);

        if ($preview.length === 0) return;

        // Default values (fixed)
        const defaultStatusCode = 403;
        const defaultMessage = 'アクセス禁止：このページは閲覧できません。';

        // グローバルに定義済みのメッセージオブジェクトから取得
        if (typeof ggcSettings !== 'undefined' && ggcSettings.page_eval_messages) {
            const messages = ggcSettings.page_eval_messages;

            let statusCode = defaultStatusCode;
            let message = defaultMessage;

            if (selectedValue && messages[selectedValue]) {
                const messageDef = messages[selectedValue];
                statusCode = messageDef.status_code || defaultStatusCode;
                message = messageDef.message || defaultMessage;
            }

            $preview.html(
                '<p><strong>ステータスコード:</strong> ' + statusCode + '</p>' +
                '<p><strong>メッセージ:</strong></p>' +
                '<p class="ggc-message-content">' + message.replace(/\n/g, '<br>') + '</p>'
            );
        }
    }

    // メッセージキーセレクト変更時
    $(document).on('change', 'select[id$="_block_message_key"]', function () {
        updateMessagePreview($(this));
    });

});

