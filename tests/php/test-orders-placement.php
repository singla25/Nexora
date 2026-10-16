<?php
// Marketplace Phase 6b: when an order is placed. Each marketplace line is frozen (product post id, name, SKU, price, quantity, vendor id,
// vendor business name, the commission rate, the commission and the vendor's earning); later edits or deletion of the product change
// nothing; stock is taken atomically and a missing unit refuses the order with a clear message; placing twice changes nothing.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Lines;
use Nexora\Orders\Commission;
use Nexora\Products\Repository;
use Nexora\Products\Service;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$A = nx_test_vendor( 'opa', 'approved' );
$B = nx_test_vendor( 'opb', 'approved' );
$C = nx_test_user( 'opc' );
$D = nx_test_user( 'opd' );
global $wpdb;
$stock = function ( $pid ) { return (int) get_post_meta( $pid, 'stock', true ); };

/* ---------- the snapshot ---------- */
$p1 = nx_test_product( $A, 'OP-1', '100.00', '5' );
$r  = nx_test_order( $C, array( array( $p1, 2 ) ) );
$o  = $r['order'];
nx_assert( '' === $r['error'] && $o instanceof WC_Order, 'setup: a customer orders 2 of a 100.00 product (' . $r['error'] . ')' );
$lines = Lines::of( $o );
nx_assert_same( 1, count( $lines ), 'the order has one marketplace line' );
$l = $lines[0];
nx_assert_same( $p1, $l['product_id'], 'frozen: the product post id' );
nx_assert_same( 'Order Test OP-1', $l['name'], 'frozen: the product name' );
nx_assert_same( 'OP-1', $l['sku'], 'frozen: the SKU' );
nx_assert_same( '100.00', $l['price'], 'frozen: the unit price' );
nx_assert_same( 2, $l['qty'], 'frozen: the quantity' );
nx_assert_same( $A['vendor_id'], $l['vendor_id'], 'frozen: the vendor id (the vendor application post)' );
nx_assert_same( 'Test Shop opa', $l['vendor_name'], 'frozen: the vendor business name' );
nx_assert_same( '10', (string) $l['rate'], 'frozen: the commission rate the order was placed at (10)' );
nx_assert_same( '200.00', $l['gross'], 'the line total is price x quantity' );
nx_assert_same( '20.00', $l['commission'], 'the commission is 10% of the stored line price' );
nx_assert_same( '180.00', $l['earning'], 'and the vendor earns the rest' );
nx_assert_same( 2, $l['stock_taken'], 'two units were taken from stock' );
nx_assert_same( 3, $stock( $p1 ), 'the product now has 3 in stock' );
nx_assert_same( '1', (string) $o->get_meta( '_nx_order', true ), 'the order is marked as a marketplace order' );
nx_assert_same( array( $A['vendor_id'] ), Lines::vendor_ids( $o ), 'the order lists its vendor (so a vendor\'s orders can be found)' );
nx_assert_same( $C['user_id'], (int) $o->get_customer_id(), 'the order belongs to the buyer' );

/* ---------- later edits to the product do not touch the old order ---------- */
$edit = Service::save( $A['user_id'], array( 'name' => 'Renamed After Sale', 'description' => 'd', 'price' => '999.00', 'stock' => '50', 'sku' => 'OP-1-NEW', 'category' => 'other' ), $p1 );
nx_assert( ! is_wp_error( $edit ), 'setup: the vendor edits name, price, SKU and stock afterwards' );
$again = Lines::of( wc_get_order( $o->get_id() ) )[0];
nx_assert_same( array( 'Order Test OP-1', 'OP-1', '100.00', '20.00', '180.00', '200.00' ), array( $again['name'], $again['sku'], $again['price'], $again['commission'], $again['earning'], $again['gross'] ), 'a price/name/SKU edit after the order changes nothing in the order' );
$charged = wc_get_order( $o->get_id() ); $item0 = current( $charged->get_items() );
nx_assert_same( '200.00', number_format( (float) $item0->get_total(), 2, '.', '' ), 'the amount the customer was charged for the line is unchanged too' );
update_option( Commission::OPTION, '30' );
nx_assert_same( '20.00', Lines::of( wc_get_order( $o->get_id() ) )[0]['commission'], 'changing the commission setting afterwards does not change an old order (the line keeps its rate)' );
nx_test_options( array( Commission::OPTION => null ) );
Repository::delete( $p1 );
$gone = Lines::of( wc_get_order( $o->get_id() ) )[0];
nx_assert_same( array( 'Order Test OP-1', 'OP-1', 'Test Shop opa', $p1 ), array( $gone['name'], $gone['sku'], $gone['vendor_name'], $gone['product_id'] ), 'even when the product is deleted the order still shows what was bought and from whom' );

