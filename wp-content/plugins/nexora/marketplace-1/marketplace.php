<?php
/**
 * marketplace/marketplace.php
 *
 * Single include point for the entire Marketplace module.
 * Add ONE line in your main plugin bootstrap file:
 *
 *   require_once NEXORA_PATH . 'marketplace/marketplace.php';
 *
 * Load order (dependency order):
 *   DB          → no deps (schema only)
 *   Helper      → no deps (pure static utilities)
 *   WooCommerce → depends on Helper
 *   Product     → depends on Helper, WooCommerce
 *   Ajax        → depends on Helper, Product, WooCommerce
 *   Core        → depends on all of the above
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Module constants ──────────────────────────────────────────── */
define( 'NEXORA_MARKETPLACE_PATH',      plugin_dir_path( __FILE__ ) );
define( 'NEXORA_MARKETPLACE_URL',       plugin_dir_url( __FILE__ ) );
define( 'NEXORA_MARKETPLACE_TEMPLATES', NEXORA_MARKETPLACE_PATH . 'templates/' );
define( 'NEXORA_MARKETPLACE_VERSION',   '1.0.0' );

/* ── Autoload classes ──────────────────────────────────────────── */
require_once NEXORA_MARKETPLACE_PATH . 'database/class-market-install.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-helper.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-db.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-woocommerce.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-product.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-ajax.php';
require_once NEXORA_MARKETPLACE_PATH . 'includes/class-market-core.php';

/* ── DB install / upgrade (cheap version-check, runs first) ───────── */
add_action( 'plugins_loaded', [ 'NEXORA_MARKET_DB', 'install' ], 5 );

/* ── Instantiate at priority 20 so WooCommerce (priority 10) is ready ─ */
add_action( 'plugins_loaded', 'nexora_marketplace_init', 20 );

function nexora_marketplace_init() {
    new NEXORA_MARKET_CORE();
    new NEXORA_MARKET_AJAX();
}

/* ── Activation hook (must point to the file WP scanned — this one) ─── */
// If this file IS your main plugin file, use __FILE__.
// If marketplace/ is a sub-module, call NEXORA_MARKET_DB::install() from
// your parent plugin's activation hook instead.
//
// Example for a standalone plugin:
register_activation_hook( __FILE__, [ 'NEXORA_MARKET_INSTALL', 'activate' ] );

/* ── CSV template download endpoint ────────────────────────────────── */
add_action( 'wp_ajax_nexora_market_csv_template', function () {
    check_ajax_referer( 'nexora_market_nonce', 'nonce' );
    NEXORA_MARKET_HELPER::output_csv_template();
} );
