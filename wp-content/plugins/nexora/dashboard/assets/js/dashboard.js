/**
 * dashboard.js
 *
 * Consumes: window.nexoraDashboard (localized by NEXORA_DASHBOARD_CORE)
 *
 * Key changes from old profile-page.js:
 *  - Global object renamed: profilePageData → nexoraDashboard
 *  - roleType / profileRole used for conditional form building
 *  - Work-info form adapts labels/fields for vendor vs user
 *  - Document fields adapt for vendor vs user
 *  - Marketplace tab stub added
 *  - localStorage key namespaced: activeTab → nexora_activeTab
 */

jQuery(document).ready(function ($) {

    const D = window.nexoraDashboard;   // shorthand — used everywhere below

    if (!D) {
        console.warn('[Nexora] nexoraDashboard not found.');
        return;
    }

    const data        = D.userData    || {};
    const roleType    = D.roleType    || 'guest';   // guest | owner | viewer
    const profileRole = D.profileRole || 'user';    // user  | vendor
    const isOwner     = roleType === 'owner';
    const isVendor    = profileRole === 'vendor';

    // =========================================================
    // TAB SWITCH
    // =========================================================
    const TAB_KEY = 'nexora_activeTab';

    const savedTab = localStorage.getItem(TAB_KEY);

    if (savedTab && isOwner) {
        $('.tab-btn[data-tab="' + savedTab + '"]').addClass('active').siblings().removeClass('active');
        $('#' + savedTab).addClass('active').siblings('.tab-content').removeClass('active');
    }

    $('.tab-btn').on('click', function () {

        const tab = $(this).data('tab');
        localStorage.setItem(TAB_KEY, tab);

        $('.tab-btn').removeClass('active');
        $(this).addClass('active');

        $('.tab-content').removeClass('active');
        $('#' + tab).addClass('active');
    });

    // =========================================================
    // USER INFO — EDIT FORMS (owner only)
    // =========================================================
    $(document).on('click', '.user-edit-info', function () {

        const type = $(this).data('type');
        let html   = '';

        // ── PERSONAL ──────────────────────────────────────────
        if (type === 'personal-info') {

            html = `
                <form class="info-form grid-form" data-type="personal-info">

                    <div class="form-group">
                        <label>User Name</label>
                        <input type="text" value="${esc(data.user_name)}" disabled>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" value="${esc(data.email)}" disabled>
                    </div>

                    <div class="form-group">
                        <label>First Name</label>
                        <input name="first_name" value="${esc(data.first_name)}">
                    </div>

                    <div class="form-group">
                        <label>Last Name</label>
                        <input name="last_name" value="${esc(data.last_name)}">
                    </div>

                    <div class="form-group">
                        <label>Phone</label>
                        <input name="phone" value="${esc(data.phone)}">
                    </div>

                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">Select</option>
                            <option value="male"   ${sel(data.gender,'male')}>Male</option>
                            <option value="female" ${sel(data.gender,'female')}>Female</option>
                            <option value="other"  ${sel(data.gender,'other')}>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Birthdate</label>
                        <input type="date" name="birthdate" value="${esc(data.birthdate)}">
                    </div>

                    <div class="form-group">
                        <label>LinkedIn</label>
                        <input name="linkedin_id" value="${esc(data.linkedin_id)}">
                    </div>

                    <div class="form-group full">
                        <label>Bio</label>
                        <textarea name="bio">${esc(data.bio)}</textarea>
                    </div>

                    <button class="form-submit">Save Changes</button>
                </form>
            `;
        }

        // ── ADDRESS ───────────────────────────────────────────
        if (type === 'address-info') {

            html = `
                <form class="info-form grid-form" data-type="address-info">

                    <div class="form-section">
                        <h4>Permanent Address</h4>
                        <div class="form-group full">
                            <input name="perm_address" value="${esc(data.perm_address)}" placeholder="Address">
                        </div>
                        <div class="form-group"><input name="perm_city"    value="${esc(data.perm_city)}"    placeholder="City"></div>
                        <div class="form-group"><input name="perm_state"   value="${esc(data.perm_state)}"   placeholder="State"></div>
                        <div class="form-group"><input name="perm_pincode" value="${esc(data.perm_pincode)}" placeholder="Pincode"></div>
                    </div>

                    <div class="form-section">
                        <h4>Correspondence Address</h4>
                        <div class="form-group full">
                            <input name="corr_address" value="${esc(data.corr_address)}" placeholder="Address">
                        </div>
                        <div class="form-group"><input name="corr_city"    value="${esc(data.corr_city)}"    placeholder="City"></div>
                        <div class="form-group"><input name="corr_state"   value="${esc(data.corr_state)}"   placeholder="State"></div>
                        <div class="form-group"><input name="corr_pincode" value="${esc(data.corr_pincode)}" placeholder="Pincode"></div>
                    </div>

                    <button class="form-submit">Save Changes</button>
                </form>
            `;
        }

        // ── WORK / BUSINESS (role-adaptive) ───────────────────
        if (type === 'work-info') {

            // Vendor sees business fields; user sees company fields
            html = isVendor
                ? buildVendorWorkForm()
                : buildUserWorkForm();
        }

        // ── DOCUMENTS (role-adaptive) ─────────────────────────
        if (type === 'docs-info') {

            const userDocs   = ['profile_image','cover_image','aadhaar_card','driving_license','company_id_card'];
            const vendorDocs = ['profile_image','cover_image','aadhaar_card','company_id_card','gst_certificate','business_license','pan_card','bank_proof'];
            const docKeys    = isVendor ? vendorDocs : userDocs;

            html = `
                <form class="info-form docs-form" data-type="docs-info">
                    <div class="docs-grid">
                        ${docKeys.map(key => buildDocCard(key)).join('')}
                    </div>
                    <button class="form-submit">Save Documents</button>
                </form>
            `;
        }

        // ── SECURITY ──────────────────────────────────────────
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
            title: 'Update Information',
            html: html,
            showConfirmButton: false,
            width: '520px'
        });
    });

    // ── Work form builders ────────────────────────────────────

    function buildUserWorkForm() {
        return `
            <form class="info-form grid-form" data-type="work-info">
                <div class="form-group"><label>Company Name</label>   <input name="company_name"    value="${esc(data.company_name)}"></div>
                <div class="form-group"><label>Designation</label>    <input name="designation"     value="${esc(data.designation)}"></div>
                <div class="form-group"><label>Company Email</label>  <input name="company_email"   value="${esc(data.company_email)}"></div>
                <div class="form-group"><label>Company Phone</label>  <input name="company_phone"   value="${esc(data.company_phone)}"></div>
                <div class="form-group full">
                    <label>Company Address</label>
                    <textarea name="company_address">${esc(data.company_address)}</textarea>
                </div>
                <button class="form-submit">Save Changes</button>
            </form>
        `;
    }

    function buildVendorWorkForm() {
        return `
            <form class="info-form grid-form" data-type="work-info">
                <div class="form-group"><label>Business Name</label>  <input name="business_name"   value="${esc(data.business_name)}"></div>
                <div class="form-group"><label>Business Type</label>  <input name="business_type"   value="${esc(data.business_type)}"></div>
                <div class="form-group"><label>Business Email</label> <input name="company_email"   value="${esc(data.company_email)}"></div>
                <div class="form-group"><label>Business Phone</label> <input name="company_phone"   value="${esc(data.company_phone)}"></div>
                <div class="form-group"><label>GST Number</label>     <input name="gst_number"      value="${esc(data.gst_number)}"></div>
                <div class="form-group full">
                    <label>Business Address</label>
                    <textarea name="company_address">${esc(data.company_address)}</textarea>
                </div>
                <button class="form-submit">Save Changes</button>
            </form>
        `;
    }

    // ── Document card builder ─────────────────────────────────

    function buildDocCard(key) {

        const url = data[key]        || '';
        const id  = data[key + '_id'] || '';
        const label = key.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());

        return `
            <div class="doc-upload-card">
                <div class="doc-image-wrapper">
                    ${ url
                        ? `<img src="${url}" class="doc-preview">`
                        : `<div class="doc-placeholder">No Image</div>`
                    }
                    <div class="doc-overlay">
                        <button type="button" class="upload-btn">Upload</button>
                        ${ url ? `<button type="button" class="remove-btn">Remove</button>` : '' }
                    </div>
                </div>
                <span class="doc-label">${label}</span>
                <input type="hidden" name="${key}" value="${id}">
            </div>
        `;
    }

    // =========================================================
    // DOCUMENT UPLOAD / REMOVE (WP Media)
    // =========================================================
    $(document).on('click', '.upload-btn', function (e) {

        e.preventDefault();

        const container = $(this).closest('.doc-upload-card');

        const frame = wp.media({
            title: 'Select Image',
            button: { text: 'Use this image' },
            multiple: false
        });

        frame.on('select', function () {

            const attachment = frame.state().get('selection').first().toJSON();

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

    $(document).on('click', '.remove-btn', function () {

        const card    = $(this).closest('.doc-upload-card');
        const wrapper = card.find('.doc-image-wrapper');

        card.find('input[type="hidden"]').val('');

        wrapper.html(`
            <div class="doc-placeholder">No Image</div>
            <div class="doc-overlay">
                <button type="button" class="upload-btn">Upload</button>
            </div>
        `);
    });

    // Toggle password visibility
    $(document).on('change', '#toggle_all_passwords', function () {

        const type = $(this).is(':checked') ? 'text' : 'password';
        $('.pass-field').attr('type', type);
        $('.switch-label').text($(this).is(':checked') ? 'Hide Password' : 'Show Password');
    });

    // =========================================================
    // FORM SUBMIT — unified handler
    // =========================================================
    const ACTION_MAP = {
        'personal-info' : 'update_personal_info',
        'address-info'  : 'update_address_info',
        'work-info'     : 'update_work_info',
        'docs-info'     : 'update_documents_info',
        'security-info' : 'update_profile_password',
    };

    $(document).on('submit', '.info-form', function (e) {

        e.preventDefault();

        const type   = $(this).data('type');
        const action = ACTION_MAP[type];

        if (!action) return;

        const formData = new FormData(this);
        formData.append('action', action);
        formData.append('nonce', D.nonce);

        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.ajax({
            url: D.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: res.data, timer: 1500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });

    // =========================================================
    // CONNECTIONS TAB
    // =========================================================

    // ── Add New ───────────────────────────────────────────────
    $(document).on('click', '.conn-tab[data-type="add"]', function () {

        ajaxPost('get_add_new_users', {}, function (users) {

            const html = users.map(u => connectionCard(u, 'connect')).join('');

            Swal.fire({
                title: 'Add New Connections',
                html: `<div class="conn-popup-grid">${html || '<p>No new users found.</p>'}</div>`,
                width: '700px',
                showConfirmButton: false
            });
        });
    });

    // ── Send Request ──────────────────────────────────────────
    $(document).on('click', '.connect-btn', function () {

        const btn = $(this);
        const id  = btn.data('id');

        ajaxPost('send_connection_request', { receiver_profile_id: id }, function () {
            btn.text('Sent ✓').prop('disabled', true).css('opacity', 0.6);
        });
    });

    // ── Requests ──────────────────────────────────────────────
    $(document).on('click', '.conn-tab[data-type="requests"]', function () {

        ajaxPost('get_requests', {}, function (users) {

            const html = users.map(u => connectionCard(u, 'request')).join('');

            Swal.fire({
                title: 'Connection Requests',
                html: `<div class="conn-popup-grid">${html || '<p>No pending requests.</p>'}</div>`,
                width: '700px',
                showConfirmButton: false
            });
        });
    });

    // ── Accept / Reject ───────────────────────────────────────
    $(document).on('click', '.accept-btn, .reject-btn', function () {

        const id       = $(this).data('id');
        const accepted = $(this).hasClass('accept-btn');
        const status   = accepted ? 'accepted' : 'rejected';

        ajaxPost('update_connection_status', { connection_id: id, status }, function () {
            Swal.fire({
                icon: accepted ? 'success' : 'error',
                title: accepted ? 'Accepted!' : 'Rejected!',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                localStorage.setItem(TAB_KEY, 'connections');
                location.reload();
            });
        });
    });

    // ── Remove Connection ─────────────────────────────────────
    $(document).on('click', '.remove-connection-btn', function () {

        const id   = $(this).data('id');
        const card = $(this).closest('.establish-connection-card');

        Swal.fire({
            title: 'Remove connection?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove'
        }).then(result => {
            if (!result.isConfirmed) return;

            ajaxPost('update_connection_status', { connection_id: id, status: 'removed' }, function () {
                Swal.fire({ icon: 'success', title: 'Removed!', timer: 1500, showConfirmButton: false });
                card.fadeOut(300, function () { $(this).remove(); });
            });
        });
    });

    // ── History ───────────────────────────────────────────────
    $(document).on('click', '.conn-tab[data-type="history"]', function () {

        ajaxPostHtml('get_history', {}, function (html) {
            Swal.fire({ title: 'Connection History', html, width: '620px' });
        });
    });

    // ── View All / Mutual ─────────────────────────────────────
    $(document).on('click', '[data-type="view-all-conn"]', function () {

        const profileId = $(this).data('profile');

        ajaxPostHtml('view_all_connection', { profile_id: profileId }, function (html) {
            Swal.fire({
                title: 'All Connections',
                html: `<div class="conn-popup-grid">${html}</div>`,
                width: '700px',
                showConfirmButton: false
            });
        });
    });

    $(document).on('click', '[data-type="view-common-conn"]', function () {

        const profileId = $(this).data('profile');

        ajaxPostHtml('view_mutual_connection', { profile_id: profileId }, function (html) {
            Swal.fire({
                title: 'Mutual Connections',
                html: `<div class="conn-popup-grid">${html}</div>`,
                width: '700px',
                showConfirmButton: false
            });
        });
    });

    // =========================================================
    // NOTIFICATIONS TAB
    // =========================================================
    $(document).on('click', '.notification-view', function (e) {

        e.stopPropagation();

        const btn     = $(this);
        const id      = btn.data('id');
        const item    = btn.closest('.notification-item');
        const message = item.find('.noti-message').text().trim();
        const avatar  = item.find('.noti-avatar img').attr('src');
        const time    = item.find('.noti-time').text();

        Swal.fire({
            title: 'Notification',
            html: `
                <div class="noti-popup">
                    <img src="${avatar}" class="noti-popup-avatar">
                    <div class="noti-popup-message">${message}</div>
                    <div class="noti-popup-time">${time}</div>
                </div>
            `,
            confirmButtonText: 'OK',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(() => {
            $.post(D.ajaxUrl, { action: 'mark_notification_read', id, nonce: D.nonce });
            Swal.close();
            setTimeout(() => {
                localStorage.setItem(TAB_KEY, 'notifications');
                window.location.reload(true);
            }, 200);
        });
    });

    // =========================================================
    // CONTENT TAB
    // =========================================================

    // ── View Post ─────────────────────────────────────────────
    $(document).on('click', '.view-post', function (e) {

        e.stopPropagation();

        const card = $(this).closest('.content-card');

        Swal.fire({
            html: `
                <div class="modern-post">
                    <img src="${card.data('image')}" class="modern-post-img">
                    <div class="modern-post-body">
                        <h2 class="modern-post-title">${card.data('title')}</h2>
                        <p class="modern-post-desc">${card.data('content')}</p>
                        <div class="modern-post-meta">
                            <a href="${card.data('profile')}" target="_blank" class="meta-username">${card.data('username')}</a>
                            <span class="meta-fullname">${card.data('fullname')}</span>
                            <span class="meta-date">${card.data('date')}</span>
                        </div>
                    </div>
                </div>
            `,
            width: '550px',
            showConfirmButton: false,
            customClass: { popup: 'modern-popup' }
        });
    });

    // ── Add New Content ───────────────────────────────────────
    $(document).on('click', '.content-tab[data-type="add"]', function () {

        Swal.fire({
            title: 'Add New Content',
            html: `
                <form id="add-content-form" class="content-form">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" placeholder="Enter title" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Write something..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <div class="upload-box">
                            <input type="hidden" name="image" id="content_image_id">
                            <img id="content_preview" style="display:none;max-width:100%;border-radius:8px;margin-bottom:8px;">
                            <button type="button" class="upload-content-image">Choose Image</button>
                        </div>
                    </div>
                    <button type="submit" class="submit-btn">Post Content</button>
                </form>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Cancel',
            allowOutsideClick: false,
            allowEscapeKey: false,
            width: '500px',
            customClass: { popup: 'content-popup' }
        });
    });

    $(document).on('click', '.upload-content-image', function (e) {

        e.preventDefault();

        const frame = wp.media({ title: 'Select Image', button: { text: 'Use this image' }, multiple: false });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            $('#content_image_id').val(attachment.id);
            $('#content_preview').attr('src', attachment.url).show();
        });

        frame.open();
    });

    $(document).on('submit', '#add-content-form', function (e) {

        e.preventDefault();

        const formData = new FormData(this);
        formData.append('action', 'save_user_content');
        formData.append('nonce', D.nonce);

        Swal.fire({ title: 'Posting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.ajax({
            url: D.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Posted!', timer: 1500, showConfirmButton: false })
                        .then(() => { localStorage.setItem(TAB_KEY, 'content'); location.reload(); });
                } else {
                    Swal.fire('Error', res.data, 'error');
                }
            }
        });
    });

    // ── Content History ───────────────────────────────────────
    $(document).on('click', '.content-tab[data-type="history"]', function () {

        ajaxPostHtml('get_user_content_history', {}, function (html) {
            Swal.fire({ title: 'Your Content', html, width: '700px' });
        });
    });

    $(document).on('click', '.view-content-btn', function () {

        const btn = $(this);

        Swal.fire({
            html: `
                <div class="modern-post">
                    <img src="${btn.data('image')}" class="modern-post-img">
                    <div class="modern-post-body">
                        <h2 class="modern-post-title">${btn.data('title')}</h2>
                        <p class="modern-post-desc">${btn.data('content')}</p>
                        <div class="modern-post-meta">
                            <span></span>
                            <span class="meta-date">Posted on: ${btn.data('date')}</span>
                        </div>
                    </div>
                </div>
            `,
            width: '550px',
            showConfirmButton: false,
            customClass: { popup: 'modern-popup' }
        });
    });

    // =========================================================
    // MARKETPLACE TAB
    // Stub — extend via nexoraDashboard.marketHook if needed
    // =========================================================
    $(document).on('click', '.tab-btn[data-tab="market"]', function () {
        // Market tab content is server-rendered.
        // Plugins can hook 'nexora_marketplace_content' filter in PHP,
        // or fire custom JS events here:
        $(document).trigger('nexora:market_tab_opened', [D]);
    });

    // =========================================================
    // LOGOUT
    // =========================================================
    $(document).on('click', '.logout-btn', function () {
        localStorage.removeItem(TAB_KEY);
    });

    if (window.location.pathname.includes('login-page')) {
        localStorage.removeItem(TAB_KEY);
    }

    // =========================================================
    // PRIVATE HELPERS
    // =========================================================

    /** Escape HTML special chars for injection into innerHTML */
    function esc(val) {
        if (val == null) return '';
        return String(val)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    /** Build selected="" attribute */
    function sel(current, value) {
        return current === value ? 'selected' : '';
    }

    /** AJAX post that expects res.data to be an array/object */
    function ajaxPost(action, params, onSuccess) {
        $.post(D.ajaxUrl, { action, nonce: D.nonce, ...params }, function (res) {
            if (res.success) onSuccess(res.data);
            else Swal.fire('Error', res.data?.message || res.data || 'Something went wrong.', 'error');
        });
    }

    /** AJAX post that expects res.data to be an HTML string */
    function ajaxPostHtml(action, params, onSuccess) {
        $.post(D.ajaxUrl, { action, nonce: D.nonce, ...params }, function (res) {
            if (res.success) onSuccess(res.data);
            else Swal.fire('Error', res.data || 'Something went wrong.', 'error');
        });
    }

    /**
     * Build a connection card HTML string.
     * @param {object} user
     * @param {'connect'|'request'} mode
     */
    function connectionCard(user, mode) {

        const profileLink = user.profile_link
            || `${D.homeUrl}/dashboard/${user.username}`;

        const fallbackImg = data.profile_image || '';
        const img = user.image || fallbackImg;

        let actionBtn = '';
        if (mode === 'connect') {
            actionBtn = `<button class="connect-btn" data-id="${user.profile_id}">Connect</button>`;
        } else if (mode === 'request') {
            actionBtn = `
                <button class="accept-btn" data-id="${user.connection_id}">Accept</button>
                <button class="reject-btn" data-id="${user.connection_id}">Reject</button>
            `;
        }

        return `
            <div class="connection-card">
                <div class="conn-cover"></div>
                <div class="conn-avatar"><img src="${img}" alt=""></div>
                <div class="conn-body">
                    <a href="${profileLink}" class="conn-username" target="_blank">${esc(user.username)}</a>
                    <p class="conn-name">${esc(user.name)}</p>
                    ${actionBtn}
                </div>
            </div>
        `;
    }

});