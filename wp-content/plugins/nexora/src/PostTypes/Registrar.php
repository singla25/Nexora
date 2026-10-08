<?php

namespace Nexora\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three private post types the social data lives in. They are never public and never in REST;
 * they appear only under the "Nexora System" admin menu.
 */
class Registrar {

	/**
	 * Hooks the post type registration.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	/**
	 * Registers user_profile, user_connections and user_content (private, admin-only).
	 */
	public function register_cpt() {

		register_post_type(
			'user_profile',
			array(
				'label'        => 'User Profiles',
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title', 'thumbnail' ),
				'show_in_menu' => 'nexora-system',
				'menu_icon'    => 'dashicons-groups',
			)
		);

		register_post_type(
			'user_connections',
			array(
				'label'        => 'User Connections',
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title' ),
				'show_in_menu' => 'nexora-system',
				'menu_icon'    => 'dashicons-groups',
			)
		);

		register_post_type(
			'user_content',
			array(
				'label'        => 'User Content',
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_menu' => 'nexora-system',
				'menu_icon'    => 'dashicons-groups',
			)
		);
	}
}
