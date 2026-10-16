<?php
// Marketplace Phase 6a: the WooCommerce cart. Only Nexora's own code can put a marketplace product in it; price, name, image,
// vendor and stock are read from the product post, never from the request; a product that changes (or disappears) while it sits in
// the cart is fixed on the next load and the member is told what changed; checkout re-checks without fixing.
require_once __DIR__ . '/../bootstrap.php';
nx_test_marketplace_item_scope(); // the cart goes through a scratch Marketplace Item; the real one (real orders point at it) is never touched

use Nexora\Marketplace\Cart_Bridge;
use Nexora\Marketplace\Cart_Panel;
use Nexora\Marketplace\Marketplace_Item;
use Nexora\Products\Repository;
use Nexora\Products\Service;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }

global $wpdb;
nx_test_reset_limits();
nx_test_capture_mail();

$A = nx_test_vendor( 'cba', 'approved' );
$B = nx_test_vendor( 'cbb', 'approved' );
$D = nx_test_vendor( 'cbd', 'approved' );
$C = nx_test_user( 'cbc' );
$admin = nx_test_admin();
$HIDDEN = Marketplace_Item::id();

$make = function ( $v, $sku, array $o = array() ) {
	$d = \Nexora\Products\Validator::validate( array_merge( array( 'name' => "Cart $sku", 'description' => 'd', 'price' => '250', 'stock' => '5', 'sku' => $sku, 'category' => 'other' ), $o ) );
	if ( $d['errors'] ) { throw new RuntimeException( wp_json_encode( $d['errors'] ) ); }
	$id = Repository::create( $v['user_id'], $v['vendor_id'], $d['data'], 'manual' );
	$GLOBALS['nx_test']['posts'][] = $id;
	return $id;
};
/** A fresh, empty cart for a user (as a new session would have). */
$cart = function ( $uid ) {
	wp_set_current_user( $uid );
	WC()->initialize_session();
	WC()->initialize_cart();
	WC()->cart->empty_cart( true );
	WC()->session->set( 'nexora_cart_seen', null );
	WC()->session->set( 'nexora_cart_changes', null );
	if ( $uid ) { delete_user_meta( $uid, '_woocommerce_persistent_cart_' . get_current_blog_id() ); }
	return WC()->cart;
};
/** What happens when the cart is loaded again from the session (the next page view). */
$reload = function () {
	WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
	WC()->cart->set_cart_contents( array() );
	WC()->cart->get_cart_from_session();
	WC()->cart->calculate_totals();
};
$lines = function () { return array_values( WC()->cart->get_cart() ); };
$line_data = function ( $line ) { return array_diff_key( $line, array_flip( array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ) ) ); };
$suspend = function ( $v ) use ( $admin ) { wp_set_current_user( $admin ); \Nexora\Vendors\Service::change_status( $v['vendor_id'], 'suspend' ); wp_set_current_user( 0 ); };
$reactivate = function ( $v ) use ( $admin ) { wp_set_current_user( $admin ); \Nexora\Vendors\Service::change_status( $v['vendor_id'], 'reactivate' ); wp_set_current_user( 0 ); };
$set_status = function ( $id, $status ) use ( $wpdb ) { $wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) ); clean_post_cache( $id ); };

$a1 = $make( $A, 'A-1', array( 'price' => '250.00', 'stock' => '5' ) );
$a2 = $make( $A, 'A-2', array( 'price' => '100.00', 'stock' => '9' ) );
$b1 = $make( $B, 'B-1', array( 'price' => '40.50', 'stock' => '3' ) );
$d1 = $make( $D, 'D-1', array( 'price' => '10.00', 'stock' => '50' ) );

