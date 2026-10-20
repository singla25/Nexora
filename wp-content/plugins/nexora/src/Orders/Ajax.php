<?php

namespace Nexora\Orders;

use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;
use Nexora\Vendors\Vendor_Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The My Orders and Sales endpoints. Every call: nonce, login and profile (the member guard), a rate limit, then:
 *
 *  - nexora_my_orders: any member; their OWN orders (the customer is the logged-in member, nothing posted is used).
 *  - nexora_sales / nexora_sale_order: approved vendors, and suspended ones for reading their history (never anyone else); the vendor is
 *    the caller's own application, nothing posted names one. A sale_order id that is not a number, not an order, not a marketplace order,
 *    or not one with a line of the caller's, is refused with the same "forbidden" answer, which reveals nothing about the order.
 */
class Ajax extends Vendor_Ajax {

	/**
	 * Registers the actions.
	 */
	public function __construct() {
		\Nexora\Http\Ajax::register( 'my_orders', array( $this, 'my_orders' ) );
		\Nexora\Http\Ajax::register( 'sales', array( $this, 'sales' ) );
		\Nexora\Http\Ajax::register( 'sale_order', array( $this, 'sale_order' ) );
		\Nexora\Http\Ajax::register( 'mark_shipped', array( $this, 'mark_shipped' ) );
	}

	/**
	 * A page of the member's own orders as read-only cards.
	 */
	public function my_orders() {

		$member = $this->member();
		$this->throttle( $member['user_id'] );

		$result = Customer_Orders::page( $member['user_id'], $this->page() );

		wp_send_json_success(
			array(
				'html'  => View::render( 'orders/my-orders', array( 'orders' => $result['orders'] ) ),
				'page'  => $result['page'],
				'pages' => $result['pages'],
				'total' => $result['total'],
			)
		);
	}

	/**
	 * A page of the vendor's sales (only their lines) with the totals.
	 */
	public function sales() {

		$member = $this->earnings_viewer();
		$this->throttle( $member['user_id'] );

		$result  = Earnings::sales( $member['vendor_id'], $this->page() );
		$summary = Earnings::summary( $member['vendor_id'] );

		wp_send_json_success(
			array(
				'html'    => View::render( 'orders/sales', array( 'orders' => $result['orders'] ) ),
				'summary' => $summary,
				'totals'  => array(
					array(
						'label' => __( 'Sales counted (Processing and Completed)', 'nexora' ),
						'value' => Display::price( $summary['gross'] ),
					),
					array(
						'label' => __( 'Commission', 'nexora' ),
						'value' => Display::price( $summary['commission'] ),
					),
					array(
						'label' => __( 'Your earnings', 'nexora' ),
						'value' => Display::price( $summary['earning'] ),
					),
					array(
						'label' => __( 'Awaiting payment (On hold, Pending)', 'nexora' ),
						'value' => Display::price( $summary['awaiting'] ),
					),
				),
				'page'    => $result['page'],
				'pages'   => $result['pages'],
				'total'   => $result['total'],
			)
		);
	}

	/**
	 * One of the vendor's orders, only their lines, with what shipping needs.
	 */
	public function sale_order() {

		$member = $this->earnings_viewer();
		$this->throttle( $member['user_id'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in member(); Earnings::order_for_vendor() accepts only digits.
		$order_id = isset( $_POST['order_id'] ) ? wp_unslash( $_POST['order_id'] ) : '';
		$entry    = Earnings::order_for_vendor( $order_id, $member['vendor_id'] );

		if ( null === $entry ) {
			$this->forbid();
		}

		wp_send_json_success( array( 'html' => View::render( 'orders/sale-order', array( 'order' => $entry ) ) ) );
	}

	/**
	 * The seller marks their own lines of an order as shipped. Approved sellers only (a suspended one reads history, nothing more); the
	 * seller is the caller's own application, nothing posted names one; the order id must be digits. A foreign, unknown or non-marketplace
	 * order gets the same "forbidden" answer; an order that is not Processing or Completed gets a plain message. Clicking twice is harmless.
	 */
	public function mark_shipped() {

		$member = $this->seller();

		if ( Rate_Limiter::hit( 'order_ship', (string) $member['user_id'] ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in seller(); Fulfilment::ship() accepts only digits.
		$order_id = isset( $_POST['order_id'] ) ? wp_unslash( $_POST['order_id'] ) : '';
		$result   = Fulfilment::ship( $member['user_id'], $order_id );

		if ( is_wp_error( $result ) ) {

			if ( 'nexora_not_shippable' === $result->get_error_code() || 'nexora_busy' === $result->get_error_code() ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			$this->forbid();
		}

		$entry = Earnings::order_for_vendor( $order_id, $member['vendor_id'] );

		wp_send_json_success(
			array(
				'message'   => $result['shipped'] > 0 ? __( 'Marked as shipped.', 'nexora' ) : __( 'These items were already marked as shipped.', 'nexora' ),
				'shipped'   => $result['shipped'],
				'completed' => $result['completed'],
				'html'      => $entry ? View::render( 'orders/sale-order', array( 'order' => $entry ) ) : '',
			)
		);
	}

	/**
	 * The requested page: digits only, 1 to 1000, else 1.
	 *
	 * @return int
	 */
	private function page() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in member(); only digits are accepted.
		$value = isset( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : '1';

		return is_string( $value ) && ctype_digit( $value ) && strlen( $value ) <= 4 ? max( 1, min( 1000, (int) $value ) ) : 1;
	}

	/**
	 * One use of the orders rate limit.
	 *
	 * @param int $user_id The member.
	 */
	private function throttle( $user_id ) {

		if ( Rate_Limiter::hit( 'orders_view', (string) $user_id ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}
	}
}
