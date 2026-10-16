<?php
// Marketplace Phase 4: the listing. Only published products of approved vendors, with search, category
// filter and pagination, through a member-only endpoint that returns server-escaped cards.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Products\Listing;
use Nexora\Products\Presenter;
use Nexora\Products\Repository;
use Nexora\Products\Validator;
use Nexora\Vendors\Service as Vendor_Service;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();

$A = nx_test_vendor( 'mla', 'approved' );
$B = nx_test_vendor( 'mlb', 'approved' );
$P = nx_test_vendor( 'mlp', 'pending' );
$S = nx_test_vendor( 'mls', 'suspended' );
$C = nx_test_user( 'mlc' );
$admin = nx_test_admin();

$seq = 0;
$make = function ( $v, $name, array $over = array(), $status = 'publish' ) use ( &$seq ) {
	$seq++;
	$data = Validator::validate( array_merge( array( 'name' => $name, 'description' => "Description of $name", 'price' => '100', 'stock' => '5', 'sku' => 'ML-' . $seq . '-' . wp_rand( 100, 999 ), 'category' => 'other' ), $over ) );
	if ( $data['errors'] ) { throw new RuntimeException( 'fixture: ' . wp_json_encode( $data['errors'] ) ); }
	$id = Repository::create( $v['user_id'], $v['vendor_id'], $data['data'], 'manual', '', $status );
	$GLOBALS['nx_test']['posts'][] = $id;
	return $id;
};
$ids = function ( $q ) { return $q['ids']; };

$a1 = $make( $A, 'Alpha Kurta', array( 'category' => 'fashion' ) );
$a2 = $make( $A, 'Alpha Mug', array( 'category' => 'home-kitchen', 'description' => 'Ceramic coffee cup' ) );
$a3 = $make( $A, 'Alpha Draft', array(), 'draft' );
$b1 = $make( $B, 'Beta Novel', array( 'category' => 'books' ) );
$p1 = $make( $P, 'Pending Shop Thing' );
$s1 = $make( $S, 'Suspended Shop Thing' );

/* ---------- who is listed ---------- */
$q = Listing::query( array() );
$got = $ids( $q );
nx_assert( in_array( $a1, $got, true ) && in_array( $a2, $got, true ) && in_array( $b1, $got, true ), 'published products of approved vendors are listed' );
nx_assert( ! in_array( $a3, $got, true ), 'a draft product is not listed' );
nx_assert( ! in_array( $p1, $got, true ), "a pending applicant's product is not listed" );
nx_assert( ! in_array( $s1, $got, true ), "a suspended vendor's product is not listed" );
nx_assert_same( array(), array_intersect( $got, array( $a3, $p1, $s1 ) ), 'none of the three hidden products appears' );
nx_assert( in_array( $A['vendor_id'], Listing::approved_vendor_ids(), true ) && ! in_array( $S['vendor_id'], Listing::approved_vendor_ids(), true ) && ! in_array( $P['vendor_id'], Listing::approved_vendor_ids(), true ), 'the approved-vendor set is exactly the approved vendors' );

