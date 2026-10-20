<?php
// Marketplace Phase 9: a seller marks THEIR OWN lines of an order as shipped (Sales tab). Only on orders in a counted status (Processing or
// Completed); never another seller's lines; shipping twice changes nothing; when every non-refunded line of a Processing order is shipped it
// moves to Completed through the WooCommerce API; shipping changes no earnings or report figure; the buyer's shipping address is shown to a
// seller only while they still have unshipped lines on a counted order.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Earnings;
use Nexora\Orders\Fulfilment;
use Nexora\Orders\Lines;
use Nexora\Orders\Reports;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$A = nx_test_vendor( 'fua', 'approved' );
$B = nx_test_vendor( 'fub', 'approved' );
$S = nx_test_vendor( 'fus', 'approved' );     // suspended below
$P = nx_test_vendor( 'fup', 'pending' );
$C = nx_test_user( 'fuc' );
$pa = nx_test_product( $A, 'FU-A1', '100.00', '900' );
$pb = nx_test_product( $B, 'FU-B1', '40.00', '900' );
$pa2 = nx_test_product( $A, 'FU-A2', '10.00', '900' );
$ps = nx_test_product( $S, 'FU-S1', '30.00', '900' );
$order  = function ( array $lines, $payment = 'cod' ) use ( $C ) { $r = nx_test_order( $C, $lines, array( 'payment' => $payment ) ); if ( ! $r['order'] ) { throw new RuntimeException( 'order failed: ' . $r['error'] ); } return $r['order']; };
$reload = function ( $o ) { return wc_get_order( is_object( $o ) ? $o->get_id() : $o ); };
$line   = function ( $o, $vendor ) use ( $reload ) { foreach ( Lines::of( $reload( $o ) ) as $l ) { if ( (int) $l['vendor_id'] === (int) $vendor['vendor_id'] ) { return $l; } } return null; };
$status = function ( $o ) use ( $reload ) { return $reload( $o )->get_status(); };
$ship_to = function ( $o, $v ) { $e = Earnings::order_for_vendor( is_object( $o ) ? $o->get_id() : $o, $v['vendor_id'] ); return $e ? $e['ship_to'] : null; };
$mails = function () { return count( $GLOBALS['nx_test']['mail'] ); };
$strip = function ( array $lines ) { return array_map( function ( $l ) { unset( $l['shipped'], $l['shipped_at'] ); return $l; }, $lines ); };

$oAB = $order( array( array( $pa, 1 ), array( $pb, 2 ) ) );   // A 100.00 and B 80.00, Processing
$oA  = $order( array( array( $pa, 2 ) ) );
nx_assert_same( 'processing', $status( $oAB ), 'setup: a cash-on-delivery order is Processing' );
nx_assert_same( array( false, '' ), array( $line( $oAB, $A )['shipped'], $line( $oAB, $A )['shipped_at'] ), 'a new line is not shipped' );

/* ---------- shipping my own lines only; earnings and reports do not move ---------- */
$sum0 = Earnings::summary( $A['vendor_id'] ); $sumB0 = Earnings::summary( $B['vendor_id'] );
Reports::flush(); $rep0 = Reports::seller( $A['vendor_id'], 'all', 1 ); $ov0 = Reports::overview( 'all' );
$lines0 = $strip( Lines::of( $reload( $oAB ) ) );
wp_set_current_user( $A['user_id'] );
$r = Fulfilment::ship( $A['user_id'], $oAB->get_id() );
nx_assert( ! is_wp_error( $r ) && 1 === $r['shipped'] && false === $r['completed'], 'seller A ships their line of a two-seller order' );
$la = $line( $oAB, $A ); $lb = $line( $oAB, $B );
nx_assert( true === $la['shipped'] && $la['shipped_at'] && abs( strtotime( $la['shipped_at'] . ' UTC' ) - time() ) < 300, 'A\'s line carries the flag and the time' );
nx_assert_same( array( false, '' ), array( $lb['shipped'], $lb['shipped_at'] ), 'B\'s line is untouched: A can only ship A\'s own lines' );
nx_assert_same( 'processing', $status( $oAB ), 'the order stays Processing while B\'s line is unshipped (no auto-complete)' );
nx_assert_same( array( $sum0, $sumB0 ), array( Earnings::summary( $A['vendor_id'] ), Earnings::summary( $B['vendor_id'] ) ), 'shipping changes no earnings' );
Reports::flush();
$rep1 = Reports::seller( $A['vendor_id'], 'all', 1 );
$pick = function ( $r ) { return array( $r['totals'], $r['awaiting'], $r['series'], $r['products'], $r['total'] ); };
nx_assert_same( array( $pick( $rep0 ), $ov0 ), array( $pick( $rep1 ), Reports::overview( 'all' ) ), 'and no report figure (seller totals, chart, best sellers and the admin overview)' );
nx_assert_same( $lines0, $strip( Lines::of( $reload( $oAB ) ) ), 'the frozen price, commission and earning figures of every line are as they were' );

