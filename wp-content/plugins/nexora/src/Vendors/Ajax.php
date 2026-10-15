<?php

namespace Nexora\Vendors;

use Nexora\Http\Member_Ajax;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The member-side vendor actions: "Become a seller" and editing one's own business details.
 * Both only ever act on the logged-in member; nothing in the request names a user, a vendor or a status.
 */
class Ajax extends Member_Ajax {

	/**
	 * Registers the two actions (nonce `profile_nonce`, logged-in members only).
	 */
	public function __construct() {
		\Nexora\Http\Ajax::register( 'vendor_apply', array( $this, 'apply' ) );
		\Nexora\Http\Ajax::register( 'vendor_update', array( $this, 'update' ) );
	}

	/**
	 * Applies to become a seller. Always creates a PENDING application.
	 */
	public function apply() {

		$member  = $this->member();
		$user_id = $member['user_id'];

		if ( Rate_Limiter::blocked( 'vendor_apply', (string) $user_id ) ) {
			wp_send_json_error( array( 'message' => Rate_Limiter::message() ) );
		}

		$raw     = Validator::read_post();
		$checked = Validator::validate( $raw );

		if ( $checked['errors'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the highlighted fields.', 'nexora' ),
					'fields'  => $checked['errors'],
				)
			);
		}

		// Only well-formed attempts (the ones that reach the GSTIN lookup) use the daily allowance.
		Rate_Limiter::hit( 'vendor_apply', (string) $user_id );

		$result = Service::apply( $user_id, $raw );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Application received. We will review it shortly.', 'nexora' ),
				'status'  => 'pending',
			)
		);
	}

	/**
	 * Updates the member's own business details (which fields depends on their status).
	 */
	public function update() {

		$member = $this->member();

		$raw = array();

		foreach ( Fields::keys() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in member(); values are sanitised by Validator::validate().
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
				$raw[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}

		$result = Service::update_details( $member['user_id'], $raw );

		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success( array( 'message' => __( 'Details saved.', 'nexora' ) ) );
	}

	/**
	 * Ends the request with a JSON error: a message plus per-field messages when there are any.
	 *
	 * @param \WP_Error $error Error from the Service.
	 */
	private function send_error( \WP_Error $error ) {

		$data = $error->get_error_data();

		wp_send_json_error(
			array(
				'message' => $error->get_error_message(),
				'fields'  => is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array(),
			)
		);
	}
}
