<?php

namespace Nexora\Orders;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Marketplace overview" under the Nexora System menu: total sales, commission and counted orders for a range, and a per-seller table
 * (sortable by column). Administrators only (manage_options); read-only; the numbers come from Orders\Reports.
 */
class Overview_Page {

	const SLUG = 'nexora-marketplace-overview';

	/**
	 * Adds the menu entry.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
	}

	/**
	 * Registers the page under Nexora System.
	 */
	public function menu() {

		add_submenu_page(
			'nexora-system',
			__( 'Marketplace overview', 'nexora' ),
			__( 'Marketplace overview', 'nexora' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the page.
	 */
	public function render() {

		// Only the range and the sort column/direction are read; each goes through an allow-list in Orders\Reports.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request = array_map( 'wp_unslash', array_filter( $_GET, 'is_string' ) );

		echo self::html( $request ); // phpcs:ignore WordPress.Security.EscapeOutput -- built by the escaped template.
	}

	/**
	 * The page HTML, or '' for anyone who may not manage the site.
	 *
	 * @param array $request nx_range, orderby, order.
	 * @return string
	 */
	public static function html( array $request ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$range = Reports::range_from( isset( $request['nx_range'] ) ? $request['nx_range'] : '' );
		$data  = Reports::overview( $range, isset( $request['orderby'] ) ? $request['orderby'] : 'earnings', isset( $request['order'] ) ? $request['order'] : 'desc' );
		$base  = admin_url( 'admin.php?page=' . self::SLUG );

		$ranges = array();

		foreach ( array(
			'7'   => __( 'Last 7 days', 'nexora' ),
			'30'  => __( 'Last 30 days', 'nexora' ),
			'90'  => __( 'Last 90 days', 'nexora' ),
			'all' => __( 'All time', 'nexora' ),
		) as $key => $label ) {
			$ranges[] = array(
				'label'   => $label,
				'url'     => add_query_arg( array( 'nx_range' => $key ), $base ),
				'current' => (string) $key === $range,
			);
		}

		$heads = array(
			'name'       => __( 'Seller', 'nexora' ),
			'orders'     => __( 'Orders', 'nexora' ),
			'units'      => __( 'Units', 'nexora' ),
			'sales'      => __( 'Sales', 'nexora' ),
			'commission' => __( 'Commission', 'nexora' ),
			'earnings'   => __( 'Seller earnings', 'nexora' ),
		);

		foreach ( $heads as $col => $label ) {
			$next          = $data['orderby'] === $col && 'desc' === $data['order'] ? 'asc' : 'desc';
			$heads[ $col ] = array(
				'label'  => $label,
				'url'    => add_query_arg(
					array(
						'nx_range' => $range,
						'orderby'  => $col,
						'order'    => 'name' === $col && $data['orderby'] !== $col ? 'asc' : $next,
					),
					$base
				),
				'sorted' => $data['orderby'] === $col ? $data['order'] : '',
			);
		}

		return View::render(
			'orders/report-overview',
			array(
				'data'   => $data,
				'ranges' => $ranges,
				'heads'  => $heads,
				'price'  => static function ( $amount ) {
					return Display::price( $amount );
				},
			)
		);
	}
}
