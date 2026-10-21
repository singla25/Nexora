<?php

namespace Nexora\Orders;

use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-only reports of Phase 8: a seller's sales over a date range, and the administrator's marketplace overview.
 *
 * There is no money maths of its own. Every figure is a sum of the frozen order-line figures of Phase 6b (Orders\Lines: price, quantity,
 * commission, earning, the refund counters), added in whole cents with Orders\Commission, over the same statuses as Orders\Earnings:
 * Processing and Completed count; Pending and On hold are shown apart as "not counted yet"; every other status is in no total.
 * Nothing here reads the live product, so editing a price later changes nothing old. Reports hold no buyer data: a line carries product,
 * quantity, price, commission and earning only.
 *
 * Results are computed on the fly and kept for a few minutes in a transient; every order change (status, refund, new order) bumps the
 * cache version so the next call recomputes.
 */
class Reports {

	const RANGES        = array( '7', '30', '90', 'all' );
	const DEFAULT_RANGE = '30';
	const CACHE_SECONDS = 300;
	const VERSION_KEY   = 'nexora_rep_version';
	const SORTS         = array( 'name', 'orders', 'units', 'sales', 'commission', 'earnings' );
	const TOP_PRODUCTS  = 10;

	/**
	 * Hooks the cache clearing: every change to an order.
	 */
	public static function hooks() {

		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_order_refunded', 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_delete_order', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ), 99 );
		}
	}

	/**
	 * Forgets every cached report (a new version makes the old entries unreachable; they expire by themselves).
	 */
	public static function flush() {
		set_transient( self::VERSION_KEY, (string) microtime( true ) );
	}

	/**
	 * A range from a request value: only the allowed ones, otherwise the default.
	 *
	 * @param mixed $raw The value.
	 * @return string
	 */
	public static function range_from( $raw ) {
		$raw = is_int( $raw ) ? (string) $raw : $raw;

		return is_string( $raw ) && in_array( $raw, self::RANGES, true ) ? $raw : self::DEFAULT_RANGE;
	}

	/**
	 * The first moment of a range (site time): the start of today minus (days - 1) days, so "7 days" is today and the six before it.
	 *
	 * @param string $range 7 | 30 | 90 | all.
	 * @return int|null A Unix time, or null for all time.
	 */
	public static function since( $range ) {

		$range = self::range_from( $range );

		if ( 'all' === $range ) {
			return null;
		}

		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( (int) $range - 1 ) . ' days' )->getTimestamp();
	}

	/**
	 * A seller's report for a range.
	 *
	 * @param int    $vendor_id The seller record ID.
	 * @param string $range     7 | 30 | 90 | all.
	 * @param int    $page      Page of the orders table (from 1; past the end gives the last page).
	 * @return array range, since, totals (orders, units, sales, commission, earnings, reversed), awaiting (orders, earnings), series, products, orders, page, pages, total
	 */
	public static function seller( $vendor_id, $range = self::DEFAULT_RANGE, $page = 1 ) {

		$vendor_id = (int) $vendor_id;
		$range     = self::range_from( $range );
		$page      = max( 1, (int) $page );

		return self::cached(
			array( 'seller', $vendor_id, $range, $page ),
			function () use ( $vendor_id, $range, $page ) {
				return self::build_seller( $vendor_id, $range, $page );
			}
		);
	}

	/**
	 * The administrator's overview for a range.
	 *
	 * @param string $range   7 | 30 | 90 | all.
	 * @param string $orderby name | orders | units | sales | commission | earnings.
	 * @param string $order   asc | desc.
	 * @return array range, orderby, order, totals (orders, units, sales, commission, earnings), rows
	 */
	public static function overview( $range = self::DEFAULT_RANGE, $orderby = 'earnings', $order = 'desc' ) {

		$range   = self::range_from( $range );
		$orderby = is_string( $orderby ) && in_array( $orderby, self::SORTS, true ) ? $orderby : 'earnings';
		$order   = 'asc' === $order ? 'asc' : 'desc';

		return self::cached(
			array( 'overview', $range, $orderby, $order ),
			function () use ( $range, $orderby, $order ) {
				return self::build_overview( $range, $orderby, $order );
			}
		);
	}

	/**
	 * Runs a builder through the short cache.
	 *
	 * @param array    $parts Cache key parts.
	 * @param callable $build Builds the result.
	 * @return array
	 */
	private static function cached( array $parts, callable $build ) {

		$version = get_transient( self::VERSION_KEY );

		if ( false === $version ) {
			$version = (string) microtime( true );
			set_transient( self::VERSION_KEY, $version );
		}

		$key    = 'nexora_rep_' . md5( wp_json_encode( $parts ) . '|' . $version . '|' . wp_date( 'Y-m-d' ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $build();

		set_transient( $key, $result, self::CACHE_SECONDS );

		return $result;
	}

	/**
	 * Computes a seller's report.
	 *
	 * @param int    $vendor_id Seller record ID.
	 * @param string $range     Range.
	 * @param int    $page      Orders page.
	 * @return array
	 */
	private static function build_seller( $vendor_id, $range, $page ) {

		$since    = self::since( $range );
		$totals   = array_fill_keys( array( 'sales', 'commission', 'earnings', 'reversed', 'units', 'orders' ), 0 );
		$awaiting = array(
			'orders'   => 0,
			'earnings' => 0,
		);
		$days     = array();
		$products = array();

		foreach ( Earnings::orders( $vendor_id, $since ) as $order ) {

			$lines = Earnings::vendor_lines( $order, $vendor_id );

			if ( ! $lines ) {
				continue;
			}

			$status = 0 === strpos( (string) $order->get_status(), 'wc-' ) ? substr( (string) $order->get_status(), 3 ) : (string) $order->get_status();

			if ( Earnings::counts( $status ) ) {

				++$totals['orders'];
				$day = wp_date( 'Y-m-d', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() );

				foreach ( $lines as $line ) {

					$earning = Commission::cents( $line['net_earning'] );
					$units   = max( 0, (int) $line['qty'] - (int) $line['refunded_qty'] );

					$totals['sales']      += Commission::cents( $line['gross'] ) - Commission::cents( $line['refunded'] );
					$totals['commission'] += Commission::cents( $line['net_commission'] );
					$totals['earnings']   += $earning;
					$totals['reversed']   += Commission::cents( $line['earning_reversed'] );
					$totals['units']      += $units;
					$days[ $day ]          = ( isset( $days[ $day ] ) ? $days[ $day ] : 0 ) + $earning;

					$pid = (int) $line['product_id'];

					if ( ! isset( $products[ $pid ] ) ) {
						$products[ $pid ] = array(
							'product_id' => $pid,
							'name'       => (string) $line['name'],
							'sku'        => (string) $line['sku'],
							'units'      => 0,
							'cents'      => 0,
						);
					}

					$products[ $pid ]['units'] += $units;
					$products[ $pid ]['cents'] += $earning;
				}
			} elseif ( in_array( $status, Earnings::AWAITING, true ) ) {

				++$awaiting['orders'];

				foreach ( $lines as $line ) {
					$awaiting['earnings'] += Commission::cents( $line['net_earning'] );
				}
			}
		}

		ksort( $days );
		usort(
			$products,
			function ( $a, $b ) {

				foreach ( array( 'cents', 'units' ) as $key ) {
					if ( $a[ $key ] !== $b[ $key ] ) {
						return $b[ $key ] <=> $a[ $key ];
					}
				}

				return $a['product_id'] <=> $b['product_id'];
			}
		);

		$series = array();

		foreach ( $days as $day => $cents ) {
			$series[] = array(
				'date'     => $day,
				'earnings' => Commission::money( $cents ),
			);
		}

		$top = array();

		foreach ( array_slice( $products, 0, self::TOP_PRODUCTS ) as $product ) {
			$top[] = array(
				'product_id' => $product['product_id'],
				'name'       => $product['name'],
				'sku'        => $product['sku'],
				'units'      => $product['units'],
				'earnings'   => Commission::money( $product['cents'] ),
			);
		}

		$table = $vendor_id > 0 ? Earnings::sales( $vendor_id, $page, $since ) : array(
			'orders' => array(),
			'page'   => 1,
			'pages'  => 1,
			'total'  => 0,
		);

		// A page past the end shows the last page (WooCommerce reports no total for a page that is out of range, so ask for page 1 to learn it).
		if ( $page > 1 && ! $table['orders'] ) {

			$first = Earnings::sales( $vendor_id, 1, $since );
			$table = $page > $first['pages'] ? Earnings::sales( $vendor_id, $first['pages'], $since ) : $first;
		}

		return array(
			'range'    => $range,
			'since'    => $since,
			'totals'   => array(
				'orders'     => $totals['orders'],
				'units'      => $totals['units'],
				'sales'      => Commission::money( $totals['sales'] ),
				'commission' => Commission::money( $totals['commission'] ),
				'earnings'   => Commission::money( $totals['earnings'] ),
				'reversed'   => Commission::money( $totals['reversed'] ),
			),
			'awaiting' => array(
				'orders'   => $awaiting['orders'],
				'earnings' => Commission::money( $awaiting['earnings'] ),
			),
			'series'   => $series,
			'products' => $top,
			'orders'   => $table['orders'],
			'page'     => $table['page'],
			'pages'    => $table['pages'],
			'total'    => $table['total'],
		);
	}

	/**
	 * Computes the overview: every counted marketplace order in the range, grouped by the seller record its lines name.
	 *
	 * @param string $range   Range.
	 * @param string $orderby Column.
	 * @param string $order   Direction.
	 * @return array
	 */
	private static function build_overview( $range, $orderby, $order ) {

		$since = self::since( $range );
		$args  = array(
			'status'     => array( 'wc-processing', 'wc-completed' ),
			'limit'      => -1,
			// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the marketplace orders; the order meta is the index.
			// meta_key / meta_value (not meta_query) because they work on both WooCommerce order stores.
			'meta_key'   => Lines::ORDER_FLAG,
			'meta_value' => '1',
			// phpcs:enable
		);

		if ( null !== $since ) {
			$args['date_created'] = '>=' . (int) $since;
		}

		$sellers = array();
		$orders  = 0;

		// The orders come back as objects in one batch (WooCommerce reads their items together), so the cost does not grow per order.
		foreach ( wc_get_orders( $args ) as $wc_order ) {

			$lines = Lines::of( $wc_order );

			if ( ! $lines ) {
				continue;
			}

			++$orders;
			$seen = array();

			foreach ( $lines as $line ) {

				$sid = (int) $line['vendor_id'];

				if ( ! isset( $sellers[ $sid ] ) ) {
					$sellers[ $sid ] = array_fill_keys( array( 'orders', 'units', 'sales', 'commission', 'earnings' ), 0 );
				}

				if ( ! isset( $seen[ $sid ] ) ) {
					$seen[ $sid ] = true;
					++$sellers[ $sid ]['orders'];
				}

				$sellers[ $sid ]['units']      += max( 0, (int) $line['qty'] - (int) $line['refunded_qty'] );
				$sellers[ $sid ]['sales']      += Commission::cents( $line['gross'] ) - Commission::cents( $line['refunded'] );
				$sellers[ $sid ]['commission'] += Commission::cents( $line['net_commission'] );
				$sellers[ $sid ]['earnings']   += Commission::cents( $line['net_earning'] );
			}
		}

		$rows   = array();
		$totals = array_fill_keys( array( 'units', 'sales', 'commission', 'earnings' ), 0 );

		foreach ( $sellers as $sid => $figures ) {

			$exists = in_array( get_post_type( $sid ), Post_Types::sellers(), true );
			$rows[] = array(
				'seller_id'  => $sid,
				'name'       => $exists ? (string) get_post_field( 'post_title', $sid ) : __( 'Deleted seller', 'nexora' ),
				'deleted'    => ! $exists,
				'orders'     => $figures['orders'],
				'units'      => $figures['units'],
				'sales'      => Commission::money( $figures['sales'] ),
				'commission' => Commission::money( $figures['commission'] ),
				'earnings'   => Commission::money( $figures['earnings'] ),
			);

			foreach ( $totals as $key => $_ ) {
				$totals[ $key ] += $figures[ $key ];
			}
		}

		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $order ) {

				$cmp = 'name' === $orderby ? strcasecmp( $a['name'], $b['name'] ) : (float) $a[ $orderby ] <=> (float) $b[ $orderby ];
				$cmp = 0 === $cmp ? $a['seller_id'] <=> $b['seller_id'] : $cmp;

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return array(
			'range'   => $range,
			'orderby' => $orderby,
			'order'   => $order,
			'totals'  => array(
				'orders'     => $orders,
				'units'      => $totals['units'],
				'sales'      => Commission::money( $totals['sales'] ),
				'commission' => Commission::money( $totals['commission'] ),
				'earnings'   => Commission::money( $totals['earnings'] ),
			),
			'rows'    => $rows,
		);
	}
}
