<?php
/**
 * class-market-helper.php
 *
 * Pure static utility methods — no hooks, no side-effects.
 * Called from AJAX handlers, templates, WooCommerce bridge, and cron.
 *
 * Covers:
 *   - Product / order / earnings / activity DB queries
 *   - CSV → array parser
 *   - API sync engine (fetch remote products → upsert nx_products)
 *   - WP-Cron scheduling helpers
 *   - Webhook HMAC verification
 *   - Simple symmetric encryption for API keys
 *   - Price formatter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_HELPER {

    /* =========================================================
       PRODUCTS
    ========================================================= */

    /**
     * All active products NOT owned by $exclude_user_id (the browse view).
     */
    public static function get_products( int $exclude_user_id = 0, int $limit = 40, int $offset = 0 ): array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_products';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name AS owner_name
                   FROM {$t} p
              LEFT JOIN {$wpdb->users} u ON u.ID = p.owner_user_id
                  WHERE p.status = 'active'
                    AND p.owner_user_id != %d
                  ORDER BY p.id DESC
                  LIMIT %d OFFSET %d",
                $exclude_user_id, $limit, $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * All products owned by a specific user (My Products view).
     */
    public static function get_my_products( int $user_id ): array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_products';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE owner_user_id = %d ORDER BY id DESC",
                $user_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Single product by ID.
     */
    public static function get_product( int $product_id ): ?array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_products';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $product_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /* =========================================================
       ORDERS
    ========================================================= */

    /**
     * Orders where the user is the BUYER (purchases made).
     */
    public static function get_purchases( int $user_id, int $limit = 50 ): array {

        global $wpdb;
        $o = $wpdb->prefix . 'nx_orders';
        $p = $wpdb->prefix . 'nx_products';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.*, p.title AS product_title, p.image_url,
                        u.display_name AS seller_name
                   FROM {$o} o
              LEFT JOIN {$p} p ON p.id = o.product_id
              LEFT JOIN {$wpdb->users} u ON u.ID = o.seller_id
                  WHERE o.buyer_id = %d
                  ORDER BY o.id DESC
                  LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Orders where the user is the SELLER (sales made).
     */
    public static function get_sales( int $user_id, int $limit = 50 ): array {

        global $wpdb;
        $o = $wpdb->prefix . 'nx_orders';
        $p = $wpdb->prefix . 'nx_products';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.*, p.title AS product_title, p.image_url,
                        u.display_name AS buyer_name
                   FROM {$o} o
              LEFT JOIN {$p} p ON p.id = o.product_id
              LEFT JOIN {$wpdb->users} u ON u.ID = o.buyer_id
                  WHERE o.seller_id = %d
                  ORDER BY o.id DESC
                  LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /* =========================================================
       EARNINGS
    ========================================================= */

    /**
     * All earnings rows for a vendor, newest first.
     */
    public static function get_earnings( int $vendor_id, int $limit = 24 ): array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_earnings';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE vendor_id = %d ORDER BY period DESC LIMIT %d",
                $vendor_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Single earnings row for a specific period ('YYYY-MM').
     */
    public static function get_earnings_for_period( int $vendor_id, string $period ): ?array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_earnings';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE vendor_id = %d AND period = %s",
                $vendor_id, $period
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Upsert an earnings row for (vendor, period).
     * Negative $gross is allowed for refund reversals.
     *
     * @param int    $vendor_id
     * @param string $period     'YYYY-MM'
     * @param float  $gross      Gross sale amount (can be negative for refund)
     * @param float  $fee_pct    Platform fee percentage (default 10)
     */
    public static function upsert_earnings( int $vendor_id, string $period, float $gross, float $fee_pct = 10.0 ): void {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_earnings';

        $fee = round( abs( $gross ) * ( $fee_pct / 100 ), 2 ) * ( $gross < 0 ? -1 : 1 );
        $net = round( $gross - $fee, 2 );

        $existing = self::get_earnings_for_period( $vendor_id, $period );

        if ( $existing ) {

            $wpdb->update(
                $t,
                [
                    'order_count'  => max( 0, (int) $existing['order_count'] + ( $gross >= 0 ? 1 : -1 ) ),
                    'gross'        => round( (float) $existing['gross']        + $gross, 2 ),
                    'platform_fee' => round( (float) $existing['platform_fee'] + $fee,   2 ),
                    'net'          => round( (float) $existing['net']          + $net,    2 ),
                ],
                [ 'id' => (int) $existing['id'] ]
            );

        } else {

            $wpdb->insert( $t, [
                'vendor_id'     => $vendor_id,
                'period'        => $period,
                'order_count'   => max( 0, $gross >= 0 ? 1 : 0 ),
                'gross'         => $gross,
                'platform_fee'  => $fee,
                'net'           => $net,
                'payout_status' => 'pending',
            ] );
        }
    }

    /* =========================================================
       ACTIVITY LOG
    ========================================================= */

    /**
     * Write one activity row.
     *
     * @param int    $user_id
     * @param string $action_type  e.g. 'product_created'
     * @param array  $meta         Arbitrary context data (stored as JSON)
     */
    public static function log_activity( int $user_id, string $action_type, array $meta = [] ): void {

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'nx_activity_log',
            [
                'user_id'     => $user_id,
                'action_type' => sanitize_text_field( $action_type ),
                'meta'        => wp_json_encode( $meta ),
                'ip_address'  => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            ]
        );
    }

    /**
     * Recent activity rows for a user.
     */
    public static function get_activity( int $user_id, int $limit = 40 ): array {

        global $wpdb;
        $t = $wpdb->prefix . 'nx_activity_log';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action_type, meta, created_at
                   FROM {$t}
                  WHERE user_id = %d
                  ORDER BY id DESC
                  LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /* =========================================================
       CSV PARSER
    ========================================================= */

    /**
     * Parse a CSV file into an array of associative rows.
     * First row is treated as headers.
     *
     * @param  string $file_path  Absolute path to the temp file.
     * @return array              Array of [ header => value ] rows.
     */
    public static function parse_csv( string $file_path ): array {

        $rows   = [];
        $handle = @fopen( $file_path, 'r' );

        if ( ! $handle ) {
            return $rows;
        }

        $headers = fgetcsv( $handle );

        if ( ! $headers || ! is_array( $headers ) ) {
            fclose( $handle );
            return $rows;
        }

        $headers = array_map( 'trim', $headers );

        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            if ( count( $line ) === count( $headers ) ) {
                $rows[] = array_combine( $headers, $line );
            }
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Generate a sample CSV template for download.
     * Called from the add-product template via a direct URL.
     */
    public static function output_csv_template(): void {

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="nexora-products-template.csv"' );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, [
            'title', 'price', 'sale_price', 'stock_qty',
            'sku', 'category', 'tags', 'product_type',
            'description', 'short_desc', 'image_url',
        ] );

        fputcsv( $out, [
            'Sample Product', '999.00', '799.00', '50',
            'SKU-001', 'Electronics', 'gadget,tech', 'simple',
            'Full product description here', 'Short description', 'https://example.com/image.jpg',
        ] );

        fclose( $out );
        exit;
    }

    /* =========================================================
       API SYNC ENGINE
    ========================================================= */

    /**
     * Fetch products from a vendor API source and upsert into nx_products.
     *
     * Protocol:
     *   GET {endpoint_url}?page=1&per_page=100
     *   Authorization: Bearer {api_key}
     *
     * Expected JSON response:
     *   { "products": [ { "id", "name", "price", "stock_quantity", "description", ... } ] }
     *   OR a bare array:
     *   [ { "id", "name", "price", ... } ]
     *
     * @param  int   $source_id  Row ID in nx_api_sources.
     * @return array             [ 'imported', 'updated', 'skipped', 'message', 'error' ]
     */
    public static function sync_api_source( int $source_id ): array {

        global $wpdb;
        $api_table = $wpdb->prefix . 'nx_api_sources';
        $prd_table = $wpdb->prefix . 'nx_products';

        /* ── Load source record ───────────────────────────── */
        $source = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$api_table} WHERE id = %d AND status = 'active'", $source_id ),
            ARRAY_A
        );

        if ( ! $source ) {
            return [ 'error' => 'API source not found or inactive.', 'imported' => 0, 'updated' => 0 ];
        }

        $endpoint = esc_url_raw( $source['endpoint_url'] );
        $api_key  = self::decrypt( $source['api_key'] );

        /* ── HTTP request ─────────────────────────────────── */
        $response = wp_remote_get(
            add_query_arg( [ 'per_page' => 100, 'page' => 1 ], $endpoint ),
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'sslverify' => true,
            ]
        );

        /* ── Handle HTTP error ────────────────────────────── */
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            $wpdb->update(
                $api_table,
                [ 'last_status' => 'error', 'last_error' => $error, 'last_synced_at' => current_time( 'mysql' ) ],
                [ 'id' => $source_id ]
            );
            self::log_activity( (int) $source['user_id'], 'api_sync_failed', [
                'source_id' => $source_id,
                'error'     => $error,
            ] );
            return [ 'error' => $error, 'imported' => 0, 'updated' => 0, 'skipped' => 0 ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $error = "API returned HTTP {$status_code}.";
            $wpdb->update(
                $api_table,
                [ 'last_status' => 'error', 'last_error' => $error, 'last_synced_at' => current_time( 'mysql' ) ],
                [ 'id' => $source_id ]
            );
            return [ 'error' => $error, 'imported' => 0, 'updated' => 0, 'skipped' => 0 ];
        }

        /* ── Normalise response shape ─────────────────────── */
        // Support: { "products": [...] }  OR  bare array [...]
        $products_raw = $data['products'] ?? $data['data'] ?? ( isset( $data[0] ) ? $data : [] );

        if ( empty( $products_raw ) ) {
            $wpdb->update(
                $api_table,
                [ 'last_status' => 'ok', 'last_error' => null, 'last_synced_at' => current_time( 'mysql' ) ],
                [ 'id' => $source_id ]
            );
            return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'No products returned by API.' ];
        }

        /* ── Upsert each product ──────────────────────────── */
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ( $products_raw as $item ) {

            if ( ! is_array( $item ) ) { $skipped++; continue; }

            /* Map common field names from popular API schemas */
            $external_id = (string) ( $item['id']    ?? $item['product_id'] ?? '' );
            $title       = sanitize_text_field( $item['name']  ?? $item['title'] ?? '' );
            $price       = (float) ( $item['price'] ?? $item['regular_price'] ?? 0 );

            if ( empty( $title ) || $price <= 0 ) { $skipped++; continue; }

            $stock    = (int) ( $item['stock_quantity'] ?? $item['stock'] ?? $item['qty'] ?? 0 );
            $desc     = wp_kses_post( $item['description'] ?? '' );
            $image    = esc_url_raw( $item['image']     ?? $item['image_url'] ?? $item['thumbnail'] ?? '' );
            $category = sanitize_text_field( $item['category'] ?? $item['category_name'] ?? '' );
            $sku      = sanitize_text_field( $item['sku'] ?? '' );

            /* Check if we've already synced this external product */
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$prd_table}
                  WHERE owner_user_id = %d AND api_source_id = %d AND external_id = %s",
                (int) $source['user_id'], $source_id, $external_id
            ) );

            if ( $existing_id ) {
                /* UPDATE existing row */
                $wpdb->update(
                    $prd_table,
                    [
                        'title'          => $title,
                        'price'          => $price,
                        'stock_qty'      => $stock,
                        'description'    => $desc,
                        'image_url'      => $image,
                        'last_synced_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) $existing_id ]
                );

                /* Mirror to WooCommerce */
                $wc_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT wc_product_id FROM {$prd_table} WHERE id = %d", (int) $existing_id
                ) );
                if ( $wc_id ) {
                    NEXORA_MARKET_WOOCOMMERCE::update_wc_product( $wc_id, [
                        'price'     => $price,
                        'stock_qty' => $stock,
                        'title'     => $title,
                    ] );
                }

                $updated++;

            } else {
                /* INSERT new row */
                $wpdb->insert( $prd_table, [
                    'owner_user_id'  => (int) $source['user_id'],
                    'owner_role'     => 'vendor',
                    'title'          => $title,
                    'slug'           => sanitize_title( $title ) . '-' . uniqid(),
                    'description'    => $desc,
                    'price'          => $price,
                    'stock_qty'      => $stock,
                    'sku'            => $sku,
                    'category'       => $category,
                    'image_url'      => $image,
                    'source_type'    => 'api',
                    'api_source_id'  => $source_id,
                    'external_id'    => $external_id,
                    'status'         => 'active',
                    'last_synced_at' => current_time( 'mysql' ),
                ] );

                $nx_id = (int) $wpdb->insert_id;

                /* Create WooCommerce product */
                $wc_id = NEXORA_MARKET_WOOCOMMERCE::create_wc_product( [
                    'title'         => $title,
                    'description'   => $desc,
                    'price'         => $price,
                    'stock_qty'     => $stock,
                    'sku'           => $sku,
                    'category'      => $category,
                    'image_url'     => $image,
                    'owner_user_id' => (int) $source['user_id'],
                    'nx_product_id' => $nx_id,
                ] );

                if ( $wc_id ) {
                    $wpdb->update( $prd_table, [ 'wc_product_id' => $wc_id ], [ 'id' => $nx_id ] );
                }

                $imported++;
            }
        }

        /* ── Mark source as synced ────────────────────────── */
        $wpdb->update(
            $api_table,
            [
                'last_status'    => 'ok',
                'last_error'     => null,
                'last_synced_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $source_id ]
        );

        self::log_activity( (int) $source['user_id'], 'api_synced', [
            'source_id' => $source_id,
            'imported'  => $imported,
            'updated'   => $updated,
            'skipped'   => $skipped,
        ] );

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'message'  => "{$imported} new, {$updated} updated, {$skipped} skipped.",
        ];
    }

    /* =========================================================
       WP-CRON SCHEDULING
    ========================================================= */

    /**
     * Schedule a recurring sync for one API source.
     * Hook: nexora_market_cron_sync_{$source_id}
     *
     * @param int    $source_id
     * @param string $interval  WP cron recurrence slug (hourly, twicedaily, daily)
     */
    public static function schedule_api_sync( int $source_id, string $interval = 'hourly' ): void {

        $hook = "nexora_market_cron_sync_{$source_id}";

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), $interval, $hook );
        }

        /* Register the callback that fires when cron runs */
        add_action( $hook, function() use ( $source_id ) {
            NEXORA_MARKET_HELPER::sync_api_source( $source_id );
        } );
    }

    /**
     * Unschedule the cron job for a source (called on source deletion).
     *
     * @param int $source_id
     */
    public static function unschedule_api_sync( int $source_id ): void {

        $hook      = "nexora_market_cron_sync_{$source_id}";
        $timestamp = wp_next_scheduled( $hook );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }

    /**
     * Register all active cron hooks on init.
     * Called from NEXORA_MARKET_CORE::init().
     */
    public static function register_cron_hooks(): void {

        global $wpdb;
        $table = $wpdb->prefix . 'nx_api_sources';

        // Only run if table exists
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $sources = $wpdb->get_results(
            "SELECT id, sync_interval FROM {$table}
              WHERE status = 'active'
                AND sync_method IN ('cron','both')",
            ARRAY_A
        );

        foreach ( (array) $sources as $source ) {
            self::schedule_api_sync( (int) $source['id'], $source['sync_interval'] ?? 'hourly' );
        }
    }

    /* =========================================================
       WEBHOOK VERIFICATION
       Called from NEXORA_MARKET_CORE REST endpoint.
    ========================================================= */

    /**
     * Verify an incoming webhook payload against its HMAC signature.
     *
     * The vendor should send:
     *   X-Nexora-Signature: sha256=<hex_digest>
     *   (same convention as GitHub / Shopify webhooks)
     *
     * @param  string $raw_body        Raw request body string.
     * @param  string $received_sig    Value of the X-Nexora-Signature header.
     * @param  string $secret          Stored webhook_secret for this source.
     * @return bool
     */
    public static function verify_webhook_signature( string $raw_body, string $received_sig, string $secret ): bool {

        if ( empty( $secret ) || empty( $received_sig ) ) {
            return false;
        }

        // Strip "sha256=" prefix if present
        $hash = ltrim( $received_sig, 'sha256=' );
        $expected = hash_hmac( 'sha256', $raw_body, $secret );

        return hash_equals( $expected, $hash );
    }

    /* =========================================================
       ENCRYPTION  (for API keys stored in DB)
    ========================================================= */

    /**
     * Encrypt a string using WordPress's AUTH_KEY as the passphrase.
     * Returns base64-encoded ciphertext.
     * Falls back to base64 if OpenSSL unavailable (not recommended for production).
     */
    public static function encrypt( string $plaintext ): string {

        if ( empty( $plaintext ) ) return '';

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            // Fallback: simple obfuscation — not true encryption
            return base64_encode( $plaintext );
        }

        $key    = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );

        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a string previously encrypted with self::encrypt().
     */
    public static function decrypt( string $ciphertext ): string {

        if ( empty( $ciphertext ) ) return '';

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $ciphertext );
        }

        $decoded = base64_decode( $ciphertext );
        $iv      = substr( $decoded, 0, 16 );
        $data    = substr( $decoded, 16 );
        $key     = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );

        $result = openssl_decrypt( $data, 'AES-256-CBC', $key, 0, $iv );

        return $result !== false ? $result : '';
    }

    /* =========================================================
       MISC
    ========================================================= */

    /**
     * Format a price for display.
     *
     * @param  float  $amount
     * @param  string $symbol  Currency symbol (default ₹)
     * @return string          e.g. "₹1,299.00"
     */
    public static function format_price( float $amount, string $symbol = '₹' ): string {
        return $symbol . number_format( $amount, 2 );
    }

    /**
     * Check whether an nx_products table row exists (graceful guard in templates).
     */
    public static function table_exists( string $table_name ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
