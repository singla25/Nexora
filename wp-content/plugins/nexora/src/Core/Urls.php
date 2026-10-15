<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The URLs of the pages Nexora depends on (slugs login-page, registration-page, profile-page).
 * One place to change them; everything else asks here instead of hard-coding paths.
 */
class Urls {

	const LOGIN_SLUG        = 'login-page';
	const REGISTRATION_SLUG = 'registration-page';
	const PROFILE_SLUG      = 'profile-page';

	/**
	 * URL of the login page.
	 *
	 * @param bool $trailing_slash End the URL with a slash.
	 * @return string
	 */
	public static function login( $trailing_slash = false ) {
		return home_url( '/' . self::LOGIN_SLUG . ( $trailing_slash ? '/' : '' ) );
	}

	/**
	 * URL of the registration page.
	 *
	 * @param bool $trailing_slash End the URL with a slash.
	 * @return string
	 */
	public static function registration( $trailing_slash = false ) {
		return home_url( '/' . self::REGISTRATION_SLUG . ( $trailing_slash ? '/' : '' ) );
	}

	/**
	 * Profile page of a member, or the bare profile page when no username is given.
	 *
	 * @param string $username Username, or an empty string for the bare profile page.
	 * @param bool   $trailing_slash End the URL with a slash.
	 * @return string
	 */
	public static function profile( $username = '', $trailing_slash = false ) {

		if ( '' === $username || null === $username ) {
			return home_url( '/' . self::PROFILE_SLUG . ( $trailing_slash ? '/' : '' ) );
		}

		return home_url( '/' . self::PROFILE_SLUG . '/' . rawurlencode( $username ) );
	}

	/**
	 * Where a user lands: administrators use the bare profile page, members their own.
	 *
	 * @param \WP_User|int $user The user.
	 * @param bool         $trailing_slash End the URL with a slash.
	 * @return string
	 */
	public static function profile_for( $user, $trailing_slash = false ) {

		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );

		if ( ! $user ) {
			return self::login( $trailing_slash );
		}

		return user_can( $user, 'manage_options' )
			? self::profile( '', $trailing_slash )
			: self::profile( $user->user_login );
	}

	/**
	 * A return URL that is safe to send a member to after logging in: on this site, over http(s), and a shopping
	 * or profile page. Anything else (other hosts, wp-admin, the login pages, odd characters) gives ''.
	 *
	 * @param mixed $url Requested URL (unslashed).
	 * @return string The absolute URL, or an empty string.
	 */
	public static function safe_return( $url ) {

		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = trim( $url );

		// Control characters and backslashes are how redirects get smuggled past host checks.
		if ( '' === $url || preg_match( '/[\x00-\x1f\x7f\\\\]/', $url ) ) {
			return '';
		}

		$valid = wp_validate_redirect( $url, '' );

		if ( '' === $valid ) {
			return '';
		}

		$home = wp_parse_url( home_url() );

		// A site-relative path becomes an absolute URL on this host.
		if ( 0 === strpos( $valid, '/' ) && 0 !== strpos( $valid, '//' ) ) {
			$valid = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $valid;
		}

		$parts = wp_parse_url( $valid );

		if ( ! $parts || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || strcasecmp( $parts['host'], $home['host'] ) !== 0 ) {
			return '';
		}

		$base = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		$path = $parts['path'];

		if ( '' !== $base ) {

			if ( 0 !== strpos( $path, $base . '/' ) ) {
				return '';
			}

			$path = substr( $path, strlen( $base ) );
		}

		$first = strtolower( strtok( ltrim( $path, '/' ), '/' ) );

		/**
		 * The first path segments a login may return to.
		 *
		 * @param string[] $paths Allowed first segments.
		 */
		$allowed = (array) apply_filters( 'nexora_return_url_paths', array( self::PROFILE_SLUG, 'cart', 'checkout', 'shop', 'marketplace' ) );

		return in_array( $first, $allowed, true ) ? $valid : '';
	}

	/**
	 * Where a member goes after logging in: a safe return URL if one was asked for, otherwise their own profile.
	 *
	 * @param \WP_User|int $user      The user who logged in.
	 * @param mixed        $requested Requested return URL (unslashed).
	 * @return string
	 */
	public static function after_login( $user, $requested = '' ) {

		$user = $user instanceof \WP_User ? $user : get_userdata( (int) $user );

		if ( ! $user ) {
			return self::login();
		}

		$safe = self::safe_return( $requested );

		return '' !== $safe ? $safe : self::profile_for( $user );
	}
}
