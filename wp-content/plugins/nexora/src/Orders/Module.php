<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the order side of the marketplace into WooCommerce: tagging and placing marketplace orders, stock on status changes, the
 * reversal of a vendor's share on refunds, and the declaration that Nexora works with high-performance order storage (everything here
 * goes through the WooCommerce order API, never straight to the posts table).
 */
class Module {

	/**
	 * Registers the hooks.
	 */
	public function __construct() {

		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_hpos' ) );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __NAMESPACE__ . '\Placement', 'tag' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __NAMESPACE__ . '\Placement', 'on_classic' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __NAMESPACE__ . '\Placement', 'on_store_api' ) );
		add_action( 'woocommerce_order_status_changed', array( __NAMESPACE__ . '\Order_Events', 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __NAMESPACE__ . '\Refunds', 'apply' ), 10, 2 );
	}

	/**
	 * Tells WooCommerce this plugin is compatible with high-performance order storage (custom order tables).
	 */
	public static function declare_hpos() {

		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEXORA_PATH . 'nexora.php', true );
		}
	}
}
