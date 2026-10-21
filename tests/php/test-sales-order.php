<?php
// Phase 10: the orders lists are ordered newest first AND the same way every time: orders placed in the same second are told apart by their id
// (newest id first), so a page boundary can never repeat or skip an order, and a fast machine (the automatic tests) sees what a slow one does.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Earnings;
use Nexora\Orders\Reports;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$V = nx_test_vendor( 'sor', 'approved' );
$C = nx_test_user( 'sorc' );
$p = nx_test_product( $V, 'SO-1', '10.00', '500' );
$same = time() - 3600;
$ids = array();
for ( $i = 0; $i < 4; $i++ ) {
	$r = nx_test_order( $C, array( array( $p, 1 ) ), array( 'payment' => 'cod' ) );
	$o = wc_get_order( $r['order']->get_id() ); $o->set_date_created( $same ); $o->save();
	$ids[] = $o->get_id();
}
$newest_first = array_reverse( $ids );
$got = array_column( Earnings::sales( $V['vendor_id'], 1 )['orders'], 'id' );
nx_assert_same( $newest_first, $got, 'four orders with the very same time come back newest id first' );
nx_assert_same( $got, array_column( Earnings::sales( $V['vendor_id'], 1 )['orders'], 'id' ), '... and the same again on a second call' );
add_filter( 'nexora_orders_per_page', function () { return 3; } );
$p1 = array_column( Earnings::sales( $V['vendor_id'], 1 )['orders'], 'id' );
$p2 = array_column( Earnings::sales( $V['vendor_id'], 2 )['orders'], 'id' );
nx_assert_same( $newest_first, array_merge( $p1, $p2 ), 'pages of 3 then 1 repeat and skip nothing' );
Reports::flush();
nx_assert_same( array_slice( $newest_first, 0, 3 ), array_column( Reports::seller( $V['vendor_id'], 'all', 1 )['orders'], 'id' ), 'the seller report lists them in the same order (its first page of 3)' );

nx_test_finish();