// A status change shows up at once.
wp_set_current_user( $admin );
Vendor_Service::change_status( $S['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );
nx_assert( in_array( $s1, $ids( Listing::query( array() ) ), true ), 'reactivating a vendor lists their products again' );
wp_set_current_user( $admin );
Vendor_Service::change_status( $S['vendor_id'], 'suspend' );
Vendor_Service::change_status( $B['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
$got = $ids( Listing::query( array() ) );
nx_assert( ! in_array( $s1, $got, true ) && ! in_array( $b1, $got, true ), 'suspending a vendor hides their products at once' );
wp_set_current_user( $admin );
Vendor_Service::change_status( $B['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );
nx_assert( in_array( $b1, $ids( Listing::query( array() ) ), true ), 'and reactivating shows them again' );

/* ---------- search ---------- */
nx_assert_same( array( $a2, $a1 ), array_values( array_intersect( $ids( Listing::query( array( 'search' => 'Alpha' ) ) ), array( $a1, $a2, $a3 ) ) ), 'search by name finds the products, newest first' );
nx_assert_same( array( $a2 ), $ids( Listing::query( array( 'search' => 'ceramic coffee' ) ) ), 'search also reads the description' );
nx_assert_same( array(), $ids( Listing::query( array( 'search' => 'zzzznomatch' ) ) ), 'a search with no match is empty' );
nx_assert_same( array(), $ids( Listing::query( array( 'search' => 'Pending Shop' ) ) ), 'a search cannot reveal a hidden vendor\'s product' );
foreach ( array( "'; DROP TABLE x; --", '%', '_', '\\', '<script>', str_repeat( 'a', 5000 ), array( 'x' ) ) as $odd ) {
	$res = Listing::query( array( 'search' => $odd ) );
	nx_assert( is_array( $res['ids'] ) && $res['page'] >= 1, 'an odd search does not break the listing: ' . substr( wp_json_encode( $odd ), 0, 30 ) );
}

/* ---------- category ---------- */
$res = Listing::query( array( 'category' => 'books' ) );
nx_assert( in_array( $b1, $res['ids'], true ) && ! in_array( $a1, $res['ids'], true ), 'the category filter narrows the list' );
nx_assert_same( $ids( Listing::query( array( 'category' => 'cars' ) ) ), $ids( Listing::query( array() ) ), 'an unknown category is ignored (same as no filter)' );
nx_assert_same( array( $a1 ), $ids( Listing::query( array( 'category' => 'fashion', 'search' => 'Alpha' ) ) ), 'search and category combine' );

/* ---------- pagination ---------- */
$bulk = nx_test_vendor( 'mlbulk', 'approved' );
for ( $i = 1; $i <= 30; $i++ ) { $make( $bulk, "Bulkitem $i", array( 'category' => 'toys' ) ); }
$p1q = Listing::query( array( 'category' => 'toys' ) );
nx_assert( 30 === $p1q['total'] && 3 === $p1q['pages'] && 1 === $p1q['page'] && 12 === count( $p1q['ids'] ), 'pagination: 30 items = 3 pages of 12, page 1 has 12' );
$p3q = Listing::query( array( 'category' => 'toys', 'page' => 3 ) );
nx_assert( 6 === count( $p3q['ids'] ) && 3 === $p3q['page'], 'pagination: the last page has the remaining 6' );
nx_assert_same( array(), array_intersect( $p1q['ids'], $p3q['ids'] ), 'pagination: pages do not overlap' );
nx_assert_same( 3, Listing::query( array( 'category' => 'toys', 'page' => 99 ) )['page'], 'pagination: a page past the end is clamped to the last' );
nx_assert_same( 1, Listing::query( array( 'category' => 'toys', 'page' => -4 ) )['page'], 'pagination: a negative page is page 1' );
nx_assert_same( 1, Listing::query( array( 'category' => 'toys', 'page' => 'abc' ) )['page'], 'pagination: a non-numeric page is page 1' );
nx_assert_same( 1, Listing::query( array( 'category' => 'toys', 'page' => array( 2 ) ) )['page'], 'pagination: an array page is page 1' );
nx_assert( 48 >= count( Listing::query( array( 'category' => 'toys', 'per_page' => 5000 ) )['ids'] ), 'pagination: per_page is capped' );
nx_assert_same( 5, count( Listing::query( array( 'category' => 'toys', 'per_page' => 5 ) )['ids'] ), 'pagination: per_page is honoured within the cap' );

/* ---------- query count does not grow with the number of products ---------- */
$small = nx_test_vendor( 'mlsm', 'approved' );
$ids_small = array();
foreach ( array( 1, 2, 3 ) as $n ) { $ids_small[] = $make( $small, "Smallitem $n", array( 'category' => 'sports' ) ); }
$queries = function ( array $args ) use ( $wpdb, $admin ) {
	wp_cache_flush();
	$before = $wpdb->num_queries;
	$res    = Listing::query( $args );
	Presenter::cards( $res['ids'], $admin );
	return $wpdb->num_queries - $before;
};
$q_few  = $queries( array( 'category' => 'sports' ) );
$q_many = $queries( array( 'category' => 'toys', 'per_page' => 12 ) );
nx_assert( $q_many <= $q_few + 4, "the query count does not grow with the number of products (3 products: $q_few, 12 products from another vendor: $q_many)" );

/* ---------- presenter: what a card shows ---------- */
$tricky = $make( $A, 'Tom & "Jerry" <Tee>', array( 'description' => "It's a <b>test</b> & more", 'price' => '1234.5', 'category' => 'fashion' ) );
$card = Presenter::card( $tricky, $C['user_id'] );
nx_assert( 'Tom & "Jerry"' === $card['name'] || false !== strpos( $card['name'], 'Jerry' ), 'the card carries the product name' );
nx_assert( false !== strpos( $card['price'], '1,234.50' ), 'the price is formatted with two decimals and a thousands separator' );
nx_assert_same( 'Test Shop mla', $card['seller'], 'the seller shown is the business name' );
nx_assert_same( false, $card['own'], 'a customer\'s card is not flagged as own' );
nx_assert_same( true, Presenter::card( $tricky, $A['user_id'] )['own'], 'the vendor\'s own product is flagged' );
nx_assert_same( false, Presenter::card( $tricky, $B['user_id'] )['own'], 'another vendor\'s product is not flagged as own' );
nx_assert_same( 'Fashion', $card['category'], 'the category shows its label' );
$out = wp_json_encode( $card );
foreach ( array( $A['gstin'], '9811100000', 'mla@example.test', '1 Test Road', '110001', 'business_email', 'gstin' ) as $private ) {
	nx_assert( false === strpos( $out, $private ), "a card never carries seller private data ($private)" );
}

/* ---------- the endpoint ---------- */
$call = function ( $uid, array $post = array(), $nonce = true ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( 'nexora_marketplace_list', $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
nx_assert( nx_rejected( $call( 0, array() ) ), 'ajax: a guest is rejected' );
nx_assert( nx_rejected( $call( $C['user_id'], array(), false ) ), 'ajax: no nonce is rejected' );
$r = $call( $C['user_id'], array( 'search' => 'Alpha' ) );
nx_assert( ! empty( $r['success'] ) && is_string( $r['data']['html'] ?? null ), 'ajax: a customer gets the cards as HTML' );
nx_assert( false !== strpos( $r['data']['html'], 'Alpha Kurta' ) && false !== strpos( $r['data']['html'], 'Alpha Mug' ) && false === strpos( $r['data']['html'], 'Alpha Draft' ), 'ajax: the cards are the published products found' );
nx_assert( false === strpos( $r['data']['html'], 'Pending Shop' ) && false === strpos( $r['data']['html'], 'Suspended Shop' ), 'ajax: hidden vendors\' products never appear' );
$r = $call( $A['user_id'], array() );
nx_assert( ! empty( $r['success'] ), 'ajax: a vendor can browse too (vendors are customers as well)' );
foreach ( array( 'pending' => $P['user_id'], 'suspended' => $S['user_id'] ) as $label => $uid ) {
	nx_assert( ! empty( $call( $uid, array() )['success'] ), "ajax: a $label vendor can browse as a customer" );
}
$r = $call( $C['user_id'], array( 'search' => 'Tom' ) );
nx_assert( false !== strpos( $r['data']['html'], 'Tom &amp; &quot;Jerry&quot;' ) || false !== strpos( $r['data']['html'], 'Tom &amp; &#8220;Jerry&#8221;' ) || false !== strpos( $r['data']['html'], 'Tom &amp; &#034;Jerry&#034;' ), 'ajax: names are escaped in the HTML' );
nx_assert( false === strpos( $r['data']['html'], '<Tee>' ) && false === strpos( $r['data']['html'], '<b>' ), 'ajax: no raw markup from product data' );
$r = $call( $C['user_id'], array( 'category' => 'toys', 'page' => 2 ) );
nx_assert( ! empty( $r['success'] ) && 2 === (int) $r['data']['page'] && 3 === (int) $r['data']['pages'] && 30 === (int) $r['data']['total'], 'ajax: page, pages and total are returned' );
nx_assert( 12 === substr_count( $r['data']['html'], 'nx-product-card"' ) || 12 === substr_count( $r['data']['html'], 'class="nx-product-card' ), 'ajax: page 2 has 12 cards' );
$r = $call( $C['user_id'], array( 'search' => 'zzzznomatch' ) );
nx_assert( ! empty( $r['success'] ) && false !== strpos( $r['data']['html'], 'nx-market-empty' ), 'ajax: an empty result shows the empty state' );
$r = $call( $C['user_id'], array( 'search' => array( 'x' ), 'category' => array( 'y' ), 'page' => array( 'z' ) ) );
nx_assert( ! empty( $r['success'] ), 'ajax: array parameters are handled cleanly' );
$r = $call( $A['user_id'], array( 'search' => 'Alpha Kurta' ) );
nx_assert( false !== strpos( $r['data']['html'], 'nx-product-own' ), 'ajax: a vendor\'s own product is marked as theirs' );
$r = $call( $C['user_id'], array( 'search' => 'Alpha Kurta' ) );
nx_assert( false === strpos( $r['data']['html'], 'nx-product-own' ), 'ajax: and not for anyone else' );
$leak = wp_json_encode( $call( $C['user_id'], array( 'search' => 'Alpha' ) ) );
foreach ( array( $A['gstin'], 'mla@example.test', '9811100000', '1 Test Road' ) as $private ) { nx_assert( false === strpos( $leak, $private ), "ajax: the reply carries no seller private data ($private)" ); }

add_filter( 'nexora_rate_limits', function ( $l ) { $l['marketplace_list'] = array( 3, MINUTE_IN_SECONDS ); return $l; } );
nx_test_reset_limits();
wp_set_current_user( $C['user_id'] );
$last = array();
for ( $i = 0; $i < 4; $i++ ) { $last = nx_call_ajax( 'nexora_marketplace_list', nx_profile_nonce() ); }
wp_set_current_user( 0 );
nx_assert( nx_rejected( $last ), 'ajax: the 4th listing request in a minute is rate limited' );
remove_all_filters( 'nexora_rate_limits' );

/* ---------- category setting ---------- */
update_option( 'nexora_product_categories', "Gadgets\nGarden Tools" ); // (scoped by nx_test_default_settings(): the site's value comes back at the end)
nx_assert_same( array( 'gadgets', 'garden-tools', 'other' ), array_keys( \Nexora\Products\Fields::categories() ), 'the category list comes from the setting, one per line, and keeps Other' );

nx_test_finish();
