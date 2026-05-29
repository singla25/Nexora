<?php
/**
 * class-market-ajax.php
 *
 * Every wp_ajax_* handler for the marketplace.
 *
 * Registered actions:
 *   nexora_market_tab             — load any tab view
 *   nexora_market_add_manual      — create product (manual form)
 *   nexora_market_csv_import      — bulk import from CSV upload
 *   nexora_market_api_import      — save API source + trigger first sync
 *   nexora_market_api_sync        — manually re-sync one API source
 *   nexora_market_update_product  — inline price / stock / field edit
 *   nexora_market_delete_product  — soft-delete (status → inactive)
 *   nexora_market_single_product  — return single-product view HTML
 *   nexora_market_add_to_cart     — add WC product to cart
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_AJAX {

    public function __construct() {

        /* Tab loader */
        add_action( 'wp_ajax_nexora_market_tab',            [ $this, 'market_tab'      ] );

        /* Product CRUD */
        add_action( 'wp_ajax_nexora_market_add_manual',     [ $this, 'add_manual'      ] );
        add_action( 'wp_ajax_nexora_market_csv_import',     [ $this, 'csv_import'      ] );
        add_action( 'wp_ajax_nexora_market_api_import',     [ $this, 'api_import'      ] );
        add_action( 'wp_ajax_nexora_market_api_sync',       [ $this, 'api_sync'        ] );
        add_action( 'wp_ajax_nexora_market_update_product', [ $this, 'update_product'  ] );
        add_action( 'wp_ajax_nexora_market_delete_product', [ $this, 'delete_product'  ] );

        /* Single product + cart */
        add_action( 'wp_ajax_nexora_market_single_product', [ $this, 'single_product'  ] );
        add_action( 'wp_ajax_nexora_market_add_to_cart',    [ $this, 'add_to_cart'     ] );
    }

    /* =========================================================
       SHARED AUTH
    ========================================================= */

    private function auth() {
        check_ajax_referer( 'nexora_market_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
        }
    }

    /* =========================================================
       TAB VIEW LOADER
    ========================================================= */

    public function market_tab() {

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

    public function single_product() {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product.' ] );
        }

        $product = NEXORA_MARKET_HELPER::get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        ob_start();
        include NEXORA_MARKETPLACE_TEMPLATES . 'single-product.php';
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /* =========================================================
       ADD TO CART  (WooCommerce)
    ========================================================= */

    public function add_to_cart() {

        $this->auth();

        $nx_product_id = intval( $_POST['product_id'] ?? 0 );
        $qty           = max( 1, intval( $_POST['qty'] ?? 1 ) );

        if ( ! $nx_product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product.' ] );
        }

        if ( ! NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            wp_send_json_error( [ 'message' => 'WooCommerce is not active.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        $wc_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT wc_product_id FROM {$table} WHERE id = %d", $nx_product_id )
        );

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

    public function add_manual() {

        $this->auth();

        /* ── Collect + sanitise ───────────────────────────── */
        $title      = sanitize_text_field( wp_unslash( $_POST['title']        ?? '' ) );
        $price      = (float) ( $_POST['price']       ?? 0 );
        $sale_raw   = $_POST['sale_price'] ?? '';
        $sale_price = strlen( trim( $sale_raw ) ) ? (float) $sale_raw : null;
        $stock      = (int) ( $_POST['stock_qty']     ?? 0 );
        $desc       = wp_kses_post( wp_unslash( $_POST['description']         ?? '' ) );
        $short_desc = sanitize_textarea_field( wp_unslash( $_POST['short_desc'] ?? '' ) );
        $category   = sanitize_text_field( wp_unslash( $_POST['category']     ?? '' ) );
        $tags       = sanitize_text_field( wp_unslash( $_POST['tags']         ?? '' ) );
        $sku        = sanitize_text_field( wp_unslash( $_POST['sku']          ?? '' ) );
        $prod_type  = sanitize_text_field( wp_unslash( $_POST['product_type'] ?? 'simple' ) );
        $image_id   = intval( $_POST['image_id'] ?? 0 );
        $image_url  = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';

        /* ── Validate ─────────────────────────────────────── */
        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Product title is required.' ] );
        }
        if ( $price <= 0 ) {
            wp_send_json_error( [ 'message' => 'Price must be greater than 0.' ] );
        }

        /* ── Determine owner role ─────────────────────────── */
        $user_id    = get_current_user_id();
        $user       = wp_get_current_user();
        $owner_role = in_array( 'vendor', (array) $user->roles, true ) ? 'vendor' : 'user';

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        /* ── Insert into nx_products ──────────────────────── */
        $inserted = $wpdb->insert( $table, [
            'owner_user_id'     => $user_id,
            'owner_role'        => $owner_role,
            'title'             => $title,
            'slug'              => sanitize_title( $title ) . '-' . uniqid(),
            'description'       => $desc,
            'short_description' => $short_desc,
            'price'             => $price,
            'sale_price'        => $sale_price,
            'stock_qty'         => $stock,
            'sku'               => $sku,
            'category'          => $category,
            'tags'              => $tags,
            'product_type'      => $prod_type,
            'source_type'       => 'manual',
            'image_url'         => $image_url,
            'status'            => 'active',
        ] );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => 'Database error — could not save product.' ] );
        }

        $nx_product_id = (int) $wpdb->insert_id;

        /* ── Create matching WooCommerce product ──────────── */
        $wc_id = NEXORA_MARKET_WOOCOMMERCE::create_wc_product( [
            'title'         => $title,
            'description'   => $desc,
            'price'         => $price,
            'sale_price'    => $sale_price,
            'stock_qty'     => $stock,
            'sku'           => $sku,
            'category'      => $category,
            'image_url'     => $image_url,
            'owner_user_id' => $user_id,
            'nx_product_id' => $nx_product_id,
        ] );

        if ( $wc_id ) {
            $wpdb->update( $table, [ 'wc_product_id' => $wc_id ], [ 'id' => $nx_product_id ] );
        }

        /* ── Log activity ─────────────────────────────────── */
        NEXORA_MARKET_HELPER::log_activity( $user_id, 'product_created', [
            'product_id' => $nx_product_id,
            'title'      => $title,
            'method'     => 'manual',
        ] );

        wp_send_json_success( [
            'product_id' => $nx_product_id,
            'message'    => 'Product added successfully.',
        ] );
    }

    /* =========================================================
       ADD PRODUCT — CSV IMPORT
    ========================================================= */

    public function csv_import() {

        $this->auth();

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'No CSV file uploaded.' ] );
        }

        $file = $_FILES['csv_file'];
        $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( $ext !== 'csv' ) {
            wp_send_json_error( [ 'message' => 'Only .csv files are accepted.' ] );
        }

        $rows = NEXORA_MARKET_HELPER::parse_csv( $file['tmp_name'] );

        if ( empty( $rows ) ) {
            wp_send_json_error( [ 'message' => 'CSV is empty or could not be parsed.' ] );
        }

        /* Validate required columns exist */
        $headers = array_keys( $rows[0] );
        foreach ( [ 'title', 'price' ] as $required ) {
            if ( ! in_array( $required, $headers, true ) ) {
                wp_send_json_error( [ 'message' => "CSV must have a \"{$required}\" column." ] );
            }
        }

        $user_id    = get_current_user_id();
        $user       = wp_get_current_user();
        $owner_role = in_array( 'vendor', (array) $user->roles, true ) ? 'vendor' : 'user';

        global $wpdb;
        $table    = $wpdb->prefix . 'nx_products';
        $imported = 0;
        $skipped  = 0;

        foreach ( $rows as $row ) {

            $title = sanitize_text_field( $row['title'] ?? '' );
            $price = (float) ( $row['price'] ?? 0 );

            /* Skip rows with no title or zero price */
            if ( empty( $title ) || $price <= 0 ) {
                $skipped++;
                continue;
            }

            $sale_raw   = $row['sale_price'] ?? '';
            $sale_price = strlen( trim( $sale_raw ) ) ? (float) $sale_raw : null;

            $inserted = $wpdb->insert( $table, [
                'owner_user_id'     => $user_id,
                'owner_role'        => $owner_role,
                'title'             => $title,
                'slug'              => sanitize_title( $title ) . '-' . uniqid(),
                'description'       => sanitize_textarea_field( $row['description']   ?? '' ),
                'short_description' => sanitize_textarea_field( $row['short_desc']    ?? '' ),
                'price'             => $price,
                'sale_price'        => $sale_price,
                'stock_qty'         => (int) ( $row['stock_qty']    ?? 0 ),
                'sku'               => sanitize_text_field( $row['sku']          ?? '' ),
                'category'          => sanitize_text_field( $row['category']     ?? '' ),
                'tags'              => sanitize_text_field( $row['tags']         ?? '' ),
                'product_type'      => sanitize_text_field( $row['product_type'] ?? 'simple' ),
                'image_url'         => esc_url_raw( $row['image_url']            ?? '' ),
                'source_type'       => 'csv',
                'status'            => 'active',
            ] );

            if ( $inserted ) {
                $nx_id = (int) $wpdb->insert_id;

                $wc_id = NEXORA_MARKET_WOOCOMMERCE::create_wc_product( [
                    'title'         => $title,
                    'description'   => sanitize_textarea_field( $row['description'] ?? '' ),
                    'price'         => $price,
                    'sale_price'    => $sale_price,
                    'stock_qty'     => (int) ( $row['stock_qty'] ?? 0 ),
                    'image_url'     => esc_url_raw( $row['image_url'] ?? '' ),
                    'owner_user_id' => $user_id,
                    'nx_product_id' => $nx_id,
                ] );

                if ( $wc_id ) {
                    $wpdb->update( $table, [ 'wc_product_id' => $wc_id ], [ 'id' => $nx_id ] );
                }

                $imported++;
            } else {
                $skipped++;
            }
        }

        NEXORA_MARKET_HELPER::log_activity( $user_id, 'csv_import', [
            'imported' => $imported,
            'skipped'  => $skipped,
            'file'     => sanitize_text_field( $file['name'] ),
        ] );

        wp_send_json_success( [
            'imported' => $imported,
            'skipped'  => $skipped,
            'message'  => "{$imported} product(s) imported" . ( $skipped ? ", {$skipped} row(s) skipped." : '.' ),
        ] );
    }

    /* =========================================================
       ADD PRODUCT — API IMPORT  (save source + first sync)
    ========================================================= */

    public function api_import() {

        $this->auth();

        $label      = sanitize_text_field( wp_unslash( $_POST['label']          ?? 'My API Store' ) );
        $endpoint   = esc_url_raw( wp_unslash( $_POST['endpoint_url']            ?? '' ) );
        $api_key    = sanitize_text_field( wp_unslash( $_POST['api_key']         ?? '' ) );
        $sync_meth  = sanitize_text_field( wp_unslash( $_POST['sync_method']     ?? 'cron' ) );
        $wh_secret  = sanitize_text_field( wp_unslash( $_POST['webhook_secret']  ?? '' ) );

        if ( empty( $endpoint ) ) {
            wp_send_json_error( [ 'message' => 'Endpoint URL is required.' ] );
        }

        if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            wp_send_json_error( [ 'message' => 'Endpoint must be a valid URL.' ] );
        }

        /* Encrypt key before storing — plaintext never hits DB */
        $encrypted_key = NEXORA_MARKET_HELPER::encrypt( $api_key );

        global $wpdb;
        $api_table = $wpdb->prefix . 'nx_api_sources';

        $inserted = $wpdb->insert( $api_table, [
            'user_id'        => get_current_user_id(),
            'label'          => $label,
            'endpoint_url'   => $endpoint,
            'api_key'        => $encrypted_key,
            'api_secret'     => '',
            'sync_method'    => $sync_meth,
            'webhook_secret' => $wh_secret,
            'status'         => 'active',
        ] );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => 'Could not save API source.' ] );
        }

        $source_id = (int) $wpdb->insert_id;

        NEXORA_MARKET_HELPER::log_activity( get_current_user_id(), 'api_source_added', [
            'source_id' => $source_id,
            'endpoint'  => $endpoint,
            'method'    => $sync_meth,
        ] );

        /* Schedule recurring WP-Cron job for this source */
        if ( in_array( $sync_meth, [ 'cron', 'both' ], true ) ) {
            NEXORA_MARKET_HELPER::schedule_api_sync( $source_id );
        }

        /* Fire first sync immediately so user sees products right away */
        $sync_result = NEXORA_MARKET_HELPER::sync_api_source( $source_id );

        wp_send_json_success( [
            'source_id' => $source_id,
            'synced'    => $sync_result['imported'] ?? 0,
            'message'   => 'API source connected. ' . ( $sync_result['message'] ?? 'Sync started.' ),
        ] );
    }

    /* =========================================================
       MANUAL RE-SYNC ONE API SOURCE
    ========================================================= */

    public function api_sync() {

        $this->auth();

        $source_id = intval( $_POST['source_id'] ?? 0 );

        if ( ! $source_id ) {
            wp_send_json_error( [ 'message' => 'Invalid source ID.' ] );
        }

        global $wpdb;
        $owner_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}nx_api_sources WHERE id = %d",
            $source_id
        ) );

        if ( $owner_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $result = NEXORA_MARKET_HELPER::sync_api_source( $source_id );

        wp_send_json_success( $result );
    }

    /* =========================================================
       UPDATE PRODUCT  (inline edit from My Products)
    ========================================================= */

    public function update_product() {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        /* Ownership check */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT owner_user_id, wc_product_id FROM {$table} WHERE id = %d",
            $product_id
        ), ARRAY_A );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        if ( (int) $row['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        /* Build update payload — only include fields that were actually sent */
        $update = [];

        if ( isset( $_POST['price'] ) && strlen( (string) $_POST['price'] ) ) {
            $update['price'] = (float) $_POST['price'];
        }
        if ( isset( $_POST['stock_qty'] ) && strlen( (string) $_POST['stock_qty'] ) ) {
            $update['stock_qty'] = (int) $_POST['stock_qty'];
        }
        if ( ! empty( $_POST['title'] ) ) {
            $update['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
        }
        if ( isset( $_POST['description'] ) ) {
            $update['description'] = wp_kses_post( wp_unslash( $_POST['description'] ) );
        }
        if ( isset( $_POST['category'] ) ) {
            $update['category'] = sanitize_text_field( wp_unslash( $_POST['category'] ) );
        }

        if ( empty( $update ) ) {
            wp_send_json_error( [ 'message' => 'Nothing to update.' ] );
        }

        $result = $wpdb->update( $table, $update, [ 'id' => $product_id ] );

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => 'Database update failed.' ] );
        }

        /* Mirror to WooCommerce */
        if ( $row['wc_product_id'] ) {
            NEXORA_MARKET_WOOCOMMERCE::update_wc_product( (int) $row['wc_product_id'], $update );
        }

        NEXORA_MARKET_HELPER::log_activity( get_current_user_id(), 'product_updated', [
            'product_id' => $product_id,
            'fields'     => array_keys( $update ),
        ] );

        wp_send_json_success( [ 'message' => 'Product updated.' ] );
    }

    /* =========================================================
       DELETE PRODUCT  (soft-delete → status = inactive)
    ========================================================= */

    public function delete_product() {

        $this->auth();

        $product_id = intval( $_POST['product_id'] ?? 0 );

        if ( ! $product_id ) {
            wp_send_json_error( [ 'message' => 'Invalid product ID.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT owner_user_id, wc_product_id FROM {$table} WHERE id = %d",
            $product_id
        ), ARRAY_A );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Product not found.' ] );
        }

        if ( (int) $row['owner_user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        /* Soft-delete in nx_products */
        $wpdb->update( $table, [ 'status' => 'inactive' ], [ 'id' => $product_id ] );

        /* Draft the WooCommerce product so it disappears from the shop */
        if ( $row['wc_product_id'] ) {
            NEXORA_MARKET_WOOCOMMERCE::unpublish_wc_product( (int) $row['wc_product_id'] );
        }

        NEXORA_MARKET_HELPER::log_activity( get_current_user_id(), 'product_deleted', [
            'product_id' => $product_id,
        ] );

        wp_send_json_success( [ 'message' => 'Product removed.' ] );
    }
}
