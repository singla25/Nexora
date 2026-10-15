/* Nexora imports (CSV and API): a SweetAlert2 popup built from a server-rendered <template>. Step 1 "Preview" is a dry
   run that saves nothing and returns a report plus a one-time token; step 2 "Import" applies exactly that preview.
   The report is built from text nodes only. The browser sends the file, the chosen source (and, for "My own API", the
   address and key the vendor typed) or the token. Never a vendor, an owner or a status: the server decides those. Keys
   are sent once and never shown back. */
(function ($) {
    'use strict';

    var cfg = window.nexoraMarketplace || {};
    var token = '';
    var ACTIONS = {
        csv: { preview: 'nexora_csv_preview', apply: 'nexora_csv_import' },
        api: { preview: 'nexora_api_preview', apply: 'nexora_api_import' }
    };

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
            width: '780px',
            didOpen: function (popup) { syncSource($(popup).find('.nx-import-popup')); }
        });
    }

    $(document).on('click', '.nx-import-csv, .nx-import-api', function () {
        open($(this).data('template'), $(this).data('title') || '');
    });

    function message($popup, text, ok) {
        var $r = $popup.find('.nx-import-report').empty();
        $('<p>', { 'class': ok ? 'nx-import-ok' : 'nx-import-bad', role: 'status' }).text(text).appendTo($r);
    }

    function row(cells, cls) {
        var $tr = $('<tr>', { 'class': cls || '' });
        $.each(cells, function (i, text) { $('<td>').text(text).appendTo($tr); });
        return $tr;
    }

    function table(headers) {
        var $table = $('<table>', { 'class': 'nx-import-table' });
        var $head = $('<tr>');
        $.each(headers, function (i, h) { $('<th>', { scope: 'col' }).text(h).appendTo($head); });
        $('<thead>').append($head).appendTo($table);
        return $table;
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

        var $table = table(['Line', 'SKU', 'Result', 'Problem']);
        var $body = $('<tbody>').appendTo($table);
        $.each(d.rows || [], function (i, r) {
            var problems = [];
            $.each(r.errors || {}, function (field, text) { problems.push(field + ': ' + text); });
            row([r.line ? r.line : r.ref, r.sku || '', r.action, problems.join('; ')], 'nx-import-row-' + r.action).appendTo($body);
        });

        if ((d.rows || []).length) { $table.appendTo($('<div>', { 'class': 'nx-import-scroll' }).appendTo($r)); }
        if (d.truncated) { $('<p>').text('Only the first rows are shown.').appendTo($r); }
    }

    function renderTest($popup, d) {
        var $r = $popup.find('.nx-import-report').empty();

        if (d.fetch_error) {
            $('<p>', { 'class': 'nx-import-bad', role: 'alert' }).text(d.fetch_error).appendTo($r);
            return;
        }

        $('<p>', { 'class': (d.problems || []).length ? 'nx-import-bad' : 'nx-import-ok', role: 'status' }).text(
            (d.checked || 0) + ' item(s) checked: ' + (d.valid || 0) + ' ready to import' + ((d.problems || []).length ? ', problems below.' : ', no problems found.')
        ).appendTo($r);

        if ((d.problems || []).length) {
            var $ul = $('<ul>', { 'class': 'nx-import-errors' }).appendTo($r);
            $.each(d.problems, function (i, p) { $('<li>').text(p.message).appendTo($ul); });
            if (d.more) { $('<p>').text('...and ' + d.more + ' more.').appendTo($r); }
        }
    }

    // ---- API popup: the chosen source and what "My own API" needs ----

    function sourceFields($popup) {
        var source = $popup.find('.nx-api-source').val() || '';
        var data = { source: source };

        // Only a typed ("My own API") source carries an address and key; a platform source or a saved connection never does.
        if (source === 'adhoc') {
            data.url = $popup.find('.nx-api-url').val();
            data.auth_type = $popup.find('.nx-api-auth').val();
            data.auth_header = $popup.find('.nx-api-header').val();
            data.secret = $popup.find('.nx-api-key').val();
        }
        return data;
    }

    function syncAuth($popup) {
        var type = $popup.find('.nx-api-auth').val();
        $popup.find('.nx-api-header-field').prop('hidden', type !== 'header');
        $popup.find('.nx-api-key-field').prop('hidden', type === 'none');
    }

    function syncSource($popup) {
        if ($popup.data('kind') !== 'api') { return; }
        var source = $popup.find('.nx-api-source').val();
        var $own = $popup.find('.nx-api-own');
        var editing = $popup.find('.nx-api-conn-id').val() !== '';
        $own.prop('hidden', !(source === 'adhoc' || editing));
        $popup.find('.nx-api-test').prop('hidden', !(source === 'adhoc' || (source || '').indexOf('conn:') === 0));
        $popup.find('.nx-import-confirm').prop('hidden', true);
        syncAuth($popup);
        token = '';
    }

    $(document).on('change', '.nx-api-source', function () {
        var $popup = $(this).closest('.nx-import-popup');
        if ($(this).val() === 'adhoc') { $popup.find('.nx-api-conn-id').val(''); clearOwn($popup); }
        syncSource($popup);
    });
    $(document).on('change', '.nx-api-auth', function () { syncAuth($(this).closest('.nx-import-popup')); });

    function clearOwn($popup) {
        $popup.find('.nx-api-name, .nx-api-url, .nx-api-header, .nx-api-key').val('');
        $popup.find('.nx-api-auth').val('none');
        $popup.find('.nx-api-key-note').text('');
        $popup.find('.nx-field-error').text('');
        syncAuth($popup);
    }

    function fieldErrors($popup, fields) {
        $popup.find('.nx-api-own .nx-field-error').text('');
        $.each(fields || {}, function (name, text) { $popup.find('.nx-field-error[data-field="' + name + '"]').text(text); });
    }

    // Saved connections: edit, delete (two clicks), save.
    $(document).on('click', '.nx-api-edit', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var $li = $(this).closest('li');
        $popup.find('.nx-api-conn-id').val($li.data('id'));
        $popup.find('.nx-api-source').val('conn:' + $li.data('id'));
        $popup.find('.nx-api-name').val($li.data('name'));
        $popup.find('.nx-api-url').val($li.data('url'));
        $popup.find('.nx-api-auth').val($li.data('auth'));
        $popup.find('.nx-api-header').val($li.data('header'));
        $popup.find('.nx-api-key').val('');
        $popup.find('.nx-api-key-note').text(String($li.data('key')) === '1' ? 'A key is saved. Leave empty to keep it.' : '');
        syncSource($popup);
        $popup.find('.nx-api-own').prop('hidden', false);
        syncAuth($popup);
    });

    $(document).on('click', '.nx-api-delete', function () {
        var $btn = $(this);
        var $popup = $btn.closest('.nx-import-popup');

        if (!$btn.data('armed')) {
            $btn.data('armed', true).text('Click again to delete');
            window.setTimeout(function () { $btn.data('armed', false).text('Delete'); }, 4000);
            return;
        }

        $btn.prop('disabled', true);
        $.post(cfg.ajaxUrl, { action: 'nexora_api_connection_delete', nonce: cfg.nonce, connection_id: $btn.closest('li').data('id') })
            .done(function (res) {
                if (res && res.success) { renderConnections($popup, res.data.connections || []); message($popup, res.data.message, true); return; }
                message($popup, (res && res.data && res.data.message) || 'Could not delete.', false);
                $btn.prop('disabled', false);
            })
            .fail(function () { message($popup, 'Server error. Please try again.', false); $btn.prop('disabled', false); });
    });

    $(document).on('click', '.nx-api-save', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var $btn = $(this).prop('disabled', true);
        var data = {
            action: 'nexora_api_connection_save', nonce: cfg.nonce,
            connection_id: $popup.find('.nx-api-conn-id').val(),
            name: $popup.find('.nx-api-name').val(), url: $popup.find('.nx-api-url').val(),
            auth_type: $popup.find('.nx-api-auth').val(), auth_header: $popup.find('.nx-api-header').val(), secret: $popup.find('.nx-api-key').val()
        };
        fieldErrors($popup, {});

        $.post(cfg.ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    renderConnections($popup, res.data.connections || []);
                    $popup.find('.nx-api-conn-id').val('');
                    $popup.find('.nx-api-source').val('conn:' + res.data.id);
                    clearOwn($popup);
                    syncSource($popup);
                    message($popup, res.data.message, true);
                    return;
                }
                var info = (res && res.data) || {};
                fieldErrors($popup, info.fields);
                message($popup, info.message || 'Could not save.', false);
            })
            .fail(function () { message($popup, 'Server error. Please try again.', false); })
            .always(function () { $btn.prop('disabled', false); });
    });

    function renderConnections($popup, list) {
        var $group = $popup.find('.nx-api-conn-group').empty();
        var $ul = $popup.find('.nx-api-list').empty();

        $.each(list, function (i, c) {
            $('<option>', { value: 'conn:' + c.id }).text(c.name).appendTo($group);
            var $li = $('<li>').attr({ 'data-id': c.id, 'data-name': c.name, 'data-url': c.url, 'data-auth': c.auth_type, 'data-header': c.auth_header, 'data-key': c.has_secret ? '1' : '0' });
            $li.data({ id: c.id, name: c.name, url: c.url, auth: c.auth_type, header: c.auth_header, key: c.has_secret ? '1' : '0' });
            $('<span>', { 'class': 'nx-api-list-name' }).text(c.name).appendTo($li);
            $('<span>', { 'class': 'nx-api-list-url' }).text(c.url).appendTo($li);
            $('<button>', { type: 'button', 'class': 'nx-api-edit' }).text('Edit').appendTo($li);
            $('<button>', { type: 'button', 'class': 'nx-api-delete' }).text('Delete').appendTo($li);
            $li.appendTo($ul);
        });

        $popup.find('.nx-api-empty').prop('hidden', list.length > 0);
        if (!$popup.find('.nx-api-source').val()) { $popup.find('.nx-api-source').prop('selectedIndex', 0); }
    }

    $(document).on('click', '.nx-api-test', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var $btn = $(this).prop('disabled', true);
        message($popup, 'Testing...', true);

        $.post(cfg.ajaxUrl, $.extend({ action: 'nexora_api_test', nonce: cfg.nonce }, sourceFields($popup)))
            .done(function (res) {
                if (res && res.success) { renderTest($popup, res.data); return; }
                var info = res && res.data;
                message($popup, (info && info.message) || 'The test could not run.', false);
            })
            .fail(function () { message($popup, 'Server error. Please try again.', false); })
            .always(function () { $btn.prop('disabled', false); });
    });

    // ---- Preview and import (both kinds) ----

    $(document).on('click', '.nx-import-preview', function () {
        var $popup = $(this).closest('.nx-import-popup');
        var kind = $popup.data('kind');
        var $confirm = $popup.find('.nx-import-confirm').prop('hidden', true);
        var data = new FormData();

        if (kind === 'csv') {
            var file = $popup.find('input[type="file"]')[0];
            if (!file || !file.files.length) { message($popup, 'Choose a .csv file first.', false); return; }
            data.append('file', file.files[0]);
        } else {
            $.each(sourceFields($popup), function (k, v) { data.append(k, v); });
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
                    message($popup, (info && info.message) || 'The check could not run.', false);
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
