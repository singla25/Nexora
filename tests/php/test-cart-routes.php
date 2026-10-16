<?php
// Marketplace Phase 6a: WooCommerce's own doors are closed to guests: the Store API cart/checkout/order routes, ?add-to-cart=,
// the cart-related wc-ajax actions, the cart and checkout pages, Woo's login / registration / lost-password handlers; and guest
// checkout stays off.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Urls;
use Nexora\Integrations\Woo_Routes;
use Nexora\Marketplace\Marketplace_Item;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }

global $wpdb;
$C = nx_test_vendor( 'crt', 'approved' );
$HIDDEN = Marketplace_Item::id();
rest_get_server();

/* ---------- Store API: guests get 401 on everything that touches a cart, a checkout or an order ---------- */
$call = function ( $method, $route, array $params = array() ) {
	$req = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) { $req->set_param( $k, $v ); }
	return rest_do_request( $req );
};
$closed = array(
	array( 'GET', '/wc/store/v1/cart' ), array( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $HIDDEN, 'quantity' => 1 ) ), array( 'GET', '/wc/store/v1/cart/items' ),
	array( 'POST', '/wc/store/v1/cart/items', array( 'id' => $HIDDEN, 'quantity' => 1 ) ), array( 'POST', '/wc/store/v1/cart/apply-coupon', array( 'code' => 'x' ) ),
	array( 'POST', '/wc/store/v1/cart/select-shipping-rate', array( 'package_id' => 0, 'rate_id' => 'flat_rate:1' ) ), array( 'POST', '/wc/store/v1/cart/update-customer', array() ),
	array( 'POST', '/wc/store/v1/batch', array( 'requests' => array() ) ), array( 'GET', '/wc/store/v1/checkout' ), array( 'POST', '/wc/store/v1/checkout', array() ), array( 'PUT', '/wc/store/v1/checkout', array() ),
	array( 'GET', '/wc/store/v1/order/1' ), array( 'POST', '/wc/store/v1/order/1', array( 'key' => 'wc_order_x' ) ), array( 'GET', '/wc/store/v1/cart-extensions' ),
);
wp_set_current_user( 0 );
foreach ( $closed as $r ) {
	$res = $call( $r[0], $r[1], $r[2] ?? array() );
	$d   = $res->get_data();
	nx_assert( 401 === $res->get_status() && 'nexora_sign_in' === ( $d['code'] ?? '' ), "a guest gets 401 on {$r[0]} {$r[1]}" );
	nx_assert( false === strpos( wp_json_encode( $d ), 'wc_order' ) && false === strpos( wp_json_encode( $d ), '/wc/' ), "the refusal of {$r[1]} reveals nothing" );
}
$res = $call( 'GET', '/wc/store/v1/products' );
nx_assert( 200 === $res->get_status(), 'the public product list is left alone for guests' );
wp_set_current_user( $C['user_id'] );
$res = $call( 'GET', '/wc/store/v1/cart' );
nx_assert( 200 === $res->get_status(), 'a logged-in member can read their own cart through the Store API' );
$res = $call( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $HIDDEN, 'quantity' => 1 ) );
nx_assert( $res->get_status() >= 400, 'a logged-in member cannot add the hidden item by the Store API (status ' . $res->get_status() . ')' );
wp_set_current_user( 0 );

/* ---------- ?add-to-cart= and wc-ajax for guests ---------- */
$p = new WC_Product_Simple();
$p->set_name( 'Plain Woo product' ); $p->set_regular_price( '10' ); $p->set_status( 'publish' );
$pid = $p->save();
WC()->initialize_session(); WC()->initialize_cart(); WC()->cart->empty_cart( true );
$_REQUEST = $_GET = array( 'add-to-cart' => (string) $pid, 'quantity' => '1' );
WC_Form_Handler::add_to_cart_action( '' );
$_REQUEST = $_GET = array();
nx_assert_same( 0, WC()->cart->get_cart_contents_count(), 'a guest cannot add ANY product with ?add-to-cart=' );
nx_assert( false === WC()->cart->add_to_cart( $pid, 1 ), 'a guest cannot add any product to the cart by code either' );

