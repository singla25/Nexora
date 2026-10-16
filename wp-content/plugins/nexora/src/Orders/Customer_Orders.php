<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A member's own orders ("My Orders"). The customer is always the logged-in member passed in by the endpoint, never a posted value, and
 * only placed marketplace orders are listed. Each card shows the lines as they were frozen when the order was placed.
 */
class Customer_Orders {

	/**
	 * One page of a member's orders, newest first.
	 *
	 * @param int $user_id The member.
	 * @param int $page    Page number (from 1).
	 * @return array orders, page, pages, total
	 */
	public static function page( $user_id, $page = 1 ) {

		$per    = max( 1, min( 50, (int) apply_filters( 'nexora_orders_per_page', 10 ) ) );
		$page   = max( 1, (int) $page );
		$result = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'status'      => array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ) ),
				'limit'       => $per,
				'page'        => $page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- only placed marketplace orders.
				'meta_key'    => Lines::ORDER_FLAG,
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$cards = array();

		foreach ( $result->orders as $order ) {

			if ( (int) $order->get_customer_id() !== (int) $user_id ) {
				continue; // never trust the query alone
			}

			$cards[] = self::card( $order );
		}

		return array(
			'orders' => $cards,
			'page'   => $page,
			'pages'  => max( 1, (int) $result->max_num_pages ),
			'total'  => (int) $result->total,
		);
	}

	/**
	 * One order as a card.
	 *
	 * @param \WC_Order $order The order.
	 * @return array
	 */
	private static function card( $order ) {

		$status = 0 === strpos( $order->get_status(), 'wc-' ) ? substr( $order->get_status(), 3 ) : $order->get_status();

		return array(
			'id'           => (int) $order->get_id(),
			'number'       => (string) $order->get_order_number(),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'payment'      => (string) $order->get_payment_method_title(),
			'total'        => Display::price( $order->get_total() ),
			'lines'        => Lines::of( $order ),
			'ship_to'      => array_filter(
				array(
					trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
					$order->get_shipping_address_1(),
					$order->get_shipping_address_2(),
					trim( $order->get_shipping_city() . ' ' . $order->get_shipping_postcode() ),
					trim( $order->get_shipping_state() . ' ' . $order->get_shipping_country() ),
				),
				'strlen'
			),
		);
	}
}
