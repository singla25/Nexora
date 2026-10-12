<?php
/**
 * Runs when the plugin is deleted from the Plugins screen. Removes the plugin's data
 * only if "Delete all data when the plugin is deleted" is ticked (Nexora System > Settings).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'src/Core/Autoloader.php';

Nexora\Core\Autoloader::register( plugin_dir_path( __FILE__ ) . 'src/', array() );

( new Nexora\Database\Uninstaller() )->run();
