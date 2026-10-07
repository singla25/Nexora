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
    //      HELPERS
    // ---------------------------
    private function client_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    /**
     * Simple transient based rate limiter. Returns true when the limit is exceeded.
     */
    private function rate_limited($bucket, $limit, $window) {
        $key   = 'nx_rl_' . md5($bucket . '|' . $this->client_ip());
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return true;
        }

        set_transient($key, $count + 1, $window);
        return false;
    }

    private function clear_otp($user_id) {
        delete_user_meta($user_id, 'reset_otp');
        delete_user_meta($user_id, 'otp_expiry');
        delete_user_meta($user_id, 'otp_attempts');
        delete_user_meta($user_id, 'reset_token');
        delete_user_meta($user_id, 'reset_token_expiry');
    }

    // ---------------------------
    //      SEND OTP
    // ---------------------------
    public function send_otp() {

        check_ajax_referer('profile_nonce', 'nonce');

        if ($this->rate_limited('send_otp', 5, 15 * MINUTE_IN_SECONDS)) {
            wp_send_json_error('Too many requests. Please try again later.');
        }

        $username = sanitize_user(wp_unslash($_POST['username'] ?? ''));
        $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        $generic = [
            'user_id' => 0,
            'message' => 'If the details are correct, an OTP has been sent to your email.'
        ];

        $user = $username ? get_user_by('login', $username) : false;

        // Same response for unknown user / wrong email (no account enumeration)
        if (!$user || !$email || strcasecmp($user->user_email, $email) !== 0) {
            wp_send_json_success($generic);
        }

        // Do not issue a new OTP while a valid one exists (prevents mail flooding)
        $expiry = (int) get_user_meta($user->ID, 'otp_expiry', true);

        if ($expiry && time() < $expiry) {
            $generic['user_id'] = $user->ID;
            $generic['message'] = 'An OTP was already sent. Please check your email or wait for it to expire.';
            wp_send_json_success($generic);
        }

        $otp = (string) random_int(100000, 999999);

        // Store only a hash of the OTP
        update_user_meta($user->ID, 'reset_otp', wp_hash_password($otp));
        update_user_meta($user->ID, 'otp_expiry', time() + 600);
        update_user_meta($user->ID, 'otp_attempts', 0);
        delete_user_meta($user->ID, 'reset_token');
        delete_user_meta($user->ID, 'reset_token_expiry');

        $subject = 'Reset Password OTP - Nexora';
        $message = "Your OTP is: $otp\n\nThis OTP is valid for 10 minutes. If you did not request it, ignore this email.";

        wp_mail($user->user_email, $subject, $message);

        $generic['user_id'] = $user->ID;
        wp_send_json_success($generic);
    }

    // ---------------------------
    //      VERIFY OTP
    // ---------------------------
    public function verify_otp() {

        check_ajax_referer('profile_nonce', 'nonce');

        if ($this->rate_limited('verify_otp', 20, 15 * MINUTE_IN_SECONDS)) {
            wp_send_json_error('Too many attempts. Please try again later.');
        }

        $user_id = absint($_POST['user_id'] ?? 0);
        $otp     = sanitize_text_field(wp_unslash($_POST['otp'] ?? ''));

        $saved_hash = get_user_meta($user_id, 'reset_otp', true);
        $expiry     = (int) get_user_meta($user_id, 'otp_expiry', true);
        $attempts   = (int) get_user_meta($user_id, 'otp_attempts', true);

        if (!$user_id || !$saved_hash) {
            wp_send_json_error('No OTP found');
        }

        if (time() > $expiry) {
            $this->clear_otp($user_id);
            wp_send_json_error('OTP expired');
        }

        if ($attempts >= 5) {
            $this->clear_otp($user_id);
            wp_send_json_error('Too many wrong attempts. Please request a new OTP.');
        }

        if (!wp_check_password($otp, $saved_hash)) {
            update_user_meta($user_id, 'otp_attempts', $attempts + 1);
            wp_send_json_error('Invalid OTP');
        }

        // OTP is single use; swap it for a short lived reset token
        $token = wp_generate_password(32, false);

        delete_user_meta($user_id, 'reset_otp');
        delete_user_meta($user_id, 'otp_expiry');
        delete_user_meta($user_id, 'otp_attempts');

        update_user_meta($user_id, 'reset_token', wp_hash_password($token));
        update_user_meta($user_id, 'reset_token_expiry', time() + 600);

        wp_send_json_success([
            'message' => 'OTP verified',
            'token'   => $token
        ]);
    }

    // ---------------------------
    //      RESET Password
    // ---------------------------
    public function reset_password() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id  = absint($_POST['user_id'] ?? 0);
        $token    = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $password = wp_unslash($_POST['password'] ?? '');

        if (!$user_id || $token === '') {
            wp_send_json_error('Invalid request');
        }

        // The OTP must have been verified first (proves ownership of the email)
        $saved_token = get_user_meta($user_id, 'reset_token', true);
        $expiry      = (int) get_user_meta($user_id, 'reset_token_expiry', true);

        if (!$saved_token || time() > $expiry || !wp_check_password($token, $saved_token)) {
            wp_send_json_error('Reset session expired. Please verify OTP again.');
        }

        if (strlen($password) < 8) {
            wp_send_json_error('Password must be at least 8 characters');
        }

        $user = get_userdata($user_id);

        if (!$user) {
            wp_send_json_error('Invalid request');
        }

        wp_set_password($password, $user_id);

        // Token / OTP are single use
        $this->clear_otp($user_id);

        $to      = $user->user_email;
        $subject = 'Password Reset Successful - Nexora';

        $message = "
        <div style='font-family:Segoe UI, sans-serif; padding:20px; background:#f8fafc;'>
            <div style='max-width:500px; margin:auto; background:#fff; padding:20px; border-radius:10px;'>
                <h2 style='color:#16a34a;'>Password Reset Successful</h2>
                <p>Hi <strong>" . esc_html($user->display_name) . "</strong>,</p>
                <p>Your password has been successfully reset.</p>
                <p>If this was you, enjoy using <b>Nexora</b>.</p>
                <p style='color:#ef4444;'>If not, please contact support immediately.</p>
                <hr>
                <p style='font-size:12px; color:#64748b;'>— Nexora Team</p>
            </div>
        </div>
        ";

        wp_mail($to, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);

        // Auto login
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        $redirect = user_can($user_id, 'manage_options')
            ? home_url('/profile-page')
            : home_url('/profile-page/' . rawurlencode($user->user_login));

        wp_send_json_success([
            'redirect' => $redirect
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
                            <span>' . esc_html(mb_strtoupper(mb_substr($current_user->display_name, 0, 1))) . '</span>
                        </div>

                        <h2>Welcome back, ' . esc_html($current_user->display_name) . ' 👋</h2>
                        <p>You are already logged in</p>

                        <div class="login-actions">
                            <a href="' . esc_url(home_url('/profile-page/' . rawurlencode($current_user->user_login))) . '" class="btn-primary">
                                Go to Profile
                            </a>

                            <a href="' . esc_url(wp_logout_url(home_url('/login-page'))) . '" class="btn-danger">
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

                    <div class="password-toggle-wrapper full-width">
                        <label class="switch">
                            <input type="checkbox" id="toggle-passwords">
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-label">Show Password</span>
                    </div>

                    <?php
                    $captcha = new Nexora_ReCaptcha();
                    echo $captcha->render();
                    ?>

                    <button type="submit">Login</button>

                    <div class="profile-login-extra">
                        Don’t have an account? 
                        <a href="<?php echo esc_url(home_url('/registration-page')); ?>">Register</a>
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

        if ($this->rate_limited('login', 10, 15 * MINUTE_IN_SECONDS)) {
            wp_send_json_error('Too many login attempts. Please try again later.');
        }

        $captcha = new Nexora_ReCaptcha();

        $result = $captcha->verify(sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'] ?? '')));

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        $login_input = sanitize_text_field(wp_unslash($_POST['user_name'] ?? ''));
        $password    = wp_unslash($_POST['password'] ?? '');

        if ($login_input === '' || $password === '') {
            wp_send_json_error('Invalid username or password');
        }

        // Allow login with email
        if (is_email($login_input)) {

            $by_email = get_user_by('email', $login_input);

            // Same error as a wrong password (no account enumeration)
            if (!$by_email) {
                wp_send_json_error('Invalid username or password');
            }

            $login_input = $by_email->user_login;
        }

        $user = wp_signon([
            'user_login'    => $login_input,
            'user_password' => $password,
            'remember'      => true
        ], is_ssl());

        if (is_wp_error($user)) {
            wp_send_json_error('Invalid username or password');
        }

        if (user_can($user, 'manage_options')) {
            $redirect = home_url('/profile-page');
        } else {
            $redirect = home_url('/profile-page/' . rawurlencode($user->user_login));
        }

        wp_send_json_success([
            'redirect' => $redirect
        ]);
    }
}
