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

    // Profile: open the application / edit form in a SweetAlert2 popup (same pattern as the other sections).
    // The form markup is rendered for the owner on the server inside a <template>; the browser only clones it.
    $(document).on('click', '.nx-vendor-open', function () {
        if (typeof Swal === 'undefined') { return; }

        var html = $('#' + $(this).data('template')).html();
        if (!html) { return; }

        Swal.fire({
            title: $(this).data('title') || '',
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: '600px',
            didOpen: function (popup) {
                $(popup).find('.nx-vendor-form input:not(:disabled), .nx-vendor-form select:not(:disabled)').first().trigger('focus');
            }
        });
    });

    // Send the application / save the details (works on the form inside the popup).
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
                    Swal.fire({
                        icon: 'success',
                        title: res.data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(function () { location.reload(); });
                    return;
                }
                var info = (res && res.data) || {};
                showErrors($form, info.fields);
                message($form, info.message || 'Something went wrong.', false);
                $button.prop('disabled', false);
            })
            .fail(function () {
                message($form, 'Server error. Please try again.', false);
                $button.prop('disabled', false);
            });
    });
})(jQuery);
