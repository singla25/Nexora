<?php
// Phase 10: the reports use a FLAT number of database queries however many orders there are. The seller report, the admin overview and the
// seller's totals are measured with a few orders and with many; the count must not grow, and the figures must be exactly right.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Earnings;
use Nexora\Orders\Reports;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$V = nx_test_vendor( 'qba', 'approved' );
$W = nx_test_vendor( 'qbb', 'approved' );
$C = nx_test_user( 'qbc' );
$pv = nx_test_product( $V, 'QB-V1', '10.00', '9000' );
$pw = nx_test_product( $W, 'QB-W1', '20.00', '9000' );
$order = function ( $lines ) use ( $C ) { $r = nx_test_order( $C, $lines, array( 'payment' => 'cod' ) ); if ( ! $r['order'] ) { throw new RuntimeException( $r['error'] ); } return $r['order']; };

/** Counts the SELECT statements a callback causes, with every cache emptied first. */
$queries = function ( callable $fn ) {
	wp_cache_flush();
	Reports::flush();
	$n = 0;
	$count = function ( $q ) use ( &$n ) { if ( 0 === stripos( ltrim( $q ), 'SELECT' ) || 0 === stripos( ltrim( $q ), 'SHOW' ) ) { $n++; } return $q; };
	add_filter( 'query', $count );
	$fn();
	remove_filter( 'query', $count );
	return $n;
};
$measure = function () use ( $queries, $V ) {
	return array(
		'seller'   => $queries( function () use ( $V ) { Reports::seller( $V['vendor_id'], 'all', 1 ); } ),
		'overview' => $queries( function () { Reports::overview( 'all' ); } ),
		'summary'  => $queries( function () use ( $V ) { Earnings::summary( $V['vendor_id'] ); } ),
		'sales'    => $queries( function () use ( $V ) { Earnings::sales( $V['vendor_id'], 1 ); } ),
	);
};

// A few orders (each with two products of two sellers), then many more.
for ( $i = 0; $i < 3; $i++ ) { $order( array( array( $pv, 1 ), array( $pw, 1 ) ) ); }
$few = $measure();
for ( $i = 0; $i < 14; $i++ ) { $order( array( array( $pv, 2 ), array( $pw, 1 ) ) ); }
$many = $measure();

foreach ( array( 'seller' => 'the seller report', 'overview' => 'the admin overview', 'summary' => 'the seller totals', 'sales' => 'one page of the orders table' ) as $key => $label ) {
	nx_assert( $many[ $key ] <= $few[ $key ], "$label: the same number of queries with 3 orders ($few[$key]) and with 17 ($many[$key])" );
}

// The figures are exactly right (3 x 10.00 + 14 x 20.00 = 310.00 sales for the seller; commission 10%).
$r = Reports::seller( $V['vendor_id'], 'all', 1 );
nx_assert_same( array( 17, 31, '310.00', '31.00', '279.00' ), array( $r['totals']['orders'], $r['totals']['units'], $r['totals']['sales'], $r['totals']['commission'], $r['totals']['earnings'] ), 'the seller report is unchanged: 17 orders, 31 units, 310.00 / 31.00 / 279.00' );
$s = Earnings::summary( $V['vendor_id'] );
nx_assert_same( array( '310.00', '31.00', '279.00', 17 ), array( $s['gross'], $s['commission'], $s['earning'], $s['orders'] ), 'and so is Earnings::summary' );
$ov = Reports::overview( 'all' );
$row = array_values( array_filter( $ov['rows'], function ( $x ) use ( $W ) { return $x['seller_id'] === $W['vendor_id']; } ) );
nx_assert_same( array( 17, '340.00', '34.00', '306.00' ), array( $row[0]['orders'] ?? null, $row[0]['sales'] ?? null, $row[0]['commission'] ?? null, $row[0]['earnings'] ?? null ), 'the overview row of the other seller is unchanged: 17 orders, 340.00 / 34.00 / 306.00' );

nx_test_finish();
