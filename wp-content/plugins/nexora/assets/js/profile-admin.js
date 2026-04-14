jQuery(document).ready(function ($) {

    // 🔥 Upload Image (COMMON)
    $(document).on('click', '.upload-btn', function (e) {

        e.preventDefault();

        let button = $(this);

        // ✅ Works for BOTH: CPT + Settings
        let container = button.closest('.profile-upload-box, td');

        let frame = wp.media({
            title: 'Select or Upload Image',
            button: { text: 'Use this image' },
            multiple: false
        });

        frame.on('select', function () {

            let attachment = frame.state().get('selection').first().toJSON();

            // ✅ Set hidden input
            container.find('input[type="hidden"]').val(attachment.id);

            // ✅ Handle image preview (works both cases)
            let img = container.find('.profile-preview, img');

            if (img.length) {
                img.attr('src', attachment.url).show();
            } else {
                container.prepend(
                    '<img src="' + attachment.url + '" class="profile-preview" style="max-width:120px; display:block; margin-bottom:10px;">'
                );
            }

            // ✅ Show remove button
            container.find('.remove-btn').show();
        });

        frame.open();
    });


    // ❌ Remove Image (COMMON)
    $(document).on('click', '.remove-btn', function (e) {

        e.preventDefault();

        let container = $(this).closest('.profile-upload-box, td');

        container.find('input[type="hidden"]').val('');

        // Hide image
        container.find('.profile-preview, img').attr('src', '').hide();

        $(this).hide();
    });


    // Open Chat (In common Js chat.js)
    // $(document).on('click', '.nexora-open-chat', function () {

    //     let threadId = $(this).data('thread');

    //     // Call global function (from chat.js)
    //     if (typeof openChat === 'function') {
    //         openChat(threadId);
    //     }
    // });
});