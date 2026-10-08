<?php

class NEXORA_Registration {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_registration', [$this, 'registration_form']);

        Nexora_Ajax::register('profile_register', [$this, 'registration_form_handle'], true);
    }

    public function enqueue_assets() {

        // Only the registration page needs these scripts (and the guest nonce)
        if (!NEXORA_System::is_page_for('registration-page', 'profile_registration')) {
            return;
        }

        NEXORA_System::enqueue_tokens();
        wp_enqueue_style('profile-style', NEXORA_URL . 'assets/css/profile-registration.css', ['nexora-tokens'], NEXORA_VERSION);

        NEXORA_System::enqueue_sweetalert();

        wp_enqueue_script(
            'profile-registration',
            NEXORA_URL . 'assets/js/profile-registration.js',
            ['jquery', 'sweetalert2'],
            NEXORA_VERSION,
            true
        );

        wp_localize_script('profile-registration', 'profileData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('profile_nonce')
        ]);

        // The form renders a captcha, so its script must load here too
        $captcha = new Nexora_ReCaptcha();
        $captcha->enqueue_script();
    }

    public function registration_form() {

        // LOGIN CHECK HERE
        if (is_user_logged_in()) {

            $current_user = wp_get_current_user();

            return '
                <div class="register-state-wrapper">

                    <div class="register-state-card">

                        <div class="register-avatar">
                            <span>' . esc_html(mb_strtoupper(mb_substr($current_user->display_name, 0, 1))) . '</span>
                        </div>

                        <h2>Hey ' . esc_html($current_user->display_name) . ' 👋</h2>
                        <p>You are already logged in</p>

                        <a href="' . esc_url(home_url('/profile-page/' . rawurlencode($current_user->user_login))) . '" class="btn-primary">
                            Go to Profile
                        </a>

                    </div>

                </div>
            ';
        }

        ob_start(); ?>

        <div class="profile-registration-form-div">
            <form id="profile-registration-form" class="profile-registration-form" enctype="multipart/form-data">

                <h2>Create Your Account</h2>

                <div class="profile-registration-form-grid">

                    <!-- FULL WIDTH -->
                    <input type="email" name="email" placeholder="Email *" class="full-width" required>

                    <!-- ROW 1 -->
                    <input type="text" name="user_name" placeholder="User Name *" required>
                    <select name="gender" required>
                        <option value="">Select Gender *</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>

                    <!-- ROW 2 -->
                    <input type="text" name="first_name" placeholder="First Name *" required>
                    <input type="text" name="last_name" placeholder="Last Name *" required>

                    <!-- ROW 3 -->
                    <input type="text" name="phone" placeholder="Phone *" required>
<input type="text" name="birthdate" placeholder="Date of Birth *" required
                           onfocus="(this.type='date')"
                           onblur="if(!this.value)this.type='text'">

                    <!-- ROW 4 -->
                    <input type="password" name="password" placeholder="Password * (min 8 characters)" minlength="8" required>
                    <input type="password" name="confirm_password" placeholder="Confirm Password *" required>

                    <!-- 🔥 Toggle Switch -->
                    <div class="password-toggle-wrapper full-width">
                        <label class="switch">
                            <input type="checkbox" id="toggle-passwords">
                            <span class="slider"></span>
                        </label>
                        <span class="toggle-label">Show Password</span>
                    </div>

                </div>

                <?php
                $captcha = new Nexora_ReCaptcha();
                echo $captcha->render();
                ?>

                <button type="submit" class="profile-registration-form-btn">Create Account</button>

                <div class="profile-registration-extra">
                    Already have an account? 
                    <a href="<?php echo esc_url(home_url('/login-page')); ?>">Login</a>
                </div>
            </form>
        </div>

        <?php
        return ob_get_clean();
    }

    public function registration_form_handle() {

        check_ajax_referer('profile_nonce', 'nonce');

        // Basic throttling: max 5 sign-ups per IP per hour
        if (Nexora_Rate_Limiter::blocked('register')) {
            wp_send_json_error('Too many registrations. Please try again later.');
        }

        $captcha = new Nexora_ReCaptcha();

        $result = $captcha->verify(sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'] ?? '')));

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        $email        = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $user_name    = sanitize_user(wp_unslash($_POST['user_name'] ?? ''), true);
        $password     = wp_unslash($_POST['password'] ?? '');
        $confirm_pass = wp_unslash($_POST['confirm_password'] ?? '');

        $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last_name  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $phone      = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $gender     = sanitize_text_field(wp_unslash($_POST['gender'] ?? ''));
        $birthdate  = sanitize_text_field(wp_unslash($_POST['birthdate'] ?? ''));

        if (empty($email) || empty($user_name) || empty($password) || empty($confirm_pass)) {
            wp_send_json_error('Required fields missing');
        }

        if (!is_email($email)) {
            wp_send_json_error('Invalid email address');
        }

        // The username is used in profile URLs and as a lookup key
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $user_name)) {
            wp_send_json_error('Username must be 3-30 characters (letters, numbers, . _ -)');
        }

        if (strlen($password) < 8) {
            wp_send_json_error('Password must be at least 8 characters');
        }

        if ($password !== $confirm_pass) {
            wp_send_json_error('Passwords do not match');
        }

        if (!in_array($gender, ['male', 'female', 'other', ''], true)) {
            $gender = '';
        }

        if ($birthdate !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $birthdate);

            if (!$dt || $dt->format('Y-m-d') !== $birthdate || $dt > new DateTime('today')) {
                wp_send_json_error('Invalid date of birth');
            }
        }

        if (username_exists($user_name) || email_exists($email)) {
            wp_send_json_error('User already exists');
        }

        Nexora_Rate_Limiter::hit('register');

        // Create WP User
        $wp_user_id = wp_create_user($user_name, $password, $email);

        if (is_wp_error($wp_user_id)) {
            wp_send_json_error('User creation failed');
        }

        wp_update_user([
            'ID'            => $wp_user_id,
            'user_nicename' => sanitize_title($user_name),
            'first_name'    => $first_name,
            'last_name'     => $last_name
        ]);

        // Create Profile CPT
        $post_id = wp_insert_post([
            'post_type'   => 'user_profile',
            'post_title'  => $user_name,
            'post_name'   => sanitize_title($user_name),
            'post_status' => 'publish',
            'post_author' => $wp_user_id
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            // Roll back so the user can try again
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($wp_user_id);
            wp_send_json_error('Profile creation failed');
        }

        update_post_meta($post_id, '_wp_user_id', $wp_user_id);

        // Save Meta
        update_post_meta($post_id, 'user_name', $user_name);
        update_post_meta($post_id, 'first_name', $first_name);
        update_post_meta($post_id, 'last_name', $last_name);
        update_post_meta($post_id, 'email', $email);
        update_post_meta($post_id, 'phone', $phone);
        update_post_meta($post_id, 'gender', $gender);
        update_post_meta($post_id, 'birthdate', $birthdate);

        // Link profile
        update_user_meta($wp_user_id, '_profile_id', $post_id);

        $this->nexora_send_admin_notification($user_name, $email, trim($first_name . ' ' . $last_name));

        // Auto Login
        wp_set_current_user($wp_user_id);
        wp_set_auth_cookie($wp_user_id);

        wp_send_json_success([
            'message'  => 'Registration successful',
            'redirect' => home_url('/profile-page/' . rawurlencode($user_name))
        ]);
    }

    function nexora_send_admin_notification($user_name, $email, $full_name) {

        $admin_email = get_option('default_admin_mail');

        // fallback (safety)
        if (empty($admin_email)) {
            $admin_email = get_option('admin_email');
        }

        $subject = '🚀 New User Registered on Nexora';

        $message = "
            <h2>New User Registration</h2>
            <p><strong>Username:</strong> " . esc_html($user_name) . "</p>
            <p><strong>Name:</strong> " . esc_html($full_name) . "</p>
            <p><strong>Email:</strong> " . esc_html($email) . "</p>
            <p><strong>Time:</strong> " . current_time('mysql') . "</p>
        ";

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($admin_email, $subject, $message, $headers);
    }
}