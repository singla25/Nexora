/* Nexora imports (CSV and API): a SweetAlert2 popup built from a server-rendered <template>. Step 1 "Preview" is a dry
   run that saves nothing and returns a report plus a one-time token; step 2 "Import" applies exactly that preview.
   The report is built from text nodes only. The browser sends the file or the token, never a vendor, owner or status. */
(function ($) {
    'use strict';

    var cfg = window.nexoraMarketplace || {};
    var token = '';

    function popupNode(templateId) {
        var tpl = document.getElementById(templateId);
        var box = document.createElement('div');
        if (tpl && tpl.content) { box.appendChild(tpl.content.cloneNode(true)); }
        return box;
    }

    function open(templateId, title) {
        if (typeof Swal === 'undefined') { return; }
        token = '';
        Swal.fire({
            title: title,
            html: popupNode(templateId),
            showConfirmButton: false,
            showCloseButton: true,
            width: '760px'
        });
    }

    $(document).on('click', '.nx-import-csv, .nx-import-api', function () {
        open($(this).data('template'), $(this).data('title') || '');
    });

    var ACTIONS = {
        csv: { preview: 'nexora_csv_preview', apply: 'nexora_csv_import' },
        api: { preview: 'nexora_api_preview', apply: 'nexora_api_import' }
    };

    function message($popup, text, ok) {
        var $r = $popup.find('.nx-import-report').empty();
        $('<p>', { 'class': ok ? 'nx-import-ok' : 'nx-import-bad', role: 'status' }).text(text).appendTo($r);
    }

    function renderReport($popup, d) {
        var $r = $popup.find('.nx-import-report').empty();
        var s = d.summary || {};

        if (d.fetch_error) {
            $('<p>', { 'class': 'nx-import-bad', role: 'alert' }).text(d.fetch_error).appendTo($r);
        }

        $('<p>', { 'class': 'nx-import-summary' }).text(
            (s.create || 0) + ' to create, ' + (s.update || 0) + ' to update, ' + (s.unchanged || 0) + ' unchanged, ' + (s.error || 0) + ' with errors'
        ).appendTo($r);

        var $table = $('<table>', { 'class': 'nx-import-table' });
        var $head = $('<tr>');
        $.each(['Line', 'SKU', 'Result', 'Problem'], function (i, h) { $('<th>', { scope: 'col' }).text(h).appendTo($head); });
        $('<thead>').append($head).appendTo($table);

        var $body = $('<tbody>').appendTo($table);
        $.each(d.rows || [], function (i, row) {
            var problems = [];
            $.each(row.errors || {}, function (field, text) { problems.push(field + ': ' + text); });
            var $tr = $('<tr>', { 'class': 'nx-import-row-' + row.action });
            $('<td>').text(row.line ? row.line : row.ref).appendTo($tr);
            $('<td>').text(row.sku || '').appendTo($tr);
            $('<td>').text(row.action).appendTo($tr);
            $('<td>').text(problems.join('; ')).appendTo($tr);
            $tr.appendTo($body);
        });

        if ((d.rows || []).length) { $table.appendTo($('<div>', { 'class': 'nx-import-scroll' }).appendTo($r)); }
        if (d.truncated) { $('<p>').text('Only the first rows are shown.').appendTo($r); }
    }

    $(document).on('click', '.nx-import-preview', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var kind = $popup.data('kind');
        var $confirm = $popup.find('.nx-import-confirm').prop('hidden', true);
        var data;

        if (kind === 'csv') {
            var file = $popup.find('input[type="file"]')[0];
            if (!file || !file.files.length) { message($popup, 'Choose a .csv file first.', false); return; }
            data = new FormData();
            data.append('file', file.files[0]);
        } else {
            data = new FormData();
        }

        data.append('action', ACTIONS[kind].preview);
        data.append('nonce', cfg.nonce);

        var $button = $(this).prop('disabled', true);
        message($popup, 'Checking...', true);
        token = '';

        $.ajax({ url: cfg.ajaxUrl, type: 'POST', data: data, processData: false, contentType: false })
            .done(function (res) {
                if (!res || !res.success) {
                    var info = res && res.data;
                    message($popup, (info && info.message) || 'The file could not be checked.', false);
                    return;
                }
                renderReport($popup, res.data);
                token = res.data.token || '';
                $confirm.prop('hidden', !token);
            })
            .fail(function () { message($popup, 'Server error. Please try again.', false); })
            .always(function () { $button.prop('disabled', false); });
    });

    $(document).on('click', '.nx-import-confirm', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var kind = $popup.data('kind');
        var $button = $(this).prop('disabled', true);

        $.post(cfg.ajaxUrl, { action: ACTIONS[kind].apply, nonce: cfg.nonce, token: token })
            .done(function (res) {
                if (res && res.success) {
                    var s = res.data.summary || {};
                    Swal.fire({
                        icon: 'success',
                        title: (s.created || 0) + ' created, ' + (s.updated || 0) + ' updated, ' + (s.skipped || 0) + ' skipped',
                        timer: 2200,
                        showConfirmButton: false
                    }).then(function () { location.reload(); });
                    return;
                }
                var info = res && res.data;
                message($popup, (info && info.message) || 'The import failed.', false);
                $button.prop('disabled', false);
            })
            .fail(function () { message($popup, 'Server error. Please try again.', false); $button.prop('disabled', false); });
    });
})(jQuery);
