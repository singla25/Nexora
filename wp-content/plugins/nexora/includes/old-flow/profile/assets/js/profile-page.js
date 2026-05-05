jQuery(document).ready(function ($) {

    // ===============================
    // TAB SWITCH
    // ===============================
    let savedTab = localStorage.getItem('activeTab');

    // Only owner should restore tab
    if (savedTab && profilePageData.roleType === 'owner') {

        $('.tab-btn').removeClass('active');
        $('.tab-btn[data-tab="' + savedTab + '"]').addClass('active');

        $('.tab-content').removeClass('active');
        $('#' + savedTab).addClass('active');
    }

    $('.tab-btn').on('click', function () {

        let tab = $(this).data('tab');

        // SAVE ACTIVE TAB
        localStorage.setItem('activeTab', tab);

        $('.tab-btn').removeClass('active');
        $(this).addClass('active');

        $('.tab-content').removeClass('active');
        $('#' + tab).addClass('active');
    });

    // ===============================
    // UPDATE INFORMATION
    // ===============================
    let data = profilePageData.userData;

    console.log(data)

    $(document).on('click', '.user-edit-info', function () {

        let type = $(this).data('type');
        console.log(type);
        let html = '';

        // PERSONAL
        if (type === 'personal-info') {
            html = `
                <form class="info-form grid-form" data-type="personal-info">

                    <div class="form-group">
                        <label>User Name</label>
                        <input type="text" value="${data.user_name}" disabled>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" value="${data.email}" disabled>
                    </div>

                    <div class="form-group">
                        <label>First Name</label>
                        <input name="first_name" value="${data.first_name || ''}">
                    </div>

                    <div class="form-group">
                        <label>Last Name</label>
                        <input name="last_name" value="${data.last_name || ''}">
                    </div>

                    <div class="form-group">
                        <label>Phone</label>
                        <input name="phone" value="${data.phone || ''}">
                    </div>

                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">Select</option>
                            <option value="male" ${data.gender==='male'?'selected':''}>Male</option>
                            <option value="female" ${data.gender==='female'?'selected':''}>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Birthdate</label>
                        <input type="date" name="birthdate" value="${data.birthdate || ''}">
                    </div>

                    <div class="form-group">
                        <label>LinkedIn</label>
                        <input name="linkedin_id" value="${data.linkedin_id || ''}">
                    </div>

                    <div class="form-group full">
                        <label>Bio</label>
                        <textarea name="bio">${data.bio || ''}</textarea>
                    </div>

                    <button class="form-submit">Save</button>
                </form>
            `;
        }

        // ADDRESS
        if (type === 'address-info') {
            html = `
                <form class="info-form grid-form" data-type="address-info">

                    <div class="form-section">
                        <h4>Permanent Address</h4>

                        <div class="form-group full">
                            <input name="perm_address" value="${data.perm_address || ''}" placeholder="Address">
                        </div>

                        <div class="form-group"><input name="perm_city" value="${data.perm_city || ''}" placeholder="City"></div>
                        <div class="form-group"><input name="perm_state" value="${data.perm_state || ''}" placeholder="State"></div>
                        <div class="form-group"><input name="perm_pincode" value="${data.perm_pincode || ''}" placeholder="Pincode"></div>
                    </div>

                    <div class="form-section">
                        <h4>Correspondence Address</h4>

                        <div class="form-group full">
                            <input name="corr_address" value="${data.corr_address || ''}" placeholder="Address">
                        </div>

                        <div class="form-group"><input name="corr_city" value="${data.corr_city || ''}" placeholder="City"></div>
                        <div class="form-group"><input name="corr_state" value="${data.corr_state || ''}" placeholder="State"></div>
                        <div class="form-group"><input name="corr_pincode" value="${data.corr_pincode || ''}" placeholder="Pincode"></div>
                    </div>

                    <button class="form-submit">Save</button>
                </form>
            `;
        }

        // WORK
        if (type === 'work-info') {
            html = `
                <form class="info-form grid-form" data-type="work-info">

                    <div class="form-group"><input name="company_name" value="${data.company_name || ''}" placeholder="Company"></div>
                    <div class="form-group"><input name="designation" value="${data.designation || ''}" placeholder="Designation"></div>
                    <div class="form-group"><input name="company_email" value="${data.company_email || ''}" placeholder="Email"></div>
                    <div class="form-group"><input name="company_phone" value="${data.company_phone || ''}" placeholder="Phone"></div>

                    <div class="form-group full">
                        <textarea name="company_address" placeholder="Address">${data.company_address || ''}</textarea>
                    </div>

                    <button class="form-submit">Save</button>
                </form>
            `;
        }

        // DOCUMENTS
        if (type === 'docs-info') {

            html = `
                <form class="info-form docs-form" data-type="docs-info">

                    <div class="docs-grid">

                        ${['profile_image','cover_image','aadhaar_card','driving_license','company_id_card'].map(key => `
                            
                            <div class="doc-upload-card">

                                <div class="doc-image-wrapper">

                                    ${
                                        data[key] 
                                        ? `<img src="${data[key]}" class="doc-preview">`
                                        : `<div class="doc-placeholder">No Image</div>`
                                    }

                                    <div class="doc-overlay">
                                        <button type="button" class="upload-btn">Upload</button>
                                        ${
                                            data[key] 
                                            ? `<button type="button" class="remove-btn">Remove</button>`
                                            : ''
                                        }
                                    </div>

                                </div>

                                <span class="doc-label">
                                    ${key.replaceAll('_',' ').toUpperCase()}
                                </span>

                                <!-- ⚠️ IMPORTANT: hidden should store ID, not URL -->
                                <input type="hidden" name="${key}" value="${data[key + '_id'] || ''}">

                            </div>

                        `).join('')}

                    </div>

                    <button class="form-submit">Save</button>
                </form>
            `;
        }

        // CHANGE PASSWORD
        if (type === 'security-info') {
            html = `
                <form class="info-form" data-type="security-info">

                    <div class="form-group">
                        <input type="password" name="current_password" class="pass-field" placeholder="Current Password">
                    </div>

                    <div class="form-group">
                        <input type="password" name="new_password" class="pass-field" placeholder="New Password">
                    </div>

                    <div class="form-group">
                        <input type="password" name="confirm_password" class="pass-field" placeholder="Confirm Password">
                    </div>

                    <!-- TOGGLE SWITCH -->
                    <div class="show-password-wrapper">
                        <label class="switch">
                            <input type="checkbox" id="toggle_all_passwords">
                            <span class="slider"></span>
                        </label>
                        <span class="switch-label">Show Password</span>
                    </div>

                    <button class="form-submit">Change Password</button>
                </form>
            `;
        }

        Swal.fire({
            title: 'Update Your Information',
            html: html,
            showConfirmButton: false,
            width: '500px'
        });
    });

    // UPLOAD IMAGE
    $(document).on('click', '.upload-btn', function (e) {

        e.preventDefault();

        let container = $(this).closest('.doc-upload-card');

        let frame = wp.media({
            title: 'Select Image',
            button: { text: 'Use this image' },
            multiple: false
        });

        frame.on('select', function () {

            let attachment = frame.state().get('selection').first().toJSON();

            // ✅ use SAME container (no re-query)
            container.find('input[type="hidden"]').val(attachment.id);

            container.find('.doc-image-wrapper').html(`
                <img src="${attachment.url}" class="doc-preview">
                <div class="doc-overlay">
                    <button type="button" class="upload-btn">Upload</button>
                    <button type="button" class="remove-btn">Remove</button>
                </div>
            `);
        });

        frame.open();
    });

    // Remove Image
    $(document).on('click', '.remove-btn', function () {

        let card = $(this).closest('.doc-upload-card');
        let input = card.find('input[type="hidden"]');
        let wrapper = card.find('.doc-image-wrapper');

        // ✅ get existing ID (optional debug)
        let oldId = input.val();
        console.log("Removing ID:", oldId);

        // ✅ clear value (this is actual remove signal)
        input.val('');

        // ✅ update UI
        wrapper.html(`
            <div class="doc-placeholder">No Image</div>
            <div class="doc-overlay">
                <button type="button" class="upload-btn">Upload</button>
            </div>
        `);
    });

    // Toggle button in Security Tab
    $(document).on('change', '#toggle_all_passwords', function () {

        let type = $(this).is(':checked') ? 'text' : 'password';

        $('.pass-field').attr('type', type);

        $('.switch-label').text(
            $(this).is(':checked') ? 'Hide Password' : 'Show Password'
        );
    });

    // Submit Information
    $(document).on('submit', '.info-form', function (e) {

        e.preventDefault();

        let form = this;
        let type = $(form).data('type');

        let action = '';

        // MAP TYPE → AJAX ACTION
        if (type === 'personal-info') action = 'update_personal_info';
        if (type === 'address-info')  action = 'update_address_info';
        if (type === 'work-info')     action = 'update_work_info';
        if (type === 'docs-info')     action = 'update_documents_info';
        if (type === 'security-info') action = 'update_profile_password';

        let formData = new FormData(form);

        formData.append('action', action);
        formData.append('nonce', profilePageData.nonce);

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
                        title: res.data,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => location.reload());

                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });


    // ===============================
    // CONNECTION TAB
    // ===============================
    // ADD NEW CONNECTION 
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
                        : `${profilePageData.homeUrl}/dashboard/${user.username}`;
                    
                    html += `
                        <div class="connection-card">

                            <div class="conn-cover"></div>

                            <div class="conn-avatar">
                                <img src="${user.image ? user.image : profilePageData.userData.profile_image}">
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

    // REQUESTS
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
                        : `${profilePageData.homeUrl}/dashboard/${user.username}`;

                    html += `
                        <div class="connection-card">

                            <div class="conn-cover"></div>

                            <div class="conn-avatar">
                                <img src="${user.image ? user.image : profilePageData.userData.profile_image}">
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
                localStorage.setItem('activeTab', 'connections');
                location.reload();
            });
        });

    });

    // REMOVE CONNECTION
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

    // HISTORY
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

    // VIEW ALL CONNECTIONS
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

    // VIEW MUTUAL CONNECTIONS
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
    // Chat (In common Js chat.js)
    // =============================== 
    // $(document).on('click', '[data-type="chat"]', function(){
    //     openChat(); // no thread id
    //     loadUserThreads(); // sidebar load
    // });

    // Chat
    // $(document).on('click', '.conn-tab[data-type="chat"]', function () {
    //     $('#connection-established').hide();   // existing section hide
    //     $('#connection-chat').show();          // chat show
    // });

    // $(document).on('click', '.conn-tab:not([data-type="chat"])', function () {
    //     $('#connection-chat').hide();
    //     $('#connection-established').show();
    // });

    // $(document).on('click', '#conn-heading', function () {
    //     $('#connection-chat').hide();
    //     $('#connection-established').show();
    // });


    // ===============================
    // NOTIFICATION TAB
    // ===============================
    $(document).on('click', '.notification-view', function (e) {

        e.stopPropagation();

        let btn = $(this);
        let id = btn.data('id');
        let isReceived = btn.data('type') === 'received';

        let item = btn.closest('.notification-item');
        let message = item.find('.noti-message').text().trim();
        let avatar = item.find('.noti-avatar img').attr('src');

        Swal.fire({
            title: 'Notification',
            html: `
                <div class="noti-popup">

                    <img src="${avatar}" class="noti-popup-avatar">

                    <div class="noti-popup-message">
                        ${message}
                    </div>

                    <div class="noti-popup-time">
                        ${item.find('.noti-time').text()}
                    </div>

                </div>
            `,
            confirmButtonText: 'OK',
            allowOutsideClick: false,  
            allowEscapeKey: false      
        }).then(() => {

            // ✅ ONLY CALL BACKEND
            if (isReceived) {
                $.post(profilePageData.ajaxUrl, {
                    action: 'mark_notification_read',
                    id: id,
                    nonce: profilePageData.nonce
                });
            }

            // 🔥 FORCE CLOSE EVERYTHING
            Swal.close();

            // 🔥 SMALL DELAY (VERY IMPORTANT)
            setTimeout(() => {
                localStorage.setItem('activeTab', 'notifications');
                window.location.reload(true);
            }, 200);
        });
    });
    
    // ===============================
    // CONTENT TAB
    // ===============================
    // VIEW POST
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

    // ADD NEW CONTENT
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
                        localStorage.setItem('activeTab', 'content');
                        location.reload(); // refresh feed
                    });

                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });

    // CONTENT HISTORY
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
        let date = $(this).data('date');

        Swal.fire({
            html: `
                <div class="modern-post">

                    <img src="${image}" class="modern-post-img">

                    <div class="modern-post-body">

                        <!-- TITLE -->
                        <h2 class="modern-post-title">${title}</h2>

                        <!-- DESCRIPTION -->
                        <p class="modern-post-desc">${content}</p>

                        <!-- FOOTER -->
                        <div class="modern-post-meta">

                            <span></span>

                            <span class="meta-date">
                                Posted on: ${date}
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
    // Logout Button Click
    // =============================== 
    // 1. Logout click
    $(document).on('click', '.logout-btn', function () {
        localStorage.removeItem('activeTab');
    });

    // 2. Login page load safety
    if (window.location.pathname.includes('login-page')) {
        localStorage.removeItem('activeTab');
    }
});