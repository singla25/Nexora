<?php

namespace Nexora\Marketplace;

use Nexora\Core\View;
use Nexora\Products\Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Your cart by seller" panel above the cart block: one group per vendor (lines and subtotal) and a one-time list of what
 * changed in the cart since the member last looked. Read-only; the native block under it still does quantity changes and removal.
 */
class Cart_Panel {

	/**
	 * Hooks the panel in front of the cart block and loads its style on the cart page.
	 */
	public function __construct() {
		add_filter( 'render_block', array( __CLASS__, 'in_front_of_cart_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * Puts the panel before the WooCommerce cart block.
	 *
	 * @param string $content The block's HTML.
	 * @param array  $block   The block.
	 * @return string
	 */
	public static function in_front_of_cart_block( $content, $block ) {

		if ( is_admin() || ! is_array( $block ) || 'woocommerce/cart' !== ( $block['blockName'] ?? '' ) || ! function_exists( 'WC' ) || ! is_user_logged_in() ) {
			return $content;
		}

		return self::render() . $content;
	}

	/**
	 * Loads the panel's style on the cart page.
	 */
	public static function enqueue_style() {

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			\Nexora\Core\Assets::enqueue_tokens();
			wp_enqueue_style( 'nexora-marketplace', NEXORA_URL . 'assets/css/marketplace.css', array( 'nexora-tokens' ), NEXORA_VERSION );
		}
	}

	/**
	 * The panel's HTML: '' when there is nothing to show.
	 *
	 * @return string
	 */
	public static function render() {

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$groups = array();

		foreach ( WC()->cart->get_cart() as $line ) {

			$pid = Cart_Bridge::pid_of( $line );

			if ( $pid <= 0 || \Nexora\Products\Repository::owner_of( $pid ) <= 0 ) {
				continue;
			}

			$snap = Cart_Rules::snapshot( $pid );
			$vid  = (int) $snap['vendor_id'];
			$qty  = (int) $line['quantity'];

			if ( ! isset( $groups[ $vid ] ) ) {
				$groups[ $vid ] = array(
					'vendor'   => $snap['vendor_name'],
					'lines'    => array(),
					'subtotal' => 0.0,
				);
			}

			$groups[ $vid ]['subtotal'] += $snap['price_float'] * $qty;
			$groups[ $vid ]['lines'][]   = array(
				'name'  => $snap['name'],
				'qty'   => $qty,
				'unit'  => Presenter::money( $snap['price_float'] ),
				'total' => Presenter::money( $snap['price_float'] * $qty ),
				'image' => $snap['image_url'],
			);
		}

		$changes = Cart_Bridge::take_changes();

		if ( ! $groups && ! $changes ) {
			return '';
		}

		foreach ( $groups as &$group ) {
			$group['subtotal'] = Presenter::money( $group['subtotal'] );
		}

		return View::render(
			'marketplace/cart-panel',
			array(
				'groups'  => array_values( $groups ),
				'changes' => $changes,
			)
		);
	}
}