/* ---------- adding: only the product id is stored; everything shown is read from the product post ---------- */
$c = $cart( $C['user_id'] );
$r = Cart_Bridge::add( $C['user_id'], $a1, 2 );
nx_assert( is_array( $r ) && ! empty( $r['key'] ), 'a customer can add a marketplace product' );
$L = $lines();
nx_assert_same( 1, count( $L ), 'one line' );
nx_assert_same( $HIDDEN, (int) $L[0]['product_id'], 'the line is the hidden Marketplace Item' );
nx_assert_same( array( 'nexora_product_id' => $a1 ), $line_data( $L[0] ), 'the only data stored on the line is the product post id' );
nx_assert_same( 2, (int) $L[0]['quantity'], 'quantity 2' );
nx_assert( 'Cart A-1' === $L[0]['data']->get_name() && 250.0 === (float) $L[0]['data']->get_price(), 'the line shows the product post\'s name and price (not the hidden item\'s)' );
$c->calculate_totals();
nx_assert_same( '500.00', wc_format_decimal( $c->get_subtotal(), 2 ), 'the subtotal is the DB price times the quantity' );
$extra = Cart_Bridge::item_data( array(), $L[0] );
nx_assert( 1 === count( $extra ) && 'Sold by' === $extra[0]['key'] && 'Test Shop cba' === $extra[0]['value'], 'a "Sold by <business>" line is added to the item data (the block cart shows it)' );
Cart_Bridge::add( $C['user_id'], $a1, 1 );
$L = $lines();
nx_assert( 1 === count( $L ) && 3 === (int) $L[0]['quantity'], 'adding the same product again merges into one line (2 + 1)' );
$r = Cart_Bridge::add( $C['user_id'], $a1, 3 );
nx_assert( is_wp_error( $r ) && 'insufficient_stock' === $r->get_error_code() && 3 === (int) $lines()[0]['quantity'], 'but never past the stock: 3 in the cart + 3 > 5 is refused and the line is untouched' );

/* ---------- the Marketplace Item cannot be added any other way ---------- */
$c = $cart( $C['user_id'] );
nx_assert( false === $c->add_to_cart( $HIDDEN, 1, 0, array(), array( 'nexora_product_id' => $a1 ) ), 'direct add_to_cart of the hidden item with a product id is refused' );
nx_assert( false === $c->add_to_cart( $HIDDEN, 1 ), 'direct add_to_cart of the hidden item with no data is refused' );
nx_assert( false === $c->add_to_cart( $HIDDEN, 5, 0, array(), array( 'nexora_product_id' => $a1, 'price' => '1', 'nexora_price' => '1', 'name' => 'Free' ) ), 'direct add_to_cart with a forged price and name is refused' );
nx_assert( false === $c->add_to_cart( $HIDDEN, 1, 0, array(), array( 'nexora_product_id' => 99999999 ) ), 'direct add_to_cart for a product that does not exist is refused' );
nx_assert_same( array(), $lines(), 'nothing got into the cart' );
// ?add-to-cart= and the Store API add-item go through the same gate.
$_REQUEST = $_GET = array( 'add-to-cart' => (string) $HIDDEN, 'quantity' => '3' );
WC_Form_Handler::add_to_cart_action( '' );
$_REQUEST = $_GET = array();
nx_assert_same( array(), $lines(), '?add-to-cart=<hidden item> adds nothing')
;
$req = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
$req->set_param( 'id', $HIDDEN ); $req->set_param( 'quantity', 2 );
$res = rest_do_request( $req );
nx_assert( $res->get_status() >= 400 && array() === $lines(), 'the Store API add-item for the hidden item is refused (status ' . $res->get_status() . ')' );

/* ---------- a stray extra cart-item data key is dropped ---------- */
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $a2, 1 );
$data = \Nexora\Marketplace\Cart_Bridge::strip( array( 'nexora_product_id' => $a2, 'price' => '1', 'vendor' => 'x' ), $HIDDEN, 0, 1 );
nx_assert_same( array(), $data, 'outside Nexora\'s own add, every key on the hidden item\'s cart data (forged id, price, vendor) is stripped' );

/* ---------- hostile requests to the endpoint ---------- */
$call = function ( $uid, array $post = array(), $nonce = true, $guest = false ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( 'nexora_add_to_cart', $post + ( $nonce ? nx_profile_nonce() : array() ), $guest );
	return $r;
};
$c = $cart( $C['user_id'] );
$r = $call( $C['user_id'], array( 'product_id' => (string) $a1, 'quantity' => '2', 'price' => '0.01', 'name' => 'Free stuff', 'vendor_id' => $B['vendor_id'], 'stock' => '9999', 'image' => 'https://evil.example/x.png', 'total' => '1', 'line_total' => '1', 'nexora_product_id' => (string) $b1, 'key' => 'abc', 'cart_item_data' => array( 'price' => 1 ) ) );
nx_assert( ! empty( $r['success'] ), 'a normal add works even when the request carries a tampered price, name, vendor, stock, image and totals' );
$L = $lines();
nx_assert( 1 === count( $L ) && 2 === (int) $L[0]['quantity'] && array( 'nexora_product_id' => $a1 ) === $line_data( $L[0] ), 'the line is exactly: this product id, quantity 2, no posted data' );
$c->calculate_totals();
nx_assert( 'Cart A-1' === $L[0]['data']->get_name() && '500.00' === wc_format_decimal( $c->get_subtotal(), 2 ), 'the cart shows the DB name and the DB price (250 x 2), not 0.01' );
nx_assert( false !== strpos( wp_json_encode( $r ), 'cart' ) && empty( $r['data']['price'] ), 'the reply does not echo back anything posted' );

