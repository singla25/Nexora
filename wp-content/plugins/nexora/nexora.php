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

// ✅ ADD THESE
define('NEXORA_VERSION', '1.0.0');
define('NEXORA_DASHBOARD_TEMPLATES', NEXORA_PATH . 'dashboard/templates/');

require_once NEXORA_PATH . 'includes/class-home-page.php';
require_once NEXORA_PATH . 'includes/class-cpt.php';
require_once NEXORA_PATH . 'includes/class-login.php';
require_once NEXORA_PATH . 'includes/class-registration.php';
require_once NEXORA_PATH . 'includes/class-vendor-registration.php';

// require_once NEXORA_PATH . 'includes/class-profile-page.php';
// require_once NEXORA_PATH . 'includes/class-profile-ajax.php';
// require_once NEXORA_PATH . 'includes/class-profile-helper.php';

require_once NEXORA_PATH . 'dashboard/dashboard.php';

require_once NEXORA_PATH . 'chat/class-chat-core.php';
// require_once NEXORA_PATH . 'includes/class-better-message-chat.php';
require_once NEXORA_PATH . 'includes/class-notification.php';
require_once NEXORA_PATH . 'includes/class-google-recaptcha.php';

class NEXORA_System {

    public function __construct() {

        // INIT MODULES
        new Nexora_Home_Page();
        new NEXORA_CPT();
        
        new NEXORA_Login();
        new NEXORA_Registration();
        new NEXORA_Vendor_Registration();
        
        // new Nexora_Better_Message_CHAT_Page();
        new NEXORA_CHAT_CORE();
        new Nexora_ReCaptcha();

        // new NEXORA_PROFILE_PAGE();
        // new NEXORA_PROFILE_AJAX(); 

        // new NEXORA_DASHBOARD_CORE();
        

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
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        // Allow REST
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        // Not logged in → redirect to login page
        if (!is_user_logged_in()) {
            wp_redirect(home_url('/login-page'));
            exit;
        }

        // Logged in but NOT admin → block wp-admin
        if (!current_user_can('manage_options') && is_admin()) {
            wp_redirect(home_url('/dashboard/' . wp_get_current_user()->user_login));
            exit;
        }

        // ✅ Admin allowed freely
    }

    // ===============================
    // BLOCK WP-ADMIN (NON-ADMINS)
    // ===============================
    public function block_wp_login() {

        // Allow logout
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            return;
        }

        // Allow admin to access wp-login if already logged in
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }

        // Target wp-login.php
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {

            // If NOT logged in → redirect to custom login
            if (!is_user_logged_in()) {
                wp_redirect(home_url('/login-page'));
                exit;
            }

            // If logged in but non-admin
            if (!current_user_can('manage_options')) {
                wp_redirect(home_url('/dashboard'));
                exit;
            }
        }
    }

    // ===============================
    // LOGIN REDIRECT (WP DEFAULT)
    // ===============================
    public function login_redirect($redirect_to, $request, $user) {

        if (isset($user->roles) && in_array('administrator', $user->roles)) {
            return home_url('/dashboard'); // Admin UI
        }

        return home_url('/dashboard/' . $user->user_login);
    }

    // ===============================
    // CREATE NOTIFICATION TABLE
    // ===============================
    public function create_new_tables() {

        // CREATE VENDOR ROLE
        if (!get_role('vendor')) {
            add_role('vendor', 'Vendor', [
                'read' => true,
                'upload_files' => true,
                'edit_posts' => true,
                'delete_posts' => false,
            ]);
        }

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