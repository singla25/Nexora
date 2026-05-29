<?php
/**
 * includes/class-market-core.php
 *
 * Boots the marketplace module:
 *   - Enqueues CSS + JS, localises nexora_market object
 *   - Hooks into nexora_marketplace_content filter to render the shell
 *   - Boots WooCommerce hooks
 *   - Boots wp.media upload integration (NEXORA_MARKET_UPLOAD)
 *   - Registers the REST API webhook endpoint
 *   - Re-registers all active API-source cron hooks on init
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_CORE {

    public function __construct() {

        add_action( 'wp_enqueue_scripts',          [ $this, 'enqueue_assets'     ] );
        add_filter( 'nexora_marketplace_content',  [ $this, 'render_marketplace' ], 10, 2 );
        add_action( 'rest_api_init',               [ $this, 'register_webhook'  ] );
        add_action( 'init',                        [ $this, 'boot_crons'        ] );

        // Boot WooCommerce order / stock hooks
        NEXORA_MARKET_WOOCOMMERCE::init();

        // Boot wp.media upload scoping + REST confirm endpoint
        NEXORA_MARKET_UPLOAD::init();
    }

    /* =========================================================
       ASSETS
    ========================================================= */

    public function enqueue_assets(): void {

        wp_enqueue_style(
            'nexora-market-css',
            NEXORA_MARKETPLACE_URL . 'assets/market.css',
            [],
            NEXORA_MARKETPLACE_VERSION
        );

        wp_enqueue_script(
            'nexora-market-js',
            NEXORA_MARKETPLACE_URL . 'assets/market.js',
            [ 'jquery' ],
            NEXORA_MARKETPLACE_VERSION,
            true
        );

        wp_localize_script( 'nexora-market-js', 'nexora_market', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'nexora_market_nonce' ),
            'rest_url'      => rest_url( 'nexora/v1/' ),
            'rest_nonce'    => wp_create_nonce( 'wp_rest' ),
            'current_user'  => get_current_user_id(),
            'currency'      => get_option( 'woocommerce_currency', 'INR' ),
            'currency_sym'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '₹',
            'cart_url'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
            // Tells market.js the user can upload (used to conditionally
            // show / hide the wp.media buttons in the add-product form)
            'can_upload'    => current_user_can( 'upload_files' ) ? 1 : 0,
        ] );
    }

    /* =========================================================
       RENDER MARKETPLACE SHELL
    ========================================================= */

    /**
     * Called via apply_filters( 'nexora_marketplace_content', '', $context ).
     *
     * @param  string $html
     * @param  array  $context
     * @return string
     */
    public function render_marketplace( string $html, array $context ): string {

        ob_start();

        $current_user = wp_get_current_user();

        if ( current_user_can( 'manage_options' ) ) {
            $role_type = 'admin';
        } elseif ( in_array( 'vendor', (array) $current_user->roles, true ) ) {
            $role_type = 'vendor';
        } else {
            $role_type = 'user';
        }

        include NEXORA_MARKETPLACE_TEMPLATES . 'marketplace-shell.php';

        return ob_get_clean();
    }

    /* =========================================================
       WEBHOOK REST ENDPOINT
       POST /wp-json/nexora/v1/webhook/product-update
       POST /wp-json/nexora/v1/webhook/{source_id}
    ========================================================= */

    public function register_webhook(): void {

        // Generic legacy endpoint (no source_id)
        register_rest_route( 'nexora/v1', '/webhook/product-update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // Per-source endpoint (recommended — enables HMAC verification)
        register_rest_route( 'nexora/v1', '/webhook/(?P<source_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
            'args' => [
                'source_id' => [
                    'required'          => true,
                    'validate_callback' => 'is_numeric',
                    'sanitize_callback' => 'intval',
                ],
            ],
        ] );
    }

    /**
     * Receive an incoming webhook and delegate to NEXORA_MARKET_API.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {

        $source_id = (int) $request->get_param( 'source_id' );
        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'x-nexora-signature' ) ?? '';

        $result = NEXORA_MARKET_API::handle_webhook( $source_id, $raw_body, $signature );

        return new WP_REST_Response(
            [ $result['success'] ? 'received' : 'error' => $result['success'] ? true : $result['message'] ],
            $result['status']
        );
    }

    /* =========================================================
       CRON BOOT
    ========================================================= */

    public function boot_crons(): void {
        NEXORA_MARKET_HELPER::register_cron_hooks();
    }
}