foreach ( array( '0', '-1', '-5', '100', '99999999', '1e3', '1.5', '', ' 2', 'abc', '2;DROP TABLE x', '0x2', '+3', array( '1' ), array(), '٣' ) as $badq ) {
	$c = $cart( $C['user_id'] );
	$r = $call( $C['user_id'], array( 'product_id' => (string) $a1, 'quantity' => $badq ) );
	nx_assert( nx_rejected( $r ) && array() === $lines(), 'tampered quantity refused, nothing added: ' . substr( wp_json_encode( $badq ), 0, 24 ) );
}
foreach ( array( 'a profile post' => (string) $C['profile_id'], 'a vendor application' => (string) $A['vendor_id'], 'the hidden item itself' => (string) $HIDDEN, 'a missing id' => '99999999', 'zero' => '0', 'a negative id' => '-3', 'text' => 'abc', 'a float' => '1.5', 'an exponent' => '1e3', 'empty' => '', 'an array' => array( '1' ), 'a huge number' => str_repeat( '9', 30 ) ) as $label => $badid ) {
	$c = $cart( $C['user_id'] );
	$r = $call( $C['user_id'], array( 'product_id' => $badid, 'quantity' => '1' ) );
	nx_assert( nx_rejected( $r ) && array() === $lines(), "tampered product id refused, nothing added: $label" );
}
$c = $cart( $C['user_id'] );
$r = $call( $C['user_id'], array() );
nx_assert( nx_rejected( $r ), 'no product id at all is refused' );
$r = $call( $C['user_id'], array( 'product_id' => (string) $a1 ), false );
nx_assert( nx_rejected( $r ) && array() === $lines(), 'no nonce: refused' );
$c = $cart( $C['user_id'] );
$r = $call( $C['user_id'], array( 'product_id' => (string) $a1 ) );
nx_assert( ! empty( $r['success'] ) && 1 === (int) $lines()[0]['quantity'], 'a missing quantity means 1' );

/* ---------- guests: sent to sign in ---------- */
$c = $cart( 0 );
$r = $call( 0, array( 'product_id' => (string) $a1, 'quantity' => '1' ), true, false );
nx_assert( false === has_action( 'wp_ajax_nopriv_nexora_add_to_cart' ), 'the endpoint is not registered for logged-out visitors at all' );
nx_assert( nx_rejected( $r ) && is_array( $r['data'] ) && ! empty( $r['data']['login_url'] ) && 0 === strpos( $r['data']['login_url'], \Nexora\Core\Urls::login() ), 'a guest is refused with the sign-in address' );
nx_assert_same( array(), $lines(), 'and nothing is in the guest\'s cart' );
nx_assert( false === $c->add_to_cart( $b1, 1 ) && false === $c->add_to_cart( $HIDDEN, 1, 0, array(), array( 'nexora_product_id' => $b1 ) ), 'a guest cannot add anything to the cart by any route' );

/* ---------- the rules: own product, unpublished, suspended vendor, no stock ---------- */
$c = $cart( $A['user_id'] );
$r = $call( $A['user_id'], array( 'product_id' => (string) $a1, 'quantity' => '1' ) );
nx_assert( nx_rejected( $r ) && array() === $lines() && false !== stripos( wp_json_encode( $r ), 'own' ), 'a vendor cannot add their own product (and is told why)' );
$r = $call( $A['user_id'], array( 'product_id' => (string) $b1, 'quantity' => '1' ) );
nx_assert( ! empty( $r['success'] ), 'but a vendor can buy another vendor\'s product' );
$zero = $make( $B, 'B-0', array( 'stock' => '0' ) );
$r = $call( $C['user_id'], array( 'product_id' => (string) $zero, 'quantity' => '1' ) );
nx_assert( nx_rejected( $r ) && false !== strpos( wp_json_encode( $r ), 'Out of stock' ), 'a product with 0 stock is refused: "Out of stock."' );
$drafty = $make( $B, 'B-D' ); $set_status( $drafty, 'draft' );
$r = $call( $C['user_id'], array( 'product_id' => (string) $drafty, 'quantity' => '1' ) );
nx_assert( nx_rejected( $r ) && false !== strpos( wp_json_encode( $r ), 'not available' ), 'an unpublished product is refused with the generic message' );
$suspended_product = $make( $D, 'D-S' );
$suspend( $D );
$r = $call( $C['user_id'], array( 'product_id' => (string) $suspended_product, 'quantity' => '1' ) );
nx_assert( nx_rejected( $r ) && false !== strpos( wp_json_encode( $r ), 'not available' ) && false === stripos( wp_json_encode( $r ), 'suspend' ), 'a suspended vendor\'s product is refused and the reply does not say "suspended"' );
$reactivate( $D );

