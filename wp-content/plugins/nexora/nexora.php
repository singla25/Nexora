<?php
/**
 * Plugin Name: Nexora
 * Description: Handles User Registration, Login, Profile Dashboard and User Connections
 * Version: 1.0
 * Author: Sahil Singla
 */

if (!defined('ABSPATH')) exit;

define('NEXORA_PATH', plugin_dir_path(__FILE__));
define('NEXORA_URL', plugin_dir_url(__FILE__));
define('NEXORA_VERSION', '1.0.1');

require_once NEXORA_PATH . 'includes/class-cpt.php';
require_once NEXORA_PATH . 'includes/class-registration.php';
require_once NEXORA_PATH . 'includes/class-profile-page.php';
require_once NEXORA_PATH . 'includes/class-profile-ajax.php';
require_once NEXORA_PATH . 'includes/class-profile-helper.php';
require_once NEXORA_PATH . 'includes/class-login.php';
require_once NEXORA_PATH . 'includes/class-home-page.php';
require_once NEXORA_PATH . 'includes/class-notification.php';
require_once NEXORA_PATH . 'includes/class-better-message-chat.php';
require_once NEXORA_PATH . 'includes/class-google-recaptcha.php';

require_once NEXORA_PATH . 'chat/class-chat-core.php';

class NEXORA_System {

    public function __construct() {

        // INIT MODULES
        new NEXORA_Registration();
        new NEXORA_Login();
        new NEXORA_CPT();
        new NEXORA_PROFILE_PAGE();
        new NEXORA_PROFILE_AJAX();  
        new Nexora_Home_Page();
        new Nexora_Better_Message_CHAT_Page();
        new NEXORA_CHAT_CORE();
        new Nexora_ReCaptcha();

        // GLOBAL ASSETS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // ACCESS CONTROL
        add_action('after_setup_theme', [$this, 'hide_admin_bar']);
        add_action('admin_init', [$this, 'block_wp_admin']);
        add_action('init', [$this, 'block_wp_login']);

        // LOGIN FLOW
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);

        // ACTIVATION
        register_activation_hook(__FILE__, [$this, 'create_new_tables']);
    }

    // ===============================
    // GLOBAL ASSETS
    // ===============================
    public function enqueue_assets() {

        self::enqueue_tokens();

        wp_enqueue_style(
            'profile-global-style',
            NEXORA_URL . 'assets/css/style.css',
            ['nexora-tokens'],
            NEXORA_VERSION
        );
    }

    /**
     * Shared design tokens (+ the brand font when the Nexora theme, which
     * already loads it, is not active). Safe to call repeatedly.
     */
    public static function enqueue_tokens() {

        $deps = [];

        if (get_template() !== 'nexora-theme') {
            wp_enqueue_style(
                'nexora-font',
                'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
                [],
                null
            );
            $deps[] = 'nexora-font';
        }

        wp_enqueue_style('nexora-tokens', NEXORA_URL . 'assets/css/tokens.css', $deps, NEXORA_VERSION);
    }

    // ===============================
    // HIDE ADMIN BAR (ONLY ADMIN)
    // ===============================
    public function hide_admin_bar() {

        if (!current_user_can('manage_options')) {
            show_admin_bar(false);
        }
    }

    // ===============================
    // BLOCK WP-ADMIN (NON-ADMINS)
    // ===============================
    public function block_wp_admin() {

        // Allow AJAX
        if (wp_doing_ajax()) return;

        // Allow REST
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        // Allow WP-Cron and form handlers that must work for visitors
        if (wp_doing_cron()) return;

        $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';

        if (in_array($script, ['admin-post.php', 'admin-ajax.php'], true)) return;

        // Not logged in → redirect to login page
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/login-page'));
            exit;
        }

        // Logged in but NOT admin → block wp-admin
        if (!current_user_can('manage_options')) {
            $username = wp_get_current_user()->user_login;
            wp_safe_redirect(home_url('/profile-page/' . rawurlencode($username)));
            exit;
        }
    }

    // ===============================
    // BLOCK WP-LOGIN (NON-ADMINS)
    // ===============================
    public function block_wp_login() {

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

        // Only act on wp-login.php
        if (strpos($request_uri, 'wp-login.php') === false) {
            return;
        }

        // Allow logout, password reset links and post-password forms
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if (in_array($action, ['logout', 'postpass'], true)) {
            return;
        }

        // Admins may use wp-login.php
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }

        // Anyone else is sent to the custom pages
        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/login-page'));
            exit;
        }

        $username = wp_get_current_user()->user_login;
        wp_safe_redirect(home_url('/profile-page/' . rawurlencode($username)));
        exit;
    }

    // ===============================
    // LOGIN REDIRECT (WP DEFAULT)
    // ===============================
    public function login_redirect($redirect_to, $request, $user) {

        // Failed login / no user yet: leave WordPress' default untouched
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        if (user_can($user, 'manage_options')) {
            return home_url('/profile-page'); // Admin UI
        }

        return home_url('/profile-page/' . rawurlencode($user->user_login));
    }

    // ===============================
    // CREATE NOTIFICATION TABLE
    // ===============================
    public function create_new_tables() {

        // Notification Table
        $notification = new NEXORA_Notification();
        $notification->create_table();

        // Chat Tables
        require_once NEXORA_PATH . 'chat/class-chat-db.php';
        $chat_db = new NEXORA_CHAT_DB();
        $chat_db->create_table();
    }
}

new NEXORA_System();