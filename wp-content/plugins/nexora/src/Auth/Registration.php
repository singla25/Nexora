<?php

namespace Nexora\Auth;

use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;

if (!defined('ABSPATH')) exit;

class Registration {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_registration', [$this, 'registration_form']);

        \Nexora\Http\Ajax::register('profile_register', [$this, 'registration_form_handle'], true);
    }

    public function enqueue_assets() {

        // Only the registration page needs these scripts (and the guest nonce)
        if (!\Nexora\Core\Assets::is_page_for('registration-page', 'profile_registration')) {
            return;
        }

        \Nexora\Core\Assets::enqueue_tokens();
        wp_enqueue_style('profile-style', NEXORA_URL . 'assets/css/profile-registration.css', ['nexora-tokens'], NEXORA_VERSION);

        \Nexora\Core\Assets::enqueue_sweetalert();

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
        $captcha = new \Nexora\Auth\Recaptcha();
        $captcha->enqueue_script();
    }

    public function registration_form() {

        // Already logged in
        if (is_user_logged_in()) {

            $current_user = wp_get_current_user();

            return View::render('auth/registration-state', [
                'user'        => $current_user,
                'profile_url' => Urls::profile($current_user->user_login),
            ]);
        }

        $captcha = new Recaptcha();

        return View::render('auth/registration-form', [
            'captcha_html' => $captcha->render(),
            'login_url'    => Urls::login(),
        ]);
    }

    public function registration_form_handle() {

        check_ajax_referer('profile_nonce', 'nonce');

        // Basic throttling: max 5 sign-ups per IP per hour
        if (Rate_Limiter::blocked('register')) {
            wp_send_json_error('Too many registrations. Please try again later.');
        }

        $captcha = new Recaptcha();

        $result = $captcha->verify(sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'] ?? '')));

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        $input = $this->read_input();

        $this->validate($input);

        Rate_Limiter::hit('register');

        $this->create_member($input);

        wp_send_json_success([
            'message'  => 'Registration successful',
            'redirect' => Urls::profile($input['user_name'])
        ]);
    }

    /**
     * Sanitised copy of the submitted form.
     */
    private function read_input() {

        return [
            'email'        => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'user_name'    => sanitize_user(wp_unslash($_POST['user_name'] ?? ''), true),
            'password'     => wp_unslash($_POST['password'] ?? ''),
            'confirm_pass' => wp_unslash($_POST['confirm_password'] ?? ''),
            'first_name'   => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'    => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'phone'        => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'gender'       => sanitize_text_field(wp_unslash($_POST['gender'] ?? '')),
            'birthdate'    => sanitize_text_field(wp_unslash($_POST['birthdate'] ?? '')),
        ];
    }

    /**
     * Ends the request with a JSON error for the first problem found.
     * An unknown gender is not an error: it is dropped.
     */
    private function validate(array &$input) {

        if (empty($input['email']) || empty($input['user_name']) || empty($input['password']) || empty($input['confirm_pass'])) {
            wp_send_json_error('Required fields missing');
        }

        if (!is_email($input['email'])) {
            wp_send_json_error('Invalid email address');
        }

        // The username is used in profile URLs and as a lookup key
        if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $input['user_name'])) {
            wp_send_json_error('Username must be 3-30 characters (letters, numbers, . _ -)');
        }

        if (strlen($input['password']) < 8) {
            wp_send_json_error('Password must be at least 8 characters');
        }

        if ($input['password'] !== $input['confirm_pass']) {
            wp_send_json_error('Passwords do not match');
        }

        if (!in_array($input['gender'], ['male', 'female', 'other', ''], true)) {
            $input['gender'] = '';
        }

        if ($input['birthdate'] !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d', $input['birthdate']);

            if (!$dt || $dt->format('Y-m-d') !== $input['birthdate'] || $dt > new \DateTime('today')) {
                wp_send_json_error('Invalid date of birth');
            }
        }

        if (username_exists($input['user_name']) || email_exists($input['email'])) {
            wp_send_json_error('User already exists');
        }
    }

    /**
     * Creates the WP user and the linked user_profile post, notifies the admin and logs the
     * new member in. Rolls the user back (and ends the request) if the profile cannot be created.
     */
    private function create_member(array $input) {

        // Create WP User
        $wp_user_id = wp_create_user($input['user_name'], $input['password'], $input['email']);

        if (is_wp_error($wp_user_id)) {
            wp_send_json_error('User creation failed');
        }

        wp_update_user([
            'ID'            => $wp_user_id,
            'user_nicename' => sanitize_title($input['user_name']),
            'first_name'    => $input['first_name'],
            'last_name'     => $input['last_name']
        ]);

        // Create Profile CPT
        $post_id = wp_insert_post([
            'post_type'   => 'user_profile',
            'post_title'  => $input['user_name'],
            'post_name'   => sanitize_title($input['user_name']),
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
        update_post_meta($post_id, 'user_name', $input['user_name']);
        update_post_meta($post_id, 'first_name', $input['first_name']);
        update_post_meta($post_id, 'last_name', $input['last_name']);
        update_post_meta($post_id, 'email', $input['email']);
        update_post_meta($post_id, 'phone', $input['phone']);
        update_post_meta($post_id, 'gender', $input['gender']);
        update_post_meta($post_id, 'birthdate', $input['birthdate']);

        // Link profile
        update_user_meta($wp_user_id, '_profile_id', $post_id);

        $this->send_admin_notification($input['user_name'], $input['email'], trim($input['first_name'] . ' ' . $input['last_name']));

        // Auto Login
        wp_set_current_user($wp_user_id);
        wp_set_auth_cookie($wp_user_id);
    }

    private function send_admin_notification($user_name, $email, $full_name) {

        $admin_email = get_option('default_admin_mail');

        // fallback (safety)
        if (empty($admin_email)) {
            $admin_email = get_option('admin_email');
        }

        wp_mail(
            $admin_email,
            '🚀 New User Registered on Nexora',
            View::render('mail/admin-new-member', [
                'user_name' => $user_name,
                'full_name' => $full_name,
                'email'     => $email,
                'time'      => current_time('mysql'),
            ]),
            ['Content-Type: text/html; charset=UTF-8']
        );
    }
}
