<?php
/**
 * includes/class-market-api.php
 *
 * Everything related to external API product sources:
 *   - Saving a new API source connection
 *   - Fetching & upserting products from a remote API (sync engine)
 *   - WP-Cron scheduling (delegates to NEXORA_MARKET_HELPER)
 *   - Webhook payload handling (called from NEXORA_MARKET_CORE REST endpoint)
 *
 * Depends on: NEXORA_MARKET_DB, NEXORA_MARKET_HELPER, NEXORA_MARKET_PRODUCT,
 *             NEXORA_MARKET_WOOCOMMERCE
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_API {

    /* =========================================================
       SAVE API SOURCE
    ========================================================= */

    /**
     * Save a new API source record and optionally schedule cron + run
     * an immediate first sync.
     *
     * @param  array $data {
     *   user_id        int     (required)
     *   label          string
     *   endpoint_url   string  (required)
     *   api_key        string  — will be encrypted before storage
     *   sync_method    string  'cron' | 'webhook' | 'both'
     *   webhook_secret string
     * }
     * @return array {
     *   source_id int,
     *   synced    int,
     *   message   string,
     *   error?    string
     * }
     */
    public static function save_source( array $data ): array {

        $endpoint  = esc_url_raw( $data['endpoint_url'] ?? '' );
        $user_id   = (int) ( $data['user_id'] ?? 0 );
        $sync_meth = sanitize_text_field( $data['sync_method'] ?? 'cron' );

        if ( empty( $endpoint ) || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
            return [ 'source_id' => 0, 'synced' => 0, 'error' => 'Endpoint must be a valid URL.' ];
        }

        // Encrypt the API key — plaintext must never hit the DB
        $encrypted_key = NEXORA_MARKET_HELPER::encrypt( $data['api_key'] ?? '' );

        $source_id = NEXORA_MARKET_DB::insert_api_source( [
            'user_id'        => $user_id,
            'label'          => sanitize_text_field( $data['label'] ?? 'My API Store' ),
            'endpoint_url'   => $endpoint,
            'api_key'        => $encrypted_key,
            'api_secret'     => '',
            'webhook_secret' => sanitize_text_field( $data['webhook_secret'] ?? '' ),
            'sync_method'    => $sync_meth,
            'sync_interval'  => 'hourly',
        ] );

        if ( ! $source_id ) {
            return [ 'source_id' => 0, 'synced' => 0, 'error' => 'Could not save API source.' ];
        }

        NEXORA_MARKET_DB::log_activity( $user_id, 'api_source_added', [
            'source_id' => $source_id,
            'endpoint'  => $endpoint,
            'method'    => $sync_meth,
        ] );

        // Schedule recurring cron if needed
        if ( in_array( $sync_meth, [ 'cron', 'both' ], true ) ) {
            NEXORA_MARKET_HELPER::schedule_api_sync( $source_id );
        }

        // Fire first sync immediately so the user sees products right away
        $sync_result = self::sync_source( $source_id );

        return [
            'source_id' => $source_id,
            'synced'    => $sync_result['imported'] ?? 0,
            'message'   => 'API source connected. ' . ( $sync_result['message'] ?? 'Sync started.' ),
        ];
    }

    /* =========================================================
       SYNC ENGINE
    ========================================================= */

    /**
     * Fetch products from a vendor API source and upsert into nx_products.
     *
     * Protocol:
     *   GET {endpoint_url}?page=1&per_page=100
     *   Authorization: Bearer {api_key}
     *
     * Expected JSON response shapes (both supported):
     *   { "products": [ {...}, ... ] }
     *   OR bare array: [ {...}, ... ]
     *
     * Product object field aliases (most popular API schemas):
     *   id            → id | product_id
     *   title         → name | title
     *   price         → price | regular_price
     *   stock         → stock_quantity | stock | qty
     *   image         → image | image_url | thumbnail
     *   category      → category | category_name
     *
     * @param  int   $source_id  nx_api_sources.id
     * @return array { imported, updated, skipped, message, error? }
     */
    public static function sync_source( int $source_id ): array {

        $source = NEXORA_MARKET_DB::get_api_source( $source_id );

        if ( ! $source ) {
            return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 'API source not found or inactive.' ];
        }

        $endpoint = esc_url_raw( $source['endpoint_url'] );
        $api_key  = NEXORA_MARKET_HELPER::decrypt( $source['api_key'] );

        /* ── HTTP request ─────────────────────────────────── */
        $response = wp_remote_get(
            add_query_arg( [ 'per_page' => 100, 'page' => 1 ], $endpoint ),
            [
                'timeout'   => 20,
                'sslverify' => true,
                'headers'   => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
            ]
        );

        /* ── WP HTTP error ────────────────────────────────── */
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
            NEXORA_MARKET_DB::update_api_source_status( $source_id, 'error', $error );
            NEXORA_MARKET_DB::log_activity( (int) $source['user_id'], 'api_sync_failed', [
                'source_id' => $source_id, 'error' => $error,
            ] );
            return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'error' => $error ];
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( $status_code !== 200 || ! is_array( $data ) ) {
            $error = "API returned HTTP {$status_code}.";
            NEXORA_MARKET_DB::update_api_source_status( $source_id, 'error', $error );
            return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'error' => $error ];
        }

        /* ── Normalise response shape ─────────────────────── */
        $products_raw = $data['products'] ?? $data['data'] ?? ( isset( $data[0] ) ? $data : [] );

        if ( empty( $products_raw ) ) {
            NEXORA_MARKET_DB::update_api_source_status( $source_id, 'ok' );
            return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'message' => 'No products returned by API.' ];
        }

        /* ── Upsert each product ──────────────────────────── */
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ( $products_raw as $item ) {

            if ( ! is_array( $item ) ) { $skipped++; continue; }

            $result = self::upsert_api_product( $item, $source );

            if ( $result === 'imported' ) $imported++;
            elseif ( $result === 'updated' ) $updated++;
            else $skipped++;
        }

        NEXORA_MARKET_DB::update_api_source_status( $source_id, 'ok' );
        NEXORA_MARKET_DB::log_activity( (int) $source['user_id'], 'api_synced', [
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
       WEBHOOK HANDLER
    ========================================================= */

    /**
     * Process an incoming webhook payload.
     * Called from NEXORA_MARKET_CORE::handle_webhook().
     *
     * @param  int             $source_id   0 if generic endpoint used.
     * @param  string          $raw_body    Raw JSON request body.
     * @param  string          $signature   X-Nexora-Signature header value.
     * @return array { success bool, status int, message string }
     */
    public static function handle_webhook( int $source_id, string $raw_body, string $signature ): array {

        /* ── Load source & verify HMAC ────────────────────── */
        $source = $source_id ? NEXORA_MARKET_DB::get_api_source( $source_id ) : null;

        if ( $source && ! empty( $source['webhook_secret'] ) ) {
            if ( ! NEXORA_MARKET_HELPER::verify_webhook_signature( $raw_body, $signature, $source['webhook_secret'] ) ) {
                return [ 'success' => false, 'status' => 403, 'message' => 'Invalid signature.' ];
            }
        }

        /* ── Parse payload ────────────────────────────────── */
        $payload = json_decode( $raw_body, true );

        if ( ! is_array( $payload ) ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Invalid JSON payload.' ];
        }

        $event       = sanitize_text_field( $payload['event']   ?? 'product.updated' );
        $item        = $payload['product'] ?? $payload['data']  ?? null;

        if ( ! is_array( $item ) ) {
            return [ 'success' => false, 'status' => 400, 'message' => 'Missing product data.' ];
        }

        $external_id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );

        /* ── Route by event type ──────────────────────────── */
        switch ( $event ) {

            case 'product.deleted':
            case 'product.removed':
                if ( $external_id && $source_id ) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'nx_products',
                        [ 'status' => 'inactive' ],
                        [ 'external_id' => $external_id, 'api_source_id' => $source_id ]
                    );
                }
                break;

            case 'product.created':
            case 'product.updated':
            default:
                // Re-use full sync so all products stay consistent.
                // For high-volume production use, upsert just this one item instead.
                if ( $source_id ) {
                    wp_schedule_single_event( time(), "nexora_market_cron_sync_{$source_id}" );
                }
                break;
        }

        $user_id = $source ? (int) $source['user_id'] : 0;
        NEXORA_MARKET_DB::log_activity( $user_id, 'webhook_received', [
            'source_id'   => $source_id,
            'event'       => $event,
            'external_id' => $external_id,
        ] );

        return [ 'success' => true, 'status' => 200, 'message' => 'Received.' ];
    }

    /* =========================================================
       PRIVATE HELPERS
    ========================================================= */

    /**
     * Upsert one product item from a sync response.
     *
     * @param  array  $item    Normalised product data from remote API.
     * @param  array  $source  nx_api_sources row.
     * @return string          'imported' | 'updated' | 'skipped'
     */
    private static function upsert_api_product( array $item, array $source ): string {

        /* Map common field name variants from popular API schemas */
        $external_id = (string) ( $item['id']    ?? $item['product_id'] ?? '' );
        $title       = sanitize_text_field( $item['name']  ?? $item['title'] ?? '' );
        $price       = (float) ( $item['price'] ?? $item['regular_price'] ?? 0 );

        if ( empty( $title ) || $price <= 0 ) return 'skipped';

        $source_id = (int) $source['id'];
        $user_id   = (int) $source['user_id'];

        $stock    = (int)    ( $item['stock_quantity'] ?? $item['stock'] ?? $item['qty'] ?? 0 );
        $desc     = wp_kses_post( $item['description'] ?? '' );
        $image    = esc_url_raw( $item['image'] ?? $item['image_url'] ?? $item['thumbnail'] ?? '' );
        $category = sanitize_text_field( $item['category'] ?? $item['category_name'] ?? '' );
        $sku      = sanitize_text_field( $item['sku'] ?? '' );

        /* Check if this external product already exists */
        $existing_id = NEXORA_MARKET_DB::find_api_product( $user_id, $source_id, $external_id );

        if ( $existing_id ) {

            /* UPDATE existing row */
            NEXORA_MARKET_DB::update_product( $existing_id, [
                'title'          => $title,
                'price'          => $price,
                'stock_qty'      => $stock,
                'description'    => $desc,
                'image_url'      => $image,
                'last_synced_at' => current_time( 'mysql' ),
            ] );

            // Mirror price / stock to WooCommerce
            $wc_id = NEXORA_MARKET_DB::get_wc_id( $existing_id );
            if ( $wc_id ) {
                NEXORA_MARKET_WOOCOMMERCE::update_wc_product( $wc_id, [
                    'title'     => $title,
                    'price'     => $price,
                    'stock_qty' => $stock,
                ] );
            }

            return 'updated';
        }

        /* INSERT new row via the full product-creation pipeline */
        $nx_id = NEXORA_MARKET_PRODUCT::create( [
            'owner_user_id'  => $user_id,
            'owner_role'     => 'vendor',
            'title'          => $title,
            'price'          => $price,
            'stock_qty'      => $stock,
            'sku'            => $sku,
            'category'       => $category,
            'description'    => $desc,
            'image_url'      => $image,
            'source_type'    => 'api',
            'api_source_id'  => $source_id,
            'external_id'    => $external_id,
        ] );

        return $nx_id ? 'imported' : 'skipped';
    }
}
