<?php
// Marketplace Phase 4: the Marketplace tab (everyone) and the My Products tab (approved vendors) on the profile.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Products\Repository;
use Nexora\Products\Service;

$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
$tabs = function ( $html ) {
	preg_match( '#<div class="profile-tabs">(.*?)<!-- MAIN CONTENT -->#s', $html, $m );
	preg_match_all( '/data-tab="([a-z-]+)"/', $m[1] ?? '', $t );
	return $t[1];
};
$visible  = function ( $html ) { return preg_replace( '#<template\b.*?</template>#s', '', $html ); };
$template = function ( $html, $id ) { return preg_match( '#<template id="' . preg_quote( $id, '#' ) . '">(.*?)</template>#s', $html, $m ) ? $m[1] : ''; };

$cust = nx_test_user( 'uicust' );
$A    = nx_test_vendor( 'uia', 'approved' );
$B    = nx_test_vendor( 'uib', 'approved' );
$P    = nx_test_vendor( 'uip', 'pending' );
$S    = nx_test_vendor( 'uis', 'suspended' );
$view = nx_test_user( 'uiview' );
$img  = nx_test_attachment( $A['user_id'], 'pic' );
$mk   = function ( $v, $sku, array $o = array() ) {
	$r = Service::save( $v['user_id'], array_merge( array( 'name' => "Item $sku", 'description' => "Desc $sku", 'price' => '250', 'stock' => '3', 'sku' => $sku, 'category' => 'books' ), $o ) );
	if ( ! is_array( $r ) ) { throw new RuntimeException( 'fixture failed' ); }
	$GLOBALS['nx_test']['posts'][] = $r['product_id'];
	return $r['product_id'];
};
$a1 = $mk( $A, 'UIA-1', array( 'image_id' => (string) $img, 'name' => 'Tom & "Jerry" Book' ) );
$a2 = $mk( $A, 'UIA-2' );
$b1 = $mk( $B, 'UIB-1', array( 'name' => 'Other Vendor Book' ) );

/* ---------- tab rows ---------- */
nx_assert_same( array( 'user-info', 'connections', 'content', 'notifications', 'marketplace' ), $tabs( $render( $cust['user_id'], $cust['login'] ) ), 'customer: the five tabs, with Marketplace' );
nx_assert_same( array( 'user-info', 'connections', 'content', 'notifications', 'marketplace' ), $tabs( $render( $P['user_id'], $P['login'] ) ), 'pending vendor: Marketplace, no My Products' );
nx_assert_same( array( 'user-info', 'connections', 'content', 'notifications', 'marketplace', 'my-products' ), $tabs( $render( $A['user_id'], $A['login'] ) ), 'approved vendor: Marketplace and My Products' );
nx_assert_same( array( 'user-info', 'connections', 'content', 'notifications', 'marketplace' ), $tabs( $render( $S['user_id'], $S['login'] ) ), 'suspended vendor: Marketplace, no My Products' );
nx_assert_same( array( 'user-info', 'connections' ), $tabs( $render( $view['user_id'], $A['login'] ) ), 'a visitor on a vendor\'s profile sees only the two public tabs' );
foreach ( array( 'my-orders', 'sales', 'vendor-status' ) as $later ) {
	nx_assert( false === strpos( $render( $A['user_id'], $A['login'] ), 'data-tab="' . $later . '"' ), "the unbuilt tab $later still does not render" );
}

/* ---------- Marketplace tab ---------- */
$html = $render( $cust['user_id'], $cust['login'] );
nx_assert( 1 === preg_match( '#<div class="tab-content" id="marketplace">#', $html ), 'customer: the Marketplace content area exists' );
nx_assert( 1 === preg_match( '/<input[^>]*type="search"[^>]*name="search"/', $html ) && 1 === preg_match( '/<select[^>]*name="category"/', $html ), 'the Marketplace tab has a search box and a category filter' );
nx_assert( false !== strpos( $html, '<option value="books">' ) && false !== strpos( $html, '<option value="">' ), 'the category filter lists the categories plus "all"' );
nx_assert( false === strpos( $html, 'Item UIA-1' ) && false === strpos( $html, 'nx-product-card' ), 'the products are fetched when the tab opens, not rendered into every profile view' );
nx_assert( false !== strpos( $html, 'nx-market-results' ) && false !== strpos( $html, 'aria-live' ), 'there is a results area that announces updates' );
nx_assert( false === strpos( $html, 'id="my-products"' ), 'a customer has no My Products content' );

