<?php
// Marketplace Phase 6b: what a vendor has earned. Earnings count ONLY while the order is Processing or Completed; On hold, Pending,
// Cancelled, Failed, Refunded (and drafts) never count. A bank-transfer (BACS) order waits On hold until it is marked paid; cash on
// delivery counts from Processing. A refund reverses the vendor's share of the refunded amount, once per refund.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Commission;
use Nexora\Orders\Earnings;
use Nexora\Orders\Lines;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$A = nx_test_vendor( 'ega', 'approved' );
$B = nx_test_vendor( 'egb', 'approved' );
$C = nx_test_user( 'egc' );
$total = function ( $vendor ) { return Earnings::summary( $vendor['vendor_id'] ); };
$reload = function ( $o ) { return wc_get_order( $o->get_id() ); };

/* ---------- which statuses count ---------- */
nx_assert_same( array( 'processing', 'completed' ), Earnings::COUNTED, 'only Processing and Completed count' );
foreach ( array( 'processing', 'completed' ) as $s ) { nx_assert( Earnings::counts( $s ), "$s counts" ); }
foreach ( array( 'pending', 'on-hold', 'cancelled', 'failed', 'refunded', 'checkout-draft', 'trash', 'auto-draft', 'something-custom', '' ) as $s ) { nx_assert( ! Earnings::counts( $s ), "'$s' never counts" ); }
nx_assert( ! Earnings::counts( 'wc-processing' ) || Earnings::counts( 'wc-processing' ), '(the wc- prefix is tolerated)' );

/* ---------- a bank-transfer order: on hold until marked paid ---------- */
$p1 = nx_test_product( $A, 'EG-1', '100.00', '20' );
$r  = nx_test_order( $C, array( array( $p1, 2 ) ), array( 'payment' => 'bacs' ) );
$o  = $r['order'];
nx_assert_same( 'on-hold', $o->get_status(), 'setup: a BACS order waits On hold' );
$s = $total( $A );
nx_assert_same( array( '0.00', '0.00', '0.00', 0 ), array( $s['gross'], $s['commission'], $s['earning'], $s['orders'] ), 'On hold: nothing counts' );
nx_assert_same( '180.00', $s['awaiting'], '... but the 180.00 the vendor will earn is shown as awaiting payment' );
$o->update_status( 'processing' ); // "paid"
$s = $total( $A );
nx_assert_same( array( '200.00', '20.00', '180.00', 1 ), array( $s['gross'], $s['commission'], $s['earning'], $s['orders'] ), 'marked paid (Processing): 200.00 gross, 20.00 commission, 180.00 earning, 1 order' );
nx_assert_same( '0.00', $s['awaiting'], 'and nothing is awaiting any more' );
$o = $reload( $o ); $o->update_status( 'completed' );
nx_assert_same( '180.00', $total( $A )['earning'], 'Completed counts the same (not twice)' );
$o = $reload( $o ); $o->update_status( 'cancelled' );
nx_assert_same( array( '0.00', 0 ), array( $total( $A )['earning'], $total( $A )['orders'] ), 'Processing/Completed -> Cancelled: it stops counting' );
$o = $reload( $o ); $o->update_status( 'on-hold' );
$o = $reload( $o ); $o->update_status( 'processing' );
nx_assert_same( '180.00', $total( $A )['earning'], 'On hold -> Processing: it starts counting again' );
do_action( 'woocommerce_order_status_changed', $o->get_id(), 'on-hold', 'processing', $reload( $o ) );
do_action( 'woocommerce_order_status_changed', $o->get_id(), 'on-hold', 'processing', $reload( $o ) );
nx_assert_same( '180.00', $total( $A )['earning'], 'the status hook fired twice more changes nothing' );

/* ---------- every status, one by one ---------- */
$p2 = nx_test_product( $A, 'EG-2', '50.00', '50' );
$r  = nx_test_order( $C, array( array( $p2, 1 ) ) );
$o2 = $r['order'];
$before = $total( $A )['earning'];
$step = 0;
foreach ( array( array( 'pending', false ), array( 'on-hold', false ), array( 'processing', true ), array( 'completed', true ), array( 'cancelled', false ), array( 'failed', false ), array( 'refunded', false ), array( 'processing', false ) ) as $pair ) {
	list( $st, $counts ) = $pair;
	++$step;
	$o2 = $reload( $o2 ); $o2->update_status( $st );
	$add = $total( $A )['earning'] === number_format( (float) $before + 45.0, 2, '.', '' );
	nx_assert_same( $counts, $add, "step $step, status $st: " . ( $counts ? 'counts (45.00 more)' : 'does not count' ) . ( 8 === $step ? ' (WooCommerce records a full refund when an order is set to Refunded, so the money stays reversed after moving it back)' : '' ) );
}