/* ---------- several vendors in one order ---------- */
$pa = nx_test_product( $A, 'OP-A', '40.00', '9' );
$pb = nx_test_product( $B, 'OP-B', '25.50', '9' );
$r  = nx_test_order( $D, array( array( $pa, 1 ), array( $pb, 3 ) ) );
$m  = Lines::of( $r['order'] );
nx_assert_same( 2, count( $m ), 'two lines' );
$by = array(); foreach ( $m as $x ) { $by[ $x['sku'] ] = $x; }
nx_assert_same( array( $A['vendor_id'], 'Test Shop opa', '4.00', '36.00' ), array( $by['OP-A']['vendor_id'], $by['OP-A']['vendor_name'], $by['OP-A']['commission'], $by['OP-A']['earning'] ), 'line A carries vendor A and its own commission' );
nx_assert_same( array( $B['vendor_id'], 'Test Shop opb', '7.65', '68.85', '76.50' ), array( $by['OP-B']['vendor_id'], $by['OP-B']['vendor_name'], $by['OP-B']['commission'], $by['OP-B']['earning'], $by['OP-B']['gross'] ), 'line B carries vendor B (25.50 x 3 = 76.50, commission 7.65)' );
$vids = Lines::vendor_ids( $r['order'] );
sort( $vids );
$want = array( $A['vendor_id'], $B['vendor_id'] ); sort( $want );
nx_assert_same( $want, $vids, 'the order lists both vendors' );
nx_assert_same( array( 8, 6 ), array( $stock( $pa ), $stock( $pb ) ), 'both products lost the ordered quantity' );

/* ---------- placing twice changes nothing (both checkout hooks may fire) ---------- */
$o2   = $r['order'];
$snap = wp_json_encode( Lines::of( $o2 ) );
do_action( 'woocommerce_checkout_order_processed', $o2->get_id(), array(), wc_get_order( $o2->get_id() ) );
do_action( 'woocommerce_store_api_checkout_order_processed', wc_get_order( $o2->get_id() ) );
nx_assert_same( $snap, wp_json_encode( Lines::of( wc_get_order( $o2->get_id() ) ) ), 'firing both placement hooks again changes no line' );
nx_assert_same( array( 8, 6 ), array( $stock( $pa ), $stock( $pb ) ), '... and takes no more stock' );

/* ---------- not enough stock: refused with a clear message, nothing taken ---------- */
$low = nx_test_product( $A, 'OP-LOW', '10.00', '2' );
// Between the cart and the placement somebody else took a unit:
$r = nx_test_order( $C, array( array( $low, 2 ) ), array( 'place' => false ) );
update_post_meta( $low, 'stock', '1' );
try {
	do_action( 'woocommerce_checkout_order_processed', $r['id'], array(), wc_get_order( $r['id'] ) );
	$msg = '';
} catch ( \Exception $e ) {
	$msg = $e->getMessage();
}
nx_assert( '' !== $msg && false !== stripos( $msg, 'Order Test OP-LOW' ) && false !== stripos( $msg, 'stock' ), 'placing is refused with a message that names the product and says stock is gone: "' . $msg . '"' );
nx_assert_same( 1, $stock( $low ), 'nothing was taken' );
$refused = wc_get_order( $r['id'] );
nx_assert( in_array( $refused->get_status(), array( 'cancelled', 'failed' ), true ), 'the order is not left waiting (' . $refused->get_status() . ')' );
nx_assert_same( array(), array_filter( Lines::of( $refused ), function ( $x ) { return $x['stock_taken'] > 0; } ), 'and no line claims stock' );

