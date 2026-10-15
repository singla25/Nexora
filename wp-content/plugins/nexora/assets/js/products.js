/* Nexora My Products: add, edit and delete in a SweetAlert2 popup (same pattern as Business Details). The popup form
   is a server-rendered <template>; the browser sends only the product fields and, when editing, the product id.
   The vendor and owner are decided by the server. Images use the existing WordPress media uploader. */
(function ($) {
    'use strict';

    var cfg = window.nexoraMarketplace || {};
    var FIELDS = ['name', 'description', 'price', 'stock', 'sku', 'category'];

    function clearErrors($form) {
        $form.find('.nx-vendor-field').removeClass('has-error');
        $form.find('.nx-field-error').text('');
        $form.find('.nx-vendor-form-message').remove();
    }

    function showErrors($form, fields) {
        $.each(fields || {}, function (name, text) {
            var $field = $form.find('[name="' + name + '"]').closest('.nx-vendor-field');
            $field.addClass('has-error').find('.nx-field-error').text(text);
        });
        $form.find('.has-error input, .has-error select, .has-error textarea').first().trigger('focus');
    }

    function message($form, text) {
        $('<p>', { 'class': 'nx-vendor-form-message is-error', role: 'status' }).text(text).appendTo($form);
    }

    function showImage($form, id, url) {
        var $preview = $form.find('.nx-product-image-preview').empty();
        $form.find('input[name="image_id"]').val(id || 0);
        if (url) {
            $('<img>', { src: url, alt: '', 'class': 'nx-product-image-thumb' }).appendTo($preview);
        }
    }

    function openPopup(title, product) {
        var html = $('#nx-product-tpl-form').html();
        if (!html || typeof Swal === 'undefined') { return; }

        Swal.fire({
            title: title,
            html: html,
            showConfirmButton: false,
            showCloseButton: true,
            width: '640px',
            didOpen: function (popup) {
                var $form = $(popup).find('.nx-product-form');
                if (product) {
                    $form.find('input[name="product_id"]').val(product.id);
                    $.each(FIELDS, function (i, name) {
                        $form.find('[name="' + name + '"]').val(product[name] === null || product[name] === undefined ? '' : product[name]);
                    });
                    showImage($form, product.image_id, product.image_url);
                }
                $form.find('input[name="name"]').trigger('focus');
            }
        });
    }

    $(document).on('click', '.nx-product-add', function () {
        openPopup($(this).data('title') || 'Add product', null);
    });

    $(document).on('click', '.nx-product-edit', function () {
        var product = $(this).closest('.nx-product-actions').data('product');
        openPopup($(this).data('title') || 'Edit product', product);
    });

    // Image: the existing media uploader (members only see their own uploads).
    $(document).on('click', '.nx-product-image-pick', function (e) {
        e.preventDefault();
        if (typeof wp === 'undefined' || !wp.media) { return; }

        var $form = $(this).closest('.nx-product-form');
        var frame = wp.media({
            title: 'Select image',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var sizes = attachment.sizes || {};
            showImage($form, attachment.id, (sizes.thumbnail && sizes.thumbnail.url) || attachment.url);
        });

        frame.open();
    });

    $(document).on('click', '.nx-product-image-remove', function () {
        showImage($(this).closest('.nx-product-form'), 0, '');
    });

    // Save.
    $(document).on('submit', '.nx-product-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('.nx-vendor-submit');
        var data = { action: $form.data('action'), nonce: cfg.nonce, product_id: $form.find('input[name="product_id"]').val() };

        $.each(FIELDS.concat(['image_id']), function (i, name) {
            data[name] = $form.find('[name="' + name + '"]').val();
        });

        clearErrors($form);
        $button.prop('disabled', true);

        $.post(cfg.ajaxUrl, data)
            .done(function (res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: res.data.message, timer: 1500, showConfirmButton: false })
                        .then(function () { location.reload(); });
                    return;
                }
                var info = (res && res.data) || {};
                showErrors($form, info.fields);
                message($form, info.message || 'Something went wrong.');
                $button.prop('disabled', false);
            })
            .fail(function () {
                message($form, 'Server error. Please try again.');
                $button.prop('disabled', false);
            });
    });

    // Delete (asks first).
    $(document).on('click', '.nx-product-delete', function () {
        var id = $(this).data('id');

        Swal.fire({
            title: 'Delete this product?',
            text: 'It disappears from the marketplace. Past orders keep their own record.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete'
        }).then(function (result) {
            if (!result.isConfirmed) { return; }

            $.post(cfg.ajaxUrl, { action: 'nexora_product_delete', nonce: cfg.nonce, product_id: id })
                .done(function (res) {
                    if (res && res.success) {
                        Swal.fire({ icon: 'success', title: res.data.message, timer: 1200, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    } else {
                        var info = res && res.data;
                        Swal.fire('Error', (info && info.message) || 'Could not delete.', 'error');
                    }
                })
                .fail(function () { Swal.fire('Error', 'Server error', 'error'); });
        });
    });
})(jQuery);
