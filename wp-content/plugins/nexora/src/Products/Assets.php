<?php

namespace Nexora\Products;

use Nexora\Accounts\Account_Types;
use Nexora\Core\Assets as Core_Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the marketplace script and style on a logged-in member's profile page, and the product-management
 * script only for approved vendors (the server still checks every call; this only avoids shipping needless code).
 */
class Assets {

	/**
	 * Hooks the loader.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the assets on the profile page.
	 */
	public function enqueue() {

		if ( ! is_user_logged_in() || ! Core_Assets::is_page_for( 'profile-page', 'profile_dashboard' ) ) {
			return;
		}

		Core_Assets::enqueue_tokens();
		Core_Assets::enqueue_sweetalert();

		wp_enqueue_style( 'nexora-marketplace', NEXORA_URL . 'assets/css/marketplace.css', array( 'nexora-tokens' ), NEXORA_VERSION );
		wp_enqueue_script( 'nexora-marketplace', NEXORA_URL . 'assets/js/marketplace.js', array( 'jquery' ), NEXORA_VERSION, true );

		wp_localize_script(
			'nexora-marketplace',
			'nexoraMarketplace',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'profile_nonce' ),
			)
		);

		wp_enqueue_style( 'nexora-orders', NEXORA_URL . 'assets/css/orders.css', array( 'nexora-tokens' ), NEXORA_VERSION );
		wp_enqueue_script( 'nexora-orders', NEXORA_URL . 'assets/js/orders.js', array( 'jquery', 'sweetalert2', 'nexora-marketplace' ), NEXORA_VERSION, true );

		if ( Account_Types::can_sell( get_current_user_id() ) ) {
			wp_enqueue_script( 'nexora-products', NEXORA_URL . 'assets/js/products.js', array( 'jquery', 'sweetalert2', 'nexora-marketplace' ), NEXORA_VERSION, true );
			wp_enqueue_script( 'nexora-imports', NEXORA_URL . 'assets/js/imports.js', array( 'jquery', 'sweetalert2', 'nexora-marketplace' ), NEXORA_VERSION, true );
		}
	}
}