/* ---------- twice is harmless ---------- */
$t1 = $line( $oAB, $A )['shipped_at'];
$notes0 = count( wc_get_order_notes( array( 'order_id' => $oAB->get_id() ) ) );
$r2 = Fulfilment::ship( $A['user_id'], $oAB->get_id() );
nx_assert( ! is_wp_error( $r2 ) && 0 === $r2['shipped'] && false === $r2['completed'], 'a second click ships nothing new' );
nx_assert_same( $t1, $line( $oAB, $A )['shipped_at'], '... and keeps the first shipping time' );
nx_assert_same( $notes0, count( wc_get_order_notes( array( 'order_id' => $oAB->get_id() ) ) ), '... and adds no order note' );

/* ---------- every non-refunded line shipped: Processing -> Completed ---------- */
wp_set_current_user( $B['user_id'] );
$r = Fulfilment::ship( $B['user_id'], $oAB->get_id() );
nx_assert( ! is_wp_error( $r ) && 1 === $r['shipped'] && true === $r['completed'], 'when B ships the last open line the order completes' );
nx_assert_same( 'completed', $status( $oAB ), 'the order is Completed (through the WooCommerce API)' );
$notes = array_map( function ( $n ) { return $n->content; }, wc_get_order_notes( array( 'order_id' => $oAB->get_id() ) ) );
nx_assert( (bool) array_filter( $notes, function ( $n ) { return false !== stripos( $n, 'shipped' ); } ), 'with an order note saying why' );
nx_assert_same( array( $sum0, $sumB0 ), array( Earnings::summary( $A['vendor_id'] ), Earnings::summary( $B['vendor_id'] ) ), 'Processing -> Completed moves no earnings either (both count)' );

/* ---------- not another seller's order, not a bad id ---------- */
$res = Fulfilment::ship( $B['user_id'], $oA->get_id() );
nx_assert( is_wp_error( $res ) && false === $line( $oA, $A )['shipped'] && 'processing' === $status( $oA ), 'B cannot ship an order that has no line of B (nothing changes)' );
foreach ( array( 0, -5, 'abc', '1e3', 999999999 ) as $bad ) { nx_assert( is_wp_error( Fulfilment::ship( $A['user_id'], $bad ) ), 'order id ' . wp_json_encode( $bad ) . ' is refused' ); }
$plain = wc_create_order(); $plain->set_status( 'processing' ); $plain->save();
nx_assert( is_wp_error( Fulfilment::ship( $A['user_id'], $plain->get_id() ) ), 'an ordinary WooCommerce order is refused' );
$plain->delete( true );

/* ---------- only counted statuses ---------- */
foreach ( array( 'pending', 'on-hold', 'cancelled', 'failed', 'refunded' ) as $st ) {
	$o = $order( array( array( $pa2, 1 ) ), 'bacs' );
	$o = $reload( $o ); $o->update_status( $st );
	$res = Fulfilment::ship( $A['user_id'], $o->get_id() );
	nx_assert( is_wp_error( $res ) && false === $line( $o, $A )['shipped'] && $st === $status( $o ), "an order that is $st cannot be shipped (nothing changes, status stays $st)" );
}
$oc = $order( array( array( $pa2, 1 ), array( $pa, 1 ) ) ); $oc->update_status( 'completed' );
$res = Fulfilment::ship( $A['user_id'], $oc->get_id() );
nx_assert( ! is_wp_error( $res ) && 2 === $res['shipped'] && 'completed' === $status( $oc ), 'a Completed order (counted) can still be shipped; it stays Completed' );

