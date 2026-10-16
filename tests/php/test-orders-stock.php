<?php
// Marketplace Phase 6b: stock follows the order. Taken once when the order is placed; given back on cancel, fail and refund,
// ONCE ONLY: every hook is fired twice (and in combination) and the second time changes nothing.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Lines;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$A = nx_test_vendor( 'ska', 'approved' );
$B = nx_test_user( 'skb' );
$stock = function ( $pid ) { clean_post_cache( $pid ); wp_cache_delete( $pid, 'post_meta' ); return (int) get_post_meta( $pid, 'stock', true ); };
/** Fire the status hook exactly as WooCommerce does. */
$status = function ( $order_id, $to ) {
	$order = wc_get_order( $order_id );
	$from  = $order->get_status();
	$order->set_status( $to );
	$order->save();
	return array( $from, $to );
};
$fire = function ( $order_id, $from, $to ) { do_action( 'woocommerce_order_status_changed', $order_id, $from, $to, wc_get_order( $order_id ) ); };
$new = function ( $qty = 3, $start = '10' ) use ( $A, $B ) {
	$pid = nx_test_product( $A, 'SK-' . wp_generate_password( 5, false ), '20.00', $start );
	$r   = nx_test_order( $B, array( array( $pid, $qty ) ) );
	return array( $pid, $r['order'] );
};

/* ---------- taken once at placement ---------- */
list( $pid, $o ) = $new( 3, '10' );
nx_assert_same( 7, $stock( $pid ), 'placing an order for 3 of 10 leaves 7' );
nx_assert_same( 3, Lines::of( $o )[0]['stock_taken'], 'the line remembers it took 3' );
nx_assert_same( 3, Lines::of( $o )[0]['stock_held'], '... and holds 3 (taken - given back)' );
nx_assert_same( 0, Lines::of( $o )[0]['stock_back'], 'and that none is back yet' );

/* ---------- cancel: given back once, even if the hook fires twice ---------- */
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'cancelling gives the 3 back' );
nx_assert_same( 3, Lines::of( wc_get_order( $o->get_id() ) )[0]['stock_back'], 'the line records that 3 went back' );
$fire( $o->get_id(), 'on-hold', 'cancelled' );
$fire( $o->get_id(), 'on-hold', 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'the cancel hook fired twice more changes nothing (still 10, not 16)' );
$o->update_status( 'failed' );
nx_assert_same( 10, $stock( $pid ), 'cancelled -> failed gives nothing back a second time' );

/* ---------- failed ---------- */
list( $pid, $o ) = $new( 4, '10' );
nx_assert_same( 6, $stock( $pid ), 'setup: 4 taken' );
$o->update_status( 'failed' );
nx_assert_same( 10, $stock( $pid ), 'a failed order gives its stock back' );
$fire( $o->get_id(), 'on-hold', 'failed' );
$fire( $o->get_id(), 'pending', 'failed' );
nx_assert_same( 10, $stock( $pid ), 'fired twice: still 10' );

/* ---------- pending / on-hold / processing / completed give nothing back ---------- */
list( $pid, $o ) = $new( 2, '10' );
foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $st ) {
	$o->update_status( $st );
	nx_assert_same( 8, $stock( $pid ), "moving to $st keeps the stock taken (8)" );
}

/* ---------- full refund: once only, also together with the refunded status ---------- */
list( $pid, $o ) = $new( 3, '10' );
$o->update_status( 'processing' );
$item   = current( wc_get_order( $o->get_id() )->get_items() );
$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => $item->get_total(), 'line_items' => array( $item->get_id() => array( 'qty' => 3, 'refund_total' => $item->get_total() ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert( ! is_wp_error( $refund ), 'setup: the whole line is refunded (' . ( is_wp_error( $refund ) ? $refund->get_error_message() : 'ok' ) . ')' );
nx_assert_same( 10, $stock( $pid ), 'a refund of the 3 units gives 3 back' );
do_action( 'woocommerce_order_refunded', $o->get_id(), $refund->get_id() );
do_action( 'woocommerce_order_refunded', $o->get_id(), $refund->get_id() );
nx_assert_same( 10, $stock( $pid ), 'the refund hook fired twice more: still 10' );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'refunded' );
nx_assert_same( 10, $stock( $pid ), 'then the order goes to Refunded: nothing is given back a second time' );
$fire( $o->get_id(), 'processing', 'refunded' );
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'and cancelling afterwards gives nothing more either' );
nx_assert_same( 3, Lines::of( wc_get_order( $o->get_id() ) )[0]['stock_back'], 'the line says 3 came back, in total' );

