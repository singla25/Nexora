<?php
/**
 * class-market-woocommerce.php
 *
 * WooCommerce bridge for the Nexora Marketplace.
 *
 * Responsibilities:
 *   1. Create / update / delete WC products from nx_products data
 *   2. Attach vendor meta to WC products so we can look up seller_id on order
 *   3. Hook into WC order status changes → write nx_orders + nx_earnings
 *   4. Sync stock back from WC to nx_products after a sale
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXORA_MARKET_WOOCOMMERCE {

    /**
     * Platform fee percentage charged to vendors.
     * Move to get_option( 'nx_platform_fee_pct', 10 ) for admin control.
     */
    const PLATFORM_FEE_PCT = 10;

    /** WC post meta key storing the nx vendor's user ID. */
    const VENDOR_META_KEY = '_nx_vendor_user_id';

    /** WC post meta key linking back to nx_products.id. */
    const NX_PRODUCT_META_KEY = '_nx_product_id';

    // ──────────────────────────────────────────────────────────────
    // Boot
    // ──────────────────────────────────────────────────────────────

    public static function init() {

        if ( ! self::wc_active() ) {
            return;
        }

        // Order paid/completed → record order row + earnings
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 3 );

        // Stock reduced by WC → sync back to nx_products
        add_action( 'woocommerce_reduce_order_stock', [ __CLASS__, 'on_stock_reduced' ] );
    }

    // ──────────────────────────────────────────────────────────────
    // WC product CRUD
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a WC simple product.
     *
     * @param array $data  title, description, price, stock_qty,
     *                     owner_user_id, nx_product_id,
     *                     image_url (opt), sku (opt), category (opt)
     * @return int|false  WC product post ID or false.
     */
    public static function create_wc_product( array $data ) {

        if ( ! self::wc_active() ) {
            return false;
        }

        $product = new WC_Product_Simple();
        $product->set_name( sanitize_text_field( $data['title'] ) );
        $product->set_description( wp_kses_post( $data['description'] ?? '' ) );
        $product->set_regular_price( (string) floatval( $data['price'] ?? 0 ) );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( (int) ( $data['stock_qty'] ?? 0 ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );

        if ( ! empty( $data['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $data['sku'] ) );
        }

        // Assign WC category if provided
        if ( ! empty( $data['category'] ) ) {
            $term = get_term_by( 'name', $data['category'], 'product_cat' );
            if ( ! $term ) {
                $term_result = wp_insert_term( sanitize_text_field( $data['category'] ), 'product_cat' );
                $term_id     = is_wp_error( $term_result ) ? 0 : $term_result['term_id'];
            } else {
                $term_id = $term->term_id;
            }
            if ( $term_id ) {
                $product->set_category_ids( [ $term_id ] );
            }
        }

        $wc_id = $product->save();

        if ( ! $wc_id ) {
            return false;
        }

        update_post_meta( $wc_id, self::VENDOR_META_KEY,     (int) ( $data['owner_user_id'] ?? 0 ) );
        update_post_meta( $wc_id, self::NX_PRODUCT_META_KEY, (int) ( $data['nx_product_id']  ?? 0 ) );

        if ( ! empty( $data['image_url'] ) ) {
            self::attach_image_from_url( $wc_id, $data['image_url'], $data['title'] );
        }

        return $wc_id;
    }

    /**
     * Update an existing WC product.
     * Only updates fields present in $data.
     *
     * @param int   $wc_product_id
     * @param array $data  price, stock_qty, title, description, status
     * @return bool
     */
    public static function update_wc_product( int $wc_product_id, array $data ) {

        if ( ! self::wc_active() ) {
            return false;
        }

        $product = wc_get_product( $wc_product_id );

        if ( ! $product ) {
            return false;
        }

        if ( isset( $data['price'] ) ) {
            $product->set_regular_price( (string) floatval( $data['price'] ) );
        }

        if ( isset( $data['stock_qty'] ) ) {
            $product->set_stock_quantity( (int) $data['stock_qty'] );
        }

        if ( isset( $data['title'] ) ) {
            $product->set_name( sanitize_text_field( $data['title'] ) );
        }

        if ( isset( $data['description'] ) ) {
            $product->set_description( wp_kses_post( $data['description'] ) );
        }

        if ( isset( $data['status'] ) ) {
            $product->set_status( $data['status'] === 'active' ? 'publish' : 'draft' );
        }

        return (bool) $product->save();
    }

    /**
     * Soft-remove: set WC product to draft.
     *
     * @param int $wc_product_id
     * @return bool
     */
    public static function unpublish_wc_product( int $wc_product_id ) {

        if ( ! self::wc_active() ) {
            return false;
        }

        $product = wc_get_product( $wc_product_id );

        if ( ! $product ) {
            return false;
        }

        $product->set_status( 'draft' );

        return (bool) $product->save();
    }

    // ──────────────────────────────────────────────────────────────
    // Order hooks
    // ──────────────────────────────────────────────────────────────

    /**
     * Fires on every WC order status transition.
     * We record on → processing|completed and reverse on → cancelled|refunded.
     *
     * @param int    $order_id
     * @param string $old_status
     * @param string $new_status
     */
    public static function on_order_status_changed( int $order_id, string $old_status, string $new_status ) {

        $paid_statuses    = [ 'processing', 'completed' ];
        $reverse_statuses = [ 'cancelled', 'refunded' ];

        $going_paid    = in_array( $new_status, $paid_statuses, true );
        $going_reverse = in_array( $new_status, $reverse_statuses, true );

        if ( ! $going_paid && ! $going_reverse ) {
            return;
        }

        // Don't double-count if already in a paid status
        if ( $going_paid && in_array( $old_status, $paid_statuses, true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {

            /** @var WC_Order_Item_Product $item */
            $wc_product_id  = (int) $item->get_product_id();
            $vendor_user_id = (int) get_post_meta( $wc_product_id, self::VENDOR_META_KEY, true );
            $nx_product_id  = (int) get_post_meta( $wc_product_id, self::NX_PRODUCT_META_KEY, true );

            // Skip products not from our marketplace
            if ( ! $vendor_user_id || ! $nx_product_id ) {
                continue;
            }

            $buyer_id = (int) $order->get_customer_id();
            $qty      = (int) $item->get_quantity();
            $total    = (float) $item->get_total();

            if ( $going_paid ) {

                self::record_order(
                    $order_id, $buyer_id, $vendor_user_id,
                    $nx_product_id, $qty, $total, $new_status
                );

                self::record_earnings( $vendor_user_id, $total );

            } else {

                self::reverse_order( $order_id, $nx_product_id );
                self::reverse_earnings( $vendor_user_id, $total );
            }
        }
    }

    /**
     * After WC reduces stock, sync the new quantity back to nx_products.
     *
     * @param WC_Order $order
     */
    public static function on_stock_reduced( $order ) {

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        foreach ( $order->get_items() as $item ) {

            $wc_product_id = (int) $item->get_product_id();
            $nx_product_id = (int) get_post_meta( $wc_product_id, self::NX_PRODUCT_META_KEY, true );

            if ( ! $nx_product_id ) {
                continue;
            }

            $wc_product = wc_get_product( $wc_product_id );
            $new_stock  = $wc_product ? (int) $wc_product->get_stock_quantity() : 0;

            $wpdb->update(
                $table,
                [ 'stock_qty' => $new_stock ],
                [ 'id'        => $nx_product_id ]
            );
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Private: order + earnings recording
    // ──────────────────────────────────────────────────────────────

    private static function record_order(
        int $wc_order_id, int $buyer_id, int $seller_id,
        int $nx_product_id, int $qty, float $total, string $status
    ) {
        global $wpdb;
        $table = $wpdb->prefix . 'nx_orders';

        // Idempotent upsert
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE wc_order_id = %d AND product_id = %d",
            $wc_order_id, $nx_product_id
        ) );

        if ( $existing_id ) {
            $wpdb->update(
                $table,
                [ 'order_status' => $status, 'payment_status' => 'paid' ],
                [ 'id' => (int) $existing_id ]
            );
            return;
        }

        $fee_pct      = (float) get_option( 'nx_market_fee_pct', self::PLATFORM_FEE_PCT );
        $platform_fee = round( $total * ( $fee_pct / 100 ), 2 );
        $seller_net   = round( $total - $platform_fee, 2 );

        $wpdb->insert( $table, [
            'wc_order_id'    => $wc_order_id,
            'buyer_id'       => $buyer_id,
            'seller_id'      => $seller_id,
            'product_id'     => $nx_product_id,
            'quantity'       => $qty,
            'total'          => $total,
            'platform_fee'   => $platform_fee,
            'seller_net'     => $seller_net,
            'order_status'   => $status,
            'payment_status' => 'paid',
        ] );

        NEXORA_MARKET_HELPER::log_activity( $buyer_id, 'order_placed', [
            'wc_order_id' => $wc_order_id,
            'product_id'  => $nx_product_id,
            'total'       => $total,
        ] );
    }

    private static function reverse_order( int $wc_order_id, int $nx_product_id ) {

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nx_orders',
            [ 'order_status' => 'cancelled', 'payment_status' => 'refunded' ],
            [ 'wc_order_id' => $wc_order_id, 'product_id' => $nx_product_id ]
        );
    }

    private static function record_earnings( int $vendor_id, float $gross ) {

        $period = date( 'Y-m' );
        NEXORA_MARKET_HELPER::upsert_earnings( $vendor_id, $period, $gross, self::PLATFORM_FEE_PCT );
        NEXORA_MARKET_HELPER::log_activity( $vendor_id, 'sale_recorded', [
            'period' => $period,
            'gross'  => $gross,
        ] );
    }

    private static function reverse_earnings( int $vendor_id, float $gross ) {

        $period = date( 'Y-m' );
        // negative gross → upsert subtracts
        NEXORA_MARKET_HELPER::upsert_earnings( $vendor_id, $period, -abs( $gross ), self::PLATFORM_FEE_PCT );
    }

    // ──────────────────────────────────────────────────────────────
    // Image helper
    // ──────────────────────────────────────────────────────────────

    /**
     * Sideload a remote image into WP media and set as featured image.
     *
     * @param int    $post_id
     * @param string $url
     * @param string $title
     * @return int|false  Attachment ID or false.
     */
    public static function attach_image_from_url( int $post_id, string $url, string $title = '' ) {

        if ( empty( $url ) ) {
            return false;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( esc_url_raw( $url ) );

        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        $file_array = [
            'name'     => sanitize_file_name( wp_basename( $url ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, sanitize_text_field( $title ) );

        @unlink( $file_array['tmp_name'] );

        if ( is_wp_error( $attachment_id ) ) {
            return false;
        }

        set_post_thumbnail( $post_id, $attachment_id );

        return $attachment_id;
    }

    // ──────────────────────────────────────────────────────────────
    // Utility
    // ──────────────────────────────────────────────────────────────

    public static function wc_active() {
        return class_exists( 'WooCommerce' ) && class_exists( 'WC_Product_Simple' );
    }
}