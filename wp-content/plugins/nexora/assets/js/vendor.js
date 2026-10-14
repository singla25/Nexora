/* Nexora vendor forms. Sign-up: shows the business fields for "Seller". Profile: "Become a seller" and edit details.
   The browser only sends business fields; it never sends a role or a status (the server decides both). */
(function ($) {
    'use strict';

    var cfg = window.nexoraVendor || {};

    function clearErrors($form) {
        $form.find('.nx-vendor-field').removeClass('has-error');
        $form.find('.nx-field-error').text('');
        $form.find('.nx-vendor-form-message').remove();
    }

    function showErrors($form, fields) {
        $.each(fields || {}, function (name, message) {
            var $field = $form.find('[name="' + name + '"]').closest('.nx-vendor-field');
            $field.addClass('has-error');
            $field.find('.nx-field-error').text(message);
            $field.find('input, select').attr('aria-invalid', 'true');
        });
        var $first = $form.find('.has-error input, .has-error select').first();
        if ($first.length) { $first.trigger('focus'); }
    }

    function message($form, text, ok) {
        $('<p>', { 'class': 'nx-vendor-form-message ' + (ok ? 'is-ok' : 'is-error'), role: 'status' })
            .text(text)
            .appendTo($form);
    }

    // Sign-up: Customer / Seller.
    $(document).on('change', 'input[name="intent"]', function () {
        var seller = $('input[name="intent"]:checked').val() === 'seller';
        var $box = $('.nx-seller-fields');
        $box.prop('hidden', !seller);
        $box.find('input, select').prop('required', seller);
    });

    // Profile: reveal the application form.
    $(document).on('click', '.nx-vendor-open', function () {
        var $form = $('#' + $(this).attr('aria-controls'));
        var open = $form.prop('hidden');
        $form.prop('hidden', !open);
        $(this).attr('aria-expanded', open ? 'true' : 'false');
        if (open) { $form.find('input, select').filter(':visible').first().trigger('focus'); }
    });

    // Profile: send the application / save details.
    $(document).on('submit', '.nx-vendor-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('.nx-vendor-submit');
        var data = { action: $form.data('action'), nonce: cfg.nonce };

        $form.find('input, select').each(function () {
            if (this.name && !this.disabled) { data[this.name] = $(this).val(); }
        });

        clearErrors($form);
        $button.prop('disabled', true);

        $.post(cfg.ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    message($form, res.data.message, true);
                    if (data.action === 'nexora_vendor_apply') {
                        window.setTimeout(function () { window.location.reload(); }, 1200);
                    }
                    return;
                }
                var info = (res && res.data) || {};
                showErrors($form, info.fields);
                message($form, info.message || 'Something went wrong.', false);
            })
            .fail(function () {
                message($form, 'Server error. Please try again.', false);
            })
            .always(function () {
                $button.prop('disabled', false);
            });
    });
})(jQuery);
