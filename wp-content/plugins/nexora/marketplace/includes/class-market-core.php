<?php
/**
 * class-market-core.php
 *
 * Boots the marketplace module:
 *   - Enqueues CSS + JS, localises nexora_market object
 *   - Hooks into nexora_marketplace_content filter to render the shell
 *   - Registers the WooCommerce order hooks
 *   - Registers the REST API webhook receiver endpoint
 *   - Registers all active API-source cron hooks
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_CORE {

    public function __construct() {

        add_action( 'wp_enqueue_scripts',      [ $this, 'enqueue_assets'   ] );
        add_filter( 'nexora_marketplace_content', [ $this, 'render_marketplace' ], 10, 2 );
        add_action( 'rest_api_init',           [ $this, 'register_webhook' ] );
        add_action( 'init',                    [ $this, 'boot_crons'       ] );

        /* Boot WooCommerce hooks */
        NEXORA_MARKET_WOOCOMMERCE::init();
    }

    /* =========================================================
       ASSETS
    ========================================================= */

    public function enqueue_assets() {

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
            'currency_sym'  => get_woocommerce_currency_symbol(),
            'cart_url'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
        ] );
    }

    /* =========================================================
       RENDER MARKETPLACE SHELL
    ========================================================= */

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
    ========================================================= */

    public function register_webhook() {

        register_rest_route( 'nexora/v1', '/webhook/product-update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true', // Auth done via HMAC inside callback
        ] );

        /* Generic endpoint with source_id for multi-vendor */
        register_rest_route( 'nexora/v1', '/webhook/(?P<source_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'source_id' => [
                    'required'          => true,
                    'validate_callback' => 'is_numeric',
                    'sanitize_callback' => 'intval',
                ],
            ],
        ] );
    }

    /**
     * Receive an incoming webhook from a vendor's system.
     *
     * Expected payload (JSON body):
     * {
     *   "event": "product.updated",        // or product.created / product.deleted
     *   "product": {
     *     "id": "remote-sku-123",
     *     "name": "Product Title",
     *     "price": 999.00,
     *     "stock_quantity": 45
     *   }
     * }
     *
     * Header: X-Nexora-Signature: sha256=<hmac>
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {

        global $wpdb;

        $source_id = (int) $request->get_param( 'source_id' );
        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'x-nexora-signature' ) ?? '';

        /* ── Load source record ───────────────────────────── */
        $source = null;

        if ( $source_id ) {
            $source = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}nx_api_sources WHERE id = %d AND status = 'active'",
                    $source_id
                ),
                ARRAY_A
            );
        }

        /* ── Verify HMAC signature ────────────────────────── */
        if ( $source && ! empty( $source['webhook_secret'] ) ) {
            if ( ! NEXORA_MARKET_HELPER::verify_webhook_signature( $raw_body, $signature, $source['webhook_secret'] ) ) {
                return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 403 );
            }
        }

        /* ── Parse payload ────────────────────────────────── */
        $payload = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid JSON payload.' ], 400 );
        }

        $event   = sanitize_text_field( $payload['event']   ?? 'product.updated' );
        $item    = $payload['product'] ?? $payload['data'] ?? null;

        if ( ! is_array( $item ) ) {
            return new WP_REST_Response( [ 'error' => 'Missing product data.' ], 400 );
        }

        $external_id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
        $table       = $wpdb->prefix . 'nx_products';

        /* ── Route by event type ──────────────────────────── */
        switch ( $event ) {

            case 'product.deleted':
            case 'product.removed':

                if ( $external_id && $source_id ) {
                    $wpdb->update(
                        $table,
                        [ 'status' => 'inactive' ],
                        [ 'external_id' => $external_id, 'api_source_id' => $source_id ]
                    );
                }
                break;

            case 'product.created':
            case 'product.updated':
            default:

                /* Re-use the full sync logic for a single item */
                if ( $source_id ) {
                    // For webhook updates we just trigger a targeted sync on this source
                    // so all other products stay in sync too. For high-volume scenarios
                    // you'd upsert just this one item instead.
                    wp_schedule_single_event( time(), "nexora_market_cron_sync_{$source_id}" );
                }
                break;
        }

        /* ── Log the incoming webhook ─────────────────────── */
        $user_id = $source ? (int) $source['user_id'] : 0;
        NEXORA_MARKET_HELPER::log_activity( $user_id, 'webhook_received', [
            'source_id'   => $source_id,
            'event'       => $event,
            'external_id' => $external_id,
        ] );

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /* =========================================================
       CRON BOOT  (register all active source hooks on every init)
    ========================================================= */

    public function boot_crons() {
        NEXORA_MARKET_HELPER::register_cron_hooks();
    }
}
