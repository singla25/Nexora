<?php

namespace Nexora\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for the AJAX classes serving logged-in members (profile, connections, content, ...):
 * the standard guard plus the small input helpers they all need.
 */
abstract class Member_Ajax {

	/**
	 * Nonce + login + capability + linked profile. Ends the request on failure.
	 *
	 * @return array{user_id:int,profile_id:int}
	 */
	protected function member() {
		return Ajax::member( 'profile_nonce', true, 'Unauthorized access' );
	}

	/** A sanitised single-line value from $_POST ('' when absent). */
	protected function post_value( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * An attachment may only be linked by the user who uploaded it (or an administrator).
	 */
	protected function owns_attachment( $attachment_id, $user_id ) {

		if ( get_post_type( $attachment_id ) !== 'attachment' ) {
			return false;
		}

		return (int) get_post_field( 'post_author', $attachment_id ) === (int) $user_id
			|| current_user_can( 'manage_options' );
	}
}