/* ---------- a multi-line order that fails halfway gives back what it took ---------- */
$m1 = nx_test_product( $A, 'OP-M1', '10.00', '5' );
$m2 = nx_test_product( $B, 'OP-M2', '10.00', '5' );
$r  = nx_test_order( $C, array( array( $m1, 3 ), array( $m2, 2 ) ), array( 'place' => false ) );
update_post_meta( $m2, 'stock', '1' );
try { do_action( 'woocommerce_checkout_order_processed', $r['id'], array(), wc_get_order( $r['id'] ) ); $msg = ''; } catch ( \Exception $e ) { $msg = $e->getMessage(); }
nx_assert( '' !== $msg, 'the second line is short, so the order is refused' );
nx_assert_same( array( 5, 1 ), array( $stock( $m1 ), $stock( $m2 ) ), 'the first line\'s units are back (all or nothing)' );

/* ---------- the price must still be what the customer was charged ---------- */
$pp = nx_test_product( $A, 'OP-PRICE', '50.00', '5' );
$r  = nx_test_order( $C, array( array( $pp, 1 ) ), array( 'place' => false ) );
update_post_meta( $pp, 'price', '60.00' );
try { do_action( 'woocommerce_checkout_order_processed', $r['id'], array(), wc_get_order( $r['id'] ) ); $msg = ''; } catch ( \Exception $e ) { $msg = $e->getMessage(); }
nx_assert( '' !== $msg && false !== stripos( $msg, 'price' ), 'if the price changed between cart and placement the order is refused and says so: "' . $msg . '"' );
nx_assert_same( 5, $stock( $pp ), '... with no stock taken' );

/* ---------- other refusals at placement ---------- */
$pu = nx_test_product( $A, 'OP-UNPUB', '10.00', '5' );
$r  = nx_test_order( $C, array( array( $pu, 1 ) ), array( 'place' => false ) );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $pu ) ); clean_post_cache( $pu ); // (the product guard refuses wp_update_post on purpose)
try { do_action( 'woocommerce_checkout_order_processed', $r['id'], array(), wc_get_order( $r['id'] ) ); $msg = ''; } catch ( \Exception $e ) { $msg = $e->getMessage(); }
nx_assert( '' !== $msg && 5 === $stock( $pu ), 'a product unpublished before placement refuses the order and takes nothing' );
$S2 = nx_test_vendor( 'ops', 'approved' );
$ps = nx_test_product( $S2, 'OP-SUSP', '10.00', '5' );
$r  = nx_test_order( $C, array( array( $ps, 1 ) ), array( 'place' => false ) );
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $S2['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
try { do_action( 'woocommerce_checkout_order_processed', $r['id'], array(), wc_get_order( $r['id'] ) ); $msg = ''; } catch ( \Exception $e ) { $msg = $e->getMessage(); }
nx_assert( '' !== $msg && 5 === $stock( $ps ), 'a vendor suspended before placement refuses the order and takes nothing' );

/* ---------- an order with no marketplace line is not ours ---------- */
$plain = wc_create_order();
$plain->save();
$GLOBALS['nx_test']['orders'][] = $plain->get_id();
do_action( 'woocommerce_checkout_order_processed', $plain->get_id(), array(), $plain );
nx_assert_same( '', (string) wc_get_order( $plain->get_id() )->get_meta( '_nx_order', true ), 'an ordinary order is left alone' );
nx_assert_same( array(), Lines::of( wc_get_order( $plain->get_id() ) ), '... and has no marketplace lines' );

/* ---------- the line really is tagged by the checkout hook (not by the test) ---------- */
$pt = nx_test_product( $A, 'OP-TAG', '10.00', '5' );
$r  = nx_test_order( $C, array( array( $pt, 1 ) ), array( 'place' => false ) );
$item = current( wc_get_order( $r['id'] )->get_items() );
nx_assert_same( (string) $pt, (string) $item->get_meta( '_nx_product_id', true ), 'before placing, checkout has tagged the line with the product post id (woocommerce_checkout_create_order_line_item)' );
nx_assert_same( '', (string) $item->get_meta( '_nx_price', true ), '... and nothing else is frozen yet' );

nx_test_finish();
