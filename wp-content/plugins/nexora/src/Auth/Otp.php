<?php

namespace Nexora\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Password-reset proof of ownership: a short emailed OTP, swapped for a single-use reset token.
 *
 * Storage (user meta): reset_otp (hash), otp_expiry, otp_attempts, reset_token (hash), reset_token_expiry.
 * The browser never learns a user id: it holds an opaque reference (see issue_ref) that maps to the
 * account server-side only for a real username+email pair.
 */
class Otp {

	const REF_PREFIX   = 'nexora_otp_ref_';
	const REF_TTL      = 25 * MINUTE_IN_SECONDS;
	const OTP_TTL      = 10 * MINUTE_IN_SECONDS;
	const TOKEN_TTL    = 10 * MINUTE_IN_SECONDS;
	const MAX_ATTEMPTS = 5;

	/*
	===============================
		REFERENCES
	=============================== */

	/**
	 * New opaque reference. With a user id it resolves to that account; without one it is a
	 * random value of the same shape that resolves to nothing (so replies look identical).
	 *
	 * @param int $user_id User ID.
	 * @return string 32 hex characters.
	 */
	public static function issue_ref( $user_id = 0 ) {

		$ref = bin2hex( random_bytes( 16 ) );

		if ( $user_id ) {
			set_transient( self::REF_PREFIX . $ref, (int) $user_id, self::REF_TTL );
		}

		return $ref;
	}

	/**
	 * Account id behind a reference, or 0.
	 *
	 * @param string $ref Opaque reference held by the browser.
	 * @return int User ID, or 0 when unknown or expired.
	 */
	public static function resolve_ref( $ref ) {

		$ref = is_string( $ref ) ? $ref : '';

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $ref ) ) {
			return 0;
		}

		return (int) get_transient( self::REF_PREFIX . $ref );
	}

	/**
	 * Discards a reset reference once it is no longer needed.
	 *
	 * @param string $ref Opaque reference held by the browser.
	 */
	public static function forget_ref( $ref ) {
		delete_transient( self::REF_PREFIX . $ref );
	}

	/*
	===============================
		OTP
	=============================== */

	/**
	 * True while an issued OTP has not expired yet.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_active_otp( $user_id ) {

		$expiry = (int) get_user_meta( $user_id, 'otp_expiry', true );

		return $expiry && time() < $expiry;
	}

	/**
	 * Create an OTP for the user and return it in clear (to be emailed). Only a hash is stored.
	 *
	 * @param int $user_id User ID.
	 * @return string The six-digit code (not stored in clear).
	 */
	public static function issue( $user_id ) {

		$otp = (string) random_int( 100000, 999999 );

		update_user_meta( $user_id, 'reset_otp', wp_hash_password( $otp ) );
		update_user_meta( $user_id, 'otp_expiry', time() + self::OTP_TTL );
		update_user_meta( $user_id, 'otp_attempts', 0 );
		delete_user_meta( $user_id, 'reset_token' );
		delete_user_meta( $user_id, 'reset_token_expiry' );

		return $otp;
	}

	/**
	 * Check an OTP. On success it is consumed and a one-time reset token is returned.
	 *
	 * @param int    $user_id User ID.
	 * @param string $otp The code the member typed.
	 * @return array ok (bool) plus message on failure or token on success.
	 */
	public static function verify( $user_id, $otp ) {

		$saved_hash = get_user_meta( $user_id, 'reset_otp', true );
		$expiry     = (int) get_user_meta( $user_id, 'otp_expiry', true );
		$attempts   = (int) get_user_meta( $user_id, 'otp_attempts', true );

		if ( ! $user_id || ! $saved_hash ) {
			return array(
				'ok'      => false,
				'message' => __( 'No OTP found', 'nexora' ),
			);
		}

		if ( time() > $expiry ) {
			self::clear( $user_id );
			return array(
				'ok'      => false,
				'message' => __( 'OTP expired', 'nexora' ),
			);
		}

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::clear( $user_id );
			return array(
				'ok'      => false,
				'message' => __( 'Too many wrong attempts. Please request a new OTP.', 'nexora' ),
			);
		}

		if ( ! wp_check_password( $otp, $saved_hash ) ) {
			update_user_meta( $user_id, 'otp_attempts', $attempts + 1 );
			return array(
				'ok'      => false,
				'message' => __( 'Invalid OTP', 'nexora' ),
			);
		}

		// OTP is single use; swap it for a short lived reset token
		$token = wp_generate_password( 32, false );

		delete_user_meta( $user_id, 'reset_otp' );
		delete_user_meta( $user_id, 'otp_expiry' );
		delete_user_meta( $user_id, 'otp_attempts' );

		update_user_meta( $user_id, 'reset_token', wp_hash_password( $token ) );
		update_user_meta( $user_id, 'reset_token_expiry', time() + self::TOKEN_TTL );

		return array(
			'ok'    => true,
			'token' => $token,
		);
	}

	/*
	===============================
		RESET TOKEN
	=============================== */

	/**
	 * True when $token is the live reset token of the user (does not consume it).
	 *
	 * @param int    $user_id User ID.
	 * @param string $token Reset token the browser sent.
	 * @return bool
	 */
	public static function token_valid( $user_id, $token ) {

		$saved_token = get_user_meta( $user_id, 'reset_token', true );
		$expiry      = (int) get_user_meta( $user_id, 'reset_token_expiry', true );

		return $saved_token && time() <= $expiry && wp_check_password( $token, $saved_token );
	}

	/**
	 * Remove every trace of an OTP / reset token.
	 *
	 * @param int $user_id User ID.
	 */
	public static function clear( $user_id ) {
		delete_user_meta( $user_id, 'reset_otp' );
		delete_user_meta( $user_id, 'otp_expiry' );
		delete_user_meta( $user_id, 'otp_attempts' );
		delete_user_meta( $user_id, 'reset_token' );
		delete_user_meta( $user_id, 'reset_token_expiry' );
	}
}
