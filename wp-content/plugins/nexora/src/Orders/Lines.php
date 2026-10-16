<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a marketplace order line is stored. Everything is hidden meta (underscore keys) on the WooCommerce order item, so it follows the
 * order, works with high-performance order storage and is never shown to customers by WooCommerce itself:
 *
 *  - the frozen snapshot: product post id, name, SKU, unit price, quantity, vendor id (the vendor application post), vendor business
 *    name, the commission rate, and the line's gross, commission and earning;
 *  - the stock counters: units taken from stock and units given back (taken - back = held);
 *  - the refund counters: amount and units refunded, and the earning and commission reversed because of it.
 *
 * Money is text with two decimals; counters are whole numbers. The order itself carries `_nx_order` = 1 and one `_nx_vendor` row per
 * vendor that has a line in it (so a vendor's orders can be found with an ordinary order query).
 */
class Lines {

	const ORDER_FLAG  = '_nx_order';
	const PLACED_FLAG = '_nx_placed';
	const VENDOR_META = '_nx_vendor';
	const PRODUCT     = '_nx_product_id';

	/**
	 * The order lines that came from marketplace products (tagged at checkout), placed or not.
	 *
	 * @param \WC_Order $order The order.
	 * @return \WC_Order_Item_Product[]
	 */
	public static function tagged( $order ) {

		$out = array();

		foreach ( $order->get_items() as $item ) {

			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_meta( self::PRODUCT, true ) > 0 ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * The placed marketplace lines of an order, as plain arrays.
	 *
	 * @param \WC_Order|\WC_Order_Refund|false $order The order.
	 * @return array[]
	 */
	public static function of( $order ) {

		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$out = array();

		foreach ( self::tagged( $order ) as $item ) {

			if ( '' === (string) $item->get_meta( '_nx_price', true ) ) {
				continue;
			}

			$out[] = self::read( $item );
		}

		return $out;
	}

	/**
	 * One placed line as an array.
	 *
	 * @param \WC_Order_Item_Product $item Order item.
	 * @return array
	 */
	public static function read( $item ) {

		$m = function ( $key, $fallback = '' ) use ( $item ) {
			$value = $item->get_meta( $key, true );
			return '' === (string) $value ? $fallback : $value;
		};

		$earning = Commission::cents( $m( '_nx_earning', '0' ) );
		$comm    = Commission::cents( $m( '_nx_commission', '0' ) );
		$e_rev   = Commission::cents( $m( '_nx_earning_reversed', '0' ) );
		$c_rev   = Commission::cents( $m( '_nx_commission_reversed', '0' ) );
		$taken   = (int) $m( '_nx_stock_taken', 0 );
		$back    = (int) $m( '_nx_stock_back', 0 );

		return array(
			'item_id'             => (int) $item->get_id(),
			'product_id'          => (int) $m( self::PRODUCT, 0 ),
			'name'                => (string) $m( '_nx_name' ),
			'sku'                 => (string) $m( '_nx_sku' ),
			'price'               => Commission::money( Commission::cents( $m( '_nx_price', '0' ) ) ),
			'qty'                 => (int) $m( '_nx_qty', 0 ),
			'vendor_id'           => (int) $m( '_nx_vendor_id', 0 ),
			'vendor_name'         => (string) $m( '_nx_vendor_name' ),
			'rate'                => (string) $m( '_nx_rate', '0' ),
			'gross'               => Commission::money( Commission::cents( $m( '_nx_gross', '0' ) ) ),
			'commission'          => Commission::money( $comm ),
			'earning'             => Commission::money( $earning ),
			'refunded'            => Commission::money( Commission::cents( $m( '_nx_refunded', '0' ) ) ),
			'refunded_qty'        => (int) $m( '_nx_refunded_qty', 0 ),
			'earning_reversed'    => Commission::money( $e_rev ),
			'commission_reversed' => Commission::money( $c_rev ),
			'net_earning'         => Commission::money( max( 0, $earning - $e_rev ) ),
			'net_commission'      => Commission::money( max( 0, $comm - $c_rev ) ),
			'stock_taken'         => $taken,
			'stock_back'          => $back,
			'stock_held'          => max( 0, $taken - $back ),
		);
	}

	/**
	 * The vendor application post ids that have a line in the order.
	 *
	 * @param \WC_Order $order The order.
	 * @return int[]
	 */
	public static function vendor_ids( $order ) {

		$ids = array();

		foreach ( $order->get_meta_data() as $meta ) {

			if ( self::VENDOR_META === $meta->key ) {
				$ids[ (int) $meta->value ] = (int) $meta->value;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether this is a marketplace order that has been placed.
	 *
	 * @param \WC_Order|\WC_Order_Refund|false $order The order.
	 * @return bool
	 */
	public static function is_marketplace( $order ) {
		return $order instanceof \WC_Order && '1' === (string) $order->get_meta( self::ORDER_FLAG, true );
	}

	/**
	 * Adds to a counter or money meta of an item (cents or units), without saving.
	 *
	 * @param \WC_Order_Item_Product $item  Order item.
	 * @param string                 $key   Meta key.
	 * @param int                    $delta Units or cents to add.
	 * @param bool                   $money Whether the value is money (text with two decimals).
	 */
	public static function bump( $item, $key, $delta, $money = false ) {

		if ( $money ) {
			$item->update_meta_data( $key, Commission::money( Commission::cents( $item->get_meta( $key, true ) ) + (int) $delta ) );
			return;
		}

		$item->update_meta_data( $key, (int) $item->get_meta( $key, true ) + (int) $delta );
	}
}
