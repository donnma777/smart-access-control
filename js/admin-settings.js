// custom-crawler-control\js\admin-settings.js

jQuery(document).ready(function ($) {

    // ----------------------------------------------------------------------
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

    $(document).on('click', '.ggc-remove-ip', function () {
        $(this).closest('tr').remove();
    });

    // 非同期で IP 更新を実行し、結果をモーダルで表示
    $(document).on('click', '.ggc-run-ip-update-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const ajaxUrl = (typeof ggcSettings !== 'undefined' && ggcSettings.ajax_url) ? ggcSettings.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
        const nonce = (typeof ggcSettings !== 'undefined' && ggcSettings.run_update_nonce) ? ggcSettings.run_update_nonce : $btn.data('nonce');

        // Disable all update buttons
        $('.ggc-run-ip-update-btn').prop('disabled', true).text('更新中...');

        // show loading modal
        showLoadingModal();

        $.post(ajaxUrl, { action: 'ggc_run_ip_update', nonce: nonce }, function (resp) {
            $('.ggc-run-ip-update-btn').prop('disabled', false).text('今すぐ IP 更新を強制実行する');
            $('.ggc-ip-update-modal').remove();
            if (resp && resp.success) {
                try {
                    showIpUpdateModal(resp.data.result);
                } catch (err) {
                    alert('更新は完了しましたが、結果の表示に失敗しました。ブラウザのコンソールを確認してください。');
                }
            } else {
                const msg = (resp && resp.data && resp.data.message) ? resp.data.message : '更新に失敗しました';
                showIpUpdateModal({ time: Math.floor(Date.now() / 1000), google_count: 0, openai_count: 0, details: null });
                alert(msg);
            }
        }).fail(function (jqxhr, textStatus, errorThrown) {
            $('.ggc-run-ip-update-btn').prop('disabled', false).text('今すぐ IP 更新を強制実行する');
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
        const $overlay = $('<div class="ggc-ip-update-modal" style="position:fixed;left:0;top:0;right:0;bottom:0;z-index:99999;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;"></div>');
        const $box = $('<div style="background:#fff;padding:20px;border-radius:6px;max-width:720px;width:90%;max-height:80%;overflow:auto;"></div>');
        const $close = $('<button class="button" style="float:right;margin-left:10px;">閉じる</button>');
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
                $box.append('<h4 style="margin-top:12px;">フェッチ詳細</h4>');
                const $tbl = $('<table style="width:100%;border-collapse:collapse;margin-top:6px;"></table>');
                $tbl.append('<thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #eee;">ソース</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eee;">URL</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eee;">HTTP</th><th style="text-align:left;padding:6px;border-bottom:1px solid #eee;">エラー</th></tr></thead>');
                const $tb = $('<tbody></tbody>');
                const displayed_keys = {};

                function rowFor(key, detail_obj) {
                    if (!detail_obj || typeof detail_obj.url === 'undefined') return;

                    const url = detail_obj.url || '（不明）';
                    const status = (typeof detail_obj.status !== 'undefined' && detail_obj.status !== null) ? detail_obj.status : 'N/A';
                    const err = detail_obj.error || '';
                    const $row = $('<tr>');
                    $row.append($('<td>').css({ padding: '6px', borderBottom: '1px solid #f6f6f6' }).text(key));
                    $row.append($('<td>').css({ padding: '6px', borderBottom: '1px solid #f6f6f6', wordBreak: 'break-all' }).text(url));
                    $row.append($('<td>').css({ padding: '6px', borderBottom: '1px solid #f6f6f6' }).text(status));
                    $row.append($('<td>').css({ padding: '6px', borderBottom: '1px solid #f6f6f6' }).text(err));
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
        const $overlay = $('<div class="ggc-ip-update-modal" style="position:fixed;left:0;top:0;right:0;bottom:0;z-index:99999;background:rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;"></div>');
        const $box = $('<div style="background:#fff;padding:20px;border-radius:6px;max-width:420px;width:80%;text-align:center;"></div>');
        $box.append('<p style="margin:24px 0;"><strong>更新中...</strong><br/>しばらくお待ちください。</p>');
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

});
