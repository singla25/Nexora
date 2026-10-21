<?php
// Phase 10: the order queries must work on BOTH WooCommerce order stores. A fresh WooCommerce install can use the old posts-based store, which
// does not support `meta_query` (WooCommerce says so with a notice and returns nothing), while a site with high-performance order storage does.
// Only `meta_key` / `meta_value` work on both, so every order lookup of the plugin uses those. This test makes WooCommerce use the posts-based
// store for the duration and fails on that notice.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Earnings;
use Nexora\Orders\Reports;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$V = nx_test_vendor( 'dsa', 'approved' );
$notices = array();
add_action( 'doing_it_wrong_run', function ( $function, $message ) use ( &$notices ) { $notices[] = $function . ': ' . $message; }, 10, 2 );
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
$cpt = function () { return 'WC_Order_Data_Store_CPT'; };
add_filter( 'woocommerce_order_data_store', $cpt, 999 );

// The check must be able to fail: under this store a meta_query IS refused with a notice.
wc_get_orders( array( 'meta_query' => array( array( 'key' => '_nx_probe', 'value' => '1' ) ), 'limit' => 1, 'return' => 'ids' ) );
nx_assert( (bool) array_filter( $notices, function ( $n ) { return false !== stripos( $n, 'meta_query' ); } ), 'self-check: WooCommerce refuses a meta_query on the posts-based store (so this test can fail)' );
$notices = array();

wp_set_current_user( nx_test_admin() );
Earnings::summary( $V['vendor_id'] );
Earnings::sales( $V['vendor_id'], 1 );
Earnings::sales( $V['vendor_id'], 1, time() - DAY_IN_SECONDS );
Earnings::orders( $V['vendor_id'] );
Earnings::order_ids( $V['vendor_id'] );
Earnings::has_orders( $V['vendor_id'] );
Reports::flush();
Reports::seller( $V['vendor_id'], 'all', 1 );
Reports::overview( 'all' );
$unsupported = array_values( array_filter( $notices, function ( $n ) { return false !== stripos( $n, 'meta_query' ) || false !== stripos( $n, 'not supported on the current order datastore' ); } ) );

remove_filter( 'woocommerce_order_data_store', $cpt, 999 );
nx_assert_same( array(), $unsupported, 'on the posts-based order store no order query uses an argument that store does not support' );
$src = '';
foreach ( array( 'Earnings', 'Reports' ) as $f ) { $src .= file_get_contents( NEXORA_PATH . "src/Orders/$f.php" ); }
nx_assert( 0 === preg_match( "/'meta_query'\s*=>/", $src ), 'neither Earnings nor Reports builds an order query with meta_query' );

// And the answers are the same on the current store as before (a seller with no orders is the empty report, not an error).
nx_assert_same( '0.00', Earnings::summary( $V['vendor_id'] )['earning'], 'a seller with no orders has zero earnings' );
nx_assert_same( false, Earnings::has_orders( $V['vendor_id'] ), 'and no orders' );

nx_test_finish();
