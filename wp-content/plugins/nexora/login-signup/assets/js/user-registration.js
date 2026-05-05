document.addEventListener('DOMContentLoaded', function () {

    // Password toggle – show/hide
    jQuery(document).on('change', '#toggle-passwords', function () {
        const type = jQuery(this).is(':checked') ? 'text' : 'password';
        jQuery('#profile-registration-form input[name="password"], #profile-registration-form input[name="confirm_password"]').attr('type', type);
        jQuery(this).closest('.password-toggle-wrapper').find('.toggle-label').text(
            jQuery(this).is(':checked') ? 'Hide Password' : 'Show Password'
        );
    });

    // Date of Birth – keep placeholder visible until a value is chosen,
    // and ensure it always submits as type="text" so FormData captures it
    jQuery(document).on('focus', '#user_birthdate', function () {
        jQuery(this).attr('type', 'date');
    }).on('blur', '#user_birthdate', function () {
        if (!jQuery(this).val()) {
            jQuery(this).attr('type', 'text');
        }
    });

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
});


