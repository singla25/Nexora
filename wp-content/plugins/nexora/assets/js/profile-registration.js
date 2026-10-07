document.addEventListener('DOMContentLoaded', function () {

    jQuery(document).on('submit', '#profile-registration-form', function (e) {

        e.preventDefault();

        const form = this;
        
        Swal.fire({
            title: 'Create Account?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes'
        }).then((result) => {

            if (!result.isConfirmed) return;

            // Add captcha
            let captcha = '';

            if (typeof grecaptcha !== 'undefined') {
                captcha = grecaptcha.getResponse();
            }

            // Validate captcha (only if exists)
            if (typeof grecaptcha !== 'undefined') {
                if (!captcha) {
                    alert("Please complete captcha");
                    return;
                }
            }

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData(form);
            formData.append('action', 'profile_register');
            formData.append('nonce', profileData.nonce);
            formData.append('g-recaptcha-response', captcha);

            jQuery.ajax({
                url: profileData.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,

                success: function (res) {

                    if (res.success) {

                        Swal.fire('Success', res.data.message, 'success')
                        .then(() => {
                            window.location.href = res.data.redirect;
                        });

                    } else {
                        Swal.fire('Error', res.data, 'error');
                        if (typeof grecaptcha !== 'undefined') {
                            grecaptcha.reset();
                        }
                    }
                },

                error: function () {
                    Swal.fire('Error', 'Server error', 'error');
                }
            });

        });
    });

    // 🔥 Toggle both password fields
    jQuery(document).on('change', '#toggle-passwords', function () {

        let type = jQuery(this).is(':checked') ? 'text' : 'password';

        jQuery('input[name="password"], input[name="confirm_password"]').attr('type', type);

        jQuery('.toggle-label').text(
            jQuery(this).is(':checked') ? 'Hide Password' : 'Show Password'
        );
    });

});


