<?php

class NEXORA_Registration {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_registration', [$this, 'registration_form']);

        add_action('wp_ajax_profile_register', [$this, 'registration_form_handle']);
        add_action('wp_ajax_nopriv_profile_register', [$this, 'registration_form_handle']);
    }

    public function enqueue_assets() {

        wp_enqueue_style('profile-style', NEXORA_URL . 'assets/css/profile-registration.css');

        wp_enqueue_script(
            'sweetalert2',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'profile-registration',
            NEXORA_URL . 'assets/js/profile-registration.js',
            ['jquery', 'sweetalert2'],
            null,
            true
        );

        wp_localize_script('profile-registration', 'profileData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('profile_nonce')
        ]);
    }

    public function registration_form() {

        // LOGIN CHECK HERE
        if (is_user_logged_in()) {

            $current_user = wp_get_current_user();

            return '
                <div class="register-state-wrapper">

                    <div class="register-state-card">

                        <div class="register-avatar">
                            <span>' . strtoupper(substr($current_user->display_name, 0, 1)) . '</span>
                        </div>

                        <h2>Hey ' . esc_html($current_user->display_name) . ' 👋</h2>
                        <p>You are already logged in</p>

                        <a href="' . home_url('/profile-page/' . $current_user->user_login) . '" class="btn-primary">
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
                    <input type="date" name="birthdate" required>

                    <!-- ROW 4 -->
                    <input type="password" name="password" placeholder="Password *" required>
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
                    <a href="<?php echo home_url('/login-page'); ?>">Login</a>
                </div>
            </form>
        </div>

        <?php
        return ob_get_clean();
    }

    public function registration_form_handle() {

        check_ajax_referer('profile_nonce', 'nonce');

        $captcha = new Nexora_ReCaptcha();

        $result = $captcha->verify($_POST['g-recaptcha-response'] ?? '');

        if (!$result['success']) {
            error_log($result['message']); // debug
            wp_send_json_error($result['message']);
        }

        $data = $_POST;

        $email = sanitize_email($data['email'] ?? '');
        $user_name = sanitize_user($data['user_name'] ?? '');
        $password = $data['password'] ?? '';
        $confirm_pass = $data['confirm_password'] ?? '';

        if (empty($email) || empty($user_name) || empty($password) || empty($confirm_pass)) {
            wp_send_json_error('Required fields missing');
        }

        if ($password !== $confirm_pass) {
            wp_send_json_error('Passwords do not match');
        }

        if (username_exists($user_name) || email_exists($email)) {
            wp_send_json_error('User already exists');
        }

        // Create WP User
        $wp_user_id = wp_create_user($user_name, $password, $email);

        if (is_wp_error($wp_user_id)) {
            wp_send_json_error('User creation failed');
        }

        // 🔥 SEND ADMIN EMAIL
        $first_name = sanitize_text_field($data['first_name'] ?? '');
        $last_name  = sanitize_text_field($data['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        $this->nexora_send_admin_notification($user_name, $email, $full_name);

        wp_update_user([
            'ID' => $wp_user_id,
            'user_nicename' => $user_name,
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($data['last_name'] ?? '')
        ]);

        // Create Profile CPT
        $post_id = wp_insert_post([
            'post_type' => 'user_profile',
            'post_title' => $user_name,
            'post_name'  => sanitize_title($user_name),
            'post_status' => 'publish',
            'post_author' => $wp_user_id
        ]);

        update_post_meta($post_id, '_wp_user_id', $wp_user_id);

        // Save Meta
        update_post_meta($post_id, 'user_name', sanitize_text_field($data['user_name']));
        update_post_meta($post_id, 'first_name', $first_name);
        update_post_meta($post_id, 'last_name', $last_name);
        update_post_meta($post_id, 'email', $email);
        update_post_meta($post_id, 'phone', sanitize_text_field($data['phone']));
        update_post_meta($post_id, 'gender', sanitize_text_field($data['gender']));
        update_post_meta($post_id, 'birthdate', sanitize_text_field($data['birthdate']));

        // Link profile
        update_user_meta($wp_user_id, '_profile_id', $post_id);

        // Auto Login
        wp_set_current_user($wp_user_id);
        wp_set_auth_cookie($wp_user_id);

        $username = get_post_meta($post_id, 'user_name', true);

        wp_send_json_success([
            'message' => 'Registration successful',
            'redirect' => home_url('/profile-page/' . $username)
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
            <p><strong>Username:</strong> {$user_name}</p>
            <p><strong>Name:</strong> {$full_name}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Time:</strong> " . current_time('mysql') . "</p>
        ";

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($admin_email, $subject, $message, $headers);
    }
}