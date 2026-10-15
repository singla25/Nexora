<?php

namespace Nexora\Products;

use Nexora\Core\View;
use Nexora\Http\Member_Ajax;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The marketplace listing endpoint: any logged-in member (customers and vendors alike) gets the cards of one page.
 */
class Marketplace_Ajax extends Member_Ajax {

	/**
	 * Registers the action.
	 */
	public function __construct() {
		\Nexora\Http\Ajax::register( 'marketplace_list', array( $this, 'list_products' ) );
	}

	/**
	 * Returns one page of cards as server-escaped HTML.
	 */
	public function list_products() {

		$member = $this->member();

		if ( Rate_Limiter::hit( 'marketplace_list', (string) $member['user_id'] ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		$args = array();

		foreach ( array( 'search', 'category', 'page' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in member(); Listing::query() validates every value.
			$args[ $key ] = isset( $_POST[ $key ] ) && ( is_string( $_POST[ $key ] ) || is_int( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : '';
		}

		$result = Listing::query( $args );

		wp_send_json_success(
			array(
				'html'  => View::render(
					'marketplace/results',
					array(
						'cards' => Presenter::cards( $result['ids'], $member['user_id'] ),
						'page'  => $result['page'],
						'pages' => $result['pages'],
						'total' => $result['total'],
					)
				),
				'page'  => $result['page'],
				'pages' => $result['pages'],
				'total' => $result['total'],
			)
		);
	}
}
