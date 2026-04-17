<?php

class NEXORA_Login {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'login_enqueue_assets']);
        add_shortcode('profile_login', [$this, 'login_form']);

        add_action('wp_ajax_profile_login', [$this, 'handle_login']);
        add_action('wp_ajax_nopriv_profile_login', [$this, 'handle_login']);

        add_action('wp_ajax_send_otp', [$this, 'send_otp']);
        add_action('wp_ajax_nopriv_send_otp', [$this, 'send_otp']);

        add_action('wp_ajax_verify_otp', [$this, 'verify_otp']);
        add_action('wp_ajax_nopriv_verify_otp', [$this, 'verify_otp']);

        add_action('wp_ajax_reset_password', [$this, 'reset_password']);
        add_action('wp_ajax_nopriv_reset_password', [$this, 'reset_password']);
    }

    public function login_enqueue_assets() {

        wp_enqueue_style('profile-login-style', NEXORA_URL . 'assets/css/profile-login.css');

        wp_enqueue_script(
            'sweetalert2',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'profile-login',
            NEXORA_URL . 'assets/js/profile-login.js',
            ['jquery', 'sweetalert2'],
            null,
            true
        );

        wp_localize_script('profile-login', 'profileData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('profile_nonce')
        ]);

        $captcha = new Nexora_ReCaptcha();
        $captcha->enqueue_script();
    }

    // ---------------------------
    //      SEND OTP
    // ---------------------------
    public function send_otp() {

        check_ajax_referer('profile_nonce', 'nonce');

        $username = sanitize_text_field($_POST['username']);
        $email    = sanitize_email($_POST['email']);

        $user = get_user_by('login', $username);

        if (!$user) {
            wp_send_json_error('Invalid username');
        }

        if ($user->user_email !== $email) {
            wp_send_json_error('Email does not match with username');
        }

        // Check existing OTP
        $existing_otp = get_user_meta($user->ID, 'reset_otp', true);
        $expiry       = get_user_meta($user->ID, 'otp_expiry', true);

        // If OTP exists and not expired
        if ($existing_otp && $expiry && time() < $expiry) {

            $remaining_seconds = $expiry - time();
            $remaining_minutes = ceil($remaining_seconds / 60);

            // Send SAME OTP again
            $subject = "Your OTP is still valid - Nexora";
            $message = "Your OTP is: $existing_otp\n\nThis OTP is still valid for $remaining_minutes minute(s).";

            wp_mail($email, $subject, $message);

            wp_send_json_success([
                'user_id' => $user->ID,
                'message' => "OTP already sent. Valid for $remaining_minutes minute(s)."
            ]);
        }

        // Generate NEW OTP
        $otp = rand(100000, 999999);

        update_user_meta($user->ID, 'reset_otp', $otp);
        update_user_meta($user->ID, 'otp_expiry', time() + 600);

        $subject = "Reset Password OTP - Nexora";
        $message = "Your OTP is: $otp\n\nThis OTP is valid for 10 minutes.";

        wp_mail($email, $subject, $message);

        wp_send_json_success([
            'user_id' => $user->ID,
            'message' => "New OTP sent successfully"
        ]);
    }

    // ---------------------------
    //      VERIFY OTP
    // ---------------------------
    public function verify_otp() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id = intval($_POST['user_id']);
        $otp     = sanitize_text_field($_POST['otp']);

        $saved_otp = get_user_meta($user_id, 'reset_otp', true);
        $expiry    = get_user_meta($user_id, 'otp_expiry', true);

        if (!$saved_otp) {
            wp_send_json_error('No OTP found');
        }

        if ($otp != $saved_otp) {
            wp_send_json_error('Invalid OTP');
        }

        if (time() > $expiry) {
            wp_send_json_error('OTP expired');
        }

        wp_send_json_success('OTP verified');
    }

    // ---------------------------
    //      RESET Password
    // ---------------------------
    public function reset_password() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id = intval($_POST['user_id']);
        $password = $_POST['password'];

        if (empty($password)) {
            wp_send_json_error('Password cannot be empty');
        }

        // Update password
        wp_set_password($password, $user_id);

        // Clear OTP
        delete_user_meta($user_id, 'reset_otp');
        delete_user_meta($user_id, 'otp_expiry');

        // Get user data
        $user = get_userdata($user_id);

        // SEND CONFIRMATION EMAIL
        $to = $user->user_email;
        $subject = "Password Reset Successful - Nexora";

        $message = "
        <div style='font-family:Segoe UI, sans-serif; padding:20px; background:#f8fafc;'>
            <div style='max-width:500px; margin:auto; background:#fff; padding:20px; border-radius:10px;'>
                <h2 style='color:#16a34a;'>Password Reset Successful ✅</h2>
                <p>Hi <strong>{$user->display_name}</strong>,</p>
                <p>Your password has been successfully reset.</p>
                <p>If this was you, enjoy using <b>Nexora</b> 🚀</p>
                <p style='color:#ef4444;'>If not, please contact support immediately.</p>
                <hr>
                <p style='font-size:12px; color:#64748b;'>— Nexora Team</p>
            </div>
        </div>
        ";

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($to, $subject, $message, $headers);

        // Auto login
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        $user = get_userdata($user_id);

        wp_send_json_success([
            'redirect' => home_url('/profile-page/' . $user->user_login)
        ]);
    }


    // ---------------------------
    //      LogIn Form
    // ---------------------------
    public function login_form() {

        // Already logged in
        if (is_user_logged_in()) {

            $current_user = wp_get_current_user();

            return '
                <div class="login-state-wrapper">

                    <div class="login-state-card">

                        <div class="login-avatar">
                            <span>' . strtoupper(substr($current_user->display_name, 0, 1)) . '</span>
                        </div>

                        <h2>Welcome back, ' . esc_html($current_user->display_name) . ' 👋</h2>
                        <p>You are already logged in</p>

                        <div class="login-actions">
                            <a href="' . home_url('/profile-page/' . $current_user->user_login) . '" class="btn-primary">
                                Go to Profile
                            </a>

                            <a href="' . wp_logout_url(home_url('/login-page')) . '" class="btn-danger">
                                Logout
                            </a>
                        </div>

                    </div>

                </div>
            ';
        }

        ob_start(); ?>

        <div class="profile-login-wrapper">
            <div class="profile-login-card">

                <form id="profile-login-form">

                    <h2>Welcome Back 👋</h2>

                    <input type="text" name="user_name" placeholder="Username or Email" required>
                    <input type="password" name="password" placeholder="Password" required>

                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('profile_nonce'); ?>">

                    <?php
                    $captcha = new Nexora_ReCaptcha();
                    echo $captcha->render();
                    ?>

                    <button type="submit">Login</button>

                    <div class="profile-login-extra">
                        Don’t have an account? 
                        <a href="<?php echo home_url('/registration-page'); ?>">Register</a>
                    </div>

                    <div class="profile-login-password">
                        <button type="button" id="forgot-password-btn" class="forgot-password-btn" data-type="forgot-password">
                            Forgot Password?
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    public function handle_login() {

        check_ajax_referer('profile_nonce', 'nonce');

        $captcha = new Nexora_ReCaptcha();

        $result = $captcha->verify($_POST['g-recaptcha-response'] ?? '');

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        $login_input = sanitize_text_field($_POST['user_name']);
        $password    = $_POST['password'];

        // Check if input is email
        if (is_email($login_input)) {

            $user = get_user_by('email', $login_input);

            if ($user) {
                $login_input = $user->user_login; // convert email → username
            } else {
                wp_send_json_error('No user found with this email');
            }
        }

        $creds = [
            'user_login'    => $login_input,
            'user_password' => $password,
            'remember'      => true
        ];

        $user = wp_signon($creds, false);

        if (is_wp_error($user)) {
            wp_send_json_error('Invalid credentials');
        }

        // is_ssl()is a WordPress conditional function that checks if the current page is loaded over HTTPS or via port 443.
        // It returns true if the connection is secure and false otherwise [1, 3]. 
        // It is essential for ensuring that scripts, stylesheets, and site URLs use the https protocol to avoid mixed content errors.
        $user = wp_signon($creds, is_ssl());

        if (!is_wp_error($user)) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
        }

        // ✅ Correct way to get username
        $username = $user->user_login;

        if (in_array('administrator', $user->roles)) {
            $redirect = home_url('/profile-page');
        } else {
            $redirect = home_url('/profile-page/' . $user->user_login);
        }

        wp_send_json_success([
            'redirect' => $redirect
        ]);
    }
}