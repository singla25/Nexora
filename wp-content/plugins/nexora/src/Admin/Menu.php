<?php

namespace Nexora\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Nexora System" admin menu and the admin script it needs.
 */
class Menu {

	/**
	 * The settings page.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * The overview pages.
	 *
	 * @var Pages
	 */
	private $pages;

	/**
	 * Hooks the menu and admin script.
	 *
	 * @param Settings $settings The settings page.
	 * @param Pages    $pages Admin overview pages.
	 */
	public function __construct( Settings $settings, Pages $pages ) {

		$this->settings = $settings;
		$this->pages    = $pages;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_menu', array( $this, 'register_main_menu' ) );
	}

	/**
	 * Loads the media library and the admin upload/remove script on admin screens.
	 */
	public function enqueue_admin_scripts() {
		wp_enqueue_media();
		wp_enqueue_script(
			'profile-admin-js',
			NEXORA_URL . 'assets/js/profile-admin.js',
			array( 'jquery' ),
			NEXORA_VERSION,
			true
		);
	}

	/**
	 * Registers the "Nexora System" menu with Settings, Notifications and Chat.
	 */
	public function register_main_menu() {

		add_menu_page(
			__( 'Nexora System', 'nexora' ),
			__( 'Nexora System', 'nexora' ),
			'manage_options',
			'nexora-system',
			array( $this->settings, 'settings_page' ),
			'dashicons-groups',
			5
		);

		add_submenu_page(
			'nexora-system',
			__( 'Notifications', 'nexora' ),
			__( 'Notifications', 'nexora' ),
			'manage_options',
			'nexora-notifications',
			array( $this->pages, 'notifications_page' )
		);

		add_submenu_page(
			'nexora-system',
			__( 'Nexora Chat', 'nexora' ),
			__( 'Nexora Chat', 'nexora' ),
			'manage_options',
			'nexora-chat',
			array( $this->pages, 'nexora_user_chat' )
		);

		add_submenu_page(
			'nexora-system',
			__( 'Settings', 'nexora' ),
			__( 'Settings', 'nexora' ),
			'manage_options',
			'nexora-system',
			array( $this->settings, 'settings_page' )
		);
	}
}