/* ---------- auto-complete: only when ALL non-refunded lines are shipped ---------- */
$ob = $order( array( array( $pa2, 2 ), array( $pb, 1 ) ) );             // B's line will be refunded in full
$item_b = null; foreach ( $ob->get_items() as $it ) { if ( (int) $it->get_meta( '_nx_vendor_id', true ) === (int) $B['vendor_id'] ) { $item_b = $it; } }
$ref = wc_create_refund( array( 'order_id' => $ob->get_id(), 'amount' => '40.00', 'line_items' => array( $item_b->get_id() => array( 'qty' => 1, 'refund_total' => '40.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert( ! is_wp_error( $ref ) && 'processing' === $status( $ob ), 'setup: B\'s whole line is refunded; the order is still Processing' );
$res = Fulfilment::ship( $A['user_id'], $ob->get_id() );
nx_assert( ! is_wp_error( $res ) && true === $res['completed'] && 'completed' === $status( $ob ), 'a fully refunded line does not hold the order back: A ships, the order completes' );
$res = Fulfilment::ship( $B['user_id'], $ob->get_id() );
nx_assert( is_wp_error( $res ) || 0 === $res['shipped'], 'B has nothing left to ship on it (the refunded line is not shippable)' );
nx_assert_same( false, $line( $ob, $B )['shipped'], '... and is not marked shipped' );

$oh = $order( array( array( $pa2, 1 ) ), 'bacs' ); $oh = $reload( $oh );
foreach ( $oh->get_items() as $it ) { $it->update_meta_data( '_nx_shipped', '1' ); $it->save(); }
nx_assert_same( false, Fulfilment::maybe_complete( $oh->get_id() ), 'every line shipped on an On-hold order: it is not completed' );
nx_assert_same( 'on-hold', $status( $oh ), '... the order stays On hold' );
nx_assert_same( false, Fulfilment::maybe_complete( $oc->get_id() ), 'a Completed order is left alone' );
$on = $order( array( array( $pa2, 1 ) ) );
nx_assert_same( false, Fulfilment::maybe_complete( $on->get_id() ), 'an order with an unshipped line is not completed' );
$od = $order( array( array( $pa2, 1 ) ) );
foreach ( $reload( $od )->get_items() as $it ) { $it->update_meta_data( '_nx_shipped', '1' ); $it->save(); }
$od2 = $reload( $od ); $od2->update_status( 'cancelled' );
nx_assert_same( false, Fulfilment::maybe_complete( $od->get_id() ), 'a Cancelled order with shipped lines is not completed either' );
$of = $order( array( array( $pa2, 1 ) ) ); $it = current( $of->get_items() );
wc_create_refund( array( 'order_id' => $of->get_id(), 'amount' => '10.00', 'line_items' => array( $it->get_id() => array( 'qty' => 1, 'refund_total' => '10.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
$of = $reload( $of );
nx_assert( 'refunded' === $of->get_status() || false === Fulfilment::maybe_complete( $of->get_id() ), 'an order whose every line is refunded is not completed (nothing was shipped)' );

/* ---------- the address rule ---------- */
$o1 = $order( array( array( $pa, 1 ), array( $pb, 1 ) ) );
nx_assert( ! empty( $ship_to( $o1, $A ) ) && false !== strpos( implode( ' ', $ship_to( $o1, $A ) ), 'Sia Shipping' ), 'Processing, unshipped line: A sees the shipping name and address' );
nx_assert( ! empty( $ship_to( $o1, $B ) ), '... and so does B' );
Fulfilment::ship( $A['user_id'], $o1->get_id() );
nx_assert_same( array(), $ship_to( $o1, $A ), 'once A\'s lines are shipped A no longer sees the address (although B\'s are still open)' );
nx_assert( ! empty( $ship_to( $o1, $B ) ), 'B still sees it while B has an unshipped line' );
Fulfilment::ship( $B['user_id'], $o1->get_id() );
nx_assert_same( array(), $ship_to( $o1, $B ), 'and not after B ships either (the order is Completed)' );
$o2 = $order( array( array( $pa2, 1 ) ), 'bacs' );
nx_assert_same( array(), $ship_to( $o2, $A ), 'On hold: no address (nothing can ship yet)' );
$o2 = $reload( $o2 ); $o2->update_status( 'pending' );
nx_assert_same( array(), $ship_to( $o2, $A ), 'Pending: no address' );
$o3 = $order( array( array( $pa2, 1 ) ) ); $o3->update_status( 'cancelled' );
nx_assert_same( array(), $ship_to( $o3, $A ), 'Cancelled: no address' );
$o4 = $order( array( array( $pa2, 1 ) ) ); $o4->update_status( 'completed' );
nx_assert( ! empty( $ship_to( $o4, $A ) ), 'Completed with an unshipped line: the address is shown' );
Fulfilment::ship( $A['user_id'], $o4->get_id() );
nx_assert_same( array(), $ship_to( $o4, $A ), '... and hidden once shipped' );

/* ---------- the endpoint ---------- */
$call = function ( $uid, array $post, $nonce = true ) {
	wp_set_current_user( $uid );
	return nx_call_ajax( 'nexora_mark_shipped', $post + ( $nonce ? nx_profile_nonce() : array() ) );
};
$denied = function ( $r ) { return nx_rejected( $r ); };
$oe = $order( array( array( $pa, 1 ), array( $pb, 1 ) ) );
$A_line = function () use ( $oe, $line, $A ) { return $line( $oe, $A )['shipped']; };
nx_assert( $denied( $call( $A['user_id'], array( 'order_id' => $oe->get_id() ), false ) ) && false === $A_line(), 'no nonce: refused, nothing shipped' );
nx_assert( $denied( nx_call_ajax( 'nexora_mark_shipped', array( 'order_id' => $oe->get_id(), 'nonce' => 'forged' ) ) ) && false === $A_line(), 'a forged nonce: refused' );
nx_assert( $denied( $call( 0, array( 'order_id' => $oe->get_id() ) ) ) && false === $A_line(), 'a guest: refused' );
foreach ( array( 'a customer' => $C['user_id'], 'a pending seller' => $P['user_id'], 'an administrator' => $admin, 'a seller with no line on the order' => $S['user_id'] ) as $who => $uid ) {
	nx_assert( $denied( $call( $uid, array( 'order_id' => $oe->get_id() ) ) ) && false === $A_line() && false === $line( $oe, $B )['shipped'], "$who: refused, nothing shipped" );
}
wp_set_current_user( $admin );
$os = $order( array( array( $ps, 1 ) ) );   // placed while S is still approved
\Nexora\Vendors\Service::change_status( $S['vendor_id'], 'suspend' );
nx_assert( $denied( $call( $S['user_id'], array( 'order_id' => $os->get_id() ) ) ) && false === $line( $os, $S )['shipped'], 'a suspended seller has read-only access: cannot ship' );
$view = nx_call_ajax( 'nexora_sale_order', array( 'order_id' => $os->get_id() ) + nx_profile_nonce() );
nx_assert( ! empty( $view['success'] ) && false === strpos( $view['data']['html'], 'nx-ship' ), 'and their order view has no ship button' );
foreach ( array( '', 'abc', '-1', '1e3', '0', '99999999999999', array( '1' ) ) as $bad ) {
	nx_assert( $denied( $call( $A['user_id'], array( 'order_id' => $bad ) ) ), 'order id ' . wp_json_encode( $bad ) . ' is refused' );
}
nx_assert( $denied( $call( $A['user_id'], array() ) ), 'no order id: refused' );
$r = $call( $A['user_id'], array( 'order_id' => $oe->get_id() ) );
nx_assert( ! empty( $r['success'] ) && true === $A_line() && false === $line( $oe, $B )['shipped'], 'the owner (approved seller) ships their own lines through the endpoint' );
nx_assert( is_string( $r['data']['html'] ?? null ) && false !== strpos( $r['data']['html'], 'FU-A1' ) && false === strpos( $r['data']['html'], 'FU-B1' ), 'the reply shows the order for the seller: their lines only' );
foreach ( array( 'Bob', 'Billing', 'bob.billing', '9800000001', '9 Billing Road', $C['login'] ) as $leak ) { nx_assert( false === stripos( $r['data']['html'], $leak ), "the reply never carries '$leak'" ); }
$again = $call( $A['user_id'], array( 'order_id' => $oe->get_id() ) );
nx_assert( ! empty( $again['success'] ), 'clicking again is answered kindly' );
nx_assert_same( array( 'processing' ), array( $status( $oe ) ), '(the order still waits for B)' );

/* ---------- rate limit ---------- */
nx_test_reset_limits();
add_filter( 'nexora_rate_limits', function ( $d ) { $d['order_ship'] = array( 2, MINUTE_IN_SECONDS ); return $d; } );
$ol = $order( array( array( $pa2, 1 ) ) );
$got = array(); for ( $i = 0; $i < 4; $i++ ) { $x = $call( $A['user_id'], array( 'order_id' => $ol->get_id() ) ); $got[] = ! empty( $x['success'] ); }
nx_assert_same( array( true, true, false, false ), $got, 'the third call within the minute is refused (rate limit order_ship)' );
nx_assert( isset( \Nexora\Http\Rate_Limiter::defaults()['order_ship'] ), 'the bucket exists by default' );

/* ---------- the button and the script ---------- */
$tpl = file_get_contents( NEXORA_PATH . 'templates/orders/sale-order.php' );
$js  = file_get_contents( NEXORA_PATH . 'assets/js/orders.js' );
nx_assert( false !== strpos( $tpl, 'nx-ship' ) && false !== strpos( $js, 'nexora_mark_shipped' ), 'the order popup has the ship button and orders.js calls the endpoint' );
$js_sends = preg_match( '/nexora_mark_shipped[^}]*\}/s', $js, $m ) ? $m[0] : '';
nx_assert( false === stripos( $js_sends, 'vendor' ) && false === stripos( $js_sends, 'user' ), 'the script sends the order id and the nonce, never a seller or a user' );

/* ---------- nothing else moved ---------- */
nx_assert_same( array( '100.00', '40.00', '10.00' ), array( get_post_meta( $pa, 'price', true ), get_post_meta( $pb, 'price', true ), get_post_meta( $pa2, 'price', true ) ), 'product prices are as they were' );

nx_test_finish();
