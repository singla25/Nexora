<?php

namespace Nexora\Orders;

use Nexora\Core\Lock;
use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Notifications\Repository as Notifications;
use Nexora\Vendors\Repository as Sellers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The order e-mails and the in-app notifications that go with them.
 *
 *  - A seller is told when an order that contains their products reaches Processing (not while it is On hold): their own lines only.
 *  - The customer is told when a seller ships their items.
 *  - A seller is told when a product's stock is low (Orders\Low_Stock).
 *
 * E-mail is never sent while the request runs: it is queued as a scheduled single event (nexora_send_mail) so checkout and status changes
 * stay fast, and the job builds and sends it later. Every step is wrapped so that a mail problem (nothing scheduled, a mail server that
 * fails or throws) is logged and never breaks an order, a status change or a shipment. Each event is remembered on the order (or the
 * product), so a hook that fires twice does not send twice. No seller mail ever carries the buyer's name, e-mail, phone or address, and no
 * customer mail carries a seller's private details.
 */
class Mailer {

	const HOOK = 'nexora_send_mail';

	/**
	 * Registers the hooks.
	 */
	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 4 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 3 );
	}

	/**
	 * An order changed status: when it reaches Processing, each seller with a line in it is told once.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 */
	public static function on_status_changed( $order_id, $from, $to ) {

		unset( $from );

		if ( 'processing' !== $to ) {
			return;
		}

		try {
			$order = wc_get_order( (int) $order_id );

			if ( ! Lines::is_marketplace( $order ) ) {
				return;
			}

			foreach ( Lines::vendor_ids( $order ) as $vendor_id ) {
				self::once(
					$order,
					'new_order',
					$vendor_id,
					function () use ( $order, $vendor_id ) {
						self::notify(
							Sellers::user_for( $vendor_id ),
							'order_new',
							/* translators: %s: order number */
							sprintf( __( 'New order #%s contains your products.', 'nexora' ), $order->get_order_number() )
						);
					}
				);
			}
		} catch ( \Throwable $e ) {
			self::log( 'status change: ' . $e->getMessage() );
		}
	}

	/**
	 * A seller shipped their lines: tell the customer once.
	 *
	 * @param int $order_id  Order id.
	 * @param int $vendor_id Seller record.
	 */
	public static function shipped( $order_id, $vendor_id ) {

		try {
			$order = wc_get_order( (int) $order_id );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			self::once(
				$order,
				'shipped',
				(int) $vendor_id,
				function () use ( $order, $vendor_id ) {
					self::notify(
						(int) $order->get_customer_id(),
						'order_shipped',
						sprintf(
						/* translators: 1: seller business name, 2: order number */
							__( 'Your items from %1$s in order #%2$s have shipped.', 'nexora' ),
							(string) get_post_meta( $vendor_id, 'business_name', true ),
							$order->get_order_number()
						)
					);
				}
			);
		} catch ( \Throwable $e ) {
			self::log( 'shipped: ' . $e->getMessage() );
		}
	}

	/**
	 * Queues the low-stock e-mail for a product (called by Orders\Low_Stock, once per drop).
	 *
	 * @param int $product_id Product post id.
	 */
	public static function low_stock( $product_id ) {

		try {
			// The extra argument makes every alert its own event (WordPress drops a repeat of identical arguments within ten minutes).
			self::queue( array( 'low_stock', (int) $product_id, 0, wp_generate_uuid4() ) );
		} catch ( \Throwable $e ) {
			self::log( 'low stock: ' . $e->getMessage() );
		}
	}

	/**
	 * Stores an in-app notification from the system for a member.
	 *
	 * @param int    $user_id Receiver.
	 * @param string $type    Notification type.
	 * @param string $message Plain text.
	 */
	public static function notify( $user_id, $type, $message ) {

		$user = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : false;

		if ( ! $user ) {
			return;
		}

		try {
			( new Notifications() )->insert(
				array(
					'actor_user_id'      => 0,
					'actor_user_name'    => 'Nexora',
					'receiver_user_id'   => $user->ID,
					'receiver_user_name' => $user->user_login,
					'type'               => (string) $type,
					'message'            => (string) $message,
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'notification: ' . $e->getMessage() );
		}
	}

	/**
	 * The scheduled job: builds and sends one mail. Never throws.
	 *
	 * @param string $kind   new_order | shipped | low_stock.
	 * @param int    $a      Order id (new_order, shipped) or product id (low_stock).
	 * @param int    $b      Seller record (new_order, shipped).
	 * @param string $unique Makes an event unique (unused).
	 * @return bool Whether a mail was handed to wp_mail successfully.
	 */
	public static function run( $kind = '', $a = 0, $b = 0, $unique = '' ) {

		unset( $unique );

		try {
			$mail = 'low_stock' === $kind ? self::low_stock_mail( (int) $a ) : self::order_mail( (string) $kind, (int) $a, (int) $b );

			return $mail ? self::deliver( $mail['to'], $mail['subject'], $mail['body'] ) : false;
		} catch ( \Throwable $e ) {
			self::log( "job $kind: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Does the once-per-event part: remembers the event on the order, then runs the in-app step and queues the mail.
	 *
	 * @param \WC_Order $order     The order.
	 * @param string    $kind      new_order | shipped.
	 * @param int       $vendor_id Seller record.
	 * @param callable  $notify    Creates the in-app notification.
	 */
	private static function once( $order, $kind, $vendor_id, callable $notify ) {

		$vendor_id = (int) $vendor_id;
		$lock      = 'nx-mail-' . $order->get_id();

		if ( $vendor_id <= 0 || ! Lock::take( $lock ) ) {
			return;
		}

		try {
			$fresh = wc_get_order( $order->get_id() );
			$flag  = '_nx_mail_' . $kind . '_' . $vendor_id;

			if ( '' !== (string) $fresh->get_meta( $flag, true ) ) {
				return;
			}

			$fresh->update_meta_data( $flag, (string) time() );
			$fresh->save();
		} finally {
			Lock::release( $lock );
		}

		$notify();
		self::queue( array( $kind, (int) $order->get_id(), $vendor_id ) );
	}

	/**
	 * Schedules a single event for a job.
	 *
	 * @param array $args Job arguments.
	 */
	private static function queue( array $args ) {
		wp_schedule_single_event( time(), self::HOOK, $args );
	}

	/**
	 * Builds the mail of an order job.
	 *
	 * @param string $kind      new_order | shipped.
	 * @param int    $order_id  Order id.
	 * @param int    $vendor_id Seller record.
	 * @return array|null to, subject, body.
	 */
	private static function order_mail( $kind, $order_id, $vendor_id ) {

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! in_array( $kind, array( 'new_order', 'shipped' ), true ) || ! Lines::is_marketplace( $order ) || $vendor_id <= 0 ) {
			return null;
		}

		$lines = Earnings::vendor_lines( $order, $vendor_id );

		if ( 'shipped' === $kind ) {
			$lines = array_values(
				array_filter(
					$lines,
					function ( $line ) {
						return $line['shipped'];
					}
				)
			);
		}

		if ( ! $lines ) {
			return null;
		}

		$business = (string) get_post_meta( $vendor_id, 'business_name', true );
		$number   = (string) $order->get_order_number();

		if ( 'new_order' === $kind ) {

			$user = get_userdata( Sellers::user_for( $vendor_id ) );

			if ( ! $user ) {
				return null;
			}

			return array(
				'to'      => $user->user_email,
				/* translators: %s: order number */
				'subject' => sprintf( __( 'New order #%s contains your products', 'nexora' ), $number ),
				'body'    => View::render(
					'emails/new-order-seller',
					array(
						'greeting' => $user->display_name,
						'number'   => $number,
						'lines'    => $lines,
						'price'    => array( Display::class, 'price' ),
						'sales'    => Urls::profile_for( $user ),
					)
				),
			);
		}

		$to = (string) $order->get_billing_email();

		if ( ! is_email( $to ) ) {
			$customer = (int) $order->get_customer_id() ? get_userdata( (int) $order->get_customer_id() ) : false;
			$to       = $customer ? $customer->user_email : '';
		}

		if ( ! is_email( $to ) ) {
			return null;
		}

		return array(
			'to'      => $to,
			/* translators: 1: seller business name, 2: order number */
			'subject' => sprintf( __( 'Your items from %1$s have shipped (order #%2$s)', 'nexora' ), $business, $number ),
			'body'    => View::render(
				'emails/shipped-customer',
				array(
					'greeting' => $order->get_billing_first_name(),
					'business' => $business,
					'number'   => $number,
					'lines'    => $lines,
				)
			),
		);
	}

	/**
	 * Builds the low-stock mail.
	 *
	 * @param int $product_id Product post id.
	 * @return array|null to, subject, body.
	 */
	private static function low_stock_mail( $product_id ) {

		if ( $product_id <= 0 || \Nexora\Products\Repository::POST_TYPE !== get_post_type( $product_id ) ) {
			return null;
		}

		$user = get_userdata( Sellers::user_for( \Nexora\Products\Repository::vendor_of( $product_id ) ) );

		if ( ! $user ) {
			return null;
		}

		$data = \Nexora\Products\Repository::data( $product_id );

		return array(
			'to'      => $user->user_email,
			/* translators: %s: product name */
			'subject' => sprintf( __( 'Low stock: %s', 'nexora' ), $data['name'] ),
			'body'    => View::render(
				'emails/low-stock-seller',
				array(
					'greeting'  => $user->display_name,
					'name'      => $data['name'],
					'sku'       => $data['sku'],
					'stock'     => $data['stock'],
					'threshold' => Low_Stock::threshold(),
				)
			),
		);
	}

	/**
	 * Sends one HTML mail. A failure is logged, never raised.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject (one line).
	 * @param string $body    HTML body.
	 * @return bool
	 */
	private static function deliver( $to, $subject, $body ) {

		try {
			$sent = wp_mail( $to, sanitize_text_field( $subject ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		} catch ( \Throwable $e ) {
			self::log( 'send: ' . $e->getMessage() );
			return false;
		}

		if ( ! $sent ) {
			self::log( 'wp_mail could not send a message' );
		}

		return (bool) $sent;
	}

	/**
	 * Writes a problem to the WooCommerce log (never to the screen, never an exception).
	 *
	 * @param string $message What went wrong.
	 */
	private static function log( $message ) {

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( 'Nexora mail: ' . $message, array( 'source' => 'nexora-mail' ) );
		}
	}
}
