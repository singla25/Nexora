<?php

namespace Nexora\Orders;

use Nexora\Core\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock follows the order. The product's stock number is the `stock` post meta the vendor edits.
 *
 *  - take() decrements it in ONE statement that only succeeds while enough is left, so two buyers can never both get the last unit.
 *  - Every order line counts what it took and what it gave back (Orders\Lines: _nx_stock_taken, _nx_stock_back); it can only give back
 *    what it still holds. A hook that fires twice, a cancel after a refund, a refunded status after a refund: each finds nothing left
 *    to give back, and a stock number the vendor raised by hand is never pushed above what they set.
 *  - A short lock per order makes two simultaneous requests for the same order take turns.
 */
class Stock {

	const META = 'stock';

	/**
	 * Takes units from a product's stock, atomically.
	 *
	 * @param int $product_id Product post id.
	 * @param int $qty        Units.
	 * @return bool False when there is not enough (nothing is taken).
	 */
	public static function take( $product_id, $qty ) {

		global $wpdb;

		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		if ( $product_id <= 0 || $qty <= 0 ) {
			return false;
		}

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one conditional UPDATE is what makes the decrement atomic.
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
				$qty,
				$product_id,
				self::META,
				$qty
			)
		);

		self::forget( $product_id );

		return 1 === (int) $rows;
	}

	/**
	 * Puts units back into a product's stock.
	 *
	 * @param int $product_id Product post id.
	 * @param int $qty        Units.
	 */
	public static function give( $product_id, $qty ) {

		global $wpdb;

		$product_id = (int) $product_id;
		$qty        = (int) $qty;

		if ( $product_id <= 0 || $qty <= 0 ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the matching atomic increment.
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = %s",
				$qty,
				$product_id,
				self::META
			)
		);

		self::forget( $product_id );
	}

	/**
	 * Gives back up to $qty units a line still holds. Does not save the item.
	 *
	 * @param \WC_Order_Item_Product $item Order item (a placed marketplace line).
	 * @param int                    $qty  Units wanted back.
	 * @return int Units really given back.
	 */
	public static function release( $item, $qty ) {

		$line = Lines::read( $item );
		$n    = min( max( 0, (int) $qty ), $line['stock_held'] );

		if ( $n > 0 ) {
			self::give( $line['product_id'], $n );
			Lines::bump( $item, '_nx_stock_back', $n );
		}

		return $n;
	}

	/**
	 * Gives back everything every line of the order still holds (cancelled, failed, refunded). Safe to repeat.
	 *
	 * @param \WC_Order|int $order Order or its id.
	 * @return int Units given back now.
	 */
	public static function restore_order( $order ) {

		return self::with_order(
			$order,
			function ( $order ) {

				$total = 0;

				foreach ( Lines::tagged( $order ) as $item ) {

					if ( '' === (string) $item->get_meta( '_nx_price', true ) ) {
						continue;
					}

					$n = self::release( $item, PHP_INT_MAX );

					if ( $n > 0 ) {
						$item->save();
						$total += $n;
					}
				}

				return $total;
			}
		);
	}

	/**
	 * Takes the stock again for lines of an order that was cancelled or failed and is now back in play. A line that cannot get its units is
	 * left without them and named in an order note. Safe to repeat (a line that holds its units is skipped).
	 *
	 * @param \WC_Order|int $order Order or its id.
	 * @return int Units taken now.
	 */
	public static function retake_order( $order ) {

		return self::with_order(
			$order,
			function ( $order ) {

				$total = 0;
				$short = array();

				foreach ( Lines::tagged( $order ) as $item ) {

					if ( '' === (string) $item->get_meta( '_nx_price', true ) ) {
						continue;
					}

					$line = Lines::read( $item );
					$need = $line['qty'] - $line['refunded_qty'];

					if ( $line['stock_held'] > 0 || $need <= 0 ) {
						continue;
					}

					if ( self::take( $line['product_id'], $need ) ) {
						Lines::bump( $item, '_nx_stock_taken', $need );
						$item->save();
						$total += $need;
					} else {
						$short[] = $line['name'];
					}
				}

				if ( $short ) {
					/* translators: %s: product names. */
					$order->add_order_note( sprintf( __( 'Nexora: the stock for %s is no longer available, so these units are not reserved for this order. Check it before shipping.', 'nexora' ), implode( ', ', $short ) ) );
				}

				return $total;
			}
		);
	}

	/**
	 * Runs a change on a freshly loaded order under that order's lock; a second request for the same order waits its turn by doing nothing.
	 *
	 * @param \WC_Order|int $order    Order or its id.
	 * @param callable      $callback Receives the fresh order, returns a number.
	 * @return int
	 */
	private static function with_order( $order, $callback ) {

		$id = $order instanceof \WC_Order ? $order->get_id() : (int) $order;

		if ( $id <= 0 || ! Lock::take( 'nx-order-' . $id ) ) {
			return 0;
		}

		try {
			$fresh  = wc_get_order( $id );
			$result = $fresh instanceof \WC_Order && Lines::is_marketplace( $fresh ) ? (int) $callback( $fresh ) : 0;
		} finally {
			Lock::release( 'nx-order-' . $id );
		}

		return $result;
	}

	/**
	 * Forgets cached copies of a product's meta after a direct update.
	 *
	 * @param int $product_id Product post id.
	 */
	private static function forget( $product_id ) {
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );
	}
}
