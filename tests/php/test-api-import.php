<?php
// Marketplace Phase 5: the API import (DummyJSON). The HTTP layer is mocked; nothing here touches the network.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Api_Source;
use Nexora\Imports\Importer;
use Nexora\Products\Repository;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();

$A = nx_test_vendor( 'apa', 'approved' );
$B = nx_test_vendor( 'apb', 'approved' );
$P = nx_test_vendor( 'app', 'pending' );
$S = nx_test_vendor( 'aps', 'suspended' );
$C = nx_test_user( 'apc' );
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$track = function ( $v ) { foreach ( Repository::ids_for_vendor( $v['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } };

$item = function ( $id, array $over = array() ) {
	return array_merge( array(
		'id' => $id, 'title' => "Item $id", 'description' => "About item $id", 'category' => 'beauty', 'price' => 9.99, 'stock' => 20,
		'sku' => 'DJ-SKU-' . $id, 'thumbnail' => "https://cdn.dummyjson.com/products/images/$id/thumbnail.png", 'rating' => 4.5, 'brand' => 'X',
	), $over );
};
$page = function ( array $items, $total, $skip = 0 ) { return wp_json_encode( array( 'products' => $items, 'total' => $total, 'skip' => $skip, 'limit' => 100 ) ); };

/** Mock the HTTP layer: $routes maps a skip value to array( code, body ) or WP_Error. Records every request. */
$mock = function ( array $routes ) {
	$GLOBALS['nx_http'] = array();
	add_filter( 'pre_http_request', $f = function ( $pre, $args, $url ) use ( $routes ) {
		$GLOBALS['nx_http'][] = array( 'url' => $url, 'args' => $args );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$skip = isset( $q['skip'] ) ? (int) $q['skip'] : 0;
		$r    = $routes[ $skip ] ?? array( 404, '{}' );
		if ( is_wp_error( $r ) ) { return $r; }
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => $r[1], 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	return $f;
};
$unmock = function ( $f ) { remove_filter( 'pre_http_request', $f, 10 ); };
$flush  = function () use ( $wpdb ) { $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nexora\\_api\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nexora\\_api\\_%'" ); wp_cache_flush(); };

/* ---------- the request ---------- */
$f = $mock( array( 0 => array( 200, $page( array( $item( 1 ) ), 1 ) ) ) );
$flush();
$r = Api_Source::fetch();
$req = $GLOBALS['nx_http'][0] ?? array();
nx_assert( 1 === count( $GLOBALS['nx_http'] ), 'one page is one request' );
nx_assert( 0 === strpos( $req['url'] ?? '', 'https://dummyjson.com/products?' ) && false !== strpos( $req['url'], 'limit=100' ), 'the request goes to the fixed DummyJSON products URL with limit=100' );
nx_assert_same( 10, (int) ( $req['args']['timeout'] ?? 0 ), 'the request has a 10 second timeout' );
nx_assert( ! empty( $req['args']['reject_unsafe_urls'] ) && (int) ( $req['args']['redirection'] ?? 9 ) <= 2, 'unsafe URLs are rejected and redirects are limited' );
nx_assert( ! empty( $req['args']['limit_response_size'] ), 'the response size is capped' );
nx_assert( empty( $req['args']['headers']['Authorization'] ) && false === strpos( wp_json_encode( $req ), 'api_key' ), 'no credential is sent (the source needs none)' );
$unmock( $f );

/* ---------- the mapping ---------- */
$long  = str_repeat( 'x', 300 );
$many  = str_repeat( 'd', 2500 );
$items = array(
	$item( 1 ),
	$item( 2, array( 'title' => $long, 'description' => $many ) ),
	$item( 3, array( 'sku' => '' ) ),
	$item( 4, array( 'category' => 'womens-dresses' ) ),
	$item( 5, array( 'category' => 'something-new' ) ),
	$item( 6, array( 'thumbnail' => 'http://cdn.dummyjson.com/x.png' ) ),
	$item( 7, array( 'thumbnail' => 'https://evil.example/x.png' ) ),
	$item( 8, array( 'price' => 0 ) ),
	$item( 9, array( 'stock' => -4 ) ),
	$item( 10, array( 'title' => '' ) ),
	'not an item',
	array( 'title' => 'no id' ),
	$item( 12, array( 'price' => 'free' ) ),
	$item( 13, array( 'title' => '<script>alert(1)</script>Safe <b>name</b>' ) ),
	$item( 14, array( 'sku' => 'bad sku!' ) ),
);
$f = $mock( array( 0 => array( 200, $page( $items, 15 ) ) ) );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
$by = array(); foreach ( $r['items'] as $it ) { $by[ $it['external_id'] ] = $it; }
nx_assert_same( '', $r['fetch_error'], 'a good response has no fetch error' );
nx_assert_same( '1', $by['1']['external_id'], 'the id becomes the external_id, as a string' );
nx_assert_same( array( 'name' => 'Item 1', 'description' => 'About item 1', 'price' => '9.99', 'stock' => '20', 'sku' => 'DJ-SKU-1', 'category' => 'beauty' ), array_intersect_key( $by['1']['raw'], array_flip( array( 'name', 'description', 'price', 'stock', 'sku', 'category' ) ) ), 'title, description, price, stock, sku and category map across (price as-is, no conversion)' );
nx_assert( 120 === mb_strlen( $by['2']['raw']['name'] ) && 2000 === mb_strlen( $by['2']['raw']['description'] ), 'a long title is cut to 120 and a long description to 2,000' );
nx_assert_same( 'DJ-3', $by['3']['raw']['sku'], 'a missing sku becomes DJ-{id}' );
nx_assert_same( 'fashion', $by['4']['raw']['category'], 'a DummyJSON category maps to the fixed list' );
nx_assert_same( 'other', $by['5']['raw']['category'], 'an unknown category becomes Other' );
nx_assert_same( 'https://cdn.dummyjson.com/products/images/1/thumbnail.png', $by['1']['image_url'], 'a cdn.dummyjson.com https thumbnail is kept as an external URL' );
nx_assert( '' === $by['6']['image_url'] && '' === $by['7']['image_url'], 'a thumbnail that is http or from another host is dropped (the product still imports)' );
foreach ( array( '8' => 'price 0', '9' => 'negative stock', '10' => 'empty title', '12' => 'price not a number', '14' => 'bad sku' ) as $id => $why ) {
	$plan = Importer::plan_api( $A['user_id'], array( 'items' => array( $by[ $id ] ?? array() ), 'errors' => array(), 'fetch_error' => '' ) );
	nx_assert( is_array( $plan ) && 'error' === ( $plan['rows'][0]['action'] ?? '' ), "an item with $why is an error row in the plan" );
}
nx_assert_same( 2, count( $r['errors'] ), 'items that are not objects, or have no id, are reported (2 of them)' );
nx_assert( isset( $r['errors'][0]['ref'], $r['errors'][0]['message'] ), 'each such error has a reference and a message' );
$plan13 = Importer::plan_api( $A['user_id'], array( 'items' => array( $by['13'] ), 'errors' => array(), 'fetch_error' => '' ) );
Importer::apply( $A['user_id'], $plan13 );
$track( $A );
$p13 = Repository::find_by_sku( $A['vendor_id'], 'DJ-SKU-13' );
nx_assert( $p13 > 0 && false === strpos( Repository::data( $p13 )['name'], '<' ) && false === stripos( Repository::data( $p13 )['name'], 'alert' ), 'a title with markup is stored clean' );

/* ---------- plan, apply, idempotence ---------- */
$good = array( $item( 101 ), $item( 102 ), $item( 103 ) );
$f = $mock( array( 0 => array( 200, $page( $good, 3 ) ) ) );
$flush();
$fetched = Api_Source::fetch();
$before  = $count();
$plan = Importer::plan_api( $A['user_id'], $fetched );
nx_assert( is_array( $plan ) && 'api' === $plan['source'] && 3 === $plan['summary']['create'], 'a dry run plans three creates' );
nx_assert_same( $before, $count(), 'a dry run writes nothing' );
$res = Importer::apply( $A['user_id'], $plan );
$track( $A );
nx_assert_same( 3, $res['summary']['created'], 'applying creates the three products' );
$pid = Repository::find_by_sku( $A['vendor_id'], 'DJ-SKU-101' );
$d   = Repository::data( $pid );
nx_assert( 'api' === $d['source'] && '101' === $d['external_id'] && $A['vendor_id'] === $d['vendor_id'] && 'Item 101' === $d['name'], 'the product has source api, the external id and the importing vendor' );
nx_assert_same( 'https://cdn.dummyjson.com/products/images/101/thumbnail.png', (string) get_post_meta( $pid, 'image_url', true ), 'the external image URL is stored (not sideloaded into the Media Library)' );
nx_assert_same( 0, $d['image_id'], 'no attachment was created for it' );
$modified = $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $pid ) );
sleep( 1 );
$again = Importer::plan_api( $A['user_id'], $fetched );
nx_assert_same( array( 'create' => 0, 'update' => 0, 'unchanged' => 3, 'error' => 0, 'total' => 3 ), $again['summary'], 're-importing plans nothing to change' );
Importer::apply( $A['user_id'], $again );
nx_assert( $before + 3 === $count() && $modified === $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $pid ) ), 'applying it again creates no duplicate and touches nothing' );
$unmock( $f );
$upd = $mock( array( 0 => array( 200, $page( array( $item( 101, array( 'price' => 19.5, 'title' => 'Item 101 v2' ) ), $item( 102 ), $item( 103 ) ), 3 ) ) ) );
$flush();
$plan2 = Importer::plan_api( $A['user_id'], Api_Source::fetch() );
nx_assert( 1 === $plan2['summary']['update'] && 2 === $plan2['summary']['unchanged'], 'a changed upstream item plans one update' );
Importer::apply( $A['user_id'], $plan2 );
nx_assert( 'Item 101 v2' === Repository::data( $pid )['name'] && '19.50' === Repository::data( $pid )['price'], 'the update lands on the same product (upsert by external id)' );
$unmock( $upd );

/* ---------- vendors do not share products ---------- */
$f = $mock( array( 0 => array( 200, $page( $good, 3 ) ) ) );
$flush();
$planB = Importer::plan_api( $B['user_id'], Api_Source::fetch() );
nx_assert_same( 3, $planB['summary']['create'], "vendor B importing the same items creates their OWN products (upsert key is vendor + external id)" );
Importer::apply( $B['user_id'], $planB );
$track( $B );
$bid = Repository::find_by_sku( $B['vendor_id'], 'DJ-SKU-101' );
nx_assert( $bid > 0 && $bid !== $pid && 'Item 101 v2' === Repository::data( $pid )['name'], "B has their own copy and A's product is untouched" );

/* ---------- a SKU that belongs to one of the vendor's own manual products is a conflict, not a takeover ---------- */
$M = nx_test_vendor( 'apm', 'approved' );
$m = \Nexora\Products\Service::save( $M['user_id'], array( 'name' => 'Manual one', 'description' => '', 'price' => '5', 'stock' => '1', 'sku' => 'DJ-SKU-101', 'category' => 'other' ) );
$GLOBALS['nx_test']['posts'][] = $m['product_id'];
$pm = Importer::plan_api( $M['user_id'], Api_Source::fetch() );
nx_assert( 2 === $pm['summary']['create'] && 1 === $pm['summary']['error'], 'an imported item whose SKU is one of your own manual products is an error row' );
nx_assert_same( 'Manual one', Repository::data( $m['product_id'] )['name'], 'and the manual product is not overwritten' );
$unmock( $f );

/* ---------- failures upstream ---------- */
$cases = array(
	'a timeout / transport error' => array( 0 => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ),
	'a 500'                       => array( 0 => array( 500, '{"message":"boom"}' ) ),
	'a 429'                       => array( 0 => array( 429, '{}' ) ),
	'not JSON'                    => array( 0 => array( 200, '<html>maintenance</html>' ) ),
	'JSON without products'       => array( 0 => array( 200, '{"error":"x"}' ) ),
	'products is not a list'      => array( 0 => array( 200, '{"products":"nope","total":1}' ) ),
	'an empty body'               => array( 0 => array( 200, '' ) ),
);
foreach ( $cases as $label => $routes ) {
	$f = $mock( $routes );
	$flush();
	$r = Api_Source::fetch();
	$unmock( $f );
	nx_assert( '' !== $r['fetch_error'] && array() === $r['items'], "$label: a fetch error is reported and nothing is imported" );
	$pl = Importer::plan_api( $A['user_id'], $r );
	nx_assert( is_array( $pl ) && 0 === $pl['summary']['create'] && '' !== $pl['fetch_error'], "$label: the plan carries the error and plans nothing" );
}
// A failure on a later page keeps the earlier pages and says so (partial failure).
$p1 = array(); for ( $i = 1; $i <= 100; $i++ ) { $p1[] = $item( 2000 + $i ); }
$f = $mock( array( 0 => array( 200, $page( $p1, 250 ) ), 100 => array( 500, '{}' ) ) );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
nx_assert( 100 === count( $r['items'] ) && '' !== $r['fetch_error'], 'page 1 is kept when page 2 fails, and the failure is reported' );
// Paging stops at the row cap.
$routes = array(); for ( $skip = 0; $skip < 1000; $skip += 100 ) { $rows = array(); for ( $i = 1; $i <= 100; $i++ ) { $rows[] = $item( 3000 + $skip + $i ); } $routes[ $skip ] = array( 200, $page( $rows, 1000, $skip ) ); }
$f = $mock( $routes );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
nx_assert( 500 === count( $r['items'] ) && 5 === count( $GLOBALS['nx_http'] ), 'at most 500 items (5 requests) are fetched' );
// A response that is far too large is refused.
$f = $mock( array( 0 => array( 200, str_repeat( 'x', 3 * 1024 * 1024 ) ) ) );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
nx_assert( '' !== $r['fetch_error'] && array() === $r['items'], 'a response over 2 MB is refused' );

/* ---------- cache ---------- */
$f = $mock( array( 0 => array( 200, $page( $good, 3 ) ) ) );
$flush();
Api_Source::fetch();
$first = count( $GLOBALS['nx_http'] );
Api_Source::fetch();
nx_assert( 1 === $first && 1 === count( $GLOBALS['nx_http'] ), 'a second fetch within the cache window makes no request' );
$unmock( $f );
$f = $mock( array( 0 => array( 500, '{}' ) ) );
$flush();
Api_Source::fetch();
$e = Api_Source::fetch();
nx_assert( 2 === count( $GLOBALS['nx_http'] ) && '' !== $e['fetch_error'], 'a failed response is not cached' );
$unmock( $f );

/* ---------- the URL is not controllable ---------- */
$f = $mock( array( 0 => array( 200, $page( $good, 3 ) ) ) );
$flush();
add_filter( 'nexora_import_api_url', function () { return 'https://evil.example/products'; } );
$r = Api_Source::fetch();
nx_assert( '' !== $r['fetch_error'] && array() === $GLOBALS['nx_http'], 'a URL on another host is refused before any request is made' );
remove_all_filters( 'nexora_import_api_url' );
add_filter( 'nexora_import_api_url', function () { return 'http://dummyjson.com/products'; } );
$r = Api_Source::fetch();
nx_assert( '' !== $r['fetch_error'] && array() === $GLOBALS['nx_http'], 'a plain http URL is refused' );
remove_all_filters( 'nexora_import_api_url' );
$unmock( $f );

/* ---------- no credential or internal detail in a report ---------- */
$f = $mock( array( 0 => array( 500, '{"debug":"secret-token-123"}' ) ) );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
nx_assert( false === strpos( wp_json_encode( $r ), 'secret-token-123' ) && false === strpos( wp_json_encode( $r ), 'dummyjson.com/products?' ), 'an upstream error body and the request URL are not echoed to the caller' );

/* ---------- who may import ---------- */
foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor' => $S['user_id'], 'a guest' => 0 ) as $label => $uid ) {
	$r = Importer::plan_api( $uid, array( 'items' => array(), 'errors' => array(), 'fetch_error' => '' ) );
	nx_assert( is_wp_error( $r ) && 'nexora_forbidden' === $r->get_error_code(), "$label cannot import" );
}

$flush(); // leave no mocked catalog behind in the real cache
nx_test_finish();