/* ---------- cancel first, refund later: still once ---------- */
list( $pid, $o ) = $new( 2, '10' );
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'cancelled: 10' );
$item   = current( wc_get_order( $o->get_id() )->get_items() );
$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 10, 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 10 ) ), 'refund_payment' => false, 'restock_items' => false ) );
do_action( 'woocommerce_order_refunded', $o->get_id(), $refund->get_id() );
nx_assert_same( 10, $stock( $pid ), 'a refund after a cancel does not give back what is already back' );

/* ---------- partial refund: only the refunded units, once ---------- */
list( $pid, $o ) = $new( 5, '10' );
$o->update_status( 'processing' );
$item   = current( wc_get_order( $o->get_id() )->get_items() );
$unit   = (float) $item->get_total() / 5;
$refund = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => 2 * $unit, 'line_items' => array( $item->get_id() => array( 'qty' => 2, 'refund_total' => 2 * $unit ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert_same( 7, $stock( $pid ), 'refunding 2 of 5 units gives back 2 (5 -> 7)' );
do_action( 'woocommerce_order_refunded', $o->get_id(), $refund->get_id() );
nx_assert_same( 7, $stock( $pid ), 'the same refund fired again: still 7' );
$refund2 = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => $unit, 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => $unit ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert_same( 8, $stock( $pid ), 'a second refund of 1 more unit gives back 1 (8)' );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'cancelling the rest gives back only the 2 not yet back (10, never above what was taken)' );
$fire( $o->get_id(), 'processing', 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'fired twice: 10' );

/* ---------- never more than was taken ---------- */
list( $pid, $o ) = $new( 2, '4' );
$o->update_status( 'cancelled' );
update_post_meta( $pid, 'stock', '50' ); // the vendor restocked by hand
$fire( $o->get_id(), 'on-hold', 'cancelled' );
nx_assert_same( 50, $stock( $pid ), 'a restock by the vendor is never touched by a repeated hook' );

/* ---------- an order brought back to life takes its stock again (and only once) ---------- */
list( $pid, $o ) = $new( 3, '10' );
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'cancelled: 10' );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'processing' );
nx_assert_same( 7, $stock( $pid ), 'cancelled -> processing takes the 3 again (7)' );
$fire( $o->get_id(), 'cancelled', 'processing' );
nx_assert_same( 7, $stock( $pid ), 'fired twice: still 7' );
$o->update_status( 'cancelled' );
nx_assert_same( 10, $stock( $pid ), 'and cancelling again gives them back (10)' );

/* ---------- if the stock is gone when an order is revived: the status stays, the shop owner is told ---------- */
list( $pid, $o ) = $new( 3, '3' );
nx_assert_same( 0, $stock( $pid ), 'setup: the last 3 are taken' );
$o->update_status( 'cancelled' );
$buyer2 = nx_test_user( 'skb2' );
$r2 = nx_test_order( $buyer2, array( array( $pid, 3 ) ) );
nx_assert_same( 0, $stock( $pid ), 'setup: another buyer took them' );
$o = wc_get_order( $o->get_id() );
$o->update_status( 'processing' );
nx_assert_same( 0, $stock( $pid ), 'reviving the cancelled order cannot take what is not there' );
$notes = wc_get_order_notes( array( 'order_id' => $o->get_id() ) );
$text  = implode( ' ', wp_list_pluck( $notes, 'content' ) );
nx_assert( false !== stripos( $text, 'stock' ), 'an order note tells the shop owner the stock is short' );
nx_assert_same( 0, Lines::of( wc_get_order( $o->get_id() ) )[0]['stock_held'], 'the line does not claim stock it did not get (held = taken - given back = 0)' );

nx_test_finish();
