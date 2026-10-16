<?php
// Marketplace Phase 6b over real HTTP, the way the block checkout does it: a buyer's Store API checkout with bank transfer places the order
// (woocommerce_store_api_checkout_order_processed), freezes the lines and takes the stock; when the stock is gone the next buyer is
// refused with a clear message and no order is left waiting. Skipped when the site cannot be reached.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Lines;

if ( ! function_exists( 'curl_init' ) || ! function_exists( 'WC' ) ) { echo "curl or WooCommerce missing: skipped\n"; nx_test_finish(); return; }
$base = untrailingslashit( home_url() );

$http = function ( $url, array $opts = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, $opts + array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false ) );
	$out = curl_exec( $ch ); $info = curl_getinfo( $ch ); curl_close( $ch );
	if ( false === $out ) { return array( 'status' => 0, 'body' => '', 'json' => null ); }
	$body = substr( $out, $info['header_size'] );
	return array( 'status' => (int) $info['http_code'], 'body' => $body, 'json' => json_decode( $body, true ) );
};
if ( ! $http( $base . '/wp-login.php', array( CURLOPT_NOBODY => true ) )['status'] ) { echo "site not reachable from the CLI: skipped\n"; nx_test_finish(); return; }

// "Coming soon" hides the cart and checkout from everyone but administrators; the site's own value comes back at the end.
nx_test_options( array( 'woocommerce_coming_soon' => 'no', 'woocommerce_store_pages_only' => 'no' ) );
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$seller = nx_test_vendor( 'ohs', 'approved' );
$pw     = 'Tmp#Pass' . wp_rand( 1000, 9999 ) . 'x';
$pid    = nx_test_product( $seller, 'OH-1', '100.00', '3' );
$stock  = function () use ( $pid ) { clean_post_cache( $pid ); wp_cache_delete( $pid, 'post_meta' ); return (int) get_post_meta( $pid, 'stock', true ); };

/** Log a buyer in over HTTP, put $qty of the product in their cart, and return what checkout needs. */
$shopper = function ( $qty ) use ( $http, $base, $pw, $pid ) {
	$u = nx_test_user( 'ohb' . wp_rand( 100, 999 ) );
	wp_set_password( $pw, $u['user_id'] );
	$jar = tempnam( sys_get_temp_dir(), 'nxjar' );
	$login = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_profile_login', 'nonce' => wp_create_nonce( 'profile_nonce' ), 'user_name' => $u['login'], 'password' => $pw ) ), CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar ) );
	$page  = $http( home_url( '/profile-page/' . rawurlencode( $u['login'] ) ), array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true ) );
	preg_match( '/var nexoraMarketplace = (\{.*?\});/s', $page['body'], $m );
	$nonce = json_decode( $m[1] ?? '{}', true )['nonce'] ?? '';
	$add   = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_add_to_cart', 'nonce' => $nonce, 'product_id' => (string) $pid, 'quantity' => (string) $qty ) ) ) );
	$cart  = $http( $base . '/cart/', array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true ) );
	preg_match( '/createNonceMiddleware\(\s*"([a-f0-9]+)"/', $cart['body'], $wpn );
	preg_match( '/%22Nonce%22%3A%22([a-f0-9]+)%22/', $cart['body'], $sn );
	return array( 'user' => $u, 'jar' => $jar, 'added' => ! empty( $add['json']['success'] ), 'rest' => $wpn[1] ?? '', 'store' => $sn[1] ?? '' );
};
$store = function ( $s, $method, $route, array $body = array() ) use ( $http, $base ) {
	return $http( $base . '/wp-json' . $route, array( CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEFILE => $s['jar'], CURLOPT_COOKIEJAR => $s['jar'], CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $s['rest'], 'Nonce: ' . $s['store'] ), CURLOPT_POSTFIELDS => $body ? wp_json_encode( $body ) : null ) );
};
$address = array( 'first_name' => 'Sia', 'last_name' => 'Shipping', 'address_1' => '1 Ship Street', 'address_2' => '', 'city' => 'Delhi', 'state' => 'DL', 'postcode' => '110001', 'country' => 'IN', 'phone' => '9800000002' );
$checkout = function ( $s ) use ( $store, $address ) {
	$store( $s, 'GET', '/wc/store/v1/cart' ); // lets WooCommerce pick the shipping rate
	return $store( $s, 'POST', '/wc/store/v1/checkout', array(
		'billing_address' => $address + array( 'email' => 'bob.billing@example.test' ), 'shipping_address' => $address,
		'payment_method' => 'bacs', 'payment_data' => array(), 'customer_note' => '', 'create_account' => false, 'extensions' => new stdClass(),
	) );
};
$orders_of = function ( $uid ) { $ids = wc_get_orders( array( 'customer_id' => $uid, 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) ); foreach ( $ids as $i ) { $GLOBALS['nx_test']['orders'][] = $i; } return $ids; };

