/* Nexora Marketplace tab: search, category filter and pagination. The cards come back as HTML that the server
   has already escaped; this script only places it on the page. */
(function ($) {
    'use strict';

    var cfg = window.nexoraMarketplace || {};
    var loaded = false;
    var busy = false;

    function $tab() { return $('#marketplace'); }

    function load(page) {
        var $t = $tab();
        if (!$t.length || busy) { return; }

        busy = true;
        var $results = $t.find('.nx-market-results').attr('aria-busy', 'true');

        $.post(cfg.ajaxUrl, {
            action: 'nexora_marketplace_list',
            nonce: cfg.nonce,
            search: $t.find('input[name="search"]').val() || '',
            category: $t.find('select[name="category"]').val() || '',
            page: page || 1
        }).done(function (res) {
            if (res && res.success) {
                $results.html(res.data.html);
            } else {
                var info = res && res.data;
                var text = (info && info.message) || (typeof info === 'string' ? info : 'Could not load products.');
                $results.empty().append($('<p>', { 'class': 'nx-market-empty' }).text(text));
            }
        }).fail(function () {
            $results.empty().append($('<p>', { 'class': 'nx-market-empty' }).text('Server error. Please try again.'));
        }).always(function () {
            busy = false;
            $results.attr('aria-busy', 'false');
            loaded = true;
        });
    }

    // First time the tab is opened (or restored as the active tab on load).
    $(document).on('click', '.tab-btn[data-tab="marketplace"]', function () {
        if (!loaded) { load(1); }
    });

    $(function () {
        if ($('#marketplace.active').length) { load(1); }
    });

    $(document).on('submit', '#marketplace .nx-market-filter', function (e) {
        e.preventDefault();
        load(1);
    });

    $(document).on('change', '#marketplace select[name="category"]', function () {
        load(1);
    });

    $(document).on('click', '#marketplace .nx-market-page', function () {
        if (this.disabled) { return; }
        load($(this).data('page'));
        $tab()[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
})(jQuery);