/* ---------- a product changes while it sits in the cart ---------- */
// price change
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $b1, 2 );
$reload();
Cart_Bridge::take_changes();
Repository::update( $b1, array( 'price' => '55.00' ) );
$reload();
nx_assert( 55.0 === (float) $lines()[0]['data']->get_price(), 'a price edit while in the cart: the new price is used after the next load' );
$c->calculate_totals();
nx_assert_same( '110.00', wc_format_decimal( $c->get_subtotal(), 2 ), 'and the subtotal follows (55 x 2)' );
$ch = Cart_Bridge::peek_changes();
nx_assert( 1 === count( $ch ) && false !== strpos( $ch[0], 'Cart B-1' ) && false !== strpos( $ch[0], '40.50' ) && false !== strpos( $ch[0], '55.00' ), 'the member is told: the product, the old price and the new price' );
$reload();
nx_assert_same( 1, count( Cart_Bridge::peek_changes() ), 'loading again without a new change adds nothing' );
$taken = Cart_Bridge::take_changes();
nx_assert( 1 === count( $taken ) && array() === Cart_Bridge::take_changes(), 'the change list is handed over once and then cleared' );
// stock now lower than the quantity
Repository::update( $b1, array( 'stock' => 1 ) );
$reload();
$L = $lines();
nx_assert( 1 === count( $L ) && 1 === (int) $L[0]['quantity'], 'stock dropped below the quantity: the line is lowered to what is left' );
$ch = Cart_Bridge::take_changes();
nx_assert( 1 === count( $ch ) && false !== strpos( $ch[0], 'Cart B-1' ) && false !== stripos( $ch[0], 'only 1' ), 'and the member is told how many are left' );
// stock gone
Repository::update( $b1, array( 'stock' => 0 ) );
$reload();
nx_assert_same( array(), $lines(), 'stock 0: the line is removed' );
nx_assert( 1 === count( Cart_Bridge::take_changes() ), 'and the member is told it is sold out' );
Repository::update( $b1, array( 'stock' => 3, 'price' => '40.50' ) );

// product deleted by its vendor
$gone = $make( $B, 'B-GONE', array( 'name' => 'Vanishing Vase' ) );
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $gone, 1 );
Cart_Bridge::add( $C['user_id'], $a2, 1 );
$reload();
Cart_Bridge::take_changes();
wp_set_current_user( $B['user_id'] );
Service::delete( $B['user_id'], $gone );
wp_set_current_user( $C['user_id'] );
nx_assert_same( null, get_post( $gone ), 'setup: the vendor deleted the product' );
$reload();
$L = $lines();
nx_assert( 1 === count( $L ) && $a2 === (int) $L[0]['nexora_product_id'], 'a deleted product is removed from the cart; the other line stays' );
$ch = Cart_Bridge::take_changes();
nx_assert( 1 === count( $ch ) && false !== strpos( $ch[0], 'Vanishing Vase' ) && false !== stripos( $ch[0], 'no longer available' ), 'the member is told which product is no longer available (by the name they saw)' );

// unpublished
$up = $make( $B, 'B-UP' );
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $up, 1 );
$reload(); Cart_Bridge::take_changes();
$set_status( $up, 'draft' );
$reload();
nx_assert( array() === $lines() && 1 === count( Cart_Bridge::take_changes() ), 'a product that was unpublished is removed and the member is told' );
$set_status( $up, 'publish' );

