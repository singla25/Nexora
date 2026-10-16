<?php
// Marketplace Phase 6a: the read-only "Add to cart" button on the product card, and the script behind it.
require_once __DIR__ . '/../bootstrap.php';
nx_test_marketplace_item_scope(); // the cart goes through a scratch Marketplace Item; the real one (real orders point at it) is never touched

use Nexora\Core\View;
use Nexora\Products\Presenter;
use Nexora\Products\Repository;

global $wpdb;
nx_test_reset_limits();
$A = nx_test_vendor( 'cua', 'approved' );
$B = nx_test_vendor( 'cub', 'approved' );
$C = nx_test_user( 'cuc' );
$make = function ( $v, $sku, array $o = array() ) {
	$d = \Nexora\Products\Validator::validate( array_merge( array( 'name' => "UI $sku", 'description' => 'd', 'price' => '250', 'stock' => '5', 'sku' => $sku, 'category' => 'other' ), $o ) );
	$id = Repository::create( $v['user_id'], $v['vendor_id'], $d['data'], 'manual' );
	$GLOBALS['nx_test']['posts'][] = $id;
	return $id;
};
$p1 = $make( $A, 'U-1' );
$zero = $make( $A, 'U-0', array( 'stock' => '0' ) );
$html = function ( $id, $viewer ) { return View::render( 'marketplace/card', array( 'card' => Presenter::card( $id, $viewer ) ) ); };

/* ---------- the button ---------- */
$h = $html( $p1, $C['user_id'] );
nx_assert( 1 === preg_match( '/<button[^>]*class="[^"]*nx-add-to-cart[^"]*"[^>]*>\s*Add to cart\s*<\/button>/', $h ), 'a customer sees an "Add to cart" button on another seller\'s product' );
preg_match( '/<button[^>]*nx-add-to-cart[^>]*>/', $h, $m );
nx_assert( 1 === preg_match( '/data-product="' . $p1 . '"/', $m[0] ), 'the button carries only the product post id' );
nx_assert( 1 === preg_match_all( '/\sdata-[a-z-]+=/', $m[0] ) && false === strpos( $m[0], 'price' ) && false === strpos( $m[0], 'vendor' ) && false === strpos( $m[0], 'stock' ), 'and nothing else: no price, vendor or stock data to tamper with' );
nx_assert( 1 === preg_match( '/<button[^>]*type="button"/', $m[0] ), 'it is a plain button (it does not submit a form)' );
nx_assert( false !== strpos( html_entity_decode( $html( $p1, $B['user_id'] ) ), 'Add to cart' ), 'another vendor sees it too' );
$h = $html( $p1, $A['user_id'] );
nx_assert( false === strpos( $h, 'nx-add-to-cart' ) && false !== strpos( $h, 'Your product' ), 'the seller sees no button on their own product, only "Your product"' );
$h = $html( $zero, $C['user_id'] );
nx_assert( 1 === preg_match( '/<button[^>]*disabled[^>]*>\s*Out of stock\s*<\/button>/', $h ) && false === strpos( $h, 'nx-add-to-cart' ), 'a product with no stock shows a disabled "Out of stock" button and cannot be added' );
$h = $html( $p1, 0 );
nx_assert( false === strpos( $h, 'nx-add-to-cart' ) && 1 === preg_match( '/<a[^>]*class="[^"]*nx-sign-in-to-buy[^"]*"[^>]*href="' . preg_quote( \Nexora\Core\Urls::login(), '/' ) . '[^"]*"/', $h ), 'a guest (should one ever see a card) gets a "sign in" link instead of a button' );
$card = Presenter::card( $p1, $C['user_id'] );
nx_assert_same( 'ok', $card['buy'], 'the card says what the viewer may do: ok' );
nx_assert_same( 'own', Presenter::card( $p1, $A['user_id'] )['buy'], '... own' );
nx_assert_same( 'out', Presenter::card( $zero, $C['user_id'] )['buy'], '... out' );
nx_assert_same( 'guest', Presenter::card( $p1, 0 )['buy'], '... guest' );

/* ---------- the listing endpoint renders the same buttons ---------- */
wp_set_current_user( $C['user_id'] );
$r = nx_call_ajax( 'nexora_marketplace_list', array( 'search' => 'UI U-' ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 1 === substr_count( $r['data']['html'], 'nx-add-to-cart' ) && false !== strpos( $r['data']['html'], 'Out of stock' ), 'the listing shows the button on the product that can be bought and "Out of stock" on the other' );
wp_set_current_user( $A['user_id'] );
$r = nx_call_ajax( 'nexora_marketplace_list', array( 'search' => 'UI U-' ) + nx_profile_nonce() );
nx_assert( false === strpos( $r['data']['html'], 'nx-add-to-cart' ), 'the seller\'s own listing has no buttons for their own products' );
wp_set_current_user( 0 );

/* ---------- the My Products cards (the seller's own) have no buy button ---------- */
foreach ( Presenter::mine( $A['user_id'], $A['vendor_id'] ) as $c ) {
	nx_assert( 'own' === $c['buy'], 'My Products card ' . $c['id'] . ' is flagged own' );
}

/* ---------- the query count stays flat ---------- */
$many = array(); for ( $i = 1; $i <= 30; $i++ ) { $many[] = $make( $B, "M-$i" ); }
$queries = function ( array $ids ) use ( $wpdb, $C ) { wp_cache_flush(); $b = $wpdb->num_queries; Presenter::cards( $ids, $C['user_id'] ); return $wpdb->num_queries - $b; };
$few = $queries( array( $p1, $zero ) );
$lots = $queries( array_slice( $many, 0, 12 ) );
nx_assert( $lots <= $few + 4, "buy state does not add a query per card (2 cards: $few queries, 12 cards: $lots)" );

/* ---------- the script ---------- */
$js = file_get_contents( NEXORA_PATH . 'assets/js/marketplace.js' );
nx_assert( false !== strpos( $js, 'nexora_add_to_cart' ) && false !== strpos( $js, 'nx-add-to-cart' ), 'marketplace.js handles the button and calls nexora_add_to_cart' );
preg_match( '/nexora_add_to_cart.*?\}\)/s', $js, $post );
$payload = $post[0] ?? '';
nx_assert( false !== strpos( $payload, 'product_id' ) && false !== strpos( $payload, 'quantity' ) && false !== strpos( $payload, 'nonce' ), 'it posts the product id, a quantity and the nonce' );
nx_assert( false === stripos( $payload, 'price' ) && false === stripos( $payload, 'vendor' ) && false === stripos( $payload, 'name' ) && false === stripos( $payload, 'stock' ), 'and nothing else (no price, vendor, name or stock)' );
nx_assert( false !== strpos( $js, 'login_url' ), 'it follows the sign-in address a guest answer carries' );
nx_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'eval(' ), 'it writes no raw HTML and uses no eval' );
nx_assert( false !== strpos( $js, 'cart_url' ) || false !== strpos( $js, 'View cart' ), 'it offers a link to the cart' );

nx_test_finish();