$ajax = function ( $name ) {
	$_GET = $_REQUEST = array( 'wc-ajax' => $name );
	$handler = function () { return function () { throw new RuntimeException( 'die' ); }; };
	add_filter( 'wp_die_ajax_handler', $handler, 99 ); add_filter( 'wp_die_handler', $handler, 99 ); add_filter( 'wp_doing_ajax', '__return_true' );
	ob_start();
	$stopped = false;
	try { Woo_Routes::guard_wc_ajax(); } catch ( RuntimeException $e ) { $stopped = true; }
	$out = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $handler, 99 ); remove_filter( 'wp_die_handler', $handler, 99 ); remove_filter( 'wp_doing_ajax', '__return_true' );
	$_GET = $_REQUEST = array();
	return array( $stopped, json_decode( $out, true ) );
};
foreach ( array( 'add_to_cart', 'remove_from_cart', 'checkout', 'update_order_review', 'apply_coupon', 'remove_coupon', 'update_shipping_method' ) as $name ) {
	wp_set_current_user( 0 );
	list( $stopped, $json ) = $ajax( $name );
	nx_assert( $stopped && 'nexora_sign_in' === ( $json['code'] ?? '' ) && ! empty( $json['login_url'] ), "a guest calling wc-ajax=$name is answered 'sign in' with the login address" );
	wp_set_current_user( $C['user_id'] );
	list( $stopped ) = $ajax( $name );
	nx_assert( ! $stopped, "a member calling wc-ajax=$name is not stopped by the guard" );
}
wp_set_current_user( 0 );
list( $stopped ) = $ajax( 'get_refreshed_fragments' );
nx_assert( ! $stopped, 'harmless wc-ajax calls (fragments) are not touched' );
wp_delete_post( $pid, true );

/* ---------- Woo's own login, registration and password-reset handlers are gone ---------- */
foreach ( array( 'process_login', 'process_registration', 'process_lost_password', 'process_reset_password' ) as $h ) {
	nx_assert( false === has_action( 'wp_loaded', array( 'WC_Form_Handler', $h ) ), "WC_Form_Handler::$h is no longer hooked to wp_loaded" );
}
nx_assert( false !== has_action( 'wp_loaded', array( 'WC_Form_Handler', 'add_to_cart_action' ) ), '(the add-to-cart handler stays, behind the gate)' );
nx_assert_same( Urls::login(), wp_lostpassword_url(), 'and so is WordPress\'s' );
nx_assert_same( Urls::login(), apply_filters( 'woocommerce_lostpassword_url', 'https://x.example/wp-login.php?action=lostpassword' ), 'whatever address was proposed' );

/* ---------- guest checkout stays off, whatever the settings say ---------- */
$raw = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'woocommerce_enable_guest_checkout'" );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
nx_assert_same( 'no', get_option( 'woocommerce_enable_guest_checkout' ), 'guest checkout reads as "no" even when the database says yes' );
nx_assert( in_array( 'woocommerce_enable_guest_checkout', Woo_Routes::forced_off_options(), true ), 'it is on the forced-off list with the other sign-up options' );
nx_assert( true === WC()->checkout()->is_registration_required(), 'so WooCommerce requires an account to check out' );
if ( null === $raw ) { delete_option( 'woocommerce_enable_guest_checkout' ); } else { $wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => 'woocommerce_enable_guest_checkout' ) ); }
wp_cache_flush();

/* ---------- the cart and checkout pages send guests to sign in ---------- */
$visit = function ( $uid, $page_id, $vars = array() ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'page_id' => $page_id ) );
	if ( $GLOBALS['wp_query']->have_posts() ) { $GLOBALS['wp_query']->the_post(); $GLOBALS['post'] = $GLOBALS['wp_query']->post; }
	foreach ( $vars as $k => $v ) { set_query_var( $k, $v ); $GLOBALS['wp']->query_vars[ $k ] = $v; }
	$throw = function ( $location ) { throw new RuntimeException( $location ); };
	add_filter( 'wp_redirect', $throw, 1 );
	$out = 'none';
	try { Woo_Routes::redirect_shop_pages(); } catch ( RuntimeException $e ) { $out = $e->getMessage(); }
	remove_filter( 'wp_redirect', $throw, 1 );
	foreach ( $vars as $k => $v ) { unset( $GLOBALS['wp']->query_vars[ $k ] ); }
	wp_set_current_user( 0 );
	return $out;
};
foreach ( array( 'cart' => wc_get_page_id( 'cart' ), 'checkout' => wc_get_page_id( 'checkout' ) ) as $label => $id ) {
	$loc = $visit( 0, $id );
	parse_str( (string) wp_parse_url( $loc, PHP_URL_QUERY ), $q );
	nx_assert( 0 === strpos( $loc, Urls::login() ) && ! empty( $q['redirect_to'] ), "a guest on the $label page is sent to the Nexora login with a return address" );
	nx_assert( '' !== Urls::safe_return( $q['redirect_to'] ?? '' ), "and that return address is one the login will follow ($label)" );
	nx_assert_same( 'none', $visit( $C['user_id'], $id ), "a member on the $label page is left alone" );
}
$loc = $visit( 0, wc_get_page_id( 'checkout' ), array( 'order-received' => '123' ) );
nx_assert( 0 === strpos( $loc, Urls::login() ) && false === strpos( $loc, 'redirect_to' ), 'a guest on an order-received page goes to the login (without a return address)' );
nx_assert_same( 'none', $visit( 0, wc_get_page_id( 'shop' ) ), 'the shop page itself is left alone' );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) { nx_assert_same( 'none', $visit( 0, $sample->ID ), 'ordinary pages are left alone' ); }

nx_test_finish();
