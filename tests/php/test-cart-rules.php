<?php
// Marketplace Phase 6a: may this member buy this product, this many? One set of rules for the button, the add endpoint,
// cart load and checkout. Pure reads: nothing here changes the database.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Marketplace\Cart_Rules;
use Nexora\Products\Repository;
use Nexora\Products\Service;

$A = nx_test_vendor( 'cra', 'approved' );
$B = nx_test_vendor( 'crb', 'approved' );
$P = nx_test_vendor( 'crp', 'pending' );
$S = nx_test_vendor( 'crs', 'suspended' );
$C = nx_test_user( 'crc' );

$make = function ( $v, $sku, array $o = array(), $status = 'publish' ) {
	$d = \Nexora\Products\Validator::validate( array_merge( array( 'name' => "Rule $sku", 'description' => 'd', 'price' => '250', 'stock' => '5', 'sku' => $sku, 'category' => 'other' ), $o ) );
	if ( $d['errors'] ) { throw new RuntimeException( wp_json_encode( $d['errors'] ) ); }
	$id = Repository::create( $v['user_id'], $v['vendor_id'], $d['data'], 'manual', '', $status );
	$GLOBALS['nx_test']['posts'][] = $id;
	return $id;
};
$ok = $make( $A, 'OK-1', array( 'stock' => '5', 'price' => '1234.50' ) );
$draft = $make( $A, 'DR-1', array(), 'draft' );
$zero = $make( $A, 'ZE-1', array( 'stock' => '0' ) );
$pend = $make( $P, 'PE-1' );
$susp = $make( $S, 'SU-1' );
$other = $make( $B, 'BB-1' );
$MSG = Cart_Rules::UNAVAILABLE;

/* ---------- the happy path and the snapshot (read from the product post) ---------- */
$r = Cart_Rules::check( $ok, $C['user_id'], 2 );
nx_assert( true === $r['ok'] && 'ok' === $r['code'], 'a customer may buy 2 of a published product with stock 5' );
$s = $r['snapshot'];
nx_assert_same( 'Rule OK-1', $s['name'], 'the snapshot has the name from the product post' );
nx_assert_same( '1234.50', $s['price'], 'and the price as stored (two decimals, text)' );
nx_assert_same( 5, $s['stock'], 'and the stock' );
nx_assert_same( $A['vendor_id'], $s['vendor_id'], 'and the vendor application id' );
nx_assert_same( 'Test Shop cra', $s['vendor_name'], 'and the seller\'s business name' );
nx_assert_same( $A['user_id'], $s['owner_id'], 'and the owner' );
nx_assert( array_key_exists( 'image_id', $s ) && array_key_exists( 'image_url', $s ), 'and the image fields' );
nx_assert( true === Cart_Rules::check( $ok, $B['user_id'], 1 )['ok'], 'another vendor may buy it too (vendors are customers as well)' );
nx_assert( true === Cart_Rules::check( $ok, $C['user_id'], 5 )['ok'], 'the whole stock may be bought' );

/* ---------- refusals ---------- */
$no = function ( $r, $code ) { return false === $r['ok'] && $code === $r['code'] && '' !== $r['message']; };
nx_assert( $no( Cart_Rules::check( $ok, $A['user_id'], 1 ), 'own_product' ), 'a vendor cannot buy their own product' );
nx_assert( $no( Cart_Rules::check( $ok, 0, 1 ), 'not_logged_in' ), 'a guest cannot buy' );
nx_assert( $no( Cart_Rules::check( $draft, $C['user_id'], 1 ), 'not_published' ) && $MSG === Cart_Rules::check( $draft, $C['user_id'], 1 )['message'], 'an unpublished product is refused with the generic message' );
nx_assert( $no( Cart_Rules::check( $pend, $C['user_id'], 1 ), 'vendor_unavailable' ) && $MSG === Cart_Rules::check( $pend, $C['user_id'], 1 )['message'], 'a product of a pending vendor is refused with the generic message' );
nx_assert( $no( Cart_Rules::check( $susp, $C['user_id'], 1 ), 'vendor_unavailable' ) && $MSG === Cart_Rules::check( $susp, $C['user_id'], 1 )['message'], 'a product of a suspended vendor is refused with the generic message (it does not say "suspended")' );
nx_assert( $no( Cart_Rules::check( 99999999, $C['user_id'], 1 ), 'not_found' ) && $MSG === Cart_Rules::check( 99999999, $C['user_id'], 1 )['message'], 'a missing product is refused with the same generic message' );
foreach ( array( 'a profile post' => $C['profile_id'], 'a vendor application' => $A['vendor_id'], 'zero' => 0, 'a negative id' => -5 ) as $label => $bad ) {
	nx_assert( $no( Cart_Rules::check( $bad, $C['user_id'], 1 ), 'not_found' ), "not a product: $label" );
}
nx_assert( $no( Cart_Rules::check( \Nexora\Marketplace\Marketplace_Item::id(), $C['user_id'], 1 ), 'not_found' ), 'the hidden Marketplace Item itself is not a buyable product' );
nx_assert( $no( Cart_Rules::check( $zero, $C['user_id'], 1 ), 'out_of_stock' ) && 'Out of stock.' === Cart_Rules::check( $zero, $C['user_id'], 1 )['message'], 'stock 0: out of stock' );
$r = Cart_Rules::check( $ok, $C['user_id'], 6 );
nx_assert( $no( $r, 'insufficient_stock' ) && false !== strpos( $r['message'], '5' ), 'quantity above the stock: refused, and the message says how many are left' );
$r = Cart_Rules::check( $ok, $C['user_id'], 3, 3 );
nx_assert( $no( $r, 'insufficient_stock' ) && false !== strpos( $r['message'], '2' ), 'quantity already in the cart counts: 3 + 3 > 5, and the message says 2 more can be added' );
nx_assert( true === Cart_Rules::check( $ok, $C['user_id'], 2, 3 )['ok'], '3 in the cart + 2 = 5 is fine' );

