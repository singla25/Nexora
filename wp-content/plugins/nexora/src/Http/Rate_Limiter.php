<?php

namespace Nexora\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed-window rate limiter.
 *
 * Counting is a single atomic statement (object-cache increment when a persistent
 * cache exists, otherwise INSERT ... ON DUPLICATE KEY UPDATE), so concurrent
 * requests cannot slip past the limit by racing a read-modify-write.
 *
 * Limits are named buckets (see defaults()) that can be tuned with the
 * 'nexora_rate_limits' filter. Per-visitor buckets key on the client IP; per-member
 * buckets pass the user id as the subject.
 */
class Rate_Limiter {

	/**
	 * Tests only: pretend it is this unix time.
	 *
	 * @var int|null
	 */
	public static $time_override = null;

	/**
	 * Default limits, keyed by bucket name: [max hits, window in seconds].
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'login'              => array( 10, 15 * MINUTE_IN_SECONDS ),
			'otp_send'           => array( 5, 15 * MINUTE_IN_SECONDS ),
			'otp_verify'         => array( 20, 15 * MINUTE_IN_SECONDS ),
			'reset_password'     => array( 10, 15 * MINUTE_IN_SECONDS ),
			'register'           => array( 5, HOUR_IN_SECONDS ),
			'contact'            => array( 3, 10 * MINUTE_IN_SECONDS ),
			'password_change'    => array( 5, 15 * MINUTE_IN_SECONDS ),
			'connection_request' => array( 20, HOUR_IN_SECONDS ),
			'content_save'       => array( 20, HOUR_IN_SECONDS ),
			'chat_message'       => array( 30, MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * Limit and window of a bucket, or null for an unknown bucket.
	 *
	 * @param string $name Bucket name.
	 * @return array|null Limit and window in seconds.
	 */
	private static function config( $name ) {

		$all = apply_filters( 'nexora_rate_limits', self::defaults() );

		return isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? array( (int) $all[ $name ][0], max( 1, (int) $all[ $name ][1] ) ) : null;
	}

	/**
	 * Current unix time (overridable in tests).
	 *
	 * @return int
	 */
	private static function now() {
		return null !== self::$time_override ? (int) self::$time_override : time();
	}

	/**
	 * REMOTE_ADDR, unless the site owner names a trusted proxy header
	 * (define('NEXORA_CLIENT_IP_HEADER', 'HTTP_CF_CONNECTING_IP') or the
	 * 'nexora_client_ip_header' filter). Forwarded headers are never trusted by default.
	 *
	 * @return string
	 */
	public static function client_ip() {

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$header = apply_filters( 'nexora_client_ip_header', defined( 'NEXORA_CLIENT_IP_HEADER' ) ? NEXORA_CLIENT_IP_HEADER : '' );

		if ( $header && ! empty( $_SERVER[ $header ] ) ) {

			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) as $candidate ) {

				$candidate = trim( $candidate );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return $remote;
	}

	/**
	 * Storage key for a bucket, subject and the current window.
	 *
	 * @param string      $name Bucket name.
	 * @param string|null $subject What is being limited (user id, or null for the client IP).
	 * @param int         $window Window length in seconds.
	 * @return string
	 */
	private static function key( $name, $subject, $window ) {

		$subject = ( null === $subject || '' === $subject ) ? self::client_ip() : (string) $subject;
		$slot    = (int) floor( self::now() / $window );

		return 'nexora_rl_' . md5( $name . '|' . $subject ) . '_' . $slot;
	}

	/**
	 * Hits recorded for this subject in the current window.
	 *
	 * @param string      $name Bucket name.
	 * @param string|null $subject What is being limited (null for the client IP).
	 * @return int
	 */
	public static function count( $name, $subject = null ) {

		$cfg = self::config( $name );

		if ( ! $cfg ) {
			return 0;
		}

		$key = self::key( $name, $subject, $cfg[1] );

		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, 'nexora_rl' );
		}

		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	}

	/**
	 * True when the subject has already used up the window (does not count a hit).
	 *
	 * @param string      $name Bucket name.
	 * @param string|null $subject What is being limited (null for the client IP).
	 * @return bool
	 */
	public static function blocked( $name, $subject = null ) {

		$cfg = self::config( $name );

		return $cfg ? self::count( $name, $subject ) >= $cfg[0] : false;
	}

	/**
	 * Record one hit. Returns true when this hit is OVER the limit.
	 *
	 * @param string      $name Bucket name.
	 * @param string|null $subject What is being limited (null for the client IP).
	 * @return bool True when this hit is over the limit.
	 */
	public static function hit( $name, $subject = null ) {

		$cfg = self::config( $name );

		if ( ! $cfg ) {
			return false;
		}

		$key = self::key( $name, $subject, $cfg[1] );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'nexora_rl', $cfg[1] );
			$count = (int) wp_cache_incr( $key, 1, 'nexora_rl' );
		} else {
			global $wpdb;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
                 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
					$key
				)
			);

			$count = self::count( $name, $subject );

			// Drop expired windows for this subject (keeps the options table small)
			if ( 1 === $count ) {
				$prefix = substr( $key, 0, strrpos( $key, '_' ) + 1 );
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s",
						$wpdb->esc_like( $prefix ) . '%',
						$key
					)
				);
			}
		}

		return $count > $cfg[0];
	}

	/**
	 * Standard message for AJAX replies.
	 *
	 * @return string
	 */
	public static function message() {
		return 'Too many requests. Please try again later.';
	}
}
