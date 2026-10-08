<?php
/**
 * Plugin Name: Nexora
 * Description: Handles User Registration, Login, Profile Dashboard and User Connections
 * Version: 1.0.5
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Sahil Singla
 * License: GPL-2.0-or-later
 * Text Domain: nexora
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEXORA_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEXORA_URL', plugin_dir_url( __FILE__ ) );
define( 'NEXORA_VERSION', '1.0.5' );

require_once NEXORA_PATH . 'src/Core/Autoloader.php';

Nexora\Core\Autoloader::register( NEXORA_PATH . 'src/', require NEXORA_PATH . 'src/Core/legacy-aliases.php' );

register_activation_hook( __FILE__, array( 'Nexora\Database\Installer', 'activate' ) );

Nexora\Core\Plugin::boot();
