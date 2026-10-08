<?php

namespace Nexora\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared plumbing for AJAX handlers: registering an action under its nexora_ name
 * (with the old generic name kept as a deprecated alias) and the standard
 * nonce + login + profile guard.
 */
class Ajax {

	const PREFIX = 'nexora_';

	/**
	 * Register $callback for wp_ajax_nexora_<legacy>. The un-prefixed $legacy name keeps
	 * working for one release and fires 'nexora_deprecated_ajax_action' so it can be tracked.
	 *
	 * @param string   $legacy Old action name, e.g. 'get_requests'.
	 * @param callable $callback Handler.
	 * @param bool     $guest Also reachable by logged-out visitors (wp_ajax_nopriv_*).
	 */
	public static function register( $legacy, $callback, $guest = false ) {

		$new = self::PREFIX . $legacy;

		add_action( 'wp_ajax_' . $new, $callback );

		$alias = function () use ( $legacy, $new, $callback ) {
			do_action( 'nexora_deprecated_ajax_action', $legacy, $new );
			call_user_func( $callback );
		};

		add_action( 'wp_ajax_' . $legacy, $alias );

		if ( $guest ) {
			add_action( 'wp_ajax_nopriv_' . $new, $callback );
			add_action( 'wp_ajax_nopriv_' . $legacy, $alias );
		}
	}

	/**
	 * Guard for logged-in member endpoints: nonce, login, 'read' capability and
	 * (optionally) a linked user_profile post. Ends the request on failure.
	 *
	 * @param string      $nonce_action Nonce action the page localized.
	 * @param bool        $require_profile Require a user_profile post for the current user.
	 * @param string|null $unauth_message Error text for logged-out callers (a generic one when omitted).
	 * @return array user_id and profile_id.
	 */
	public static function member( $nonce_action = 'profile_nonce', $require_profile = true, $unauth_message = null ) {

		check_ajax_referer( $nonce_action, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( null !== $unauth_message ? $unauth_message : __( 'Unauthorized access', 'nexora' ) );
		}

		$user_id = get_current_user_id();

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( __( 'Permission denied', 'nexora' ) );
		}

		$profile_id = (int) get_user_meta( $user_id, '_profile_id', true );

		if ( $require_profile && ( ! $profile_id || get_post_type( $profile_id ) !== 'user_profile' ) ) {
			wp_send_json_error( __( 'Profile not found', 'nexora' ) );
		}

		return array(
			'user_id'    => $user_id,
			'profile_id' => $profile_id,
		);
	}
}
