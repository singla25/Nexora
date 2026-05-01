document.addEventListener('DOMContentLoaded', function () {

    jQuery(document).on('submit', '#vendor-registration-form', function (e) {

        e.preventDefault();

        const form = this;

        Swal.fire({
            title: 'Create Vendor Account?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes'
        }).then((result) => {

            if (!result.isConfirmed) return;

            // CAPTCHA
            let captcha = '';

            if (typeof grecaptcha !== 'undefined') {
                captcha = grecaptcha.getResponse();
            }

            if (typeof grecaptcha !== 'undefined' && !captcha) {
                Swal.fire('Error', 'Please complete captcha', 'error');
                return;
            }

            Swal.fire({
                title: 'Processing...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            const formData = new FormData(form);
            formData.append('action', 'vendor_register');
            formData.append('nonce', vendorData.nonce);
            formData.append('g-recaptcha-response', captcha);

            jQuery.ajax({
                url: vendorData.ajaxUrl,
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