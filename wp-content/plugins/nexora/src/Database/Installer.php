<?php

namespace Nexora\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the plugin's custom tables. Runs on plugin activation
 * (versioned migrations replace this in a later phase).
 */
class Installer {

	/**
	 * Creates the notification and chat tables (plugin activation).
	 */
	public static function activate() {

		// Notification table
		$notification = new \Nexora\Notifications\Repository();
		$notification->create_table();

		// Chat tables
		$chat_db = new \Nexora\Chat\Repository();
		$chat_db->create_table();
	}
}
