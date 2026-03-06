// custom-crawler-control\js\admin - meta.js

// ggcMediaMode / ggcAltText をブロック属性として登録。
// blocks.registerBlockType フィルタはブロックタイプが登録される前に実行される必要があるため
// jQuery.ready() の外側でスクリプト読み込み時に即時実行する。
(function () {
    var _registerGgcAttrs = function () {
        if (typeof wp === 'undefined' || !wp.hooks || !wp.hooks.addFilter || !wp.blocks) return false;
        var TARGET = ['core/image', 'core/gallery', 'core/cover'];
        wp.hooks.addFilter('blocks.registerBlockType', 'ggc/access-control-attrs', function (settings, name) {
            if (TARGET.includes(name)) {
                settings.attributes = Object.assign({}, settings.attributes, {
                    ggcMediaMode: { type: 'string', default: 'normal' },
                    ggcAltText: { type: 'string', default: '' },
                });
            }
            return settings;
        });
        return true;
    };
    if (!_registerGgcAttrs()) {
        // wp.hooks がまだロードされていない場合は DOMContentLoaded で再試行
        document.addEventListener('DOMContentLoaded', _registerGgcAttrs);
    }
})();

jQuery(document).ready(function ($) {
    // マークダウン画像URL欄・画像選択ボタンの排他制御
    function updateMdImageUrlAndSelectBtn() {
        var urlInput = $('#ggc_md_replace_image_url_input');
        var selectBtn = $('#ggc-md-image-select');
        var imageId = $('#ggc_md_replace_image_id').val();
        var urlVal = urlInput.val();
        // 画像が選択されている場合はURL入力不可（完全に無効化）
        if (imageId && imageId !== '0') {
            urlInput.prop('disabled', true);
            selectBtn.prop('disabled', false);
        } else {
            urlInput.prop('disabled', false);
            // URLが入力されている場合は画像選択不可
            if (urlVal && urlVal.trim() !== '') {
                selectBtn.prop('disabled', true);
            } else {
                selectBtn.prop('disabled', false);
            }
        }
    }

    // URL入力欄の変更時
    $(document).on('input', '#ggc_md_replace_image_url_input', function () {
        updateMdImageUrlAndSelectBtn();
    });
    // 画像選択時（メディアフレーム利用の場合は別途フック必要）
    $(document).on('click', '#ggc-md-image-select', function () {
        setTimeout(updateMdImageUrlAndSelectBtn, 500);
    });
    // 画像削除時
    $(document).on('click', '#ggc-md-image-remove', function () {
        setTimeout(updateMdImageUrlAndSelectBtn, 500);
    });
    // 初期化
    updateMdImageUrlAndSelectBtn();
    // マークダウン画像UIの表示制御（画像URL欄も含めて全て）
    function updateMarkdownImageUiByMode() {
        var mode = $('#ggc_md_replace_mode').val();
        var hideImageUi = (mode === 'manual_raw' || mode === 'template_raw' || mode === 'template_random_raw');
        var $imageUiBlock = $('#ggc-md-image-ui-block');
        if ($imageUiBlock.length) {
            if (hideImageUi) {
                $imageUiBlock.hide();
            } else {
                $imageUiBlock.show();
            }
        }
    }

    // 初期状態で必ずshow/hideを明示的に実行
    $(function () {
        updateMarkdownImageUiByMode();
    });


    // ----------------------------------------------------------------------
    // マークダウン画像UIの表示制御を初期化・変更時に実行
    updateMarkdownImageUiByMode();
    $('#ggc_md_replace_mode').on('change', updateMarkdownImageUiByMode);
    // 変数定義
    // ----------------------------------------------------------------------
    const uaModeSelect = $('#ggc_ua_control_mode');
    const ipModeSelect = $('#ggc_ip_control_mode');
    const uaListWrapper = $('#ggc-ua-list-wrapper');
    const ipListWrapper = $('#ggc-ip-list-wrapper');
    const mediaModeSelect = $('#ggc_media_mode');
    const featuredModeSelect = $('#ggc_featured_mode');
    const mediaModeHelp = $('#ggc-media-mode-help');
    const mediaUaSelect = $('#ggc_media_ua_action');
    const mediaIpSelect = $('#ggc_media_ip_action');
    const uaRedirectMode = $('#ggc_ua_redirect_mode');
    const ipRedirectMode = $('#ggc_ip_redirect_mode');
    const mediaUaListWrapper = $('#ggc-media-ua-list-wrapper');
    const mediaIpListWrapper = $('#ggc-media-ip-list-wrapper');
    const mediaUaDesc = $('#ggc-media-ua-description');
    const mediaIpDesc = $('#ggc-media-ip-description');
    const mdUaSelect = $('#ggc_md_ua_mode');
    const mdIpSelect = $('#ggc_md_ip_mode');
    const mdUaListWrapper = $('#ggc-md-ua-list-wrapper');
    const mdIpListWrapper = $('#ggc-md-ip-list-wrapper');
    const mdUaDesc = $('#ggc-md-ua-description');
    const mdIpDesc = $('#ggc-md-ip-description');
    const mdReplaceModeSelect = $('#ggc_md_replace_mode');
    const mdTemplateSelectWrapper = $('#ggc-md-template-select-wrapper');
    const mdTemplateModeSelect = $('#ggc_md_template_mode');
    const mdTemplateSelectMode = $('#ggc-md-template-select-mode');
    const mdContentWrapper = $('#ggc-md-content-wrapper');
    const mdTextWrapper = $('#ggc-md-text-wrapper');
    const mdUaWrapper = $('#ggc-md-ua-wrapper');
    const mdIpWrapper = $('#ggc-md-ip-wrapper');
    const mediaEvalWrapper = $('#ggc-media-eval-wrapper');
    const globalMediaModeHidden = $('#ggc_global_media_eval_mode_hidden');
    const globalPageModeHidden = $('#ggc_global_page_eval_mode_hidden');
    const globalMdModeHidden = $('#ggc_global_md_mode_hidden');
    const featuredAltSection = $('#ggc-featured-alt-section');
    const mediaGlobalNote = $('#ggc-media-global-note');
    const uaBlockSettings = $('#ggc-ua-block-settings');
    const ipBlockSettings = $('#ggc-ip-block-settings');
    const uaPageEvalWrapper = $('#ggc-ua-page-eval-wrapper');
    const ipPageEvalWrapper = $('#ggc-ip-page-eval-wrapper');
    const uaBlockMessageSelect = $('#ggc_ua_block_message_key');
    const ipBlockMessageSelect = $('#ggc_ip_block_message_key');
    const uaBlockPreview = $('#ggc-ua-block-preview');
    const ipBlockPreview = $('#ggc-ip-block-preview');
    const uaBlockCustom = $('#ggc-ua-block-custom');
    const ipBlockCustom = $('#ggc-ip-block-custom');

    // ----------------------------------------------------------------------
    // 1. 表示制御
    // ----------------------------------------------------------------------
    function updateModeList(mode, $wrapper, $desc, listTexts, otherTexts) {
        if (!$wrapper || $wrapper.length === 0) return;
        if (mode === 'blacklist' || mode === 'whitelist') {
            $wrapper.slideDown(200);
            if ($desc && $desc.length && listTexts) {
                $desc.text(mode === 'whitelist' ? listTexts.whitelist : listTexts.blacklist);
            }
            return;
        }

        $wrapper.slideUp(200);
        if ($desc && $desc.length && otherTexts) {
            $desc.text(otherTexts[mode] || '');
        }
    }

    function updateSlideByValue($select, $target, targetValue) {
        if (!$select.length || !$target.length) return;
        if ($select.val() === targetValue) {
            $target.slideDown(200);
        } else {
            $target.slideUp(200);
        }
    }

    function updateVisibility() {
        const uaMode = uaModeSelect.val();
        const ipMode = ipModeSelect.val();
        const uaDesc = $('#ggc-ua-description');
        const ipDesc = $('#ggc-ip-description');

        updateModeList(uaMode, uaListWrapper, uaDesc, {
            whitelist: 'ホワイトリスト : チェックしたUser-Agentをアクセス許可します。',
            blacklist: 'ブラックリスト : チェックしたUser-Agentをアクセス拒否します。'
        });

        updateModeList(ipMode, ipListWrapper, ipDesc, {
            whitelist: 'ホワイトリスト : チェックしたIP範囲をアクセス許可します。',
            blacklist: 'ブラックリスト : チェックしたIP範囲をアクセス拒否します。'
        });

        // Media-specific lists
        const mediaUaMode = mediaUaSelect.val();
        const mediaIpMode = mediaIpSelect.val();

        updateModeList(
            mediaUaMode,
            mediaUaListWrapper,
            mediaUaDesc,
            {
                whitelist: 'ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。',
                blacklist: 'ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。'
            },
            {
                global: 'グローバル設定に従います。',
                allow_all: '全てのUser-Agentを許可します。',
                deny_all: '全てのUser-Agentを拒否します。'
            }
        );

        updateModeList(
            mediaIpMode,
            mediaIpListWrapper,
            mediaIpDesc,
            {
                whitelist: 'ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。',
                blacklist: 'ブラックリスト : チェックしたIP範囲を代替テキスト表示、それ以外はメディア表示します。'
            },
            {
                global: 'グローバル設定に従います。',
                allow_all: '全てのIPを許可します。',
                deny_all: '全てのIPを拒否します。'
            }
        );

        // Markdown UA/IP
        const mdUaMode = mdUaSelect.val();
        const mdIpMode = mdIpSelect.val();

        updateModeList(
            mdUaMode,
            mdUaListWrapper,
            mdUaDesc,
            {
                whitelist: 'ホワイトリスト : チェックしたUser-Agent以外をマークダウンに置換します。',
                blacklist: 'ブラックリスト : チェックしたUser-Agentをマークダウンに置換します。'
            },
            {
                global: '設定しません。',
                allow_all: '全てのUser-Agentを許可します。',
                deny_all: '全てのUser-Agentを拒否します。'
            }
        );

        updateModeList(
            mdIpMode,
            mdIpListWrapper,
            mdIpDesc,
            {
                whitelist: 'ホワイトリスト : チェックしたIP範囲以外をマークダウンに置換します。',
                blacklist: 'ブラックリスト : チェックしたIP範囲をマークダウンに置換します。'
            },
            {
                global: '設定しません。',
                allow_all: '全てのIPを許可します。',
                deny_all: '全てのIPを拒否します。'
            }
        );

        // Markdown replace mode visibility
        const mdReplaceMode = mdReplaceModeSelect.val();
        const isTemplateSelect = (mdReplaceMode === 'template' || mdReplaceMode === 'template_raw');
        const isTemplateMode = (mdReplaceMode === 'template' || mdReplaceMode === 'template_raw' || mdReplaceMode === 'template_random' || mdReplaceMode === 'template_random_raw');
        const isManualMode = (mdReplaceMode === 'manual' || mdReplaceMode === 'manual_raw');

        if (isTemplateSelect) {
            mdTemplateSelectWrapper.slideDown(200);
        } else {
            mdTemplateSelectWrapper.slideUp(200);
        }

        if (mdReplaceMode === 'none') {
            mdContentWrapper.slideUp(200);
        } else {
            mdContentWrapper.slideDown(200);
        }

        updateSlideByValue(uaRedirectMode, $('#ggc-ua-redirect-url'), 'redirect');
        updateSlideByValue(ipRedirectMode, $('#ggc-ip-redirect-url'), 'redirect');
        updateSlideByValue(uaRedirectMode, uaBlockSettings, 'block');
        updateSlideByValue(ipRedirectMode, ipBlockSettings, 'block');

        if (uaRedirectMode.length) {
            var uaVal = uaRedirectMode.val();
            uaPageEvalWrapper.toggle(uaVal !== 'global');
            if (uaVal === 'global' && uaModeSelect.length && uaModeSelect.val() !== 'global') {
                // user has effectively disabled evaluation – reset hidden control
                uaModeSelect.val('global').trigger('change');
            }
        }
        if (ipRedirectMode.length) {
            var ipVal = ipRedirectMode.val();
            ipPageEvalWrapper.toggle(ipVal !== 'global');
            if (ipVal === 'global' && ipModeSelect.length && ipModeSelect.val() !== 'global') {
                ipModeSelect.val('global').trigger('change');
            }
        }

        if (uaModeSelect.length) {
            if (uaModeSelect.val() === 'global') {
                uaBlockSettings.hide();
            }
        }

        if (ipModeSelect.length) {
            if (ipModeSelect.val() === 'global') {
                ipBlockSettings.hide();
            }
        }


        if (uaModeSelect.length && uaRedirectMode.length) {
            if (uaModeSelect.val() === 'global') {
                $('#ggc-ua-redirect-url').hide();
            }
        }

        if (ipModeSelect.length && ipRedirectMode.length) {
            if (ipModeSelect.val() === 'global') {
                $('#ggc-ip-redirect-url').hide();
            }
        }

        const mdMode = mdReplaceModeSelect.val();
        if (mdMode === 'none') {
            mdUaWrapper.hide();
            mdIpWrapper.hide();
        } else {
            mdUaWrapper.show();
            mdIpWrapper.show();
        }

        // markdown global note handling removed per user request

        if (isManualMode) {
            mdTextWrapper.show();
        } else {
            mdTextWrapper.hide();
        }

        if (mediaModeSelect.length) {
            const mediaMode = mediaModeSelect.val();
            const featuredMode = featuredModeSelect.length ? featuredModeSelect.val() : 'normal';

            // determine if post-level controls should be ignored based on global mode
            const globalMediaMode = globalMediaModeHidden.length ? globalMediaModeHidden.val() : 'none';
            const isAllPages = (globalMediaMode === 'all');

            // メディアモードが「設定しない」の場合は全サブ設定を非表示
            if (mediaMode === 'normal') {
                mediaEvalWrapper.slideUp(200);
                featuredAltSection.slideUp(200);
                $('#ggc-featured-mode-wrapper').slideUp(200);
                mediaGlobalNote.hide();
            } else {
                // individual / hide_all: UA/IP評価ラッパーを表示
                mediaEvalWrapper.slideDown(200);
                // アイキャッチ画像の非表示設定を表示
                $('#ggc-featured-mode-wrapper').slideDown(200);
                // アイキャッチモードが alt_replace の場合のみ代替テキスト入力を表示
                if (featuredMode === 'alt_replace') {
                    featuredAltSection.slideDown(200);
                } else {
                    featuredAltSection.slideUp(200);
                }
                mediaGlobalNote.hide();
            }
        }

        updateBlockMessageSection(uaBlockMessageSelect, uaBlockCustom, uaBlockPreview, 'ua');
        updateBlockMessageSection(ipBlockMessageSelect, ipBlockCustom, ipBlockPreview, 'ip');
    }

    function updateBlockMessageSection($select, $customWrapper, $previewWrapper, type) {
        if (!$select.length) return;
        const selectedVal = $select.val();
        if (selectedVal === 'custom') {
            $previewWrapper.hide();
            $customWrapper.show();
            return;
        }

        if (selectedVal) {
            $customWrapper.hide();
            const $opt = $select.find('option:selected');
            const status = $opt.data('status') || '';
            const message = $opt.data('message') || '';
            $previewWrapper.find('.ggc-' + type + '-block-preview-status').text(status ? status : '-');
            $previewWrapper.find('.ggc-' + type + '-block-preview-message').text(message ? message : '（メッセージ未設定）');
            $previewWrapper.show();
        } else {
            $previewWrapper.hide();
            $customWrapper.hide();
        }
    }

    // ----------------------------------------------------------------------
    // 3. イベントハンドラ
    // ----------------------------------------------------------------------

    // モード変更時
    uaModeSelect.on('change', updateVisibility);
    ipModeSelect.on('change', updateVisibility);
    // メディア用モード変更時（個別設定の表示切替）
    if (mediaModeSelect.length) {
        mediaModeSelect.on('change', updateVisibility);
    }
    if (featuredModeSelect.length) {
        featuredModeSelect.on('change', updateVisibility);
    }
    mediaUaSelect.on('change', updateVisibility);
    mediaIpSelect.on('change', updateVisibility);
    mdUaSelect.on('change', updateVisibility);
    mdIpSelect.on('change', updateVisibility);
    mdReplaceModeSelect.on('change', updateVisibility);
    mdTemplateModeSelect.on('change', updateVisibility);
    uaRedirectMode.on('change', updateVisibility);
    ipRedirectMode.on('change', updateVisibility);
    uaBlockMessageSelect.on('change', updateVisibility);
    ipBlockMessageSelect.on('change', updateVisibility);

    // グループヘッダー (アコーディオン開閉)
    $(document).on('click', '.ggc-group-header', function (e) {
        if ($(e.target).hasClass('ggc-toggle-all') || $(e.target).closest('.ggc-toggle-all').length) return;
        if ($(e.target).hasClass('ggc-toggle-all-pattern') || $(e.target).closest('.ggc-toggle-all-pattern').length) return;
        if ($(e.target).hasClass('ggc-toggle-all-ip') || $(e.target).closest('.ggc-toggle-all-ip').length) return;

        const $header = $(this);
        const targetId = $header.data('target');
        const $content = $(targetId);
        const $arrow = $header.find('.ggc-arrow');

        if ($content.length === 0) return;

        // Toggle open/close with display control
        $content.toggleClass('open');
        if ($content.hasClass('open')) {
            $content.slideDown(150);
        } else {
            $content.slideUp(150);
        }
        $arrow.toggleClass('rotated');
    });

    // セクションヘッダー (アコーディオン開閉) - User-Agent定義1 / 定義2
    $(document).on('click', '.ggc-section-header', function (e) {
        if ($(e.target).hasClass('ggc-toggle-section') || $(e.target).closest('.ggc-toggle-section').length) return;

        const $header = $(this);
        const targetId = $header.data('target');
        const $content = $(targetId);
        const $arrow = $header.find('.ggc-arrow');

        if ($content.length === 0) return;

        $content.toggleClass('open');
        if ($content.hasClass('open')) {
            $content.slideDown(200);
        } else {
            $content.slideUp(200);
        }
        $arrow.toggleClass('rotated');
    });

    // カテゴリごとの全選択/全解除 (User-Agentリスト用)
    $(document).on('click', '.ggc-toggle-all', function (e) {
        e.preventDefault();
        // 親要素のモードチェックは不要 (非表示なら操作できないため)

        const $boxes = $(this).closest('.ggc-group-header').next('.ggc-group-content').find('input[type="checkbox"]').not(':disabled');
        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });

    // カテゴリごとの全選択/全解除 (不正UAパターンリスト用)
    $(document).on('click', '.ggc-toggle-all-pattern', function (e) {
        e.preventDefault();

        const $boxes = $(this).closest('.ggc-group-header').next('.ggc-group-content').find('input[type="checkbox"]').not(':disabled');
        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });

    // カテゴリごとの全選択/全解除 (IPリスト用)
    $(document).on('click', '.ggc-toggle-all-ip', function (e) {
        e.preventDefault();

        const $boxes = $(this).closest('.ggc-group-header').next('.ggc-group-content').find('input[type="checkbox"]').not(':disabled');
        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });

    // セクションごとの全選択/全解除 (User-Agent定義1 / 定義2)
    $(document).on('click', '.ggc-toggle-section', function (e) {
        e.preventDefault();
        e.stopPropagation(); // ヘッダーのクリックイベント伝播防止

        const targetId = $(this).data('section');
        const $container = $('#' + targetId);

        if ($container.length === 0) return;

        const $boxes = $container.find('input[type="checkbox"]').not(':disabled');
        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });

    // 初期化
    updateVisibility();

    // Featured image alt text live toggle (Gutenberg)
    function updateFeaturedAltUI(mediaId) {
        const $wrapper = $('#ggc-featured-alt-wrapper');
        if ($wrapper.length === 0) return;

        const $empty = $('#ggc-featured-alt-empty');
        const $fields = $('#ggc-featured-alt-fields');
        const $img = $('#ggc-featured-thumb-img');

        if (!mediaId) {
            $empty.show();
            $fields.hide();
            if ($img.length) {
                $img.attr('src', '').hide();
            }
            return;
        }

        $empty.hide();
        $fields.show();

        if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core')) {
            const media = wp.data.select('core').getMedia(mediaId);
            if (!media) {
                if (wp.data.dispatch && wp.data.dispatch('core') && wp.data.dispatch('core').fetchMedia) {
                    wp.data.dispatch('core').fetchMedia(mediaId);
                }
                return;
            }

            const url = (media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail)
                ? media.media_details.sizes.thumbnail.source_url
                : (media.source_url || '');

            if (url && $img.length) {
                $img.attr('src', url).show();
            }
        }
    }

    if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core/editor')) {
        let lastFeaturedId = null;
        wp.data.subscribe(function () {
            const currentId = wp.data.select('core/editor').getEditedPostAttribute('featured_media');
            if (currentId !== lastFeaturedId) {
                lastFeaturedId = currentId;
                updateFeaturedAltUI(currentId);
            }
        });
        updateFeaturedAltUI(wp.data.select('core/editor').getEditedPostAttribute('featured_media'));
    }

    // Markdown preview (front-like preview)
    $(document).on('click', '#ggc-md-preview-btn', function () {
        const $btn = $(this);
        const previewUrl = $btn.data('preview-url') || '';
        const previewNonce = $btn.data('preview-nonce') || '';

        if (!previewUrl || !previewNonce) {
            alert('プレビューURLの生成に失敗しました。');
            return;
        }

        const $form = $('<form>', {
            method: 'POST',
            action: previewUrl,
            target: '_blank',
            style: 'display:none;'
        });

        const addField = (name, value) => {
            $form.append($('<input>', { type: 'hidden', name, value }));
        };

        addField('ggc_md_preview', '1');
        addField('ggc_md_preview_nonce', previewNonce);
        addField('ggc_md_replace_mode', $('#ggc_md_replace_mode').val() || 'manual');
        addField('ggc_md_replace_text', $('#ggc_md_replace_text').val() || '');
        addField('ggc_md_replace_title', $('#ggc_md_replace_title').val() || '');
        addField('ggc_md_replace_image_id', $('#ggc_md_replace_image_id').val() || '');
        addField('ggc_md_override_global_title', $('#ggc_md_override_global_title').is(':checked') ? '1' : '');
        addField('ggc_md_override_global_image', $('#ggc_md_override_global_image').is(':checked') ? '1' : '');
        addField('ggc_md_template_mode', $('#ggc_md_template_mode').val() || 'select');
        addField('ggc_md_template_key', $('#ggc_md_template_key').val() || '');

        $('body').append($form);
        $form.trigger('submit');
        $form.remove();
    });

    // Markdown featured image selector
    $(document).on('click', '#ggc-md-image-select', function (e) {
        e.preventDefault();

        const frame = wp.media({
            title: 'マークダウン用アイキャッチ画像を選択',
            button: { text: 'この画像を使用' },
            multiple: false
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            if (!attachment || !attachment.id) return;

            $('#ggc_md_replace_image_id').val(attachment.id);
            $('#ggc_md_replace_image_url').val(attachment.url || '');
            $('#ggc-md-image-preview').html('<img src="' + (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + '" style="max-width:120px; max-height:120px; display:block;" />');
            // 画像選択直後に即座にURL欄を無効化
            if (typeof updateMdImageUrlAndSelectBtn === 'function') {
                updateMdImageUrlAndSelectBtn();
            }
        });

        frame.open();
    });

    $(document).on('click', '#ggc-md-image-remove', function (e) {
        e.preventDefault();
        $('#ggc_md_replace_image_id').val('');
        $('#ggc_md_replace_image_url').val('');
        $('#ggc-md-image-preview').html('<span style="color:#999; font-size:12px;">未設定</span>');
    });

    // Media live preview: update when selected media checkboxes change
    function getPostContent() {
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core/editor')) {
            try {
                var content = wp.data.select('core/editor').getEditedPostContent();
                if (typeof content === 'string') return content;
            } catch (e) {
                // ignore
            }
        }
        // TinyMCE
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
            try {
                return tinyMCE.activeEditor.getContent();
            } catch (e) { }
        }
        // Classic textarea
        var $ta = $('#content');
        if ($ta.length) return $ta.val();
        return '';
    }

    // Gutenberg block note: show explanatory message when an image/gallery/cover block is selected
    function showGutenbergBlockNote() {
        // 機能を無効化
    }
    showGutenbergBlockNote();

    // Gutenberg: 代替テキスト設定はメタボックス側に統合

    // Gutenberg: メディア・ギャラリー・カバーブロックのInspectorに「アクセス制御」パネルを追加
    if (typeof wp !== 'undefined' && wp.editPost && wp.data && wp.components && wp.blocks) {
        const { addFilter } = wp.hooks;
        const { InspectorControls } = wp.blockEditor || wp.editor;
        const { PanelBody, CheckboxControl, TextControl, SelectControl } = wp.components;
        const { createHigherOrderComponent } = wp.compose;
        const { createElement, Fragment, useState, useEffect } = wp.element;
        const e = createElement;

        const TARGET_BLOCKS = ['core/image', 'core/gallery', 'core/cover'];

        // 属性登録は jQuery.ready() 外のスコープで既に実施済み。
        // ここでは UI フィルタのみ追加する。

        // UI追加
        const withAccessControlPanel = createHigherOrderComponent((BlockEdit) => (props) => {
            if (!TARGET_BLOCKS.includes(props.name)) return e(BlockEdit, props);
            const { attributes, setAttributes } = props;

            const getMediaModeHelpText = (mode) => {
                if (mode === 'replace') {
                    return 'このブロックをテキスト置換します。テキスト欄を使って置換文字列を入力してください。';
                }
                if (mode === 'hide') {
                    return 'このブロックを非表示にします。';
                }
                // normal or any other value
                return 'このブロックは通常どおり表示されます。';
            };

            const [mediaMode, setMediaMode] = useState($('#ggc_media_mode').val() || 'normal');

            useEffect(() => {
                const $select = $('#ggc_media_mode');
                if ($select.length === 0) return;
                const handler = () => {
                    setMediaMode($select.val() || 'normal');
                };
                $select.on('change', handler);
                return () => $select.off('change', handler);
            }, []);

            // ブロック属性更新時にコンソール出力（デバッグ）
            const handleAltTextChange = function (val) {
                setAttributes({ ggcAltText: val });
            };

            return e(Fragment, null,
                e(BlockEdit, props),
                e(InspectorControls, null,
                    e(PanelBody, { title: 'アクセス制御', initialOpen: true },
                        e(SelectControl, {
                            label: 'ブロックの表示モード',
                            value: attributes.ggcMediaMode || 'normal',
                            options: [
                                { label: '通常表示', value: 'normal' },
                                { label: '非表示', value: 'hide' },
                                { label: 'テキスト置換', value: 'replace' },
                            ],
                            onChange: (val) => {
                                setAttributes({ ggcMediaMode: val });
                                if (val !== 'replace') {
                                    setAttributes({ ggcAltText: '' });
                                }
                            }
                        }),
                        attributes.ggcMediaMode === 'replace' && e(TextControl, {
                            label: 'メディア表示設定',
                            value: attributes.ggcAltText || '',
                            onChange: handleAltTextChange,
                            placeholder: 'このメディア用の代替テキスト',
                            help: getMediaModeHelpText(mediaMode)
                        })
                    )
                )
            );
        }, 'withAccessControlPanel');
        addFilter('editor.BlockEdit', 'ggc/access-control-panel', withAccessControlPanel);

        // ブロック属性の更新を監視（保存確認用）
        if (wp && wp.data && wp.data.subscribe) {
            wp.data.subscribe(function () {
                const blocks = wp.data.select('core/block-editor').getBlocks();
                blocks.forEach(function checkBlock(block) {
                    if (['core/image', 'core/gallery', 'core/cover'].includes(block.name)) {
                    }
                    if (block.innerBlocks && block.innerBlocks.length) {
                        block.innerBlocks.forEach(checkBlock);
                    }
                });
            });
        }

        // ggcAltText / ggcMediaMode はブロック属性として blocks.registerBlockType で正式登録済みのため、
        // Gutenberg がポストコンテンツ（ブロックコメント）からブロックごとに独立して読み込む。
        // ポストメタ（画像IDキー）をエディタに再適用する処理は不要であり、
        // 同一画像を複数配置した場合に全ブロックが同じ値に上書きされる原因となるため削除。

        // 投稿保存時にブロック属性を収集してポストメタに保存
        if (wp && wp.data && wp.data.subscribe) {
            let isSaving = false;
            let ggcAttrsPending = null;
            let ggcModesPending = null;
            let pendingPostId = null;

            wp.data.subscribe(function () {
                try {
                    const editor = wp.data.select('core/editor');
                    if (!editor) return;

                    const currentlySaving = editor.isSavingPost();
                    const autosaving = editor.isAutosavingPost();

                    // 自動保存はスキップ、手動保存のみ
                    if (currentlySaving && !autosaving && !isSaving) {
                        isSaving = true;

                        const postId = editor.getCurrentPostId();
                        const blocks = wp.data.select('core/block-editor').getBlocks();
                        const ggcAttrs = {};
                        const ggcModes = {};

                        function collectAttrs(blocks) {
                            blocks.forEach(function (block) {
                                if (['core/image', 'core/gallery', 'core/cover'].includes(block.name)) {
                                    // 空文字列も含めて送信する必要があるため、hasOwnProperty でチェック
                                    if (block.attributes && block.attributes.hasOwnProperty('ggcAltText')) {
                                        const id = block.attributes.id;
                                        if (id) {
                                            ggcAttrs[id] = block.attributes.ggcAltText;
                                        }
                                    }
                                    if (block.attributes && block.attributes.hasOwnProperty('ggcMediaMode')) {
                                        const id = block.attributes.id;
                                        const mode = block.attributes.ggcMediaMode;
                                        if (id && (mode === 'hide' || mode === 'replace')) {
                                            ggcModes[id] = mode;
                                        }
                                    }
                                }
                                if (block.innerBlocks && block.innerBlocks.length) {
                                    collectAttrs(block.innerBlocks);
                                }
                            });
                        }

                        if (blocks) {
                            collectAttrs(blocks);
                        }

                        // 属性を保存
                        if (Object.keys(ggcAttrs).length > 0 || Object.keys(ggcModes).length > 0) {
                            ggcAttrsPending = ggcAttrs;
                            ggcModesPending = ggcModes;
                            pendingPostId = postId;
                        }
                    } else if (!currentlySaving && isSaving) {
                        // 保存が完了した
                        isSaving = false;

                        // 保存完了後、メタデータを REST API で更新
                        if ((ggcAttrsPending || ggcModesPending) && pendingPostId) {
                            if (typeof ggcAdminMeta !== 'undefined' && ggcAdminMeta.ajax_url && ggcAdminMeta.nonce) {
                                $.post(ggcAdminMeta.ajax_url, {
                                    action: 'ggc_save_block_attrs',
                                    nonce: ggcAdminMeta.nonce,
                                    post_id: pendingPostId,
                                    attrs: JSON.stringify(ggcAttrsPending || {}),
                                    modes: JSON.stringify(ggcModesPending || {})
                                }).done(function (response) {
                                    ggcAttrsPending = null;
                                    ggcModesPending = null;
                                    pendingPostId = null;
                                }).fail(function () { });
                            } else {
                                wp.apiFetch({
                                    path: '/wp/v2/posts/' + pendingPostId,
                                    method: 'POST',
                                    data: {
                                        meta: {
                                            '_ggc_block_attrs': JSON.stringify(ggcAttrsPending || {}),
                                            '_ggc_block_modes': JSON.stringify(ggcModesPending || {})
                                        }
                                    }
                                }).then(function (response) {
                                    ggcAttrsPending = null;
                                    ggcModesPending = null;
                                    pendingPostId = null;
                                }).catch(function () { });
                            }
                        }
                    }
                } catch (e) { }
            });
        }
    }

    // アコーディオンの初期状態 (すべて閉じる or 開く? CSSで制御されているがJSでも補完)
    $('.ggc-group-content').not('.open').hide();

    // メディアプレビュー（別タブ）: 現在の編集内容を送信
    $(document).on('click', '.ggc-alt-preview', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const actionUrl = $btn.attr('href');

        let content = '';
        try {
            if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
                if (wp.blocks && wp.blocks.serialize && wp.data.select('core/block-editor')) {
                    const blocks = wp.data.select('core/block-editor').getBlocks();
                    const withAltText = function (block) {
                        if (!block) return block;
                        if (block.name === 'core/image' && block.attributes) {
                            const id = block.attributes.id;
                            if (id) {
                                const $cb = $('.ggc-selected-media-checkbox[value="' + id + '"]');
                                const $input = $('.ggc-media-alt-text-input[data-media-id="' + id + '"]');
                                if ($cb.length && $cb.prop('checked') && $input.length) {
                                    const val = $input.val();
                                    if (val) block.attributes.ggcAltText = val;
                                }
                            }
                        }
                        if (block.name === 'core/gallery' && block.attributes && Array.isArray(block.attributes.ids)) {
                            const ids = block.attributes.ids;
                            const texts = {};
                            ids.forEach(function (id) {
                                const $cb = $('.ggc-selected-media-checkbox[value="' + id + '"]');
                                const $input = $('.ggc-media-alt-text-input[data-media-id="' + id + '"]');
                                if ($cb.length && $cb.prop('checked') && $input.length) {
                                    const val = $input.val();
                                    if (val) texts[id] = val;
                                }
                            });
                            if (Object.keys(texts).length) {
                                block.attributes.ggcAltText = JSON.stringify(texts);
                            }
                        }
                        if (block.innerBlocks && block.innerBlocks.length) {
                            block.innerBlocks = block.innerBlocks.map(withAltText);
                        }
                        return block;
                    };
                    const patchedBlocks = blocks.map(withAltText);
                    content = wp.blocks.serialize(patchedBlocks);
                } else if (wp.data.select('core/editor')) {
                    const edited = wp.data.select('core/editor').getEditedPostContent();
                    if (typeof edited === 'string') content = edited;
                }
            }
        } catch (e) { }
        if (!content) {
            const $ta = $('#content');
            if ($ta.length) content = $ta.val() || '';
        }

        const selectedMedia = $('.ggc-selected-media-checkbox:checked').map(function () {
            return $(this).val();
        }).get();

        const altTexts = {};
        $('.ggc-media-alt-text-input').each(function () {
            const id = $(this).data('media-id');
            const val = $(this).val();
            if (id !== undefined && val !== undefined && val !== '') {
                altTexts[String(id)] = val;
            }
        });

        const featuredAlt = $('#ggc_featured_image_alt_text').val() || '';
        const mediaMode = $('#ggc_media_mode').val() || 'normal';
        const featuredMode = $('#ggc_featured_mode').val() || 'normal';

        const previewPayload = {
            selected_media: selectedMedia,
            alt_texts: altTexts,
            featured_alt_text: featuredAlt,
            media_mode: mediaMode,
            featured_mode: featuredMode
        };

        const $form = $('<form>', {
            method: 'POST',
            action: actionUrl,
            target: '_blank'
        });
        $form.append($('<input>', { type: 'hidden', name: 'content', value: content }));
        $form.append($('<input>', { type: 'hidden', name: 'ggc_media_preview_payload', value: JSON.stringify(previewPayload) }));
        $('body').append($form);
        $form.trigger('submit');
        $form.remove();
    });

});
