<?php

namespace Nexora\Orders;

use Nexora\Accounts\Account_Types;
use Nexora\Core\View;
use Nexora\Vendors\Repository as Sellers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The seller's report block at the top of the Sales tab: the range filter, the totals for the range, a line chart of earnings over
 * time (inline SVG, with a table of the same numbers), the best sellers and the orders table. It is for the owner only: approved and
 * suspended sellers (a suspended one reads their history); anyone else gets an empty string. The seller always comes from the
 * logged-in user, never from the request; the request only carries the range and the page number.
 */
class Report_View {

	const RANGE_PARAM = 'nx_range';
	const PAGE_PARAM  = 'nx_orders_page';

	/**
	 * The report HTML for a seller, or '' when there is nothing to report to this user.
	 *
	 * @param int   $user_id The seller's user (the logged-in owner).
	 * @param array $request Request values (only the range and the page are read).
	 * @return string
	 */
	public static function seller_html( $user_id, array $request = array() ) {

		$user_id = (int) $user_id;

		if ( ! $user_id || get_current_user_id() !== $user_id || ! Account_Types::can_view_earnings( $user_id ) ) {
			return '';
		}

		$vendor_id = Sellers::id_for_user( $user_id );

		if ( ! $vendor_id ) {
			return '';
		}

		$range = Reports::range_from( isset( $request[ self::RANGE_PARAM ] ) ? $request[ self::RANGE_PARAM ] : '' );
		$page  = isset( $request[ self::PAGE_PARAM ] ) && is_string( $request[ self::PAGE_PARAM ] ) && ctype_digit( $request[ self::PAGE_PARAM ] ) && strlen( $request[ self::PAGE_PARAM ] ) <= 4 ? max( 1, (int) $request[ self::PAGE_PARAM ] ) : 1;
		$data  = Reports::seller( $vendor_id, $range, $page );

		return View::render(
			'orders/report',
			array(
				'data'     => $data,
				'ranges'   => self::ranges( $range ),
				'pager'    => self::pager( $data ),
				'chart'    => self::chart( $data['series'] ),
				'currency' => static function ( $amount ) {
					return Display::price( $amount );
				},
			)
		);
	}

	/**
	 * Prints the block for the logged-in owner, reading the range and page from the URL (the Sales tab calls this).
	 */
	public static function output_for_current_user() {

		// Only the range (an allow-list) and a page number are read; the seller is the logged-in user.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request = array_map( 'wp_unslash', array_filter( $_GET, 'is_string' ) );

		echo self::seller_html( get_current_user_id(), $request ); // phpcs:ignore WordPress.Security.EscapeOutput -- built by the escaped template.
	}

	/**
	 * The range links.
	 *
	 * @param string $current The chosen range.
	 * @return array[] label, url, current.
	 */
	private static function ranges( $current ) {

		$labels = array(
			'7'   => __( 'Last 7 days', 'nexora' ),
			'30'  => __( 'Last 30 days', 'nexora' ),
			'90'  => __( 'Last 90 days', 'nexora' ),
			'all' => __( 'All time', 'nexora' ),
		);
		$out    = array();

		foreach ( $labels as $range => $label ) {
			$out[] = array(
				'label'   => $label,
				'url'     => self::url( array( self::RANGE_PARAM => $range ) ),
				'current' => (string) $range === (string) $current,
			);
		}

		return $out;
	}

	/**
	 * The pagination links of the orders table.
	 *
	 * @param array $data The report.
	 * @return array[] label, url, current.
	 */
	private static function pager( array $data ) {

		$out = array();

		if ( $data['pages'] < 2 ) {
			return $out;
		}

		if ( $data['page'] > 1 ) {
			$out[] = array(
				'label'   => __( 'Previous', 'nexora' ),
				'url'     => self::url(
					array(
						self::RANGE_PARAM => $data['range'],
						self::PAGE_PARAM  => $data['page'] - 1,
					)
				),
				'current' => false,
			);
		}

		for ( $i = 1; $i <= $data['pages']; $i++ ) {
			$out[] = array(
				'label'   => (string) $i,
				'url'     => self::url(
					array(
						self::RANGE_PARAM => $data['range'],
						self::PAGE_PARAM  => $i,
					)
				),
				'current' => $i === (int) $data['page'],
			);
		}

		if ( $data['page'] < $data['pages'] ) {
			$out[] = array(
				'label'   => __( 'Next', 'nexora' ),
				'url'     => self::url(
					array(
						self::RANGE_PARAM => $data['range'],
						self::PAGE_PARAM  => $data['page'] + 1,
					)
				),
				'current' => false,
			);
		}

		return $out;
	}

	/**
	 * A link to this same page with the given report parameters (back to the Sales tab).
	 *
	 * @param array $args Parameters to set.
	 * @return string
	 */
	private static function url( array $args ) {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only used to build a link; esc_url() runs in the template.
		$here = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$here = remove_query_arg( array( self::RANGE_PARAM, self::PAGE_PARAM ), $here );

		return add_query_arg( $args, $here ) . '#sales';
	}

	/**
	 * The geometry of the earnings line chart.
	 *
	 * @param array[] $series Points: date, earnings.
	 * @return array width, height, points (x, y, date, earnings), path, max.
	 */
	private static function chart( array $series ) {

		$width  = 640;
		$height = 180;
		$pad    = 24;
		$max    = 0.0;

		foreach ( $series as $point ) {
			$max = max( $max, (float) $point['earnings'] );
		}

		$n      = count( $series );
		$points = array();

		foreach ( $series as $i => $point ) {
			$x        = 1 === $n ? $width / 2 : $pad + ( $width - 2 * $pad ) * $i / ( $n - 1 );
			$y        = $max > 0 ? $height - $pad - ( $height - 2 * $pad ) * (float) $point['earnings'] / $max : $height - $pad;
			$points[] = array(
				'x'        => round( $x, 1 ),
				'y'        => round( $y, 1 ),
				'date'     => $point['date'],
				'earnings' => $point['earnings'],
			);
		}

		return array(
			'width'  => $width,
			'height' => $height,
			'points' => $points,
			'path'   => implode(
				' ',
				array_map(
					static function ( $p ) {
						return $p['x'] . ',' . $p['y']; },
					$points
				)
			),
			'max'    => number_format( $max, 2, '.', '' ),
		);
	}
}
