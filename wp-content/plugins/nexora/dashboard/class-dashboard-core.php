<?php
/**
 * NEXORA_DASHBOARD_CORE
 *
 * Responsibilities (and ONLY these):
 *   - Enqueue CSS / JS and localise the JS payload
 *   - Register rewrite rules and the 'username' query var
 *   - Register the [nexora_dashboard] shortcode
 *   - Run gate checks (guest-no-url / admin / profile-not-found)
 *   - Delegate rendering to templates
 *
 * No business logic lives here — it all lives in NEXORA_DASHBOARD_HELPER.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_DASHBOARD_CORE {

    public function __construct() {
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'init',                [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars',          [ $this, 'register_query_vars' ] );
        add_shortcode( 'nexora_dashboard', [ $this, 'render' ] );
    }

    /* =========================================================================
       ASSETS
    ========================================================================= */

    public function enqueue_assets(): void {

        wp_enqueue_style(
            'nexora-dashboard',
            NEXORA_URL . 'dashboard/assets/css/dashboard.css',
            [],
            NEXORA_VERSION
        );

        wp_enqueue_script(
            'sweetalert2',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'nexora-dashboard-js',
            NEXORA_URL . 'dashboard/assets/js/dashboard.js',
            [ 'jquery', 'sweetalert2' ],
            NEXORA_VERSION,
            true
        );

        wp_enqueue_media();

        if ( is_page( 'dashboard' ) ) {
            wp_localize_script( 'nexora-dashboard-js', 'nexoraDashboard', $this->build_js_payload() );
        }
    }

    private function build_js_payload(): array {

        $ctx = NEXORA_DASHBOARD_HELPER::resolve_context();

        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'profile_nonce' ),
            'homeUrl'       => home_url(),
            'currentUserId' => get_current_user_id(),
            'roleType'      => $ctx['role_type'],
            'profileRole'   => $ctx['profile_role'],
            'viewerRole'    => $ctx['viewer_role'],
            'isOwner'       => $ctx['is_owner'],
            'isLoggedIn'    => $ctx['is_logged_in'],
            'userData'      => NEXORA_DASHBOARD_HELPER::build_user_data( $ctx['profile_id'] ),
            'visibleTabs'   => NEXORA_DASHBOARD_HELPER::get_visible_tabs( $ctx ),
        ];
    }

    /* =========================================================================
       ROUTING
    ========================================================================= */

    public function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^dashboard/([^/]+)/?$',
            'index.php?pagename=dashboard&username=$matches[1]',
            'top'
        );
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'username';
        return $vars;
    }

    /* =========================================================================
       SHORTCODE ENTRY POINT
    ========================================================================= */

    public function render(): string {

        if ( ! is_page( 'dashboard' ) ) return '';

        // Fix #5: If a logged-in user (subscriber OR vendor) hits /dashboard
        // with no username in the URL, redirect them to their own profile URL.
        $username = get_query_var( 'username' );
        if ( ! $username && is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
            $user_id    = get_current_user_id();
            $profile_id = (int) get_user_meta( $user_id, '_profile_id', true );
            if ( ! $profile_id ) {
                $profile_id = (int) get_user_meta( $user_id, '_vendor_profile_id', true );
            }
            if ( $profile_id ) {
                $own_username = get_post_meta( $profile_id, 'user_name', true );
                if ( $own_username ) {
                    wp_safe_redirect( home_url( '/dashboard/' . rawurlencode( $own_username ) ) );
                    exit;
                }
            }
        }

        $context = NEXORA_DASHBOARD_HELPER::resolve_context();

        $gate = $this->gate_check( $context );
        if ( $gate !== null ) return $gate;

        return $this->render_partial( 'dashboard-main.php', $context );
    }

    /* =========================================================================
       GATE CHECKS
    ========================================================================= */

    private function gate_check( array $ctx ): ?string {

        $username = get_query_var( 'username' );

        // Guest with no URL username → login prompt
        if ( $ctx['role_type'] === 'guest' && ! $username ) {
            return $this->render_partial( 'partials/gate-login.php', $ctx );
        }

        // Admin → redirect hint screen
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return $this->render_partial( 'partials/gate-admin.php', $ctx );
        }

        // Profile not found
        if ( empty( $ctx['profile_id'] ) ) {
            return $this->render_partial( 'partials/gate-not-found.php', $ctx );
        }

        return null;
    }

    /* =========================================================================
       PARTIAL LOADER
    ========================================================================= */

    private function render_partial( string $template, array $ctx = [] ): string {
        ob_start();
        extract( $ctx, EXTR_SKIP );
        $context = $ctx;
        include NEXORA_DASHBOARD_TEMPLATES . $template;
        return ob_get_clean();
    }
}
