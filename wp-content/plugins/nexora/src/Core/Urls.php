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

	public static function login( $trailing_slash = false ) {
		return home_url( '/' . self::LOGIN_SLUG . ( $trailing_slash ? '/' : '' ) );
	}

	public static function registration( $trailing_slash = false ) {
		return home_url( '/' . self::REGISTRATION_SLUG . ( $trailing_slash ? '/' : '' ) );
	}

	/**
	 * Profile page of a member, or the bare profile page when no username is given.
	 */
	public static function profile( $username = '', $trailing_slash = false ) {

		if ( $username === '' || $username === null ) {
			return home_url( '/' . self::PROFILE_SLUG . ( $trailing_slash ? '/' : '' ) );
		}

		return home_url( '/' . self::PROFILE_SLUG . '/' . rawurlencode( $username ) );
	}

	/**
	 * Where a user lands: administrators use the bare profile page, members their own.
	 *
	 * @param \WP_User|int $user
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
}
