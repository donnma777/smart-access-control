// custom-crawler-control\js\admin - meta.js

jQuery(document).ready(function ($) {

    // ----------------------------------------------------------------------
    // 変数定義
    // ----------------------------------------------------------------------
    const masterToggle = $('#ggc_control_active_field');
    const allPanels = $('#ggc-mode-selector-panel, #ggc-crawler-list-panel, #ggc-ip-ranges-panel, #ggc-page-browser-patterns-panel');
    const currentModeStatus = $('#ggc-current-mode-status');


    // ----------------------------------------------------------------------
    // 1. アコーディオンアニメーション
    // ----------------------------------------------------------------------
    function toggleSlide($el) {
        if ($el.length === 0) return;
        const el = $el[0];
        const isHidden = !$el.hasClass('open');

        if (isHidden) {
            // 開く: scrollHeight を使って最大高さを計算
            el.style.maxHeight = el.scrollHeight + 'px';
            $el.addClass('open');
        } else {
            // 閉じる
            el.style.maxHeight = '0px';
            $el.removeClass('open');
        }
    }

    // ----------------------------------------------------------------------
    // 2. 初期化
    // ----------------------------------------------------------------------
    function initializeControls() {
        const isEnabled = masterToggle.prop('checked');

        allPanels.css({
            'opacity': isEnabled ? '1' : '0.45',
            'pointer-events': isEnabled ? 'auto' : 'none'
        });

        // OFF の時は、UI上操作不可とする（disabled は値が POST されないため使わない）

        updateStatusText();
        updateModeDescriptions();
    }

    // ----------------------------------------------------------------------
    // 3. ステータス表示の更新
    // ----------------------------------------------------------------------
    function updateStatusText() {
        const isEnabled = masterToggle.prop('checked');
        const mode = $('input[name="ggc_control_mode_field"]:checked').val();

        if (isEnabled) {
            if (mode === 'blacklist') {
                currentModeStatus.html('現在のモード: <strong style="color:#0073aa;">個別拒否 (ブラックリスト)</strong>');
            } else {
                currentModeStatus.html('現在のモード: <strong style="color:red;">ALL拒否 (ホワイトリスト)</strong>');
            }
        } else {
            currentModeStatus.html('現在のモード: <strong style="color:green;">ALL許可 (制御無効)</strong>');
        }
    }

    // IP制御の説明文をモードに合わせて更新
    function updateModeDescriptions() {
        const mode = $('input[name="ggc_control_mode_field"]:checked').val();

        // UA説明
        const $uaDesc = $('#ggc-ua-control-description');
        if ($uaDesc.length) {
            $uaDesc.text(
                mode === 'blacklist'
                    ? 'チェックしたUser-Agentからのアクセスを拒否します。'
                    : 'チェックしたUser-Agentからのアクセスのみを許可します。'
            ).css('color', mode === 'blacklist' ? '#0073aa' : 'red');
        }

        // IP説明
        const $ipDesc = $('#ggc-ip-control-description');
        if ($ipDesc.length) {
            $ipDesc.text(
                mode === 'blacklist'
                    ? 'チェックしたIPからのアクセスを拒拒否します。'
                    : 'チェックしたIPからのアクセスを許可します。'
            ).css('color', mode === 'blacklist' ? '#0073aa' : 'red');
        }

        // 不正UA説明
        const $badUaDesc = $('#ggc-page-browser-patterns-description');
        if ($badUaDesc.length) {
            $badUaDesc.html(
                mode === 'blacklist'
                    ? 'チェックした不正UAパターンに合致するアクセスを拒否します。'
                    : 'チェックした不正UAパターンに合致するアクセスも許可します。'
            ).css('color', mode === 'blacklist' ? '#0073aa' : 'red');
        }
    }



    // ----------------------------------------------------------------------
    // 4. イベントハンドラ
    // ----------------------------------------------------------------------

    // マスターON/OFFスイッチ
    masterToggle.on('change', function () {
        initializeControls();
    });

    // 制御モード (ラジオボタン)
    $('input[name="ggc_control_mode_field"]').on('change', function () {
        updateStatusText();
        updateModeDescriptions();
    });

    // グループヘッダー (アコーディオン開閉)
    $(document).on('click', '.ggc-group-header', function (e) {
        if ($(e.target).hasClass('ggc-toggle-all') || $(e.target).closest('.ggc-toggle-all').length) return;
        if ($(e.target).hasClass('ggc-toggle-all-pattern') || $(e.target).closest('.ggc-toggle-all-pattern').length) return;

        const $header = $(this);
        const targetId = $header.data('target');
        const $content = $(targetId);
        const $arrow = $header.find('.ggc-arrow');

        if ($content.length === 0) return;
        toggleSlide($content);
        $arrow.toggleClass('rotated');
    });

    // カテゴリごとの全選択/全解除 (User-Agentリスト用)
    $(document).on('click', '.ggc-toggle-all', function (e) {
        e.preventDefault();

        if (!masterToggle.prop('checked')) return;

        const $boxes = $(this).closest('.ggc-group-header').next('.ggc-group-content').find('.ggc-selected-crawler-checkbox').not(':disabled');

        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });

    // カテゴリごとの全選択/全解除 (不正UAパターンリスト用)
    $(document).on('click', '.ggc-toggle-all-pattern', function (e) {
        e.preventDefault();

        if (!masterToggle.prop('checked')) return;

        // 対象のチェックボックスをDOMトラバーサルで特定
        const $boxes = $(this).closest('.ggc-group-header').next('.ggc-group-content').find('.ggc-page-pattern').not(':disabled');

        const allChecked = $boxes.length > 0 && $boxes.length === $boxes.filter(':checked').length;

        $boxes.prop('checked', !allChecked);
        $boxes.trigger('change');
    });


    // 初期化を最初に実行
    initializeControls();

});