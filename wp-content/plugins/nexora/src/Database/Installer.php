<?php

namespace Nexora\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin lifecycle: activation creates / updates the tables (Migrations keeps them
 * current afterwards), deactivation tidies up what must not outlive the plugin.
 */
class Installer {

	/**
	 * Plugin activation: build the tables and record the schema version.
	 */
	public static function activate() {
		Migrations::run();
	}

	/**
	 * Plugin deactivation. Data is kept (only uninstall may delete it, and only if the
	 * admin opted in): this drops the rewrite rules so /profile-page/<username> stops
	 * being routed, and clears the cached platform numbers.
	 */
	public static function deactivate() {
		delete_option( 'rewrite_rules' );
		delete_transient( 'nexora_home_stats' );
	}
}
