/* Nexora My Orders and Sales tabs. The cards come back as HTML the server has already escaped; this script only places it, builds the
   pager and the totals from text nodes, and opens one order in a popup. It sends an action, the nonce, a page number or an order id:
   never a vendor, a user, a price or an amount. The server decides who is asking and what they may see. */
(function ($) {
    'use strict';

    var cfg = window.nexoraMarketplace || {};
    var loaded = { 'my-orders': false, 'sales': false };
    var busy = false;

    function pager($tab, page, pages, kind) {
        var $p = $tab.find('.nx-orders-pager').empty();
        if (pages < 2) { return; }
        $('<button>', { type: 'button', 'class': 'nx-orders-page', 'data-kind': kind, 'data-page': page - 1, disabled: page <= 1 }).text('Previous').appendTo($p);
        $('<span>', { 'class': 'nx-orders-pageinfo' }).text(' ' + page + ' / ' + pages + ' ').appendTo($p);
        $('<button>', { type: 'button', 'class': 'nx-orders-page', 'data-kind': kind, 'data-page': page + 1, disabled: page >= pages }).text('Next').appendTo($p);
    }

    function message($area, text) {
        $area.empty().append($('<p>', { 'class': 'nx-orders-empty' }).text(text));
    }

    function totals($tab, rows) {
        var $dl = $tab.find('.nx-sales-totals').empty();
        $.each(rows || [], function (i, row) {
            $('<dt>').text(row.label).appendTo($dl);
            $('<dd>').text(row.value).appendTo($dl);
        });
    }

    function load(kind, page) {
        var $tab = $('#' + kind);
        if (!$tab.length || busy) { return; }

        var action = kind === 'sales' ? 'nexora_sales' : 'nexora_my_orders';
        var $area = $tab.find(kind === 'sales' ? '.nx-sales-results' : '.nx-orders-results').attr('aria-busy', 'true');
        busy = true;

        $.post(cfg.ajaxUrl, { action: action, nonce: cfg.nonce, page: page || 1 })
            .done(function (res) {
                if (res && res.success) {
                    $area.html(res.data.html);
                    pager($tab, res.data.page, res.data.pages, kind);
                    if (kind === 'sales') { totals($tab, res.data.totals); }
                    loaded[kind] = true;
                    return;
                }
                var info = res && res.data;
                message($area, (info && info.message) || 'Could not load your orders.');
            })
            .fail(function () { message($area, 'Server error. Please try again.'); })
            .always(function () { busy = false; $area.attr('aria-busy', 'false'); });
    }

    // The first time a tab is opened (or when it is the tab restored on load).
    $(document).on('click', '.tab-btn[data-tab="my-orders"], .tab-btn[data-tab="sales"]', function () {
        var kind = $(this).data('tab');
        if (!loaded[kind]) { load(kind, 1); }
    });

    $(function () {
        $.each(['my-orders', 'sales'], function (i, kind) {
            if ($('#' + kind + '.active').length) { load(kind, 1); }
        });
    });

    $(document).on('click', '.nx-orders-page', function () {
        if (this.disabled) { return; }
        load($(this).data('kind'), $(this).data('page'));
    });

    // One order in a popup (Sales only). The HTML is the server's, already escaped.
    $(document).on('click', '#sales .nx-sale-view', function () {
        var $btn = $(this).prop('disabled', true);

        $.post(cfg.ajaxUrl, { action: 'nexora_sale_order', nonce: cfg.nonce, order_id: $btn.data('order') })
            .done(function (res) {
                if (res && res.success && typeof Swal !== 'undefined') {
                    var box = document.createElement('div');
                    $(box).html(res.data.html);
                    Swal.fire({ html: box, showConfirmButton: false, showCloseButton: true, width: '640px' });
                    return;
                }
                var info = res && res.data;
                window.alert((info && info.message) || 'You are not allowed to do that.');
            })
            .fail(function () { window.alert('Server error. Please try again.'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    // The seller marks their own lines of an order as shipped. Only the order id and the nonce are sent; the server decides everything else
    // and answers with the order view (already escaped), which replaces the open popup.
    $(document).on('click', '.nx-ship', function () {
        var $btn = $(this).prop('disabled', true);

        $.post(cfg.ajaxUrl, { action: 'nexora_mark_shipped', nonce: cfg.nonce, order_id: $btn.data('order') })
            .done(function (res) {
                if (res && res.success) {
                    $btn.closest('.nx-sale-detail').replaceWith($(document.createElement('div')).html(res.data.html).contents());
                    loaded.sales = false;
                    load('sales', 1);
                    return;
                }
                var info = res && res.data;
                window.alert((info && info.message) || 'You are not allowed to do that.');
                $btn.prop('disabled', false);
            })
            .fail(function () { window.alert('Server error. Please try again.'); $btn.prop('disabled', false); });
    });
})(jQuery);
