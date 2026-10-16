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
				'label'        => __( 'User Profiles', 'nexora' ),
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
				'label'        => __( 'User Connections', 'nexora' ),
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
				'label'        => __( 'User Content', 'nexora' ),
				'public'       => false,
				'show_ui'      => true,
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_menu' => 'nexora-system',
				'menu_icon'    => 'dashicons-groups',
			)
		);

		// Vendor applications: private, no front-end URL. Approve/suspend happen through Vendors\Admin, not the edit form.
		register_post_type(
			'nexora_vendor',
			array(
				'label'               => __( 'Vendors', 'nexora' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'delete_with_user'    => true,
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'show_ui'             => true,
				'supports'            => array( 'title' ),
				'show_in_menu'        => 'nexora-system',
				'menu_icon'           => 'dashicons-store',
			)
		);

		// Marketplace products: private, no front-end URL. Created, edited and deleted only through Products\Service.
		register_post_type(
			'nexora_product',
			array(
				'label'               => __( 'Products', 'nexora' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'delete_with_user'    => true,
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'show_ui'             => true,
				'supports'            => array( 'title' ),
				'show_in_menu'        => 'nexora-system',
				'menu_icon'           => 'dashicons-products',
			)
		);

		// Import history: one private entry per real import, written only by Imports\Log_Repository.
		register_post_type(
			'nexora_import_log',
			array(
				'label'               => __( 'Import history', 'nexora' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'delete_with_user'    => true,
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'show_ui'             => true,
				'supports'            => array( 'title' ),
				'show_in_menu'        => 'nexora-system',
				'menu_icon'           => 'dashicons-upload',
			)
		);

		// A vendor's saved API connections: private, read-only for administrators, written only by Imports\Connection_Repository.
		register_post_type(
			'nexora_api_conn',
			array(
				'label'               => __( 'API connections', 'nexora' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'delete_with_user'    => true,
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'show_ui'             => true,
				'supports'            => array( 'title' ),
				'show_in_menu'        => 'nexora-system',
				'menu_icon'           => 'dashicons-rest-api',
			)
		);
	}
}