// vendor suspended
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $d1, 2 );
Cart_Bridge::add( $C['user_id'], $a2, 1 );
$reload(); Cart_Bridge::take_changes();
$suspend( $D );
wp_set_current_user( $C['user_id'] );
$reload();
$L = $lines();
nx_assert( 1 === count( $L ) && $a2 === (int) $L[0]['nexora_product_id'], 'a suspended vendor\'s lines are removed from the cart; other vendors\' lines stay' );
$ch = Cart_Bridge::take_changes();
nx_assert( 1 === count( $ch ) && false !== stripos( $ch[0], 'no longer available' ) && false === stripos( $ch[0], 'suspend' ), 'the member is told it is unavailable (not why)' );
$reactivate( $D );
wp_set_current_user( $C['user_id'] );

// the member's own product somehow in the cart (e.g. a cart from before they became a vendor)
$c = $cart( $A['user_id'] );
Cart_Bridge::add( $A['user_id'], $b1, 1 );
$contents = WC()->cart->get_cart();
$key = array_key_first( $contents );
$forged = $contents[ $key ]; $forged['nexora_product_id'] = $a1; $forged['key'] = 'forgedkey1';
WC()->cart->set_cart_contents( array( 'forgedkey1' => $forged ) );
$reload();
nx_assert_same( array(), $lines(), 'a line for the member\'s own product is removed at load' );
nx_assert( 1 === count( Cart_Bridge::take_changes() ), '...with a message' );

/* ---------- several vendors in one cart: grouped by vendor ---------- */
$c = $cart( $C['user_id'] );
foreach ( array( $a1, $b1, $d1, $a2, $b1 ) as $pid ) { Cart_Bridge::add( $C['user_id'], $pid, 1 ); }
$reload();
$order = array_map( function ( $l ) { return (int) $l['nexora_product_id']; }, $lines() );
nx_assert_same( array( $a1, $a2, $b1, $d1 ), $order, 'added A1, B1, D1, A2, B1: the cart is ordered by vendor (A, A, B, D), each line once' );
nx_assert_same( 2, (int) $lines()[2]['quantity'], '(the two B1 adds merged into one line of 2)' );
$html = Cart_Panel::render();
nx_assert( 3 === substr_count( $html, 'nx-cart-vendor"' ) || 3 === substr_count( $html, 'class="nx-cart-vendor' ), 'the "Your cart by seller" panel has one group per vendor (3)' );
nx_assert( false !== strpos( $html, 'Test Shop cba' ) && false !== strpos( $html, 'Test Shop cbb' ) && false !== strpos( $html, 'Test Shop cbd' ), 'each group is headed by the business name' );
nx_assert( false !== strpos( $html, 'Cart A-1' ) && false !== strpos( $html, 'Cart A-2' ) && false !== strpos( $html, 'Cart B-1' ) && false !== strpos( $html, 'Cart D-1' ), 'every line is listed under its vendor' );
nx_assert( false !== strpos( $html, '350.00' ) && false !== strpos( $html, '81.00' ) && false !== strpos( $html, '10.00' ), 'each group shows its subtotal (A 350.00, B 81.00, D 10.00)' );
$sub = function ( $name ) use ( $html ) { return preg_match( '/' . preg_quote( $name, '/' ) . '.*?nx-cart-subtotal[^>]*>([^<]*)</s', $html, $m ) ? $m[1] : ''; };
nx_assert( false !== strpos( $sub( 'Test Shop cba' ), '350.00' ) && false !== strpos( $sub( 'Test Shop cbb' ), '81.00' ) && false !== strpos( $sub( 'Test Shop cbd' ), '10.00' ), 'subtotals: A 350.00, B 81.00, D 10.00' );
nx_assert( false === strpos( $html, '<script' ) && false === strpos( $html, 'onerror' ), 'the panel prints no script' );
$block = \Nexora\Marketplace\Cart_Panel::in_front_of_cart_block( '<div class="wp-block-woocommerce-cart">BLOCK</div>', array( 'blockName' => 'woocommerce/cart' ) );
nx_assert( 0 === strpos( $block, '<section' ) && false !== strpos( $block, 'BLOCK' ) && strpos( $block, 'nx-cart-panel' ) < strpos( $block, 'BLOCK' ), 'the panel is placed above the cart block' );
$other = \Nexora\Marketplace\Cart_Panel::in_front_of_cart_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ) );
nx_assert_same( '<p>x</p>', $other, 'other blocks are untouched' );
WC()->cart->empty_cart();
nx_assert_same( '', Cart_Panel::render(), 'an empty cart has no panel' );
nx_assert_same( '<div class="wp-block-woocommerce-cart">BLOCK</div>', \Nexora\Marketplace\Cart_Panel::in_front_of_cart_block( '<div class="wp-block-woocommerce-cart">BLOCK</div>', array( 'blockName' => 'woocommerce/cart' ) ), 'and the cart block is returned as it is' );