/* ---------- My Products tab ---------- */
$html = $render( $A['user_id'], $A['login'] );
$vis  = $visible( $html );
nx_assert( 1 === preg_match( '#<div class="tab-content" id="my-products">#', $html ), 'approved vendor: the My Products content area exists' );
nx_assert( false !== strpos( $vis, 'Item UIA-2' ) && false !== strpos( $vis, 'UIA-1' ), 'it shows their own products as cards' );
nx_assert( false === strpos( $vis, 'Other Vendor Book' ) && false === strpos( $vis, 'UIB-1' ), "and never another vendor's" );
nx_assert( false !== strpos( $vis, 'Tom &amp; &quot;Jerry&quot; Book' ) || false !== strpos( $vis, 'Tom &amp; &#8220;Jerry&#8221; Book' ) || false !== strpos( $vis, 'Tom &amp; &#034;Jerry&#034; Book' ), 'product names are escaped' );
nx_assert( 0 === preg_match( '/<(input|select|textarea)[^>]*name="(name|price|stock|sku)"/', $vis ), 'the cards are read-only: no form field is on the page itself' );
nx_assert( 2 === preg_match_all( '/<button[^>]*class="[^"]*nx-product-edit[^"]*"/', $vis ), 'every card has an Edit button' );
nx_assert( 2 === preg_match_all( '/<button[^>]*class="[^"]*nx-product-delete[^"]*"/', $vis ), 'every card has a Delete button' );
nx_assert( 1 === preg_match( '/<button[^>]*class="[^"]*nx-product-add[^"]*"[^>]*>\s*Add product/', $vis ), 'there is an Add product button' );
nx_assert( 1 === preg_match( '/<img[^>]*src="[^"]+"[^>]*class="nx-product-img"|<img[^>]*class="nx-product-img"[^>]*src="[^"]+"/', $vis ), 'a product with an image shows it' );
$tpl = $template( $html, 'nx-product-tpl-form' );
foreach ( array( 'name', 'description', 'price', 'stock', 'sku', 'category', 'image_id' ) as $f ) {
	nx_assert( false !== strpos( $tpl, 'name="' . $f . '"' ), "the popup form has the $f field" );
}
nx_assert( false !== strpos( $tpl, 'data-action="nexora_product_save"' ) && false !== strpos( $tpl, 'name="product_id"' ), 'the popup form posts to product_save with a product_id (empty when adding)' );
nx_assert( false === strpos( $tpl, 'vendor_id' ) && false === strpos( $tpl, 'name="source"' ) && false === strpos( $tpl, 'post_status' ), 'the form carries no vendor_id, source or status for the browser to tamper with' );
nx_assert( false !== strpos( $tpl, 'nx-product-image-pick' ), 'the form has the image picker (the existing media uploader)' );
preg_match( '/<div class="nx-product-actions" data-product="([^"]*)"/', $vis, $m );
$json = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
nx_assert( is_array( $json ) && isset( $json['sku'], $json['price'], $json['id'] ) && ! isset( $json['vendor_id'] ) && ! isset( $json['user_id'] ), 'the card carries only the product fields for the edit popup' );
$ev = nx_test_vendor( 'uiempty', 'approved' );
nx_assert( false !== strpos( $render( $ev['user_id'], $ev['login'] ), 'You have not added any products yet.' ), 'a vendor with no products sees an empty state' );

