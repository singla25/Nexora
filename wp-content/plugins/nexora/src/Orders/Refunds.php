<?php

namespace Nexora\Orders;

use Nexora\Core\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A refund reverses the vendor's share of the refunded amount, and gives the refunded units back to stock.
 *
 *  - A refund line that names an order line (qty and/or amount) is applied to that line.
 *  - An amount that names no line is shared across the marketplace lines by what each still has left to refund (60/40 order lines take
 *    60/40 of the amount).
 *  - For an amount A on a line of gross G with earning E, the vendor's share is round(A * E / G); the rest of A is the commission that is
 *    reversed. The refund that brings the line to its full gross reverses exactly what is left, so no cent stays behind.
 *  - Each refund carries a flag once applied: firing the same refund hook again changes nothing.
 */
class Refunds {

	const APPLIED = '_nx_refund_applied';

	/**
	 * Hook: woocommerce_order_refunded.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public static function apply( $order_id, $refund_id ) {

		$order_id = (int) $order_id;

		if ( $order_id <= 0 || ! Lock::take( 'nx-order-' . $order_id ) ) {
			return;
		}

		try {
			$order  = wc_get_order( $order_id );
			$refund = wc_get_order( (int) $refund_id );

			if ( ! Lines::is_marketplace( $order ) || ! $refund instanceof \WC_Order_Refund || $refund->get_meta( self::APPLIED, true ) ) {
				return;
			}

			self::reverse( $order, $refund );

			$refund->update_meta_data( self::APPLIED, (string) time() );
			$refund->save();
		} finally {
			Lock::release( 'nx-order-' . $order_id );
		}
	}

	/**
	 * Works out and records the reversal of one refund.
	 *
	 * @param \WC_Order        $order  The order.
	 * @param \WC_Order_Refund $refund The refund.
	 */
	private static function reverse( $order, $refund ) {

		$items   = array();
		$covered = 0;

		foreach ( Lines::tagged( $order ) as $item ) {

			if ( '' !== (string) $item->get_meta( '_nx_price', true ) ) {
				$items[ $item->get_id() ] = $item;
			}
		}

		$amount = array(); // item id => cents refunded on that line
		$qty    = array(); // item id => units refunded on that line

		foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $ri ) {

			$cents    = abs( Commission::cents( $ri->get_total() ) );
			$covered += $cents;
			$of       = (int) $ri->get_meta( '_refunded_item_id', true );

			if ( $ri instanceof \WC_Order_Item_Product && isset( $items[ $of ] ) ) {
				$amount[ $of ] = ( $amount[ $of ] ?? 0 ) + $cents;
				$qty[ $of ]    = ( $qty[ $of ] ?? 0 ) + abs( (int) $ri->get_quantity() );
			}
		}

		// The part of the refund that names no line is shared by what each line still has left to refund.
		$rest = max( 0, abs( Commission::cents( $refund->get_amount() ) ) - $covered );

		if ( $rest > 0 && $items ) {

			$room = array();

			foreach ( $items as $id => $item ) {
				$line        = Lines::read( $item );
				$room[ $id ] = max( 0, Commission::cents( $line['gross'] ) - Commission::cents( $line['refunded'] ) - ( $amount[ $id ] ?? 0 ) );
			}

			$total_room = array_sum( $room );
			$left       = min( $rest, $total_room );
			$ids        = array_keys( array_filter( $room ) );
			$last       = end( $ids );

			foreach ( $ids as $id ) {
				$part          = ( $id === $last ) ? $left : (int) round( $rest * $room[ $id ] / $total_room );
				$part          = min( $part, $left, $room[ $id ] );
				$left         -= $part;
				$amount[ $id ] = ( $amount[ $id ] ?? 0 ) + $part;
			}
		}

		foreach ( $amount as $id => $cents ) {

			$item  = $items[ $id ];
			$line  = Lines::read( $item );
			$gross = Commission::cents( $line['gross'] );
			$done  = Commission::cents( $line['refunded'] );
			$cents = min( $cents, max( 0, $gross - $done ) );

			if ( $cents <= 0 && empty( $qty[ $id ] ) ) {
				continue;
			}

			$earning = Commission::cents( $line['earning'] );
			$comm    = Commission::cents( $line['commission'] );
			$e_done  = Commission::cents( $line['earning_reversed'] );
			$c_done  = Commission::cents( $line['commission_reversed'] );

			if ( $gross > 0 && $done + $cents >= $gross ) {
				$e_rev = max( 0, $earning - $e_done ); // the last of the line: clear exactly what is left
				$c_rev = max( 0, $comm - $c_done );
			} else {
				$e_rev = $gross > 0 ? (int) round( $cents * $earning / $gross ) : 0;
				$e_rev = min( $e_rev, max( 0, $earning - $e_done ) );
				$c_rev = min( $cents - $e_rev, max( 0, $comm - $c_done ) );
			}

			Lines::bump( $item, '_nx_refunded', $cents, true );
			Lines::bump( $item, '_nx_earning_reversed', $e_rev, true );
			Lines::bump( $item, '_nx_commission_reversed', $c_rev, true );

			$units = min( (int) ( $qty[ $id ] ?? 0 ), max( 0, $line['qty'] - $line['refunded_qty'] ) );

			if ( $units > 0 ) {
				Lines::bump( $item, '_nx_refunded_qty', $units );
				Stock::release( $item, $units );
			}

			$item->save();
		}
	}
}