/* ---------- buyer 1 takes 2 of the 3 ---------- */
$b1 = $shopper( 2 );
nx_assert( $b1['added'] && '' !== $b1['rest'] && '' !== $b1['store'], 'setup: buyer 1 has 2 in the cart and the checkout nonces' );
$r1 = $checkout( $b1 );
nx_assert( 200 === $r1['status'] && ! empty( $r1['json']['order_id'] ), 'the block checkout places the bank-transfer order (status ' . $r1['status'] . ' ' . substr( preg_replace( '/\s+/', ' ', $r1['body'] ), 0, 160 ) . ')' );
wp_cache_flush();
$ids = $orders_of( $b1['user']['user_id'] );
$o1  = $ids ? wc_get_order( $ids[0] ) : null;
nx_assert( $o1 && Lines::is_marketplace( $o1 ), 'it is a marketplace order' );
$l = $o1 ? ( Lines::of( $o1 )[0] ?? array() ) : array();
nx_assert_same( array( 'Order Test OH-1', 'OH-1', '100.00', 2, '20.00', '180.00', 'Test Shop ohs' ), array( $l['name'] ?? '', $l['sku'] ?? '', $l['price'] ?? '', $l['qty'] ?? 0, $l['commission'] ?? '', $l['earning'] ?? '', $l['vendor_name'] ?? '' ), 'its line is frozen: name, SKU, 100.00 x 2, commission 20.00, earning 180.00, seller name' );
nx_assert_same( 'on-hold', $o1 ? $o1->get_status() : '', 'a bank-transfer order waits On hold' );
nx_assert_same( 1, $stock(), 'and the stock went from 3 to 1' );
nx_assert_same( '0.00', \Nexora\Orders\Earnings::summary( $seller['vendor_id'] )['earning'], 'nothing is earned while the order is On hold' );
$o1->update_status( 'processing' );
nx_assert_same( '180.00', \Nexora\Orders\Earnings::summary( $seller['vendor_id'] )['earning'], 'marked paid (Processing), the vendor earns 180.00' );

/* ---------- buyer 2 takes the last unit; buyer 3 (who also had it in the cart) is refused ---------- */
$b2 = $shopper( 1 );
$b3 = $shopper( 1 );
nx_assert( $b2['added'] && $b3['added'], 'setup: two more buyers each have the last unit in their cart' );
$r2 = $checkout( $b2 );
nx_assert( 200 === $r2['status'] && ! empty( $r2['json']['order_id'] ), 'buyer 2 gets the last unit' );
nx_assert_same( 0, $stock(), 'stock is 0' );
$r3 = $checkout( $b3 );
nx_assert( $r3['status'] >= 400, 'buyer 3 is refused (status ' . $r3['status'] . ')' );
$msg = (string) ( $r3['json']['message'] ?? $r3['body'] );
nx_assert( '' !== trim( wp_strip_all_tags( $msg ) ) && false === strpos( $msg, 'Fatal' ) && false === strpos( $msg, 'Stack trace' ), 'with a plain message instead of an error page: "' . substr( wp_strip_all_tags( $msg ), 0, 120 ) . '" (the sold-out line had already been taken out of the cart by the cart check, and the cart page lists what changed)' );
nx_assert_same( 0, $stock(), 'the stock is still 0, never negative' );
wp_cache_flush();
$left = array_filter( array_map( 'wc_get_order', $orders_of( $b3['user']['user_id'] ) ), function ( $o ) { return $o && ! in_array( $o->get_status(), array( 'cancelled', 'failed', 'checkout-draft' ), true ); } );
nx_assert_same( 0, count( $left ), 'buyer 3 has no order waiting' );
$orders_of( $b2['user']['user_id'] );

nx_test_finish();
