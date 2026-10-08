<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single place that wires the plugin's modules. Each module registers its own hooks,
 * shortcodes and AJAX handlers in its constructor.
 */
class Plugin {

	/**
	 * Creates every module so each can register its hooks.
	 */
	public static function boot() {

		// Same order the modules have always registered their hooks in
		new \Nexora\Auth\Registration();
		new \Nexora\Auth\Login();
		$settings = new \Nexora\Admin\Settings();
		new \Nexora\Admin\Menu( $settings, new \Nexora\Admin\Pages() );
		new \Nexora\PostTypes\Registrar();
		new \Nexora\Admin\Meta_Boxes();
		new \Nexora\Admin\List_Columns();
		new \Nexora\Profile\Page();
		new \Nexora\Profile\Ajax();
		new \Nexora\Connections\Ajax();
		new \Nexora\Notifications\Ajax();
		new \Nexora\Content\Ajax();
		new \Nexora\Profile\Private_Documents();
		new \Nexora\Profile\Documents_Migration();
		new \Nexora\Profile\Upload_Policy();
		new \Nexora\Shortcodes\Home();
		new \Nexora\Integrations\Better_Messages();
		new \Nexora\Chat\Module();
		new \Nexora\Auth\Recaptcha();
		new \Nexora\Shortcodes\Stats();
		new \Nexora\Shortcodes\Contact_Form();

		new I18n();
		new Assets();
		new Access_Control();
	}
}
