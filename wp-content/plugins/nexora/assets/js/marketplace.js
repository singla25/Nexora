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

    // Add to cart. Only the product id and a quantity of 1 are sent; the server reads the price, name and stock itself.
    $(document).on('click', '#marketplace .nx-add-to-cart', function () {
        var $btn = $(this);
        var $msg = $btn.closest('.nx-product-body').find('.nx-cart-msg').removeClass('is-error').empty();
        if ($btn.prop('disabled')) { return; }
        $btn.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: 'nexora_add_to_cart',
            nonce: cfg.nonce,
            product_id: $btn.data('product'),
            quantity: 1
        }).done(function (res) {
            if (res && res.success) {
                $msg.text(res.data.message + ' ');
                $('<a>', { href: res.data.cart_url, text: 'View cart' }).appendTo($msg);
                return;
            }
            var info = (res && res.data) || {};
            if (info.login_url) {
                window.location.href = info.login_url;
                return;
            }
            $msg.addClass('is-error').text(info.message || 'Could not add this product.');
        }).fail(function () {
            $msg.addClass('is-error').text('Server error. Please try again.');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
