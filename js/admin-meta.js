// custom-crawler-control\js\admin - meta.js

jQuery(document).ready(function ($) {

    // ----------------------------------------------------------------------
    // 変数定義
    // ----------------------------------------------------------------------
    const uaModeSelect = $('#ggc_ua_control_mode');
    const ipModeSelect = $('#ggc_ip_control_mode');
    const uaListWrapper = $('#ggc-ua-list-wrapper');
    const ipListWrapper = $('#ggc-ip-list-wrapper');

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
                uaDesc.text('チェックしたUser-Agentを許可します (ホワイトリスト)。');
            } else {
                uaDesc.text('チェックしたUser-Agentを拒否します (ブラックリスト)。');
            }
        } else {
            uaListWrapper.slideUp(200);
        }

        if (ipMode === 'blacklist' || ipMode === 'whitelist') {
            ipListWrapper.slideDown(200);
            if (ipMode === 'blacklist') {
                ipDesc.text('チェックしたIP範囲を拒否します (ブラックリスト)。');
            } else {
                ipDesc.text('チェックしたIP範囲を許可します (ホワイトリスト)。');
            }
        } else {
            ipListWrapper.slideUp(200);
        }
    }

    // ----------------------------------------------------------------------
    // 3. イベントハンドラ
    // ----------------------------------------------------------------------

    // モード変更時
    uaModeSelect.on('change', updateVisibility);
    ipModeSelect.on('change', updateVisibility);

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

    // アコーディオンの初期状態 (すべて閉じる or 開く? CSSで制御されているがJSでも補完)
    $('.ggc-group-content').hide();
});
