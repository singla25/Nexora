<?php

namespace Nexora\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the custom tables in step with the plugin version. The stored nexora_db_version
 * is compared on every load; when it is older the schema is synced (dbDelta is idempotent)
 * and the version recorded, so updating the plugin files is enough - no reactivation.
 */
class Migrations {

	const DB_VERSION = '1.1.0';
	const OPTION     = 'nexora_db_version';
	const LOCK       = 'nexora_db_migrating';

	/**
	 * Checks the stored version early on every request.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Upgrades when the stored version is behind (a fresh install counts as behind).
	 */
	public static function maybe_upgrade() {

		if ( version_compare( (string) get_option( self::OPTION, '0' ), self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::run();
	}

	/**
	 * Syncs the schema and records the version. A short-lived lock keeps two simultaneous
	 * requests from migrating at once.
	 */
	public static function run() {

		$from = (string) get_option( self::OPTION, '0' );

		// add_option() is atomic: only one request gets the lock; a stale one (crash) expires.
		if ( ! add_option( self::LOCK, time(), '', false ) ) {

			if ( time() - (int) get_option( self::LOCK ) < 5 * MINUTE_IN_SECONDS ) {
				return;
			}

			update_option( self::LOCK, time(), false );
		}

		self::sync_schema();

		update_option( self::OPTION, self::DB_VERSION );
		delete_option( self::LOCK );

		do_action( 'nexora_migrated', $from, self::DB_VERSION );
	}

	/**
	 * Creates missing tables / columns / indexes through dbDelta.
	 *
	 * @return string[] What dbDelta changed (empty when everything is already current).
	 */
	public static function sync_schema() {

		$notifications = new \Nexora\Notifications\Repository();
		$chat          = new \Nexora\Chat\Repository();

		return array_merge( $notifications->create_table(), $chat->create_table() );
	}
}
