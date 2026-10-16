<?php
// Marketplace Phase 6a: the cart over real HTTP, as the browser uses it: a logged-in session, the Store API behind the Cart block,
// a forged add-item, a price edited while the product sits in the cart, and a guest. Skipped when the site cannot be reached.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Marketplace\Marketplace_Item;
use Nexora\Products\Repository;

if ( ! function_exists( 'curl_init' ) || ! function_exists( 'WC' ) ) { echo "curl or WooCommerce missing: skipped\n"; nx_test_finish(); return; }
$base = untrailingslashit( home_url() );

$http = function ( $url, array $opts = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, $opts + array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false ) );
	$out = curl_exec( $ch ); $info = curl_getinfo( $ch ); curl_close( $ch );
	if ( false === $out ) { return array( 'status' => 0, 'headers' => '', 'body' => '', 'json' => null ); }
	$body = substr( $out, $info['header_size'] );
	return array( 'status' => (int) $info['http_code'], 'headers' => substr( $out, 0, $info['header_size'] ), 'body' => $body, 'json' => json_decode( $body, true ), 'redirect' => (string) ( $info['redirect_url'] ?? '' ) );
};
if ( ! $http( $base . '/wp-login.php', array( CURLOPT_NOBODY => true ) )['status'] ) { echo "site not reachable from the CLI: skipped\n"; nx_test_finish(); return; }

// WooCommerce's "Coming soon" mode hides the cart and checkout from everyone but administrators; switch it off for this run only.
nx_test_options( array( 'woocommerce_coming_soon' => 'no', 'woocommerce_store_pages_only' => 'no' ) ); // the site's own values come back at the end, even on a crash
nx_test_marketplace_item_scope(); // the web requests below use a scratch Marketplace Item, not the real one

$seller = nx_test_vendor( 'chs', 'approved' );
$buyer  = nx_test_vendor( 'chb', 'approved' );
$pw = 'Tmp#Pass' . wp_rand( 1000, 9999 ) . 'x';
foreach ( array( $seller, $buyer ) as $u ) { wp_set_password( $pw, $u['user_id'] ); }
$d = \Nexora\Products\Validator::validate( array( 'name' => 'HTTP Cart Product', 'description' => 'd', 'price' => '123.45', 'stock' => '5', 'sku' => 'HC-1', 'category' => 'other' ) );
$pid = Repository::create( $seller['user_id'], $seller['vendor_id'], $d['data'], 'manual' );
$GLOBALS['nx_test']['posts'][] = $pid;
$HIDDEN = Marketplace_Item::id();

$jar = tempnam( sys_get_temp_dir(), 'nxjar' );
$login = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_profile_login', 'nonce' => wp_create_nonce( 'profile_nonce' ), 'user_name' => $buyer['login'], 'password' => $pw ) ), CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar ) );
nx_assert( ! empty( $login['json']['success'] ), 'setup: the buyer logs in over HTTP' );
$page = $http( home_url( '/profile-page/' . rawurlencode( $buyer['login'] ) ), array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true ) );
preg_match( '/var nexoraMarketplace = (\{.*?\});/s', $page['body'], $m );
$nonce = json_decode( $m[1] ?? '{}', true )['nonce'] ?? '';
nx_assert( '' !== $nonce, 'setup: the profile page carries the marketplace nonce' );

$ajax = function ( array $fields ) use ( $http, $base, $jar ) {
	return $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_POSTFIELDS => http_build_query( $fields ) ) );
};

/* ---------- add to cart with a tampered request ---------- */
$r = $ajax( array( 'action' => 'nexora_add_to_cart', 'nonce' => $nonce, 'product_id' => (string) $pid, 'quantity' => '2', 'price' => '0.01', 'name' => 'Free', 'vendor_id' => '1' ) );
nx_assert( ! empty( $r['json']['success'] ), 'the buyer adds the product (a tampered price, name and vendor ride along and are ignored): ' . substr( preg_replace( '/\s+/', ' ', $r['body'] ), 0, 140 ) );
nx_assert( ! empty( $r['json']['data']['cart_url'] ), 'the reply carries the cart address' );

