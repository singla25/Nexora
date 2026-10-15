<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A short-lived lock on a name (add_option is atomic), so two requests cannot create the same thing at once.
 */
class Lock {

	const PREFIX = 'nexora_lock_';

	/**
	 * Takes the lock. A lock left by a crashed request expires after a minute.
	 *
	 * @param string $name What is being locked.
	 * @return bool Whether the lock was obtained.
	 */
	public static function take( $name ) {

		$key = self::PREFIX . md5( $name );

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( $key ) > MINUTE_IN_SECONDS ) {
			update_option( $key, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Releases the lock.
	 *
	 * @param string $name What was locked.
	 */
	public static function release( $name ) {
		delete_option( self::PREFIX . md5( $name ) );
	}
}