/* ---------- no product data in the script data; other pages unaffected ---------- */
$data = \Nexora\Profile\Privacy::script_data( $A['profile_id'], 'owner' );
nx_assert( false === strpos( wp_json_encode( $data ), 'UIA-1' ), 'the profile script data carries no product data' );
foreach ( array( 'viewer' => $view['user_id'], 'guest' => 0 ) as $who => $uid ) {
	$h = $render( $uid, $A['login'] );
	nx_assert( false === strpos( $h, 'nx-product' ) && false === strpos( $h, 'id="marketplace"' ) && false === strpos( $h, 'id="my-products"' ) && false === strpos( $h, 'UIA-1' ), "$who: another member's profile has no marketplace, no products" );
}

/* ---------- scripts ---------- */
$load = function ( $uid, $slug ) {
	$GLOBALS['wp_scripts'] = null; $GLOBALS['wp_styles'] = null; $GLOBALS['post'] = null;
	wp_set_current_user( $uid );
	$q = $slug ? new WP_Query( array( 'pagename' => $slug ) ) : new WP_Query( array( 'p' => 1 ) );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
	if ( $q->have_posts() ) { $q->the_post(); $GLOBALS['post'] = $q->post; }
	set_query_var( 'username', '' );
	do_action( 'wp_enqueue_scripts' );
	$o = array( 'market' => wp_script_is( 'nexora-marketplace', 'enqueued' ), 'products' => wp_script_is( 'nexora-products', 'enqueued' ), 'css' => wp_style_is( 'nexora-marketplace', 'enqueued' ), 'data' => '' );
	foreach ( wp_scripts()->queue as $h ) { $o['data'] .= (string) wp_scripts()->get_data( $h, 'data' ); }
	wp_set_current_user( 0 );
	return $o;
};
$r = $load( $cust['user_id'], 'profile-page' );
nx_assert( $r['market'] && $r['css'] && ! $r['products'], 'customer profile: marketplace script and style, not the vendor product script' );
$r = $load( $A['user_id'], 'profile-page' );
nx_assert( $r['market'] && $r['products'], 'approved vendor profile: both scripts' );
preg_match( '/var nexoraMarketplace = (\{.*?\});/s', $r['data'], $m );
nx_assert_same( array( 'ajaxUrl', 'nonce' ), array_keys( json_decode( $m[1] ?? '{}', true ) ?: array() ), 'the marketplace data object holds only the ajax URL and a nonce' );
$r = $load( $S['user_id'], 'profile-page' );
nx_assert( $r['market'] && ! $r['products'], 'suspended vendor profile: no vendor product script' );
foreach ( array( 'home' => '', 'sample' => 'sample-page', 'login' => 'login-page' ) as $label => $slug ) {
	$r = $load( $A['user_id'], $slug );
	nx_assert( ! $r['market'] && ! $r['products'] && ! $r['css'], "$label page: no marketplace assets" );
}
$r = $load( 0, 'profile-page' );
nx_assert( ! $r['market'] && ! $r['products'], 'guest on the profile page: no marketplace assets' );

/* ---------- script hygiene ---------- */
foreach ( array( 'marketplace.js', 'products.js' ) as $f ) {
	nx_assert( file_exists( NEXORA_PATH . 'assets/js/' . $f ), "$f exists" );
	$js = file_get_contents( NEXORA_PATH . 'assets/js/' . $f );
	nx_assert( false === strpos( $js, 'eval(' ) && false === strpos( $js, 'document.write' ) && 1 !== preg_match( '/data\.(vendor_id|user_id|source|status)\b|[\'"](vendor_id|user_id|source|post_status)[\'"]\s*:/', $js ), "$f: no eval, no document.write, never sends a vendor_id, user_id, source or status" );
}
$pj = file_get_contents( NEXORA_PATH . 'assets/js/products.js' );
nx_assert( false !== strpos( $pj, 'Swal.fire' ) && false !== strpos( $pj, 'wp.media' ) && false !== strpos( $pj, 'location.reload' ), 'products.js uses SweetAlert2, the existing media uploader, and refreshes like the other sections' );
nx_assert( false !== strpos( file_get_contents( NEXORA_PATH . 'assets/js/marketplace.js' ), 'nexora_marketplace_list' ), 'marketplace.js calls the listing endpoint' );

nx_test_finish();
