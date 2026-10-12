<?php
/**
 * Translations.
 *
 * @package Nexora
 */

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin's translations (text domain "nexora", files in languages/).
 */
class I18n {

	/**
	 * Hooks the loader. It runs before the post types and menus are registered, so their labels translate too.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
	}

	/**
	 * Loads languages/nexora-<locale>.mo.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'nexora', false, dirname( plugin_basename( NEXORA_PATH . 'nexora.php' ) ) . '/languages' );
	}
}