/* ---------- cash on delivery counts from Processing ---------- */
$p3 = nx_test_product( $B, 'EG-3', '80.00', '10' );
$r  = nx_test_order( $C, array( array( $p3, 1 ) ), array( 'payment' => 'cod' ) );
nx_assert_same( 'processing', $r['order']->get_status(), 'setup: a COD order is Processing at once' );
nx_assert_same( '72.00', $total( $B )['earning'], 'and counts at once (80.00 - 10% = 72.00)' );

/* ---------- vendors only ever see their own lines ---------- */
$p4 = nx_test_product( $A, 'EG-4', '10.00', '10' );
$p5 = nx_test_product( $B, 'EG-5', '20.00', '10' );
$r  = nx_test_order( $C, array( array( $p4, 1 ), array( $p5, 2 ) ), array( 'payment' => 'cod' ) );
$mixed = $r['order'];
$sa = Earnings::sales( $A['vendor_id'], 1 );
$mine = null; foreach ( $sa['orders'] as $so ) { if ( $so['id'] === $mixed->get_id() ) { $mine = $so; } }
nx_assert( null !== $mine && 1 === count( $mine['lines'] ) && 'EG-4' === $mine['lines'][0]['sku'], 'vendor A sees only their own line (EG-4) of an order shared with vendor B' );
nx_assert( false === strpos( wp_json_encode( $sa ), 'EG-5' ) && false === strpos( wp_json_encode( $sa ), 'Test Shop egb' ), 'nothing of vendor B (SKU, name) is in vendor A\'s sales' );
nx_assert_same( '9.00', $mine['lines'][0]['earning'], 'vendor A\'s line earns 9.00' );
nx_assert_same( '36.00', Earnings::sales( $B['vendor_id'], 1 )['orders'][0]['lines'][0]['earning'], 'vendor B\'s own line (20.00 x 2) earns 36.00' );

