<?php

namespace Nexora\Products;

use Nexora\Http\Rate_Limiter;
use Nexora\Vendors\Vendor_Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The vendor's product actions. Only approved vendors get past seller(); the vendor and the owner always come
 * from the session, never from the request, so a posted vendor_id, user_id, source or status is ignored.
 */
class Ajax extends Vendor_Ajax {

	/**
	 * Registers the two actions (nonce `profile_nonce`, logged-in members only).
	 */
	public function __construct() {
		\Nexora\Http\Ajax::register( 'product_save', array( $this, 'save' ) );
		\Nexora\Http\Ajax::register( 'product_delete', array( $this, 'delete' ) );
	}

	/**
	 * Adds a product, or edits one of the caller's own (product_id).
	 */
	public function save() {

		$member = $this->seller();

		if ( Rate_Limiter::hit( 'product_save', (string) $member['user_id'] ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		$raw = array();

		foreach ( Fields::keys() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in seller(); Validator::validate() sanitises every value.
			$raw[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		}

		$result = Service::save( $member['user_id'], $raw, $this->product_id() );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success(
			array(
				'message'    => __( 'Product saved.', 'nexora' ),
				'product_id' => $result['product_id'],
			)
		);
	}

	/**
	 * Deletes one of the caller's own products.
	 */
	public function delete() {

		$member = $this->seller();

		if ( Rate_Limiter::hit( 'product_save', (string) $member['user_id'] ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		$id = $this->product_id();

		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nexora' ) ) );
		}

		$result = Service::delete( $member['user_id'], $id );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success( array( 'message' => __( 'Product deleted.', 'nexora' ) ) );
	}

	/**
	 * The posted product_id: 0 when absent or empty; anything that is not a whole number ends the request.
	 *
	 * @return int
	 */
	private function product_id() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in seller().
		if ( ! isset( $_POST['product_id'] ) || '' === $_POST['product_id'] ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only, checked next.
		$value = wp_unslash( $_POST['product_id'] );

		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		if ( ! is_string( $value ) || ! ctype_digit( $value ) || strlen( $value ) > 12 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'nexora' ) ) );
		}

		return (int) $value;
	}

	/**
	 * Ends the request with a JSON error: forbidden (no detail), or a message plus per-field messages.
	 *
	 * @param \WP_Error $error Error from the Service.
	 */
	private function send_error( \WP_Error $error ) {

		if ( 'nexora_forbidden' === $error->get_error_code() ) {
			$this->forbid();
		}

		$data = $error->get_error_data();

		wp_send_json_error(
			array(
				'message' => $error->get_error_message(),
				'fields'  => is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array(),
			)
		);
	}
}
