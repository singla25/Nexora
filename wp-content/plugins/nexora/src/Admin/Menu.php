<?php

namespace Nexora\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Nexora System" admin menu and the admin script it needs.
 */
class Menu {

	/** @var Settings */
	private $settings;

	/** @var Pages */
	private $pages;

	public function __construct( Settings $settings, Pages $pages ) {

		$this->settings = $settings;
		$this->pages    = $pages;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_menu', array( $this, 'register_main_menu' ) );
	}

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

	public function register_main_menu() {

		add_menu_page(
			'Nexora System',
			'Nexora System',
			'manage_options',
			'nexora-system',
			array( $this->settings, 'settings_page' ),
			'dashicons-groups',
			5
		);

		add_submenu_page(
			'nexora-system',
			'Notifications',
			'Notifications',
			'manage_options',
			'nexora-notifications',
			array( $this->pages, 'notifications_page' )
		);

		add_submenu_page(
			'nexora-system',
			'Nexora Chat',
			'Nexora Chat',
			'manage_options',
			'nexora-chat',
			array( $this->pages, 'nexora_user_chat' )
		);

		add_submenu_page(
			'nexora-system',
			'Settings',
			'Settings',
			'manage_options',
			'nexora-system',
			array( $this->settings, 'settings_page' )
		);
	}
}
