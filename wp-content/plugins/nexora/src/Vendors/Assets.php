<?php

namespace Nexora\Vendors;

use Nexora\Core\Assets as Core_Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the vendor script and style only where the vendor forms are: the registration page (the Seller choice)
 * and a member's profile page ("Become a seller" / seller details). Nothing else gets them.
 */
class Assets {

	/**
	 * Hooks the loader.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the vendor assets on the pages that need them.
	 */
	public function enqueue() {

		$on_registration = Core_Assets::is_page_for( 'registration-page', 'profile_registration' ) && ! is_user_logged_in();
		$on_profile      = is_user_logged_in() && Core_Assets::is_page_for( 'profile-page', 'profile_dashboard' );

		if ( ! $on_registration && ! $on_profile ) {
			return;
		}

		Core_Assets::enqueue_tokens();
		wp_enqueue_style( 'nexora-vendor', NEXORA_URL . 'assets/css/vendor.css', array( 'nexora-tokens' ), NEXORA_VERSION );

		// The profile popups use SweetAlert2 (the same copy the profile page loads).
		$deps = array( 'jquery' );

		if ( $on_profile ) {
			Core_Assets::enqueue_sweetalert();
			$deps[] = 'sweetalert2';
		}

		wp_enqueue_script( 'nexora-vendor', NEXORA_URL . 'assets/js/vendor.js', $deps, NEXORA_VERSION, true );

		wp_localize_script(
			'nexora-vendor',
			'nexoraVendor',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'profile_nonce' ),
			)
		);
	}
}
