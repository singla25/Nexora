<?php

namespace Nexora\Marketplace;

use Nexora\Core\Urls;
use Nexora\Http\Ajax;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Add to cart" endpoint behind the product card's button. It reads ONLY the product id and a quantity from the request;
 * a posted price, name, vendor, stock, image or cart data is never looked at. A guest is answered with the sign-in address.
 */
class Cart_Ajax {

	/**
	 * Registers the action for members only (a guest sees a sign-in link, never the button).
	 */
	public function __construct() {
		Ajax::register( 'add_to_cart', array( $this, 'add' ) );
	}

	/**
	 * Adds the product to the member's cart.
	 */
	public function add() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message'   => __( 'Please sign in to buy.', 'nexora' ),
					'login_url' => add_query_arg( 'redirect_to', rawurlencode( Urls::profile() ), Urls::login() ),
				)
			);
		}

		$member = Ajax::member( 'profile_nonce', false );

		if ( Rate_Limiter::hit( 'add_to_cart', (string) $member['user_id'] ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		// Only these two values are read; anything else in the request (price, name, vendor, stock, image, cart data) is ignored.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$product_id = isset( $_POST['product_id'] ) ? wp_unslash( $_POST['product_id'] ) : '';
		$quantity   = isset( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : '1';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = Cart_Bridge::add( $member['user_id'], $product_id, $quantity );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Added to your cart.', 'nexora' ),
				'cart_url' => wc_get_cart_url(),
				'count'    => (int) WC()->cart->get_cart_contents_count(),
			)
		);
	}
}
