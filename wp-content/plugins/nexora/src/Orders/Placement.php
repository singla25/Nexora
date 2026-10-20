<?php

namespace Nexora\Orders;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Nexora\Core\Lock;
use Nexora\Marketplace\Cart_Bridge;
use Nexora\Marketplace\Cart_Rules;
use Nexora\Products\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placing a marketplace order, on both checkout paths (the classic form fires woocommerce_checkout_order_processed, the block checkout
 * fires woocommerce_store_api_checkout_order_processed; both run before any payment):
 *
 *  1. every line is checked again with the same rules as the cart, and its price must still be what the customer was charged;
 *  2. the stock is taken line by line with Stock::take() (atomic); if one line cannot get its units, the units already taken go back, the
 *     order is cancelled and the customer gets a plain message;
 *  3. only then is each line frozen (Lines): product post id, name, SKU, unit price, quantity, vendor id and business name, the
 *     commission rate, the commission and the vendor's earning.
 *
 * A flag on the order makes the second call (both hooks can fire for one order) do nothing.
 */
class Placement {

	/**
	 * Tags the marketplace lines as checkout builds the order (the cart line carries the product post id).
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key (unused).
	 * @param array                  $values        The cart line.
	 */
	public static function tag( $item, $cart_item_key, $values ) {

		unset( $cart_item_key );

		$pid = Cart_Bridge::pid_of( $values );

		if ( $pid > 0 ) {
			$item->update_meta_data( Lines::PRODUCT, $pid );
		}
	}

	/**
	 * Classic checkout.
	 *
	 * @param int            $order_id Order id.
	 * @param array          $posted   Posted data (unused).
	 * @param \WC_Order|null $order    Order.
	 */
	public static function on_classic( $order_id, $posted = array(), $order = null ) {

		unset( $posted );

		self::place( $order instanceof \WC_Order ? $order : wc_get_order( $order_id ) );
	}

	/**
	 * Block checkout (Store API).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function on_store_api( $order ) {
		self::place( $order );
	}

	/**
	 * Places the order: checks, takes stock, freezes the lines. Throws a RouteException (shown as the checkout error) when it cannot.
	 *
	 * @param \WC_Order|false $order The order.
	 * @throws RouteException When the order cannot be placed.
	 */
	public static function place( $order ) {

		if ( ! $order instanceof \WC_Order || ! Lines::tagged( $order ) ) {
			return;
		}

		$id = $order->get_id();

		if ( ! Lock::take( 'nx-order-' . $id ) ) {
			return; // another request is placing this very order
		}

		try {
			$fresh = wc_get_order( $id );

			if ( ! $fresh instanceof \WC_Order || $fresh->get_meta( Lines::PLACED_FLAG, true ) ) {
				return;
			}

			self::run( $fresh );
		} finally {
			Lock::release( 'nx-order-' . $id );
		}
	}

	/**
	 * The three steps, on a freshly loaded order that has not been placed.
	 *
	 * @param \WC_Order $order The order.
	 * @throws RouteException When a line cannot be had.
	 */
	private static function run( $order ) {

		$plan = array();

		// 1. Check every line without writing anything.
		foreach ( Lines::tagged( $order ) as $item ) {

			$pid      = (int) $item->get_meta( Lines::PRODUCT, true );
			$qty      = (int) $item->get_quantity();
			$name     = $item->get_name();
			$rules    = Cart_Rules::check( $pid, $order->get_customer_id(), $qty, 0 );
			$snapshot = $rules['snapshot'];

			if ( ! $rules['ok'] ) {

				if ( in_array( $rules['code'], array( 'out_of_stock', 'insufficient_stock' ), true ) ) {
					self::refuse( $order, array(), self::stock_message( $name ) );
				}

				/* translators: %s: product name. */
				self::refuse( $order, array(), sprintf( __( '"%s" is no longer available, so the order was not placed. Please review your cart.', 'nexora' ), $name ) );
			}

			$charged = Commission::cents( $item->get_total() );
			$expect  = Commission::cents( $snapshot['price'] ) * $qty;

			if ( $charged !== $expect ) {
				/* translators: %s: product name. */
				self::refuse( $order, array(), sprintf( __( 'The price of "%s" changed while you were checking out, so the order was not placed. Please review your cart.', 'nexora' ), $name ) );
			}

			$plan[] = array( $item, $pid, $qty, $snapshot );
		}

		// 2. Take the stock, all or nothing.
		$taken = array();

		foreach ( $plan as $row ) {

			if ( ! Stock::take( $row[1], $row[2] ) ) {
				self::refuse( $order, $taken, self::stock_message( $row[0]->get_name() ) );
			}

			$taken[] = array( $row[1], $row[2] );
		}

		// 3. Freeze the lines.
		$rate    = Commission::format( Commission::rate() );
		$vendors = array();

		foreach ( $plan as $row ) {

			list( $item, $pid, $qty, $snapshot ) = $row;

			$split = Commission::split( $snapshot['price'], $qty, (float) $rate );

			foreach ( array(
				'_nx_name'                => $snapshot['name'],
				'_nx_sku'                 => (string) Repository::data( $pid )['sku'],
				'_nx_price'               => $snapshot['price'],
				'_nx_qty'                 => $qty,
				'_nx_vendor_id'           => $snapshot['vendor_id'],
				'_nx_vendor_name'         => $snapshot['vendor_name'],
				'_nx_rate'                => $rate,
				'_nx_gross'               => $split['gross'],
				'_nx_commission'          => $split['commission'],
				'_nx_earning'             => $split['earning'],
				'_nx_stock_taken'         => $qty,
				'_nx_stock_back'          => 0,
				'_nx_refunded'            => '0.00',
				'_nx_refunded_qty'        => 0,
				'_nx_earning_reversed'    => '0.00',
				'_nx_commission_reversed' => '0.00',
			) as $key => $value ) {
				$item->update_meta_data( $key, $value );
			}

			$item->save();
			$vendors[ $snapshot['vendor_id'] ] = $snapshot['vendor_id'];
		}

		$order->delete_meta_data( Lines::VENDOR_META );

		foreach ( $vendors as $vendor_id ) {
			$order->add_meta_data( Lines::VENDOR_META, $vendor_id, false );
		}

		$order->update_meta_data( Lines::ORDER_FLAG, '1' );
		$order->update_meta_data( Lines::PLACED_FLAG, (string) time() );
		$order->save();

		// The order is placed: now see which products fell to the low-stock threshold (an order that was refused above never alerts).
		foreach ( $plan as $row ) {
			Low_Stock::check( $row[1] );
		}
	}

	/**
	 * Undoes what was taken, cancels the order and ends checkout with a message.
	 *
	 * @param \WC_Order $order   The order.
	 * @param array     $taken   array( product id, qty ) already taken from stock.
	 * @param string    $message What the customer is told.
	 * @throws RouteException Always.
	 */
	private static function refuse( $order, array $taken, $message ) {

		foreach ( $taken as $row ) {
			Stock::give( $row[0], $row[1] );
		}

		$order->update_status( 'cancelled', __( 'Nexora: the order could not be placed.', 'nexora' ) . ' ' . $message . ' ' );

		throw new RouteException( 'nexora_order_refused', wp_strip_all_tags( $message ), 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text, tags stripped.
	}

	/**
	 * The "stock is gone" message.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	private static function stock_message( $name ) {
		/* translators: %s: product name. */
		return sprintf( __( 'Sorry, "%s" is no longer in stock in that quantity, so the order was not placed. Please review your cart.', 'nexora' ), $name );
	}
}
