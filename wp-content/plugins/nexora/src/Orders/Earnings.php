<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a vendor has earned, and which orders and lines are theirs.
 *
 * Earnings are never kept as a running total: they are read from each order's CURRENT status and the line figures frozen when it was
 * placed (already reduced by refunds), so a status change can never leave them wrong.
 *
 *  - COUNTED statuses (Processing, Completed) are earned.
 *  - On hold and Pending are "awaiting payment": shown apart, not counted. A bank-transfer order waits On hold until it is marked paid;
 *    cash on delivery is Processing from the start.
 *  - Cancelled, Failed, Refunded and drafts never count.
 *
 * A vendor only ever gets their own lines (matched by the vendor id frozen on the line), never another vendor's, and only for orders
 * that carry their vendor id.
 */
class Earnings {

	const COUNTED  = array( 'processing', 'completed' );
	const AWAITING = array( 'pending', 'on-hold' );

	/**
	 * Whether an order in this status is earned.
	 *
	 * @param string $status Status, with or without the wc- prefix.
	 * @return bool
	 */
	public static function counts( $status ) {
		return in_array( self::plain( $status ), self::COUNTED, true );
	}

	/**
	 * Totals for a vendor over all their orders.
	 *
	 * @param int $vendor_id The vendor application post id.
	 * @return array gross, commission, earning (counted orders, after refunds), orders (counted), awaiting (earning on pending / on-hold orders)
	 */
	public static function summary( $vendor_id ) {

		$gross    = 0;
		$comm     = 0;
		$earn     = 0;
		$awaiting = 0;
		$orders   = 0;

		foreach ( self::order_ids( $vendor_id ) as $order_id ) {

			$order = wc_get_order( $order_id );
			$lines = self::vendor_lines( $order, $vendor_id );

			if ( ! $lines ) {
				continue;
			}

			$status = self::plain( $order->get_status() );

			if ( self::counts( $status ) ) {
				++$orders;
			}

			foreach ( $lines as $line ) {

				if ( self::counts( $status ) ) {
					$gross += Commission::cents( $line['gross'] ) - Commission::cents( $line['refunded'] );
					$comm  += Commission::cents( $line['net_commission'] );
					$earn  += Commission::cents( $line['net_earning'] );
				} elseif ( in_array( $status, self::AWAITING, true ) ) {
					$awaiting += Commission::cents( $line['net_earning'] );
				}
			}
		}

		return array(
			'gross'      => Commission::money( $gross ),
			'commission' => Commission::money( $comm ),
			'earning'    => Commission::money( $earn ),
			'orders'     => $orders,
			'awaiting'   => Commission::money( $awaiting ),
		);
	}

