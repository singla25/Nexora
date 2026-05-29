<?php
/**
 * includes/class-market-woocommerce.php
 *
 * WooCommerce bridge for the Nexora Marketplace.
 *
 * Responsibilities:
 *   1. Create / update / draft WC products from nx_products data
 *   2. Attach vendor + nx_product meta to WC products (used on order hook)
 *   3. Hook into WC order status changes → write nx_orders + nx_earnings
 *   4. Sync stock back from WC to nx_products after a sale
 *
 * Image handling is delegated to NEXORA_MARKET_UPLOAD::attach_from_url()
 * Depends on: NEXORA_MARKET_DB, NEXORA_MARKET_UPLOAD
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_WOOCOMMERCE {

    const PLATFORM_FEE_PCT   = 10;
    const VENDOR_META_KEY    = '_nx_vendor_user_id';
    const NX_PRODUCT_META_KEY = '_nx_product_id';

    /* =========================================================
       BOOT
    ========================================================= */

    public static function init(): void {
        if ( ! self::wc_active() ) return;

        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_order_status_changed' ], 10, 3 );
        add_action( 'woocommerce_reduce_order_stock',   [ __CLASS__, 'on_stock_reduced' ] );
    }

    /* =========================================================
       WC PRODUCT CRUD
    ========================================================= */

    /**
     * Create a WC simple product from nx_products data.
     *
     * @param  array    $data {
     *   title, description, price, sale_price?,
     *   stock_qty, sku?, category?, image_url?,
     *   owner_user_id, nx_product_id
     * }
     * @return int|false  WC product post ID or false.
     */
    public static function create_wc_product( array $data ) {

        if ( ! self::wc_active() ) return false;

        $product = new WC_Product_Simple();
        $product->set_name( sanitize_text_field( $data['title'] ) );
        $product->set_description( wp_kses_post( $data['description'] ?? '' ) );
        $product->set_regular_price( (string) floatval( $data['price'] ?? 0 ) );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( (int) ( $data['stock_qty'] ?? 0 ) );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );

        // Sale price
        if ( isset( $data['sale_price'] ) && $data['sale_price'] !== null && (float) $data['sale_price'] > 0 ) {
            $product->set_sale_price( (string) floatval( $data['sale_price'] ) );
        }

        if ( ! empty( $data['sku'] ) ) {
            $product->set_sku( sanitize_text_field( $data['sku'] ) );
        }

        // Assign or create WC product category
        if ( ! empty( $data['category'] ) ) {
            $term_id = self::ensure_category( $data['category'] );
            if ( $term_id ) $product->set_category_ids( [ $term_id ] );
        }

        $wc_id = $product->save();
        if ( ! $wc_id ) return false;

        // Store vendor + nx meta for the order hooks
        update_post_meta( $wc_id, self::VENDOR_META_KEY,      (int) ( $data['owner_user_id'] ?? 0 ) );
        update_post_meta( $wc_id, self::NX_PRODUCT_META_KEY,  (int) ( $data['nx_product_id']  ?? 0 ) );

        // Sideload feature image if a URL was provided (API/CSV path)
        if ( ! empty( $data['image_url'] ) ) {
            NEXORA_MARKET_UPLOAD::attach_from_url( $wc_id, $data['image_url'], $data['title'] );
        }

        // Set image from WP media library attachment (manual/upload path)
        if ( ! empty( $data['image_attachment_id'] ) ) {
            set_post_thumbnail( $wc_id, (int) $data['image_attachment_id'] );
        }

        // Gallery attachments (manual/upload path)
        if ( ! empty( $data['gallery_attachment_ids'] ) && is_array( $data['gallery_attachment_ids'] ) ) {
            update_post_meta( $wc_id, '_product_image_gallery',
                implode( ',', array_map( 'intval', $data['gallery_attachment_ids'] ) )
            );
        }

        return $wc_id;
    }

    /**
     * Update an existing WC product.
     * Only updates fields present in $data.
     *
     * @param  int   $wc_id
     * @param  array $data  price, stock_qty, title, description, status,
     *                      image_attachment_id?, gallery_attachment_ids?
     * @return bool
     */
    public static function update_wc_product( int $wc_id, array $data ): bool {

        if ( ! self::wc_active() ) return false;

        $product = wc_get_product( $wc_id );
        if ( ! $product ) return false;

        if ( isset( $data['price'] ) )       $product->set_regular_price( (string) floatval( $data['price'] ) );
        if ( isset( $data['stock_qty'] ) )   $product->set_stock_quantity( (int) $data['stock_qty'] );
        if ( isset( $data['title'] ) )       $product->set_name( sanitize_text_field( $data['title'] ) );
        if ( isset( $data['description'] ) ) $product->set_description( wp_kses_post( $data['description'] ) );
        if ( isset( $data['status'] ) )      $product->set_status( $data['status'] === 'active' ? 'publish' : 'draft' );

        if ( isset( $data['sale_price'] ) ) {
            $product->set_sale_price( $data['sale_price'] !== null ? (string) floatval( $data['sale_price'] ) : '' );
        }

        $ok = (bool) $product->save();

        // Update feature image from media library attachment
        if ( ! empty( $data['image_attachment_id'] ) ) {
            set_post_thumbnail( $wc_id, (int) $data['image_attachment_id'] );
        }

        // Update gallery attachments
        if ( ! empty( $data['gallery_attachment_ids'] ) && is_array( $data['gallery_attachment_ids'] ) ) {
            update_post_meta( $wc_id, '_product_image_gallery',
                implode( ',', array_map( 'intval', $data['gallery_attachment_ids'] ) )
            );
        }

        return $ok;
    }

    /**
     * Soft-remove: set WC product status to 'draft'.
     *
     * @param  int  $wc_id
     * @return bool
     */
    public static function unpublish_wc_product( int $wc_id ): bool {

        if ( ! self::wc_active() ) return false;

        $product = wc_get_product( $wc_id );
        if ( ! $product ) return false;

        $product->set_status( 'draft' );
        return (bool) $product->save();
    }

    /* =========================================================
       ORDER HOOKS
    ========================================================= */

    /**
     * Fires on every WC order status transition.
     * Records on → processing|completed; reverses on → cancelled|refunded.
     */
    public static function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {

        $paid_statuses    = [ 'processing', 'completed' ];
        $reverse_statuses = [ 'cancelled', 'refunded' ];

        $going_paid    = in_array( $new_status, $paid_statuses, true );
        $going_reverse = in_array( $new_status, $reverse_statuses, true );

        if ( ! $going_paid && ! $going_reverse ) return;
        if ( $going_paid && in_array( $old_status, $paid_statuses, true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {

            /** @var WC_Order_Item_Product $item */
            $wc_product_id  = (int) $item->get_product_id();
            $vendor_user_id = (int) get_post_meta( $wc_product_id, self::VENDOR_META_KEY, true );
            $nx_product_id  = (int) get_post_meta( $wc_product_id, self::NX_PRODUCT_META_KEY, true );

            if ( ! $vendor_user_id || ! $nx_product_id ) continue;

            $buyer_id = (int) $order->get_customer_id();
            $qty      = (int) $item->get_quantity();
            $total    = (float) $item->get_total();

            if ( $going_paid ) {

                $fee_pct      = (float) get_option( 'nx_market_fee_pct', self::PLATFORM_FEE_PCT );
                $platform_fee = round( $total * ( $fee_pct / 100 ), 2 );

                NEXORA_MARKET_DB::upsert_order( [
                    'wc_order_id'  => $order_id,
                    'buyer_id'     => $buyer_id,
                    'seller_id'    => $vendor_user_id,
                    'product_id'   => $nx_product_id,
                    'quantity'     => $qty,
                    'total'        => $total,
                    'platform_fee' => $platform_fee,
                    'seller_net'   => round( $total - $platform_fee, 2 ),
                    'order_status' => $new_status,
                ] );

                NEXORA_MARKET_DB::upsert_earnings( $vendor_user_id, wp_date( 'Y-m' ), $total, self::PLATFORM_FEE_PCT );
                NEXORA_MARKET_DB::log_activity( $buyer_id, 'order_placed', [
                    'wc_order_id' => $order_id,
                    'product_id'  => $nx_product_id,
                    'total'       => $total,
                ] );

            } else {

                NEXORA_MARKET_DB::cancel_order( $order_id, $nx_product_id );
                NEXORA_MARKET_DB::upsert_earnings( $vendor_user_id, wp_date( 'Y-m' ), -abs( $total ), self::PLATFORM_FEE_PCT );
            }
        }
    }

    /**
     * After WC reduces stock on an order, sync the new quantity back to nx_products.
     *
     * @param WC_Order $order
     */
    public static function on_stock_reduced( $order ): void {

        if ( ! $order instanceof WC_Order ) return;

        foreach ( $order->get_items() as $item ) {

            $wc_product_id = (int) $item->get_product_id();
            $nx_product_id = (int) get_post_meta( $wc_product_id, self::NX_PRODUCT_META_KEY, true );

            if ( ! $nx_product_id ) continue;

            $wc_product = wc_get_product( $wc_product_id );
            $new_stock  = $wc_product ? (int) $wc_product->get_stock_quantity() : 0;

            NEXORA_MARKET_DB::update_product( $nx_product_id, [ 'stock_qty' => $new_stock ] );
        }
    }

    /* =========================================================
       UTILITIES
    ========================================================= */

    public static function wc_active(): bool {
        return class_exists( 'WooCommerce' ) && class_exists( 'WC_Product_Simple' );
    }

    /**
     * Get or create a WC product category by name.
     *
     * @param  string $name
     * @return int    Term ID or 0 on failure.
     */
    private static function ensure_category( string $name ): int {
        $term = get_term_by( 'name', $name, 'product_cat' );
        if ( $term ) return $term->term_id;

        $result = wp_insert_term( sanitize_text_field( $name ), 'product_cat' );
        return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    }
}
