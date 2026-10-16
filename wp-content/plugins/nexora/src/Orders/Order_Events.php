<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a change of order status does to stock. Earnings need no event: they are read from the order's CURRENT status every time
 * (Orders\Earnings), so a status change can never leave them wrong.
 *
 *  - To cancelled, failed or refunded: the stock the lines still hold goes back (Stock::restore_order).
 *  - From cancelled, failed or refunded back to an active status: the stock is taken again (Stock::retake_order); a line that cannot
 *    get its units is named in an order note.
 *
 * Both are safe to repeat: they work from what each line holds.
 */
class Order_Events {

	const GIVE_BACK = array( 'cancelled', 'failed', 'refunded' );
	const ACTIVE    = array( 'pending', 'on-hold', 'processing', 'completed' );

	/**
	 * Hook: woocommerce_order_status_changed.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Old status (without the wc- prefix).
	 * @param string    $to       New status.
	 * @param \WC_Order $order    The order (unused: a fresh copy is read).
	 */
	public static function status_changed( $order_id, $from, $to, $order = null ) {

		unset( $order );

		if ( in_array( $to, self::GIVE_BACK, true ) ) {
			Stock::restore_order( (int) $order_id );
			return;
		}

		if ( in_array( $from, self::GIVE_BACK, true ) && in_array( $to, self::ACTIVE, true ) ) {
			Stock::retake_order( (int) $order_id );
		}
	}
}