	/**
	 * Whether ANY order line, in any status (cancelled, refunded, even trashed), refers to this seller. The rule that keeps a seller
	 * record from being deleted for good.
	 *
	 * @param int $vendor_id The seller record post id.
	 * @return bool
	 */
	public static function has_orders( $vendor_id ) {

		if ( (int) $vendor_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$found = wc_get_orders(
			array(
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash', 'wc-checkout-draft' ) ),
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one existence check per delete.
				'meta_query' => self::vendor_query( $vendor_id ),
			)
		);

		return ! empty( $found );
	}

	/**
	 * One page of a vendor's orders, newest first, each with ONLY that vendor's lines.
	 *
	 * @param int      $vendor_id The vendor application post id.
	 * @param int      $page      Page number (from 1).
	 * @param int|null $since     Only orders created at or after this time (a Unix time), or null for all.
	 * @return array orders, page, pages, total
	 */
	public static function sales( $vendor_id, $page = 1, $since = null ) {

		$per  = self::per_page();
		$page = max( 1, (int) $page );
		$args = array(
			'status'     => self::statuses(),
			'limit'      => $per,
			'page'       => $page,
			'paginate'   => true,
			'orderby'    => 'date',
			'order'      => 'DESC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the one lookup of "orders of this vendor"; the order meta is the index.
			'meta_query' => self::vendor_query( $vendor_id ),
		);

		if ( null !== $since ) {
			$args['date_created'] = '>=' . (int) $since;
		}

		$result = wc_get_orders( $args );

		$out = array();

		foreach ( $result->orders as $order ) {

			$lines = self::vendor_lines( $order, $vendor_id );

			if ( $lines ) {
				$out[] = self::entry( $order, $lines );
			}
		}

		return array(
			'orders' => $out,
			'page'   => $page,
			'pages'  => max( 1, (int) $result->max_num_pages ),
			'total'  => (int) $result->total,
		);
	}

	/**
	 * One order for a vendor, or null: the order must be a placed marketplace order with a line of THIS vendor's.
	 *
	 * @param mixed $order_id  Order id.
	 * @param int   $vendor_id The vendor application post id.
	 * @return array|null The entry (see sales()) plus the shipping address for orders that still ship.
	 */
	public static function order_for_vendor( $order_id, $vendor_id ) {

		if ( ! is_int( $order_id ) && ! ( is_string( $order_id ) && ctype_digit( $order_id ) && strlen( $order_id ) <= 12 ) ) {
			return null;
		}

		$order = (int) $order_id > 0 ? wc_get_order( (int) $order_id ) : false;

		if ( ! Lines::is_marketplace( $order ) ) {
			return null;
		}

		$lines = self::vendor_lines( $order, $vendor_id );

		if ( ! $lines ) {
			return null;
		}

		$entry = self::entry( $order, $lines );

		// What shipping needs, and only while THIS seller still has something to ship: a counted order (Processing or Completed) with at
		// least one of their lines unshipped and not refunded. The recipient and the shipping address; never billing, e-mail or phone.
		$entry['ship_to'] = Fulfilment::has_open_lines( $order, $vendor_id )
			? array_filter(
				array(
					trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
					$order->get_shipping_address_1(),
					$order->get_shipping_address_2(),
					trim( $order->get_shipping_city() . ' ' . $order->get_shipping_postcode() ),
					trim( $order->get_shipping_state() . ' ' . $order->get_shipping_country() ),
				),
				'strlen'
			)
			: array();

		// The button is for a seller who may sell (a suspended seller reads history and cannot ship).
		$entry['can_ship'] = Fulfilment::has_open_lines( $order, $vendor_id ) && \Nexora\Accounts\Account_Types::can_sell( \Nexora\Vendors\Repository::user_for( $vendor_id ) );

		return $entry;
	}

	/**
	 * The placed lines of an order that belong to one vendor.
	 *
	 * @param \WC_Order|false $order     The order.
	 * @param int             $vendor_id The vendor application post id.
	 * @return array[]
	 */
	public static function vendor_lines( $order, $vendor_id ) {

		if ( ! Lines::is_marketplace( $order ) || (int) $vendor_id <= 0 ) {
			return array();
		}

		return array_values(
			array_filter(
				Lines::of( $order ),
				function ( $line ) use ( $vendor_id ) {
					return (int) $line['vendor_id'] === (int) $vendor_id;
				}
			)
		);
	}

	/**
	 * Ids of every order that has a line of this vendor.
	 *
	 * @param int      $vendor_id The vendor application post id.
	 * @param int|null $since     Only orders created at or after this time (a Unix time), or null for all.
	 * @return int[]
	 */
	public static function order_ids( $vendor_id, $since = null ) {

		if ( (int) $vendor_id <= 0 ) {
			return array();
		}

		$args = array(
			'status'     => self::statuses(),
			'limit'      => -1,
			'return'     => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- see sales().
			'meta_query' => self::vendor_query( $vendor_id ),
		);

		if ( null !== $since ) {
			$args['date_created'] = '>=' . (int) $since;
		}

		return array_map( 'intval', wc_get_orders( $args ) );
	}

	/**
	 * The order query for "has a line of this vendor".
	 *
	 * @param int $vendor_id The vendor application post id.
	 * @return array
	 */
	private static function vendor_query( $vendor_id ) {

		return array(
			array(
				'key'     => Lines::VENDOR_META,
				'value'   => (string) (int) $vendor_id,
				'compare' => '=',
			),
		);
	}

	/**
	 * Every real order status (drafts are not orders yet).
	 *
	 * @return string[]
	 */
	private static function statuses() {
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) );
	}

	/**
	 * Orders per page.
	 *
	 * @return int
	 */
	private static function per_page() {
		return max( 1, min( 50, (int) apply_filters( 'nexora_orders_per_page', 10 ) ) );
	}

	/**
	 * A status without the wc- prefix.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function plain( $status ) {
		return 0 === strpos( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status;
	}

	/**
	 * The shape of one order for a vendor.
	 *
	 * @param \WC_Order $order The order.
	 * @param array[]   $lines The vendor's lines.
	 * @return array
	 */
	private static function entry( $order, array $lines ) {

		$status = self::plain( $order->get_status() );
		$total  = array(
			'gross'      => 0,
			'commission' => 0,
			'earning'    => 0,
		);

		foreach ( $lines as $line ) {
			$total['gross']      += Commission::cents( $line['gross'] ) - Commission::cents( $line['refunded'] );
			$total['commission'] += Commission::cents( $line['net_commission'] );
			$total['earning']    += Commission::cents( $line['net_earning'] );
		}

		return array(
			'id'           => (int) $order->get_id(),
			'number'       => (string) $order->get_order_number(),
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
			'counted'      => self::counts( $status ),
			'awaiting'     => in_array( $status, self::AWAITING, true ),
			'payment'      => (string) $order->get_payment_method_title(),
			'lines'        => $lines,
			'totals'       => array_map( array( __NAMESPACE__ . '\Commission', 'money' ), $total ),
		);
	}
}