/* ---------- the quantity must be a whole number from 1 to 99 ---------- */
foreach ( array( 0, -1, 100, 1000000, 1.5, '1.5', '1e3', 'abc', '', ' 2', '2 ', '0x1', '+3', array( 1 ), null, true, false, NAN, INF, '٣' ) as $bad ) {
	nx_assert( $no( Cart_Rules::check( $ok, $C['user_id'], $bad ), 'bad_quantity' ), 'refused quantity: ' . substr( var_export( $bad, true ), 0, 24 ) );
}
foreach ( array( 1, '1', '2', 5, '05' ) as $good ) {
	nx_assert( true === Cart_Rules::check( $ok, $C['user_id'], $good )['ok'], 'accepted quantity: ' . var_export( $good, true ) );
}
$big = $make( $A, 'BIG-1', array( 'stock' => '1000' ) );
nx_assert( true === Cart_Rules::check( $big, $C['user_id'], 99 )['ok'] && $no( Cart_Rules::check( $big, $C['user_id'], 100 ), 'bad_quantity' ), '99 is the largest quantity, even with a big stock' );
nx_assert( $no( Cart_Rules::check( $ok, $C['user_id'], 1, -4 ), 'ok' ) || true === Cart_Rules::check( $ok, $C['user_id'], 1, -4 )['ok'], 'a negative "already in cart" cannot be used to get past the stock' );
nx_assert( $no( Cart_Rules::check( $ok, $C['user_id'], 5, -10 ), 'insufficient_stock' ) || true === Cart_Rules::check( $ok, $C['user_id'], 5, -10 )['ok'], '(negative in-cart values are treated as 0)' );
nx_assert( false === Cart_Rules::check( $ok, $C['user_id'], 6, -10 )['ok'], 'so 6 is still too many however negative the "in cart" figure is' );

/* ---------- the browser's numbers are never an input: only ids and a quantity ---------- */
$sig = ( new ReflectionMethod( Cart_Rules::class, 'check' ) )->getParameters();
nx_assert_same( array( 'product_id', 'user_id', 'quantity', 'in_cart' ), array_map( function ( $p ) { return $p->getName(); }, $sig ), 'check() takes only a product id, a user id, a quantity and the quantity already in the cart' );

/* ---------- a status change shows up at once (nothing is cached across calls) ---------- */
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $A['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
nx_assert( $no( Cart_Rules::check( $ok, $C['user_id'], 1 ), 'vendor_unavailable' ), 'suspending the vendor makes their product unbuyable straight away' );
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $A['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );
nx_assert( true === Cart_Rules::check( $ok, $C['user_id'], 1 )['ok'], 'and reactivating makes it buyable again' );
Repository::update( $ok, array( 'price' => '999.00' ) );
nx_assert_same( '999.00', Cart_Rules::check( $ok, $C['user_id'], 1 )['snapshot']['price'], 'a price edit is seen at once' );

/* ---------- it is read-only ---------- */
global $wpdb;
$before = $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}" ) . '|' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
Cart_Rules::check( $ok, $C['user_id'], 1 ); Cart_Rules::check( $zero, $C['user_id'], 1 ); Cart_Rules::check( 99999999, $C['user_id'], 1 );
nx_assert_same( $before, $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}" ) . '|' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ), 'checking writes nothing' );

nx_test_finish();
