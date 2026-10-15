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

		new \Nexora\Database\Migrations();
		new \Nexora\Accounts\Account_Types();
		new \Nexora\Vendors\Ajax();
		new \Nexora\Vendors\Admin();
		new \Nexora\Core\Post_Guard( \Nexora\Vendors\Repository::POST_TYPE, array( '\Nexora\Vendors\Repository', 'is_internal' ) );
		new \Nexora\Products\Ajax();
		new \Nexora\Products\Marketplace_Ajax();
		new \Nexora\Products\Admin();
		new \Nexora\Products\Assets();
		new \Nexora\Imports\Ajax();
		new \Nexora\Imports\Admin();
		new \Nexora\Core\Post_Guard( \Nexora\Imports\Log_Repository::POST_TYPE, array( '\Nexora\Imports\Log_Repository', 'is_internal' ) );
		new \Nexora\Core\Post_Guard( \Nexora\Products\Repository::POST_TYPE, array( '\Nexora\Products\Repository', 'is_internal' ), array( 'post_status', 'post_title', 'post_content', 'post_excerpt' ) );
		new \Nexora\Integrations\Woo_Routes();
		new \Nexora\Vendors\Assets();
		new \Nexora\Connections\Cache();

		new I18n();
		new Assets();
		new Access_Control();
	}
}
