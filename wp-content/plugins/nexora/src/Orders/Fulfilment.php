<?php

namespace Nexora\Orders;

use Nexora\Core\Lock;
use Nexora\Products\Service as Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A seller marks THEIR OWN lines of an order as shipped.
 *
 *  - Only an approved seller (a suspended one reads history, nothing more), only their own lines of the order, only while the order is in
 *    a counted status (Processing or Completed). Anything else is refused and nothing changes.
 *  - The flag and the time sit on the order line (Orders\Lines); marking is idempotent: a line already shipped is left as it is.
 *  - Shipping touches no money: earnings, commission and every report read the same frozen figures.
 *  - When every non-refunded line of a Processing order is shipped, the order moves to Completed through the WooCommerce API (with an
 *    order note). An order in any other status is never touched, and nothing is completed that still has an unshipped line.
 *  - The customer is told once (queued e-mail and an in-app notification), through Orders\Mailer.
 */
class Fulfilment {

	/**
	 * Marks the seller's open lines of an order as shipped.
	 *
	 * @param int   $user_id  The seller's user (never taken from the request).
	 * @param mixed $order_id The order (digits only).
	 * @return array|\WP_Error array( 'shipped' => lines marked now, 'completed' => whether the order was completed now )
	 */
	public static function ship( $user_id, $order_id ) {

		$user_id   = (int) $user_id;
		$vendor_id = $user_id ? Products::vendor_for( $user_id ) : 0;

		if ( ! $vendor_id || ! self::is_id( $order_id ) ) {
			return self::forbidden();
		}

		$order = wc_get_order( (int) $order_id );

		if ( ! Lines::is_marketplace( $order ) || ! Earnings::vendor_lines( $order, $vendor_id ) ) {
			return self::forbidden();
		}

		if ( ! Earnings::counts( $order->get_status() ) ) {
			return new \WP_Error( 'nexora_not_shippable', __( 'This order cannot be shipped yet. Items can be marked as shipped once the order is Processing or Completed.', 'nexora' ) );
		}

		$lock = 'nx-ship-' . $order->get_id();

		if ( ! Lock::take( $lock ) ) {
			return new \WP_Error( 'nexora_busy', __( 'This order is being updated right now. Please try again in a moment.', 'nexora' ) );
		}

		try {
			$result = self::mark( (int) $order->get_id(), $vendor_id );
		} finally {
			Lock::release( $lock );
		}

		return $result;
	}

	/**
	 * Completes a Processing order whose every non-refunded line is shipped (at least one line must be). Any other status, or any
	 * unshipped line, leaves the order alone. Safe to repeat.
	 *
	 * @param int $order_id Order id.
	 * @return bool Whether the order was completed now.
	 */
	public static function maybe_complete( $order_id ) {

		$order = wc_get_order( (int) $order_id );

		if ( ! Lines::is_marketplace( $order ) || 'processing' !== $order->get_status() ) {
			return false;
		}

		$shippable = 0;

		foreach ( Lines::of( $order ) as $line ) {

			if ( (int) $line['qty'] - (int) $line['refunded_qty'] <= 0 ) {
				continue;
			}

			if ( ! $line['shipped'] ) {
				return false;
			}

			++$shippable;
		}

		if ( 0 === $shippable ) {
			return false;
		}

		$order->update_status( 'completed', __( 'Nexora: every item was marked as shipped by its seller, so the order is completed.', 'nexora' ) );

		return true;
	}

	/**
	 * Whether a seller still has something to ship on an order: a counted status and at least one unshipped, non-refunded line of theirs.
	 *
	 * @param \WC_Order|false $order     The order.
	 * @param int             $vendor_id The seller record.
	 * @return bool
	 */
	public static function has_open_lines( $order, $vendor_id ) {

		if ( ! $order instanceof \WC_Order || ! Earnings::counts( $order->get_status() ) ) {
			return false;
		}

		foreach ( Earnings::vendor_lines( $order, $vendor_id ) as $line ) {
			if ( Lines::open_to_ship( $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The work under the lock: flag the seller's open lines, note it, tell the customer, complete the order if that was the last one.
	 *
	 * @param int $order_id  Order id.
	 * @param int $vendor_id The seller record.
	 * @return array
	 */
	private static function mark( $order_id, $vendor_id ) {

		$order  = wc_get_order( $order_id );
		$marked = 0;

		foreach ( Lines::tagged( $order ) as $item ) {

			if ( '' === (string) $item->get_meta( '_nx_price', true ) ) {
				continue;
			}

			$line = Lines::read( $item );

			if ( (int) $line['vendor_id'] !== (int) $vendor_id || ! Lines::open_to_ship( $line ) ) {
				continue;
			}

			$item->update_meta_data( Lines::SHIPPED, '1' );
			$item->update_meta_data( Lines::SHIPPED_AT, gmdate( 'Y-m-d H:i:s' ) );
			$item->save();
			++$marked;
		}

		if ( $marked > 0 ) {

			$business = (string) get_post_meta( $vendor_id, 'business_name', true );
			/* translators: %s: the seller's business name. */
			$order->add_order_note( sprintf( __( 'Nexora: %s marked its items as shipped.', 'nexora' ), $business ) );

			Mailer::shipped( $order_id, $vendor_id );
		}

		return array(
			'shipped'   => $marked,
			'completed' => self::maybe_complete( $order_id ),
		);
	}

	/**
	 * Whether a value is a plain order id (a positive whole number or digits, not an array or a float).
	 *
	 * @param mixed $value The value.
	 * @return bool
	 */
	private static function is_id( $value ) {
		return ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) <= 12 && (int) $value > 0 );
	}

	/**
	 * The one refusal for "not yours / not an order / not allowed" (it reveals nothing about the order).
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
	}
}