// totals: DB price x quantity for every line
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $a1, 2 ); Cart_Bridge::add( $C['user_id'], $b1, 3 ); Cart_Bridge::add( $C['user_id'], $d1, 10 );
$reload();
nx_assert_same( '721.50', wc_format_decimal( WC()->cart->get_subtotal(), 2 ), 'subtotal across vendors = 250x2 + 40.50x3 + 10x10 = 721.50' );

// the panel tells what changed, once
Repository::update( $d1, array( 'price' => '12.00' ) );
$reload();
$html = Cart_Panel::render();
nx_assert( false !== strpos( $html, 'nx-cart-changes' ) && false !== strpos( $html, 'Cart D-1' ) && false !== strpos( $html, '12.00' ), 'the panel lists what changed' );
nx_assert( false === strpos( Cart_Panel::render(), 'nx-cart-changes' ), 'and shows it only once' );
Repository::update( $d1, array( 'price' => '10.00' ) );

/* ---------- checkout re-checks, without fixing ---------- */
$c = $cart( $C['user_id'] );
Cart_Bridge::add( $C['user_id'], $a1, 1 ); Cart_Bridge::add( $C['user_id'], $b1, 1 );
$reload(); Cart_Bridge::take_changes();
$errors = new WP_Error();
do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
nx_assert( ! $errors->has_errors(), 'a valid cart has no checkout errors' );
Repository::update( $a1, array( 'stock' => 0 ) );
$suspend( $B );
wp_set_current_user( $C['user_id'] );
$errors = new WP_Error();
do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
nx_assert( 2 === count( $errors->get_error_codes() ) && 0 === strpos( $errors->get_error_codes()[0], 'nexora_cart_' ), 'checkout (Store API) reports a problem for each bad line, with plain messages' );
nx_assert( 2 === count( $lines() ), 'and does NOT silently change the cart (that happens on load, with a message)' );
$msgs = implode( ' ', $errors->get_error_messages() );
nx_assert( false !== strpos( $msgs, 'Cart A-1' ) && false === stripos( $msgs, 'suspend' ), 'the messages name the product and never say "suspended"' );
$threw = null;
try { do_action( 'woocommerce_store_api_validate_cart_item', wc_get_product( $HIDDEN ), $lines()[0] ); } catch ( \Exception $e ) { $threw = $e; }
nx_assert( null !== $threw, 'the per-item Store API check throws for a bad line' );
wc_clear_notices();
do_action( 'woocommerce_check_cart_items' );
nx_assert( wc_notice_count( 'error' ) >= 1, 'the classic cart check raises an error notice too' );
wc_clear_notices();
$reactivate( $B );
Repository::update( $a1, array( 'stock' => 5 ) );
wp_set_current_user( $C['user_id'] );
$errors = new WP_Error();
do_action( 'woocommerce_store_api_cart_errors', $errors, WC()->cart );
nx_assert( ! $errors->has_errors(), 'once the problems are gone the checkout check passes again' );

// the checkout check is read-only
$before = wp_json_encode( WC()->cart->get_cart_for_session() ) . wp_json_encode( WC()->session->get( 'nexora_cart_changes' ) );
do_action( 'woocommerce_store_api_cart_errors', new WP_Error(), WC()->cart );
nx_assert_same( $before, wp_json_encode( WC()->cart->get_cart_for_session() ) . wp_json_encode( WC()->session->get( 'nexora_cart_changes' ) ), 'running the checkout check changes neither the cart nor the change list' );

/* ---------- rate limit ---------- */
add_filter( 'nexora_rate_limits', function ( $l ) { $l['add_to_cart'] = array( 3, 10 * MINUTE_IN_SECONDS ); return $l; } );
nx_test_reset_limits();
$c = $cart( $C['user_id'] );
$res = array();
for ( $i = 0; $i < 4; $i++ ) { $res[] = nx_call_ajax( 'nexora_add_to_cart', array( 'product_id' => (string) $d1, 'quantity' => '1' ) + nx_profile_nonce() ); }
remove_all_filters( 'nexora_rate_limits' );
nx_assert( ! empty( $res[2]['success'] ) && nx_rejected( $res[3] ) && false !== stripos( wp_json_encode( $res[3] ), 'too many' ), 'rate limit: the 4th add in the window is refused' );

wp_set_current_user( 0 );
WC()->cart->empty_cart( true );
nx_test_finish();
