<?php
/**
 * includes/class-market-ajax.php
 *
 * Every wp_ajax_* handler for the marketplace.
 *
 * Registered actions:
 *   nexora_market_tab             — load any tab view
 *   nexora_market_add_manual      — create product (manual form + wp.media images)
 *   nexora_market_csv_import      — bulk import from CSV upload
 *   nexora_market_api_import      — save API source + trigger first sync
 *   nexora_market_api_sync        — manually re-sync one API source
 *   nexora_market_update_product  — inline price / stock / field edit
 *   nexora_market_delete_product  — soft-delete (status → inactive)
 *   nexora_market_single_product  — return single-product view HTML
 *   nexora_market_add_to_cart     — add WC product to cart
 *
 * All business logic lives in the dedicated classes (NEXORA_MARKET_PRODUCT,
 * NEXORA_MARKET_CSV, NEXORA_MARKET_API). This class only validates, unpacks
 * POST data, calls the right class, and sends JSON responses.
 *
 * Depends on: NEXORA_MARKET_DB, NEXORA_MARKET_PRODUCT, NEXORA_MARKET_CSV,
 *             NEXORA_MARKET_API, NEXORA_MARKET_HELPER, NEXORA_MARKET_WOOCOMMERCE
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_AJAX {

    public function __construct() {

        // Tab loader
        add_action( 'wp_ajax_nexora_market_tab',            [ $this, 'market_tab'     ] );

        // Product CRUD
        add_action( 'wp_ajax_nexora_market_add_manual',     [ $this, 'add_manual'     ] );
        add_action( 'wp_ajax_nexora_market_csv_import',     [ $this, 'csv_import'     ] );
        add_action( 'wp_ajax_nexora_market_api_import',     [ $this, 'api_import'     ] );
        add_action( 'wp_ajax_nexora_market_api_sync',       [ $this, 'api_sync'       ] );
        add_action( 'wp_ajax_nexora_market_update_product', [ $this, 'update_product' ] );
        add_action( 'wp_ajax_nexora_market_delete_product', [ $this, 'delete_product' ] );

        // Single product view + cart
        add_action( 'wp_ajax_nexora_market_single_product', [ $this, 'single_product' ] );
        add_action( 'wp_ajax_nexora_market_add_to_cart',    [ $this, 'add_to_cart'    ] );
    }

    /* =========================================================
       SHARED AUTH
    ========================================================= */

    private function auth(): void {
        check_ajax_referer( 'nexora_market_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
        }
    }

    /* =========================================================
       TAB VIEW LOADER
    ========================================================= */

    public function market_tab(): void {

        $this->auth();

        $type = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'browse' ) );

        $map = [
            'browse'      => 'browse-products.php',
            'add-product' => 'add-product.php',
            'my-products' => 'my-products.php',
            'orders'      => 'market-orders.php',
            'earnings'    => 'market-earnings.php',
            'history'     => 'market-history.php',
        ];

        $file = $map[ $type ] ?? 'browse-products.php';

        ob_start();
        include NEXORA_MARKETPLACE_TEMPLATES . $file;
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /* =========================================================
       SINGLE PRODUCT VIEW
    ========================================================= */

    public function single_product(): void {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product.' ] );
        }

        $product = NEXORA_MARKET_DB::get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        ob_start();
        include NEXORA_MARKETPLACE_TEMPLATES . 'single-product.php';
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /* =========================================================
       ADD TO CART (WooCommerce)
    ========================================================= */

    public function add_to_cart(): void {

        $this->auth();

        $nx_product_id = intval( $_POST['product_id'] ?? 0 );
        $qty           = max( 1, intval( $_POST['qty'] ?? 1 ) );

        if ( ! $nx_product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product.' ] );
        }

        if ( ! NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce is not active.' ] );
        }

        $wc_id = NEXORA_MARKET_DB::get_wc_id( $nx_product_id );

        if ( ! $wc_id ) {
            wp_send_json_error( [ 'message' => 'This product is not yet available for purchase.' ] );
        }

        $added = WC()->cart->add_to_cart( $wc_id, $qty );

        if ( $added ) {
            wp_send_json_success( [
                'message'    => 'Added to cart.',
                'cart_url'   => wc_get_cart_url(),
                'cart_count' => WC()->cart->get_cart_contents_count(),
            ] );
        } else {
            wp_send_json_error( [ 'message' => 'Could not add to cart. The product may be out of stock.' ] );
        }
    }

    /* =========================================================
       ADD PRODUCT — MANUAL FORM
    ========================================================= */

    public function add_manual(): void {

        $this->auth();

        $user_id = get_current_user_id();

        /* ── Collect + sanitise ───────────────────────────── */
        $title      = sanitize_text_field( wp_unslash( $_POST['title']        ?? '' ) );
        $price      = (float) ( $_POST['price']         ?? 0 );
        $sale_raw   = $_POST['sale_price'] ?? '';
        $sale_price = strlen( trim( $sale_raw ) ) ? (float) $sale_raw : null;
        $stock      = (int) ( $_POST['stock_qty']        ?? 0 );
        $desc       = wp_kses_post( wp_unslash( $_POST['description']         ?? '' ) );
        $short_desc = sanitize_textarea_field( wp_unslash( $_POST['short_desc']  ?? '' ) );
        $category   = sanitize_text_field( wp_unslash( $_POST['category']     ?? '' ) );
        $tags       = sanitize_text_field( wp_unslash( $_POST['tags']         ?? '' ) );
        $sku        = sanitize_text_field( wp_unslash( $_POST['sku']          ?? '' ) );
        $prod_type  = sanitize_text_field( wp_unslash( $_POST['product_type'] ?? 'simple' ) );

        // Feature image — single attachment ID from wp.media picker
        $image_id   = intval( $_POST['image_id'] ?? 0 );

        // Gallery — JSON-encoded array of attachment IDs from wp.media picker
        $gallery_raw = sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ?? '[]' ) );
        $gallery_ids = array_map( 'intval', (array) json_decode( $gallery_raw, true ) );
        $gallery_ids = array_filter( $gallery_ids ); // remove zeros

        /* ── Validate ─────────────────────────────────────── */
        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Product title is required.' ] );
        }
        if ( $price <= 0 ) {
            wp_send_json_error( [ 'message' => 'Price must be greater than 0.' ] );
        }

        /* ── Ownership ────────────────────────────────────── */
        $owner_role = NEXORA_MARKET_HELPER::resolve_owner_role( $user_id );

        /* ── Create product ───────────────────────────────── */
        $nx_id = NEXORA_MARKET_PRODUCT::create( [
            'owner_user_id'          => $user_id,
            'owner_role'             => $owner_role,
            'title'                  => $title,
            'price'                  => $price,
            'sale_price'             => $sale_price,
            'stock_qty'              => $stock,
            'description'            => $desc,
            'short_description'      => $short_desc,
            'category'               => $category,
            'tags'                   => $tags,
            'sku'                    => $sku,
            'product_type'           => $prod_type,
            'source_type'            => 'manual',
            'image_attachment_id'    => $image_id,
            'gallery_attachment_ids' => $gallery_ids,
        ] );

        if ( ! $nx_id ) {
            wp_send_json_error( [ 'message' => 'Database error — could not save product.' ] );
        }

        NEXORA_MARKET_DB::log_activity( $user_id, 'product_created', [
            'product_id' => $nx_id,
            'title'      => $title,
            'method'     => 'manual',
        ] );

        wp_send_json_success( [
            'product_id' => $nx_id,
            'message'    => 'Product added successfully.',
        ] );
    }

    /* =========================================================
       ADD PRODUCT — CSV IMPORT
    ========================================================= */

    public function csv_import(): void {

        $this->auth();

        $result = NEXORA_MARKET_CSV::import(
            $_FILES['csv_file'] ?? [],
            get_current_user_id()
        );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( $result );
    }

    /* =========================================================
       ADD PRODUCT — API IMPORT
    ========================================================= */

    public function api_import(): void {

        $this->auth();

        $result = NEXORA_MARKET_API::save_source( [
            'user_id'        => get_current_user_id(),
            'label'          => sanitize_text_field( wp_unslash( $_POST['label']          ?? 'My API Store' ) ),
            'endpoint_url'   => wp_unslash( $_POST['endpoint_url']                         ?? '' ),
            'api_key'        => sanitize_text_field( wp_unslash( $_POST['api_key']         ?? '' ) ),
            'sync_method'    => sanitize_text_field( wp_unslash( $_POST['sync_method']     ?? 'cron' ) ),
            'webhook_secret' => sanitize_text_field( wp_unslash( $_POST['webhook_secret']  ?? '' ) ),
        ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( $result );
    }

    /* =========================================================
       MANUAL RE-SYNC ONE API SOURCE
    ========================================================= */

    public function api_sync(): void {

        $this->auth();

        $source_id = intval( $_POST['source_id'] ?? 0 );

        if ( ! $source_id ) {
            wp_send_json_error( [ 'message' => 'Invalid source ID.' ] );
        }

        // Ownership check
        $source = NEXORA_MARKET_DB::get_api_source( $source_id );

        if ( ! $source ) {
            wp_send_json_error( [ 'message' => 'Source not found.' ] );
        }

        if ( (int) $source['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        wp_send_json_success( NEXORA_MARKET_API::sync_source( $source_id ) );
    }

    /* =========================================================
       UPDATE PRODUCT (inline edit from My Products)
    ========================================================= */

    public function update_product(): void {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        $product = NEXORA_MARKET_DB::get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        if ( (int) $product['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        // Build update payload — only include fields that were actually sent
        $data = [];

        if ( isset( $_POST['price'] )        && strlen( (string) $_POST['price'] ) )
            $data['price']       = (float) $_POST['price'];

        if ( isset( $_POST['stock_qty'] )    && strlen( (string) $_POST['stock_qty'] ) )
            $data['stock_qty']   = (int) $_POST['stock_qty'];

        if ( ! empty( $_POST['title'] ) )
            $data['title']       = sanitize_text_field( wp_unslash( $_POST['title'] ) );

        if ( isset( $_POST['description'] ) )
            $data['description'] = wp_kses_post( wp_unslash( $_POST['description'] ) );

        if ( isset( $_POST['category'] ) )
            $data['category']    = sanitize_text_field( wp_unslash( $_POST['category'] ) );

        // Image update from wp.media
        if ( ! empty( $_POST['image_id'] ) )
            $data['image_attachment_id'] = intval( $_POST['image_id'] );

        // Gallery update from wp.media
        if ( isset( $_POST['gallery_ids'] ) ) {
            $gallery_raw = sanitize_text_field( wp_unslash( $_POST['gallery_ids'] ) );
            $gallery_ids = array_filter( array_map( 'intval', (array) json_decode( $gallery_raw, true ) ) );
            if ( ! empty( $gallery_ids ) ) $data['gallery_attachment_ids'] = $gallery_ids;
        }

        if ( empty( $data ) ) {
            wp_send_json_error( [ 'message' => 'Nothing to update.' ] );
        }

        $ok = NEXORA_MARKET_PRODUCT::update( $product_id, $data );

        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Update failed.' ] );
        }

        NEXORA_MARKET_DB::log_activity( get_current_user_id(), 'product_updated', [
            'product_id' => $product_id,
            'fields'     => array_keys( $data ),
        ] );

        wp_send_json_success( [ 'message' => 'Product updated.' ] );
    }

    /* =========================================================
       DELETE PRODUCT (soft-delete)
    ========================================================= */

    public function delete_product(): void {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        $product = NEXORA_MARKET_DB::get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        if ( (int) $product['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        NEXORA_MARKET_PRODUCT::delete( $product_id );

        NEXORA_MARKET_DB::log_activity( get_current_user_id(), 'product_deleted', [
            'product_id' => $product_id,
        ] );

        wp_send_json_success( [ 'message' => 'Product removed.' ] );
    }
}
