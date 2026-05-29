<?php
/**
 * includes/class-market-product.php
 *
 * Unified product factory used by all three upload paths
 * (manual, CSV, API) and by the AJAX update/delete handlers.
 *
 * Single responsibility: orchestrate nx_products + WooCommerce
 * so callers never have to touch both directly.
 *
 * Depends on: NEXORA_MARKET_DB, NEXORA_MARKET_WOOCOMMERCE, NEXORA_MARKET_UPLOAD
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_PRODUCT {

    /* =========================================================
       CREATE
    ========================================================= */

    /**
     * Insert one product into nx_products + create a matching WC product.
     *
     * @param array $data {
     *   owner_user_id        int      (required)
     *   title                string   (required)
     *   price                float    (required)
     *   owner_role           string   default 'user'
     *   owner_profile_id     int      default 0
     *   description          string
     *   short_description    string
     *   sale_price           float|null
     *   stock_qty            int      default 0
     *   sku                  string
     *   category             string
     *   tags                 string
     *   product_type         string   default 'simple'
     *   source_type          string   default 'manual'  (manual|csv|api)
     *   api_source_id        int|null
     *   external_id          string
     *   image_url            string   — from remote URL (CSV / API path)
     *   image_attachment_id  int      — from WP media library (manual path)
     *   gallery_attachment_ids int[]  — from WP media library (manual path)
     *   gallery              string   — JSON-encoded gallery URLs
     *   status               string   default 'active'
     * }
     * @return int|false  nx_products.id or false on failure.
     */
    public static function create( array $data ) {

        // Fill defaults
        $data = array_merge( [
            'owner_role'           => 'user',
            'owner_profile_id'     => 0,
            'description'          => '',
            'short_description'    => '',
            'sale_price'           => null,
            'stock_qty'            => 0,
            'sku'                  => '',
            'category'             => '',
            'tags'                 => '',
            'product_type'         => 'simple',
            'source_type'          => 'manual',
            'api_source_id'        => null,
            'external_id'          => '',
            'image_url'            => '',
            'image_attachment_id'  => 0,
            'gallery_attachment_ids' => [],
            'gallery'              => '',
            'status'               => 'active',
        ], $data );

        if ( empty( $data['owner_user_id'] ) || empty( $data['title'] ) || empty( $data['price'] ) ) {
            return false;
        }

        // Build gallery JSON from attachment IDs (manual path takes priority)
        if ( ! empty( $data['gallery_attachment_ids'] ) ) {
            $data['gallery'] = NEXORA_MARKET_UPLOAD::encode_gallery( $data['gallery_attachment_ids'] );
        }

        // Resolve feature image URL from attachment ID if provided (manual path)
        if ( ! empty( $data['image_attachment_id'] ) && empty( $data['image_url'] ) ) {
            $data['image_url'] = (string) wp_get_attachment_url( (int) $data['image_attachment_id'] );
        }

        /* ── Insert into nx_products ──────────────────────── */
        $nx_id = NEXORA_MARKET_DB::insert_product( $data );

        if ( ! $nx_id ) return false;

        /* ── Create WooCommerce product ───────────────────── */
        if ( NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {

            $wc_id = NEXORA_MARKET_WOOCOMMERCE::create_wc_product( array_merge( $data, [
                'nx_product_id' => $nx_id,
            ] ) );

            if ( $wc_id ) {
                NEXORA_MARKET_DB::link_wc_product( $nx_id, $wc_id );
            }
        }

        return $nx_id;
    }

    /* =========================================================
       UPDATE
    ========================================================= */

    /**
     * Update an existing nx_products row and mirror changes to WooCommerce.
     *
     * @param  int   $nx_id
     * @param  array $data  Only include fields you want to change.
     *                      Same shape as create(), minus owner fields.
     * @return bool
     */
    public static function update( int $nx_id, array $data ): bool {

        // Sanitise each field that can be updated
        $allowed = [
            'title', 'description', 'short_description',
            'price', 'sale_price', 'stock_qty',
            'sku', 'category', 'tags', 'product_type',
            'image_url', 'gallery', 'status',
        ];

        $update = [];

        foreach ( $allowed as $field ) {
            if ( ! array_key_exists( $field, $data ) ) continue;

            switch ( $field ) {
                case 'price':
                case 'sale_price':
                    $update[ $field ] = is_null( $data[ $field ] ) ? null : (float) $data[ $field ];
                    break;
                case 'stock_qty':
                    $update[ $field ] = (int) $data[ $field ];
                    break;
                case 'description':
                    $update[ $field ] = wp_kses_post( $data[ $field ] );
                    break;
                case 'image_url':
                    $update[ $field ] = esc_url_raw( $data[ $field ] );
                    break;
                default:
                    $update[ $field ] = sanitize_text_field( $data[ $field ] );
            }
        }

        // Handle image attachment update (manual path)
        if ( ! empty( $data['image_attachment_id'] ) ) {
            $update['image_url'] = (string) wp_get_attachment_url( (int) $data['image_attachment_id'] );
        }

        // Handle gallery attachment update (manual path)
        if ( ! empty( $data['gallery_attachment_ids'] ) ) {
            $update['gallery'] = NEXORA_MARKET_UPLOAD::encode_gallery( $data['gallery_attachment_ids'] );
        }

        if ( empty( $update ) ) return false;

        $ok    = NEXORA_MARKET_DB::update_product( $nx_id, $update );
        $wc_id = NEXORA_MARKET_DB::get_wc_id( $nx_id );

        // Mirror to WooCommerce, passing through attachment IDs too
        if ( $wc_id && NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            NEXORA_MARKET_WOOCOMMERCE::update_wc_product( $wc_id, array_merge( $update, [
                'image_attachment_id'    => $data['image_attachment_id']    ?? 0,
                'gallery_attachment_ids' => $data['gallery_attachment_ids'] ?? [],
            ] ) );
        }

        return $ok;
    }

    /* =========================================================
       DELETE (soft)
    ========================================================= */

    /**
     * Soft-delete: set status to 'inactive' and draft the WC product.
     *
     * @param  int  $nx_id
     * @return bool
     */
    public static function delete( int $nx_id ): bool {

        $wc_id = NEXORA_MARKET_DB::get_wc_id( $nx_id );

        NEXORA_MARKET_DB::soft_delete_product( $nx_id );

        if ( $wc_id && NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            NEXORA_MARKET_WOOCOMMERCE::unpublish_wc_product( $wc_id );
        }

        return true;
    }
}
