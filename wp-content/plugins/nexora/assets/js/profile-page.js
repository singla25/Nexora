jQuery(document).ready(function ($) {

    // ===============================
    // TAB SWITCH
    // ===============================
    $('.tab-btn').on('click', function () {

        let tab = $(this).data('tab');

        $('.tab-btn').removeClass('active');
        $(this).addClass('active');

        $('.tab-content').removeClass('active');
        $('#' + tab).addClass('active');
    });


    // ===============================
    // UPDATE IMAGE
    // ===============================
    $(document).on('click', '.upload-btn', function (e) {

        e.preventDefault();

        let container = $(this).closest('.profile-upload-box, td');

        let frame = wp.media({
            title: 'Select or Upload Image',
            button: { text: 'Use this image' },
            multiple: false
        });

        frame.on('select', function () {

            let attachment = frame.state().get('selection').first().toJSON();

            container.find('input[type="hidden"]').val(attachment.id);

            container.find('.profile-preview')
                .attr('src', attachment.url)
                .show();

            container.find('.remove-btn').show();
        });

        frame.open();
    });

    // Remove Image
    $(document).on('click', '.remove-btn', function () {

        let container = $(this).closest('.profile-upload-box, td');

        container.find('input[type="hidden"]').val('');
        container.find('.profile-preview').hide();

        $(this).hide();
    });


    // ===============================
    // UPDATE INFORMATION
    // ===============================
    $(document).on('submit', '.profile-page-form', function (e) {

        e.preventDefault();

        let form = this;
        let formData = new FormData(form);

        formData.append('action', 'profile_update');
        formData.append('nonce', profilePageData.nonce);

        let submitBtn = $(form).find('button[type="submit"]');
        submitBtn.prop('disabled', true);

        Swal.fire({
            title: 'Saving...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        $.ajax({
            url: profilePageData.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,

            success: function (res) {

                if (typeof res === 'string') res = JSON.parse(res);

                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved!',
                        text: 'Profile updated successfully'
                    }).then(() => {
                        setTimeout(() => location.reload(), 500);
                    });
                } else {
                    Swal.fire('Error', res.data || 'Something went wrong', 'error');
                }

                submitBtn.prop('disabled', false);
            },

            error: function () {
                Swal.fire('Error', 'Server error', 'error');
                submitBtn.prop('disabled', false);
            }
        });
    });


    // ===============================
    // ADD NEW CONNECTION
    // ===============================
    $(document).on('click', '.conn-tab[data-type="add"]', function () {

        $.post(profilePageData.ajaxUrl, {
            action: 'get_add_new_users',
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                let html = '';

                res.data.forEach(user => {

                    let profileLink = user.profile_link 
                        ? user.profile_link 
                        : `${profilePageData.homeUrl}/profile-page/${user.username}`;
                    
                    html += `
                        <div class="connection-card">

                            <div class="conn-cover"></div>

                            <div class="conn-avatar">
                                <img src="${user.image}">
                            </div>

                            <div class="conn-body">

                                <a href="${profileLink}" class="conn-username" target="_blank">
                                    ${user.username}
                                </a>

                                <p class="conn-name">${user.name}</p>

                                <button class="connect-btn" data-id="${user.profile_id}">
                                    Connect
                                </button>

                            </div>
                        </div>
                    `;
                });

                Swal.fire({
                    title: 'Add New Connections',
                    html: `<div class="conn-popup-grid">${html}</div>`,
                    width: '700px',
                    showConfirmButton: false
                });
            }
        });
    });

    // SEND REQUEST
    $(document).on('click', '.connect-btn', function () {

        let id = $(this).data('id');
        let btn = $(this);

        $.post(profilePageData.ajaxUrl, {
            action: 'send_connection_request',
            receiver_profile_id: id,
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {
                btn.text('Sent').prop('disabled', true);
            }
        });
    });


    // ===============================
    // REQUESTS
    // ===============================
    $(document).on('click', '.conn-tab[data-type="requests"]', function () {

        $.post(profilePageData.ajaxUrl, {
            action: 'get_requests',
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                let html = '';

                res.data.forEach(user => {

                    let profileLink = user.profile_link 
                        ? user.profile_link 
                        : `${profilePageData.homeUrl}/profile-page/${user.username}`;

                    html += `
                        <div class="connection-card">

                            <div class="conn-cover"></div>

                            <div class="conn-avatar">
                                <img src="${user.image}">
                            </div>

                            <div class="conn-body">

                                <a href="${profileLink}" class="conn-username" target="_blank">
                                    ${user.username}
                                </a>

                                <p class="conn-name">${user.name}</p>

                                <button class="accept-btn" data-id="${user.connection_id}">
                                    Accept
                                </button>

                                <button class="reject-btn" data-id="${user.connection_id}">
                                    Reject
                                </button>

                            </div>
                        </div>
                    `;
                });

                Swal.fire({
                    title: 'Requests',
                    html: `<div class="conn-popup-grid">${html}</div>`,
                    width: '700px',
                    showConfirmButton: false
                });
            }
        });
    });

    // ACCEPT / REJECT
    $(document).on('click', '.accept-btn, .reject-btn', function () {

        let id = $(this).data('id');
        let isAccept = $(this).hasClass('accept-btn');

        let status = isAccept ? 'accepted' : 'rejected';

        $.post(profilePageData.ajaxUrl, {
            action: 'update_connection_status',
            connection_id: id,
            status: status,
            nonce: profilePageData.nonce
        }, function () {

            Swal.fire({
                icon: isAccept ? 'success' : 'error',
                title: isAccept ? 'Accepted!' : 'Rejected!',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        });

    });

    // ===============================
    // REMOVE CONNECTION
    // ===============================
    $(document).on('click', '.remove-connection-btn', function () {

        let id = $(this).data('id');
        let card = $(this).closest('.establish-connection-card');

        Swal.fire({
            title: 'Are you sure?',
            text: 'This connection will be removed',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove it!'
        }).then((result) => {

            if (result.isConfirmed) {

                $.post(profilePageData.ajaxUrl, {
                    action: 'update_connection_status',
                    connection_id: id,
                    status: 'removed', // 🔥 IMPORTANT
                    nonce: profilePageData.nonce
                }, function () {

                    Swal.fire({
                        icon: 'success',
                        title: 'Removed!',
                        timer: 1500,
                        showConfirmButton: false
                    });

                    // Remove from UI
                    card.fadeOut(300, function () {
                        $(this).remove();
                    });
                });
            }
        });

    });

    // ===============================
    // HISTORY
    // ===============================
    $(document).on('click', '.conn-tab[data-type="history"]', function () {

        $.post(profilePageData.ajaxUrl, {
            action: 'get_history',
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                Swal.fire({
                    title: 'Connection History',
                    html: res.data,
                    width: '600px'
                });
            }
        });
    });

    // ===============================
    // VIEW ALL CONNECTIONS
    // ===============================
    $(document).on('click', '[data-type="view-all-conn"]', function () {

        let profileId = $(this).data('profile');

        $.post(profilePageData.ajaxUrl, {
            action: 'view_all_connection',
            profile_id: profileId,
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                Swal.fire({
                    title: 'Connections',
                    html: `<div class="conn-popup-grid">${res.data}</div>`,
                    width: '700px',
                    showConfirmButton: false
                });

            }
        });
    });

    // ===============================
    // VIEW MUTUAL CONNECTIONS
    // ===============================
    $(document).on('click', '[data-type="view-common-conn"]', function () {

        let profileId = $(this).data('profile');

        $.post(profilePageData.ajaxUrl, {
            action: 'view_mutual_connection',
            profile_id: profileId,
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                Swal.fire({
                    title: 'Mutual Connections',
                    html: `<div class="conn-popup-grid">${res.data}</div>`,
                    width: '700px',
                    showConfirmButton: false
                });

            }
        });
    });

    // ===============================
    // CHANGE PASSWORD
    // ===============================
    $(document).on('submit', '#change-password-form', function (e) {

        e.preventDefault();

        let form = $(this);

        let data = form.serialize();

        $.post(profilePageData.ajaxUrl, {
            action: 'change_password',
            nonce: profilePageData.nonce,
            ...Object.fromEntries(new URLSearchParams(data))
        }, function (res) {

            if (res.success) {

                Swal.fire({
                    icon: 'success',
                    text: res.data
                });

                form.trigger('reset');

            } else {

                Swal.fire({
                    icon: 'error',
                    text: res.data
                });

            }
        });
    });

    // Toggle Eye in password
    $(document).on('click', '.toggle-pass', function () {

        let input = $(this).siblings('input');
        let isPassword = input.attr('type') === 'password';

        input.attr('type', isPassword ? 'text' : 'password');

        $(this).html(
            isPassword
                ? `<svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M3 3l18 18" stroke="black" stroke-width="2" stroke-linecap="round"/>
                        <path d="M10.58 10.58a2 2 0 002.83 2.83" stroke="black" stroke-width="2" fill="none"/>
                        <path d="M9.88 5.09A9.77 9.77 0 0112 5c6 0 10 7 10 7a17.57 17.57 0 01-2.17 3.19" stroke="black" stroke-width="2" fill="none"/>
                        <path d="M6.61 6.61A17.58 17.58 0 002 12s4 7 10 7a9.77 9.77 0 004.91-1.34" stroke="black" stroke-width="2" fill="none"/>
                </svg>`
                : `<svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M12 5C6 5 2 12 2 12s4 7 10 7 10-7 10-7-4-7-10-7z" fill="none" stroke="black" stroke-width="2"/>
                        <circle cx="12" cy="12" r="3" fill="none" stroke="black" stroke-width="2"/>
                </svg>`
        );

    });

    // ===============================
    // VIEW NOTIFICATION
    // ===============================
    $(document).on('click', '.notification-view', function (e) {

        e.stopPropagation(); // prevent parent click

        let btn = $(this);
        let item = btn.closest('.notification-item');
        let id = btn.data('id');
        let type = btn.data('type');

        let message = item.find('.noti-content').text();

        // SHOW POPUP (COMMON FOR BOTH)
        Swal.fire({
            title: 'Notification',
            text: message,
            icon: 'info'
        });

        // ===============================
        // ONLY FOR RECEIVER → AJAX
        // ===============================
        if (type === 'view-receive-noti') {

            $.post(profilePageData.ajaxUrl, {
                action: 'mark_notification_read',
                id: id,
                nonce: profilePageData.nonce
            }, function (res) {

                if (res.success) {

                    // UI update
                    item.removeClass('unread').addClass('read');

                    // badge remove (simple)
                    $('.noti-badge').fadeOut();
                }
            });
        }
    });

    // ===============================
    // VIEW POST CONTENT
    // ===============================
    $(document).on('click', '.view-post', function (e) {

        e.stopPropagation(); // prevent parent click

        let card = $(this).closest('.content-card');

        let title = card.data('title');
        let content = card.data('content');
        let image = card.data('image');
        let username = card.data('username');
        let fullname = card.data('fullname');
        let date = card.data('date');
        let profile = card.data('profile');

        Swal.fire({
            html: `
                <div class="modern-post">

                    <img src="${image}" class="modern-post-img">

                    <div class="modern-post-body">

                        <!-- TITLE -->
                        <h2 class="modern-post-title">${title}</h2>

                        <!-- DESCRIPTION -->
                        <p class="modern-post-desc">${content}</p>

                        <!-- USER ROW -->
                        <div class="modern-post-meta">

                            <a href="${profile}" target="_blank" class="meta-username">
                                ${username}
                            </a>

                            <span class="meta-fullname">
                                ${fullname}
                            </span>

                            <span class="meta-date">
                                ${date}
                            </span>

                        </div>

                    </div>

                </div>
            `,
            width: '550px',
            showConfirmButton: false,
            customClass: {
                popup: 'modern-popup'
            }
        });
    });

    // ===============================
    // ADD NEW CONTENT
    // ===============================
    $(document).on('click', '.content-tab[data-type="add"]', function () {

        let html = `
            <form id="add-content-form" class="content-form">

                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" placeholder="Enter Title" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="Write something..." required></textarea>
                </div>

                <div class="form-group">
                    <label>Upload Image</label>

                    <div class="upload-box">
                        <input type="hidden" name="image" id="content_image">

                        <img id="content_preview">

                        <button type="button" class="upload-content-image">Choose Image</button>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Post Content</button>

            </form>
        `;

        Swal.fire({
            title: 'Add New Content',
            html: html,
            showConfirmButton: false,
            width: '500px',
            showCancelButton: true,
            cancelButtonText: 'Cancel',
            showConfirmButton: false,
            allowOutsideClick: false,   // ❌ click outside disabled
            allowEscapeKey: false,      // ❌ ESC disabled
            customClass: {
                popup: 'content-popup'
            }
        });
    });

    $(document).on('click', '.upload-content-image', function (e) {

        e.preventDefault();

        let frame = wp.media({
            title: 'Select Image',
            button: { text: 'Use this image' },
            multiple: false
        });

        frame.on('select', function () {

            let attachment = frame.state().get('selection').first().toJSON();

            $('#content_image').val(attachment.id);
            $('#content_preview').attr('src', attachment.url).show();
        });

        frame.open();
    });

    $(document).on('submit', '#add-content-form', function (e) {

        e.preventDefault();

        let formData = new FormData(this);

        formData.append('action', 'save_user_content');
        formData.append('nonce', profilePageData.nonce);

        $.ajax({
            url: profilePageData.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,

            success: function (res) {

                if (res.success) {

                    Swal.fire({
                        icon: 'success',
                        title: 'Posted!',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload(); // refresh feed
                    });

                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });

    // ===============================
    // CONTENT HISTORY
    // ===============================
    $(document).on('click', '.content-tab[data-type="history"]', function () {

        $.post(profilePageData.ajaxUrl, {
            action: 'get_user_content_history',
            nonce: profilePageData.nonce
        }, function (res) {

            if (res.success) {

                Swal.fire({
                    title: 'Your Content History',
                    html: res.data,
                    width: '700px'
                });
            }
        });
    });

    $(document).on('click', '.view-content-btn', function () {

        let title = $(this).data('title');
        let content = $(this).data('content');
        let image = $(this).data('image');

        Swal.fire({
            title: title,
            html: `
                <img src="${image}" style="width:100%; margin-bottom:10px; border-radius:10px;">
                <p style="text-align:left;">${content}</p>
            `,
            width: '600px'
        });
    });

    
});