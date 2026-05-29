<?php
/**
 * class-market-product.php
 *
 * Static product factory used across AJAX handlers and cron sync.
 *
 * NEXORA_MARKET_AJAX calls create_product() for manual adds.
 * NEXORA_MARKET_HELPER::sync_api_source() calls it for API imports.
 * The CSV import path builds the row directly for performance.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_PRODUCT {

    /**
     * Insert one product row into nx_products and create a matching
     * WooCommerce product, linking both via wc_product_id.
     *
     * @param array $data {
     *   owner_user_id    int      (required)
     *   title            string   (required)
     *   price            float    (required)
     *   owner_role       string   default 'user'
     *   owner_profile_id int      default 0
     *   description      string
     *   short_description string
     *   sale_price       float|null
     *   stock_qty        int      default 0
     *   sku              string
     *   category         string
     *   tags             string
     *   product_type     string   default 'simple'
     *   source_type      string   default 'manual'  (manual|csv|api)
     *   api_source_id    int|null
     *   external_id      string
     *   image_url        string
     *   gallery          string   JSON array of URLs
     *   status           string   default 'active'
     * }
     * @return int|false  nx_products.id or false on failure.
     */
    public static function create_product( array $data ) {

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        /* ── Defaults ─────────────────────────────────────── */
        $data = wp_parse_args( $data, [
            'owner_role'        => 'user',
            'owner_profile_id'  => 0,
            'description'       => '',
            'short_description' => '',
            'sale_price'        => null,
            'stock_qty'         => 0,
            'sku'               => '',
            'category'          => '',
            'tags'              => '',
            'product_type'      => 'simple',
            'source_type'       => 'manual',
            'api_source_id'     => null,
            'external_id'       => '',
            'image_url'         => '',
            'gallery'           => '',
            'status'            => 'active',
        ] );

        /* ── Guard ────────────────────────────────────────── */
        if ( empty( $data['owner_user_id'] ) || empty( $data['title'] ) || empty( $data['price'] ) ) {
            return false;
        }

        /* ── Build slug ───────────────────────────────────── */
        $slug = sanitize_title( $data['title'] ) . '-' . uniqid();

        /* ── Insert ───────────────────────────────────────── */
        $inserted = $wpdb->insert( $table, [
            'owner_user_id'    => (int)    $data['owner_user_id'],
            'owner_profile_id' => (int)    $data['owner_profile_id'],
            'owner_role'       =>           sanitize_text_field( $data['owner_role'] ),
            'title'            =>           sanitize_text_field( $data['title'] ),
            'slug'             =>           $slug,
            'description'      =>           wp_kses_post( $data['description'] ),
            'short_description'=>           sanitize_textarea_field( $data['short_description'] ),
            'price'            => (float)  $data['price'],
            'sale_price'       => is_null( $data['sale_price'] ) ? null : (float) $data['sale_price'],
            'stock_qty'        => (int)    $data['stock_qty'],
            'sku'              =>           sanitize_text_field( $data['sku'] ),
            'category'         =>           sanitize_text_field( $data['category'] ),
            'tags'             =>           sanitize_text_field( $data['tags'] ),
            'product_type'     =>           sanitize_text_field( $data['product_type'] ),
            'source_type'      =>           sanitize_text_field( $data['source_type'] ),
            'api_source_id'    => $data['api_source_id'] ? (int) $data['api_source_id'] : null,
            'external_id'      =>           sanitize_text_field( $data['external_id'] ),
            'image_url'        =>           esc_url_raw( $data['image_url'] ),
            'gallery'          =>           $data['gallery'],
            'status'           =>           sanitize_text_field( $data['status'] ),
        ] );

        if ( ! $inserted ) {
            return false;
        }

        $nx_id = (int) $wpdb->insert_id;

        /* ── Create WooCommerce product ───────────────────── */
        if ( NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {

            $wc_id = NEXORA_MARKET_WOOCOMMERCE::create_wc_product( [
                'title'         => $data['title'],
                'description'   => $data['description'],
                'price'         => $data['price'],
                'sale_price'    => $data['sale_price'],
                'stock_qty'     => $data['stock_qty'],
                'sku'           => $data['sku'],
                'category'      => $data['category'],
                'image_url'     => $data['image_url'],
                'owner_user_id' => (int) $data['owner_user_id'],
                'nx_product_id' => $nx_id,
            ] );

            if ( $wc_id ) {
                $wpdb->update( $table, [ 'wc_product_id' => $wc_id ], [ 'id' => $nx_id ] );
            }
        }

        return $nx_id;
    }

    /**
     * Update an existing nx_products row and mirror changes to WooCommerce.
     *
     * @param int   $nx_product_id
     * @param array $data  Only include fields you want to change.
     * @return bool
     */
    public static function update_product( int $nx_product_id, array $data ): bool {

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        /* Sanitise updatable fields */
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

        if ( empty( $update ) ) return false;

        $result = $wpdb->update( $table, $update, [ 'id' => $nx_product_id ] );

        /* Mirror to WooCommerce */
        $wc_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT wc_product_id FROM {$table} WHERE id = %d", $nx_product_id )
        );

        if ( $wc_id && NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            NEXORA_MARKET_WOOCOMMERCE::update_wc_product( $wc_id, $update );
        }

        return $result !== false;
    }

    /**
     * Soft-delete: set status to 'inactive' and draft the WC product.
     *
     * @param int $nx_product_id
     * @return bool
     */
    public static function delete_product( int $nx_product_id ): bool {

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        $wc_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT wc_product_id FROM {$table} WHERE id = %d", $nx_product_id )
        );

        $ok = $wpdb->update( $table, [ 'status' => 'inactive' ], [ 'id' => $nx_product_id ] );

        if ( $wc_id && NEXORA_MARKET_WOOCOMMERCE::wc_active() ) {
            NEXORA_MARKET_WOOCOMMERCE::unpublish_wc_product( $wc_id );
        }

        return $ok !== false;
    }
}
