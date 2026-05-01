jQuery(document).ready(function ($) {

    // ==============================
    // LogIn Form Submission Flow
    // ==============================
    $(document).on('submit', '#profile-login-form', function (e) {

        e.preventDefault();

        let formData = new FormData(this);
        formData.append('action', 'profile_login');
        formData.append('nonce', profileData.nonce); // 🔥 ADD THIS

        // 🔥 Add captcha
        let captcha = '';
        if (typeof grecaptcha !== 'undefined') {
            captcha = grecaptcha.getResponse();
        }
        formData.append('g-recaptcha-response', captcha);

        // Only validate if captcha exists
        if (typeof grecaptcha !== 'undefined') {
            if (!captcha) {
                alert("Please complete captcha");
                return;
            }
        }

        $.ajax({
            url: profileData.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,

            success: function (res) {
                if (res.success) {
                    window.location.href = res.data.redirect;
                } else {
                    alert(res.data);
                    if (typeof grecaptcha !== 'undefined') {
                        grecaptcha.reset();
                    }
                }
            }
        });
    });

    // ==============================
    // FORGOT PASSWORD FLOW
    // ==============================

    let forgotUserId = null;

    $(document).on('click', '#forgot-password-btn[data-type="forgot-password"]', function (e) {

        e.preventDefault();

        Swal.fire({
            title: 'Forgot Password',
            html: `
                <input type="text" id="fp_username" class="swal2-input" placeholder="Username">
                <input type="email" id="fp_email" class="swal2-input" placeholder="Email">
            `,
            confirmButtonText: 'Send OTP',
            showCancelButton: true,

            allowOutsideClick: false,   // IMPORTANT
            allowEscapeKey: false,      // optional (disable ESC)
            allowEnterKey: true,         // optional

            preConfirm: () => {

                const username = $('#fp_username').val().trim();
                const email    = $('#fp_email').val().trim();

                if (!username || !email) {
                    Swal.showValidationMessage('Both fields are required');
                    return false;
                }

                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    Swal.showValidationMessage('Enter valid email');
                    return false;
                }

                return { username, email };
            }

        }).then((result) => {

            if (!result.isConfirmed) return;

            $.ajax({
                url: profileData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'send_otp',
                    username: result.value.username,
                    email: result.value.email,
                    nonce: profileData.nonce
                },

                success: function (res) {

                    if (!res.success) {
                        Swal.fire('Error', res.data, 'error');
                        return;
                    }

                    forgotUserId = res.data.user_id;

                    // Show message first
                    Swal.fire({
                        icon: 'success',
                        title: 'OTP Sent',
                        text: 'OTP is sent to your email. Please check your inbox.',
                        confirmButtonText: 'Continue',
                        allowOutsideClick: false,   // IMPORTANT
                        allowEscapeKey: false,      // optional (disable ESC)
                        allowEnterKey: true,         // optional
                    }).then(() => {

                        // Then open OTP popup
                        showOtpPopup();

                    });
                }
            });

        });

    });

    // ==============================
    // OTP POPUP
    // ==============================
    function showOtpPopup() {

        Swal.fire({
            title: 'Enter OTP',
            html: `<input type="text" id="fp_otp" class="swal2-input" placeholder="Enter OTP">`,
            confirmButtonText: 'Verify OTP',
            showCancelButton: true,
            allowOutsideClick: false,   // IMPORTANT
            allowEscapeKey: false,      // optional (disable ESC)
            allowEnterKey: true,        // optional

            preConfirm: () => {

                let otp = $('#fp_otp').val().trim();

                if (!otp) {
                    Swal.showValidationMessage('Enter OTP');
                    return false;
                }

                if (otp.length !== 6) {
                    Swal.showValidationMessage('Incomplete OTP, OTP must be of 6 digits');
                    return false;
                }

                return otp;
            }

        }).then((result) => {

            if (!result.isConfirmed) return;

            $.ajax({
                url: profileData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'verify_otp',
                    otp: result.value,
                    user_id: forgotUserId,
                    nonce: profileData.nonce
                },

                success: function (res) {

                    if (!res.success) {
                        Swal.fire('Error', res.data, 'error');
                        return;
                    }

                    // STEP 3 → RESET PASSWORD
                    showResetPasswordPopup();
                }
            });

        });

    }

    // ==============================
    // RESET PASSWORD POPUP
    // ==============================
    function showResetPasswordPopup() {

        Swal.fire({
            title: 'Reset Password',
            html: `
                <div class="password-wrapper">
                    <input type="password" id="new_password" class="swal2-input" placeholder="New Password">
                </div>

                <div class="password-wrapper">
                    <input type="password" id="confirm_password" class="swal2-input" placeholder="Confirm Password">
                </div>

                <div class="show-password-wrapper">
                    <label class="switch">
                        <input type="checkbox" id="toggle_all_passwords">
                        <span class="slider"></span>
                    </label>
                    <span class="switch-label">Show Password</span>
                </div>
            `,
            confirmButtonText: 'Update Password',
            showCancelButton: true,
            allowOutsideClick: false,   // IMPORTANT
            allowEscapeKey: false,      // optional (disable ESC)
            allowEnterKey: true,         // optional

            preConfirm: () => {

                let pass = $('#new_password').val().trim();
                let confirm = $('#confirm_password').val().trim();

                if (!pass || !confirm) {
                    Swal.showValidationMessage('All fields required');
                    return false;
                }

                if (pass.length < 6) {
                    Swal.showValidationMessage('Min 6 characters');
                    return false;
                }

                if (pass !== confirm) {
                    Swal.showValidationMessage('Passwords do not match');
                    return false;
                }

                return pass;
            }

        }).then((result) => {

            if (!result.isConfirmed) return;

            $.ajax({
                url: profileData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'reset_password',
                    password: result.value,
                    user_id: forgotUserId,
                    nonce: profileData.nonce
                },

                success: function (res) {

                    if (!res.success) {
                        Swal.fire('Error', res.data, 'error');
                        return;
                    }

                    Swal.fire('Success', 'Password updated!', 'success')
                        .then(() => {
                            window.location.href = res.data.redirect;
                        });
                }
            });
        });
    }

    // Toggle both password fields
    $(document).on('change', '#toggle_all_passwords', function () {

        let type = $(this).is(':checked') ? 'text' : 'password';
        $('#new_password, #confirm_password').attr('type', type);

        $('.switch-label').text($(this).is(':checked') ? 'Hide Password' : 'Show Password');
    });
});