/* ---------- the cart page and the Store API, as the Cart block sees them ---------- */
$cartpage = $http( $base . '/cart/', array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true ) );
nx_assert( 200 === $cartpage['status'], 'a logged-in member can open the cart page' );
nx_assert( false !== strpos( $cartpage['body'], 'nx-cart-panel' ) && false !== strpos( $cartpage['body'], 'Test Shop chs' ) && false !== strpos( $cartpage['body'], 'HTTP Cart Product' ), 'the page shows the grouped-by-seller panel with the seller and the product' );
preg_match( '/createNonceMiddleware\(\s*"([a-f0-9]+)"/', $cartpage['body'], $wpn );
preg_match( '/%22Nonce%22%3A%22([a-f0-9]+)%22/', $cartpage['body'], $sn );
$rest_nonce = $wpn[1] ?? ''; $store_nonce = $sn[1] ?? '';
$store = function ( $method, $route, array $body = array(), $with_cookie = true ) use ( $http, $base, $jar, $rest_nonce, $store_nonce ) {
	$h = array( 'Content-Type: application/json' );
	if ( $with_cookie && '' !== $rest_nonce ) { $h[] = 'X-WP-Nonce: ' . $rest_nonce; }
	if ( $with_cookie && '' !== $store_nonce ) { $h[] = 'Nonce: ' . $store_nonce; }
	$o = array( CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h );
	if ( $with_cookie ) { $o[ CURLOPT_COOKIEFILE ] = $jar; $o[ CURLOPT_COOKIEJAR ] = $jar; }
	if ( $body ) { $o[ CURLOPT_POSTFIELDS ] = wp_json_encode( $body ); }
	return $http( $base . '/wp-json' . $route, $o );
};
nx_assert( '' !== $rest_nonce, 'setup: the cart page carries the REST nonce the block uses' );
$c = $store( 'GET', '/wc/store/v1/cart' );
nx_assert( 200 === $c['status'] && 1 === count( $c['json']['items'] ?? array() ), 'the Store API cart has the one line (status ' . $c['status'] . ')' );
$it = $c['json']['items'][0] ?? array();
nx_assert( 'HTTP Cart Product' === ( $it['name'] ?? '' ) && 2 === (int) ( $it['quantity'] ?? 0 ), 'its name comes from the product post, quantity 2' );
nx_assert( '12345' === (string) ( $it['prices']['price'] ?? '' ), 'its price is the DB price 123.45 (12345 in minor units), not the tampered 0.01' );
$data = wp_json_encode( $it['item_data'] ?? array() );
nx_assert( false !== strpos( $data, 'Sold by' ) && false !== strpos( $data, 'Test Shop chs' ), 'the line shows "Sold by <business>"' );
nx_assert( '24690' === (string) ( $c['json']['totals']['total_items'] ?? '' ), 'the items total is 123.45 x 2 = 246.90' );

/* ---------- a forged add-item through the Store API ---------- */
if ( '' !== $store_nonce ) {
	$f = $store( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $HIDDEN, 'quantity' => 3 ) );
	nx_assert( $f['status'] >= 400, 'a forged Store API add-item of the hidden item is refused (status ' . $f['status'] . ')' );
	$f = $store( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $HIDDEN, 'quantity' => 3, 'variation' => array(), 'extensions' => array( 'nexora' => array( 'price' => 1 ) ) ) );
	nx_assert( $f['status'] >= 400, '... and with extension data too (status ' . $f['status'] . ')' );
	$c2 = $store( 'GET', '/wc/store/v1/cart' );
	nx_assert( 1 === count( $c2['json']['items'] ?? array() ) && 2 === (int) $c2['json']['items'][0]['quantity'], 'the cart is unchanged' );
} else {
	echo "  (no Store API nonce on the page: forged add-item check skipped)\n";
}

/* ---------- the price changes while the product sits in the cart ---------- */
Repository::update( $pid, array( 'price' => '150.00' ) );
$c3 = $store( 'GET', '/wc/store/v1/cart' );
nx_assert( '15000' === (string) ( $c3['json']['items'][0]['prices']['price'] ?? '' ), 'after the seller edits the price the next cart read shows 150.00' );
$again = $http( $base . '/cart/', array( CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true ) );
nx_assert( false !== strpos( $again['body'], 'nx-cart-changes' ) || false !== strpos( $c3['body'], '150.00' ), 'and the member is told what changed (panel or updated price)' );

/* ---------- suspended vendor: the line goes ---------- */
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $seller['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
$c4 = $store( 'GET', '/wc/store/v1/cart' );
nx_assert( 200 === $c4['status'] && 0 === count( $c4['json']['items'] ?? array( 1 ) ), 'when the vendor is suspended the line is gone from the cart' );
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $seller['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );

/* ---------- a guest ---------- */
$g = $store( 'GET', '/wc/store/v1/cart', array(), false );
nx_assert( 401 === $g['status'] && 'nexora_sign_in' === ( $g['json']['code'] ?? '' ), 'a guest reading the Store API cart gets 401' );
$g = $store( 'POST', '/wc/store/v1/cart/add-item', array( 'id' => $HIDDEN, 'quantity' => 1 ), false );
nx_assert( 401 === $g['status'], 'a guest posting add-item gets 401' );
foreach ( array( '/cart/', '/checkout/' ) as $path ) {
	$g = $http( $base . $path );
	nx_assert( 302 === $g['status'] && 0 === strpos( $g['redirect'], \Nexora\Core\Urls::login() ) && false !== strpos( $g['redirect'], 'redirect_to' ), "a guest opening $path is sent to the Nexora login with a return address" );
}
$g = $http( $base . '/?add-to-cart=' . $pid, array( CURLOPT_FOLLOWLOCATION => true ) );
$s = $http( $base . '/wp-json/wc/store/v1/cart' );
nx_assert( 401 === $s['status'], 'after a guest tries ?add-to-cart= the Store API still says 401 (nothing was added to any session)' );
$g = $http( $base . '/?wc-ajax=add_to_cart', array( CURLOPT_POSTFIELDS => http_build_query( array( 'product_id' => $HIDDEN, 'quantity' => 1 ) ) ) );
nx_assert( 401 === $g['status'] && 'nexora_sign_in' === ( $g['json']['code'] ?? '' ), 'a guest calling wc-ajax=add_to_cart gets 401' );

@unlink( $jar );
nx_test_finish();