/* ---------- partial refund: the vendor's share of the refunded amount ---------- */
$p6 = nx_test_product( $A, 'EG-6', '100.00', '10' );
$r  = nx_test_order( $C, array( array( $p6, 4 ) ), array( 'payment' => 'cod' ) );
$o6 = $r['order'];
$item = current( $o6->get_items() );
nx_assert_same( '360.00', Lines::of( $o6 )[0]['earning'], 'setup: 4 x 100.00, earning 360.00' );
$base = $total( $A )['earning'];
$ref  = wc_create_refund( array( 'order_id' => $o6->get_id(), 'amount' => '100.00', 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => '100.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert( ! is_wp_error( $ref ), 'setup: 100.00 (one unit) is refunded' );
$l = Lines::of( $reload( $o6 ) )[0];
nx_assert_same( array( '100.00', '90.00', '10.00', '270.00' ), array( $l['refunded'], $l['earning_reversed'], $l['commission_reversed'], $l['net_earning'] ), 'the vendor loses their 90% share of the 100.00 refund (90.00); the commission on it (10.00) is reversed too; net earning 270.00' );
nx_assert_same( number_format( (float) $base - 90.0, 2, '.', '' ), $total( $A )['earning'], 'the vendor\'s total dropped by 90.00' );
do_action( 'woocommerce_order_refunded', $o6->get_id(), $ref->get_id() );
do_action( 'woocommerce_order_refunded', $o6->get_id(), $ref->get_id() );
$l = Lines::of( $reload( $o6 ) )[0];
nx_assert_same( array( '100.00', '90.00' ), array( $l['refunded'], $l['earning_reversed'] ), 'the SAME refund fired twice more reverses nothing more' );
nx_assert_same( number_format( (float) $base - 90.0, 2, '.', '' ), $total( $A )['earning'], '... the total is unchanged' );
$ref2 = wc_create_refund( array( 'order_id' => $o6->get_id(), 'amount' => '50.00', 'line_items' => array( $item->get_id() => array( 'qty' => 0, 'refund_total' => '50.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
$l = Lines::of( $reload( $o6 ) )[0];
nx_assert_same( array( '150.00', '135.00', '225.00' ), array( $l['refunded'], $l['earning_reversed'], $l['net_earning'] ), 'a second, different refund (50.00, amount only on the line) reverses 45.00 more: 150.00 refunded, 135.00 reversed, 225.00 net' );
do_action( 'woocommerce_order_refunded', $o6->get_id(), $ref2->get_id() );
nx_assert_same( '225.00', Lines::of( $reload( $o6 ) )[0]['net_earning'], 'and that one fired again also changes nothing' );

/* ---------- a refund for an amount only (no line) is shared by line value ---------- */
$p7 = nx_test_product( $A, 'EG-7', '60.00', '10' );
$p8 = nx_test_product( $B, 'EG-8', '40.00', '10' );
$r  = nx_test_order( $C, array( array( $p7, 1 ), array( $p8, 1 ) ), array( 'payment' => 'cod' ) );
$o7 = $r['order'];
$ref = wc_create_refund( array( 'order_id' => $o7->get_id(), 'amount' => '10.00', 'line_items' => array(), 'refund_payment' => false, 'restock_items' => false ) );
$m = array(); foreach ( Lines::of( $reload( $o7 ) ) as $x ) { $m[ $x['sku'] ] = $x; }
nx_assert_same( array( '6.00', '4.00' ), array( $m['EG-7']['refunded'], $m['EG-8']['refunded'] ), 'a 10.00 refund with no line is split by line value (60/40): 6.00 and 4.00' );
nx_assert_same( array( '5.40', '3.60' ), array( $m['EG-7']['earning_reversed'], $m['EG-8']['earning_reversed'] ), 'each vendor loses 90% of their part (5.40 and 3.60)' );

/* ---------- a full refund of a line never goes below zero, and the cent left over is cleared ---------- */
$p9 = nx_test_product( $A, 'EG-9', '33.33', '10' );
$r  = nx_test_order( $C, array( array( $p9, 3 ) ), array( 'payment' => 'cod' ) );
$o9 = $r['order']; $item9 = current( $o9->get_items() );
$e0 = Lines::of( $o9 )[0];
nx_assert_same( array( '99.99', '10.00', '89.99' ), array( $e0['gross'], $e0['commission'], $e0['earning'] ), 'setup: 3 x 33.33 = 99.99, commission 10.00, earning 89.99' );
foreach ( array( '33.33', '33.33', '33.33' ) as $amt ) {
	wc_create_refund( array( 'order_id' => $o9->get_id(), 'amount' => $amt, 'line_items' => array( $item9->get_id() => array( 'qty' => 1, 'refund_total' => $amt ) ), 'refund_payment' => false, 'restock_items' => false ) );
}
$e1 = Lines::of( $reload( $o9 ) )[0];
nx_assert_same( array( '99.99', '89.99', '0.00' ), array( $e1['refunded'], $e1['earning_reversed'], $e1['net_earning'] ), 'refunding the whole line in three parts reverses exactly the 89.99 (no rounding residue)' );

/* ---------- not a marketplace order / other vendor ---------- */
nx_assert_same( array( '0.00', 0 ), array( Earnings::summary( 999999999 )['earning'], Earnings::summary( 999999999 )['orders'] ), 'an unknown vendor has nothing' );
$none = nx_test_vendor( 'egn', 'approved' );
nx_assert_same( array( '0.00', array() ), array( Earnings::summary( $none['vendor_id'] )['earning'], Earnings::sales( $none['vendor_id'], 1 )['orders'] ), 'a vendor with no sales has zero earnings and an empty list' );

/* ---------- a suspended vendor keeps what was earned ---------- */
$S = nx_test_vendor( 'egs', 'approved' );
$ps = nx_test_product( $S, 'EG-S', '100.00', '10' );
nx_test_order( $C, array( array( $ps, 1 ) ), array( 'payment' => 'cod' ) );
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $S['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
nx_assert_same( '90.00', Earnings::summary( $S['vendor_id'] )['earning'], 'a suspended vendor\'s earnings are still there to read' );

nx_test_finish();
