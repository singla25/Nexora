<?php
/**
 * Plugin Name: Nexora
 * Description: Handles User Registration and Log In, Profile Dashboard and Connection between two other User
 * Version: 1.0
 * Author: Sahil Singla
 */

if (!defined('ABSPATH')) exit;

define('NEXORA_PATH', plugin_dir_path(__FILE__));
define('NEXORA_URL', plugin_dir_url(__FILE__));

require_once NEXORA_PATH . 'includes/class-cpt.php';
require_once NEXORA_PATH . 'includes/class-registration.php';
require_once NEXORA_PATH . 'includes/class-profile-page.php';
require_once NEXORA_PATH . 'includes/class-login.php';
require_once NEXORA_PATH . 'includes/class-home-page.php';
require_once NEXORA_PATH . 'includes/class-notification.php';

class NEXORA_System {

    public function __construct() {

        new NEXORA_Registration();
        new NEXORA_Login();
        new NEXORA_CPT();
        new NEXORA_Page();
        new Nexora_Home_Page();

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // ACCESS CONTROL
        add_action('after_setup_theme', [$this, 'hide_admin_bar']);
        add_action('admin_init', [$this, 'block_wp_admin']);
        add_action('init', [$this, 'block_wp_login']);

        // LOGIN FLOW
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);

        // ACTIVATION
        register_activation_hook(__FILE__, [$this, 'notification_table']);
    }

    // ===============================
    // ASSETS
    // ===============================
    public function enqueue_assets() {

        wp_enqueue_style(
            'profile-global-style',
            NEXORA_URL . 'assets/css/style.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'profile-global-js',
            NEXORA_URL . 'assets/js/script.js',
            ['jquery'],
            '1.0',
            true
        );
    }

    // ===============================
    // HIDE ADMIN BAR (ONLY ADMIN CAN SEE)
    // ===============================
    public function hide_admin_bar() {

        if (!current_user_can('manage_options')) {
            show_admin_bar(false);
        }
    }

    // ===============================
    // BLOCK WP-ADMIN
    // ===============================
    public function block_wp_admin() {

        // Emergency access
        if (isset($_GET['admin_access']) && $_GET['admin_access'] === 'true') {
            return;
        }

        // allow AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        // allow REST
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        // Not logged in
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login-page'));
            exit;
        }

        // Logged in but NOT admin
        if (!current_user_can('manage_options') && is_admin()) {
            wp_redirect(home_url('/profile-page'));
            exit;
        }

        // ✅ Admin allowed
    }

    // ===============================
    // BLOCK WP-LOGIN
    // ===============================
    public function block_wp_login() {

        // VERY IMPORTANT: Allow logout action
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }

        if (isset($_GET['admin_access']) && $_GET['admin_access'] === 'true') {
            return;
        }

        // Only target wp-login.php
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {

            if (is_user_logged_in()) {

                if (current_user_can('manage_options')) {
                    return;
                }

                wp_redirect(home_url('/profile-page'));
                exit;
            }

            wp_redirect(home_url('/login-page'));
            exit;
        }
    }

    // ===============================
    // LOGIN REDIRECT
    // ===============================
    public function login_redirect($redirect_to, $request, $user) {

        // Admin → dashboard
        if (isset($user->roles) && in_array('administrator', $user->roles)) {
            return admin_url();
        }

        // Other users → profile page
        return home_url('/profile-page');
    }

    // ===============================
    // CREATE NOTIFICATION TABLE
    // ===============================
    public function notification_table() {
        $notification = new NEXORA_Notification();
        $notification->create_table();
    }
}

new NEXORA_System();