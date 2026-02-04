// custom-crawler-control\js\admin - meta.js

jQuery(document).ready(function ($) {

    // ----------------------------------------------------------------------
    // 変数定義
    // ----------------------------------------------------------------------
    const uaModeSelect = $('#ggc_ua_control_mode');
    const ipModeSelect = $('#ggc_ip_control_mode');
    const uaListWrapper = $('#ggc-ua-list-wrapper');
    const ipListWrapper = $('#ggc-ip-list-wrapper');
    const mediaUaSelect = $('#ggc_media_ua_action');
    const mediaIpSelect = $('#ggc_media_ip_action');
    const mediaUaListWrapper = $('#ggc-media-ua-list-wrapper');
    const mediaIpListWrapper = $('#ggc-media-ip-list-wrapper');
    const mediaUaDesc = $('#ggc-media-ua-description');
    const mediaIpDesc = $('#ggc-media-ip-description');

    // ----------------------------------------------------------------------
    // 1. アコーディオンアニメーション
    // ----------------------------------------------------------------------
    function toggleSlide($el) {
        if ($el.length === 0) return;
        const el = $el[0];
        const isHidden = !$el.hasClass('open');

        if (isHidden) {
            // 開く: scrollHeight を使って最大高さを計算 (auto height)
            el.style.maxHeight = 'none';
            $el.addClass('open');
        } else {
            // 閉じる
            el.style.maxHeight = '0px';
            $el.removeClass('open');
        }
    }

    // ----------------------------------------------------------------------
    // 2. 表示制御
    // ----------------------------------------------------------------------
    function updateVisibility() {
        const uaMode = uaModeSelect.val();
        const ipMode = ipModeSelect.val();
        const uaDesc = $('#ggc-ua-description');
        const ipDesc = $('#ggc-ip-description');

        if (uaMode === 'blacklist' || uaMode === 'whitelist') {
            uaListWrapper.slideDown(200);
            if (uaMode === 'whitelist') {
                uaDesc.text('ホワイトリスト : チェックしたUser-Agentをアクセス許可します。');
            } else {
                uaDesc.text('ブラックリスト : チェックしたUser-Agentをアクセス拒否します。');
            }
        } else {
            uaListWrapper.slideUp(200);
        }

        if (ipMode === 'blacklist' || ipMode === 'whitelist') {
            ipListWrapper.slideDown(200);
            if (ipMode === 'blacklist') {
                ipDesc.text('ブラックリスト : チェックしたIP範囲をアクセス拒否します。');
            } else {
                ipDesc.text('ホワイトリスト : チェックしたIP範囲をアクセス許可します。');
            }
        } else {
            ipListWrapper.slideUp(200);
        }

        // Media-specific lists
        const mediaUaMode = mediaUaSelect.val();
        const mediaIpMode = mediaIpSelect.val();

        if (mediaUaMode === 'blacklist' || mediaUaMode === 'whitelist') {
            mediaUaListWrapper.slideDown(200);
            if (mediaUaMode === 'whitelist') {
                mediaUaDesc.text('ホワイトリスト : チェックしたUser-Agentはメディア表示、それ以外は代替テキスト表示します。');
            } else {
                mediaUaDesc.text('ブラックリスト : チェックしたUser-Agentは代替テキスト表示、それ以外はメディア表示します。');
            }
        } else {
            mediaUaListWrapper.slideUp(200);
            if (mediaUaMode === 'global') {
                mediaUaDesc.text('グローバル設定に従います。');
            } else if (mediaUaMode === 'allow_all') {
                mediaUaDesc.text('全てのUser-Agentを許可します。');
            } else if (mediaUaMode === 'deny_all') {
                mediaUaDesc.text('全てのUser-Agentを拒否します。');
            } else {
                mediaUaDesc.text('');
            }
        }

        if (mediaIpMode === 'blacklist' || mediaIpMode === 'whitelist') {
            mediaIpListWrapper.slideDown(200);
            if (mediaIpMode === 'blacklist') {
                mediaIpDesc.text('ブラックリスト : チェックしたIP範囲を代替テキスト表示、それ以外はメディア表示します。');
            } else {
                mediaIpDesc.text('ホワイトリスト : チェックしたIP範囲はメディア表示、それ以外は代替テキスト表示します。');
            }
        } else {
            mediaIpListWrapper.slideUp(200);
            if (mediaIpMode === 'global') {
                mediaIpDesc.text('グローバル設定に従います。');
            } else if (mediaIpMode === 'allow_all') {
                mediaIpDesc.text('全てのIPを許可します。');
            } else if (mediaIpMode === 'deny_all') {
                mediaIpDesc.text('全てのIPを拒否します。');
            } else {
                mediaIpDesc.text('');
            }
        }
    }

    // ----------------------------------------------------------------------
    // 3. イベントハンドラ
    // ----------------------------------------------------------------------

    // モード変更時
    uaModeSelect.on('change', updateVisibility);
    ipModeSelect.on('change', updateVisibility);
    // メディア用モード変更時（個別設定の表示切替）
    mediaUaSelect.on('change', updateVisibility);
    mediaIpSelect.on('change', updateVisibility);

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

        // Simple toggle class for CSS-based or JS-based handling
        $content.toggleClass('open');
        if ($content.hasClass('open')) {
            $content.css('display', 'block');
        } else {
            $content.css('display', 'none');
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

    // Gutenberg: 投稿インスペクターに「評価に従って代替テキストに変更」チェックボックスを追加
    if (typeof wp !== 'undefined' && wp.plugins && wp.editPost && wp.data && wp.components) {
        const { registerPlugin } = wp.plugins;
        const { PluginDocumentSettingPanel } = wp.editPost;
        const { CheckboxControl } = wp.components;
        const { useSelect, useDispatch } = wp.data;
        const { createElement } = wp.element;
        const e = createElement;

        const GgcMediaAltReplacePanel = () => {
            // post meta の値を取得
            const meta = useSelect(select => select('core/editor').getEditedPostAttribute('meta') || {}, []);
            const checked = !!meta._ggc_media_alt_replace;

            const { editPost } = useDispatch('core/editor');

            return e(PluginDocumentSettingPanel, {
                name: 'ggc-media-alt-replace',
                title: 'メディア評価: 代替テキストに変更',
                className: 'ggc-media-alt-replace-panel'
            },
                e(CheckboxControl, {
                    label: '評価に従って代替テキストに変更（チェックしたメディア・アイキャッチが対象）',
                    checked: checked,
                    onChange: function (val) {
                        editPost({ meta: Object.assign({}, meta, { _ggc_media_alt_replace: val ? 1 : 0 }) });
                    }
                })
                // ここに既存の代替テキスト欄が続く想定
            );
        };

        registerPlugin('ggc-media-alt-replace-panel', {
            render: GgcMediaAltReplacePanel,
            icon: null,
        });
    }

    // Gutenberg: メディア・ギャラリー・カバーブロックのInspectorに「アクセス制御」パネルを追加
    if (typeof wp !== 'undefined' && wp.editPost && wp.data && wp.components && wp.blocks) {
        const { addFilter } = wp.hooks;
        const { InspectorControls } = wp.blockEditor || wp.editor;
        const { PanelBody, CheckboxControl, TextControl } = wp.components;
        const { createHigherOrderComponent } = wp.compose;
        const { createElement, Fragment } = wp.element;
        const e = createElement;

        const TARGET_BLOCKS = ['core/image', 'core/gallery', 'core/cover'];

        // 属性追加
        function addAccessControlAttributes(settings, name) {
            if (TARGET_BLOCKS.includes(name)) {
                settings.attributes = Object.assign({}, settings.attributes, {
                    ggcAltReplace: { type: 'boolean', default: false },
                    ggcAltText: { type: 'string', default: '' },
                });
            }
            return settings;
        }
        addFilter('blocks.registerBlockType', 'ggc/access-control-attrs', addAccessControlAttributes);

        // 属性が確実にブロックコメントに保存されるようにする
        // ※Gutenbergは登録された属性を自動的にブロックコメントに含めるため、
        //   このフィルタは属性が登録されていることを確認するためのログのみ
        function addAccessControlAttributesToOutput(content, block) {
            if (!TARGET_BLOCKS.includes(block.name)) return content;
            return content;
        }
        addFilter('blocks.getSaveContent.extraProps', 'ggc/access-control-output', addAccessControlAttributesToOutput);

        // UI追加
        const withAccessControlPanel = createHigherOrderComponent((BlockEdit) => (props) => {
            if (!TARGET_BLOCKS.includes(props.name)) return e(BlockEdit, props);
            const { attributes, setAttributes } = props;

            // ブロック属性更新時にコンソール出力（デバッグ）
            const handleAltTextChange = function (val) {
                setAttributes({ ggcAltText: val });
            };

            return e(Fragment, null,
                e(BlockEdit, props),
                e(InspectorControls, null,
                    e(PanelBody, { title: 'アクセス制御', initialOpen: true },
                        e(TextControl, {
                            label: '代替テキスト',
                            value: attributes.ggcAltText || '',
                            onChange: handleAltTextChange,
                            placeholder: 'このメディア用の代替テキスト',
                            help: '空欄の場合はメディアを表示します。入力されている場合は評価に従ってテキストを表示します。'
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

        // 保存済みの代替テキストを読み込み、エディタに反映（初期化タイミングのズレに対応）
        function loadSavedAltTextsWithRetry() {
            let attempts = 0;
            const maxAttempts = 20;

            const timer = setInterval(function () {
                attempts++;

                const editorStore = wp && wp.data ? wp.data.select('core/editor') : null;
                const currentPostId = editorStore ? editorStore.getCurrentPostId() : null;
                const ajaxUrl = (typeof ggcAdminMeta !== 'undefined' && ggcAdminMeta.ajax_url) ? ggcAdminMeta.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : null);
                const nonce = (typeof ggcAdminMeta !== 'undefined' && ggcAdminMeta.nonce) ? ggcAdminMeta.nonce : null;

                if (!ajaxUrl || !nonce || !currentPostId) {
                    if (attempts >= maxAttempts) {
                        clearInterval(timer);
                    }
                    return;
                }

                clearInterval(timer);
                $.post(ajaxUrl, {
                    action: 'ggc_get_block_attrs',
                    nonce: nonce,
                    post_id: currentPostId
                }).done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.attrs) {
                        return;
                    }

                    const savedAttrs = response.data.attrs;
                    let applied = false;
                    let applyCount = 0;

                    function applyAttrsToBlocks(blocks) {
                        blocks.forEach(function (block) {
                            if (['core/image', 'core/gallery', 'core/cover'].includes(block.name)) {
                                const id = block.attributes ? block.attributes.id : null;
                                if (id && savedAttrs[id]) {
                                    wp.data.dispatch('core/block-editor').updateBlockAttributes(block.clientId, {
                                        ggcAltText: savedAttrs[id]
                                    });
                                    applyCount++;
                                }
                            }
                            if (block.innerBlocks && block.innerBlocks.length) {
                                applyAttrsToBlocks(block.innerBlocks);
                            }
                        });
                    }

                    // wp.data が ready になるまで待機
                    const readyCheck = setInterval(function () {
                        const blocks = wp.data.select('core/block-editor').getBlocks();
                        if (blocks && blocks.length > 0 && !applied) {
                            clearInterval(readyCheck);
                            applyAttrsToBlocks(blocks);
                            applied = true;
                        }
                    }, 100);

                    // タイムアウト（5秒）
                    setTimeout(function () {
                        clearInterval(readyCheck);
                    }, 5000);

                }).fail(function () { });
            }, 500);
        }

        loadSavedAltTextsWithRetry();

        // 投稿保存時にブロック属性を収集してポストメタに保存
        if (wp && wp.data && wp.data.subscribe) {
            let isSaving = false;
            let ggcAttrsPending = null;
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
                        if (Object.keys(ggcAttrs).length > 0) {
                            ggcAttrsPending = ggcAttrs;
                            pendingPostId = postId;
                        }
                    } else if (!currentlySaving && isSaving) {
                        // 保存が完了した
                        isSaving = false;

                        // 保存完了後、メタデータを REST API で更新
                        if (ggcAttrsPending && pendingPostId) {
                            if (typeof ggcAdminMeta !== 'undefined' && ggcAdminMeta.ajax_url && ggcAdminMeta.nonce) {
                                $.post(ggcAdminMeta.ajax_url, {
                                    action: 'ggc_save_block_attrs',
                                    nonce: ggcAdminMeta.nonce,
                                    post_id: pendingPostId,
                                    attrs: JSON.stringify(ggcAttrsPending)
                                }).done(function (response) {
                                    ggcAttrsPending = null;
                                    pendingPostId = null;
                                }).fail(function () { });
                            } else {
                                wp.apiFetch({
                                    path: '/wp/v2/posts/' + pendingPostId,
                                    method: 'POST',
                                    data: {
                                        meta: {
                                            '_ggc_block_attrs': JSON.stringify(ggcAttrsPending)
                                        }
                                    }
                                }).then(function (response) {
                                    ggcAttrsPending = null;
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
    $('.ggc-group-content').hide();

    // 代替テキスト置換プレビュー（別タブ）: 現在の編集内容を送信
    $(document).on('click', '.ggc-alt-preview', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const postId = $btn.data('post-id');
        const nonce = $btn.data('preview-nonce');
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

        const $form = $('<form>', {
            method: 'POST',
            action: actionUrl,
            target: '_blank'
        });
        $form.append($('<input>', { type: 'hidden', name: 'post_id', value: postId }));
        $form.append($('<input>', { type: 'hidden', name: 'ggc_preview_nonce', value: nonce }));
        $form.append($('<input>', { type: 'hidden', name: 'content', value: content }));
        $('body').append($form);
        $form.trigger('submit');
        $form.remove();
    });

});
