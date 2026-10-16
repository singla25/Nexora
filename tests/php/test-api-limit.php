<?php
// "How many products" for the API import: 20 / 40 / 100 / All (up to 500), default 20, for platform sources, saved connections and
// "My own API". Checked on the server against an allow-list; fetching stops as soon as enough VALID items are collected; invalid items
// are reported but not counted; the one-time token is bound to the chosen count. The HTTP layer is mocked.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Api_Source;
use Nexora\Imports\Importer;
use Nexora\Imports\Connection_Repository as Conns;
use Nexora\Products\Repository;
use Nexora\Core\View;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();
$saved = array( get_option( 'nexora_api_sources', '__unset__' ), get_option( 'nexora_vendor_apis_enabled', '__unset__' ), get_option( 'nexora_api_host_allowlist', '__unset__' ) );
delete_option( 'nexora_api_sources' );
update_option( 'nexora_vendor_apis_enabled', 1 );
delete_option( 'nexora_api_host_allowlist' );
add_filter( 'nexora_resolve_host', function () { return array( '93.184.216.34' ); } ); // no real DNS in tests

$A = nx_test_vendor( 'lma', 'approved' );
$B = nx_test_vendor( 'lmb', 'approved' );
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$track = function ( $v ) { foreach ( Repository::ids_for_vendor( $v['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } foreach ( Conns::for_vendor( $v['vendor_id'] ) as $c ) { $GLOBALS['nx_test']['posts'][] = $c['id']; } };
$flush = function () use ( $wpdb ) { $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nexora\\_api\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nexora\\_api\\_%'" ); wp_cache_flush(); };
$call = function ( $uid, $action, array $post = array() ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + nx_profile_nonce() );
	wp_set_current_user( 0 );
	return $r;
};
$mock = function ( callable $route ) {
	$GLOBALS['nx_http'] = array();
	add_filter( 'pre_http_request', $f = function ( $pre, $args, $url ) use ( $route ) {
		$GLOBALS['nx_http'][] = $url;
		$r = $route( $url );
		return array( 'headers' => array(), 'body' => $r[1], 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	return $f;
};
$unmock = function ( $f ) { remove_filter( 'pre_http_request', $f, 10 ); };

/* DummyJSON-style feed of $total items, 100 per page (what the platform source asks for). */
$dj_item = function ( $id, array $o = array() ) { return array_merge( array( 'id' => $id, 'title' => "LM $id", 'description' => 'd', 'category' => 'beauty', 'price' => 9.99, 'stock' => 5, 'sku' => "LM-$id", 'thumbnail' => "https://cdn.dummyjson.com/products/images/$id/thumbnail.png" ), $o ); };
$dj_feed = function ( $total, array $overrides = array() ) use ( $dj_item ) {
	return function ( $url ) use ( $total, $overrides, $dj_item ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$skip  = (int) ( $q['skip'] ?? 0 );
		$items = array();
		for ( $i = $skip + 1; $i <= min( $total, $skip + 100 ); $i++ ) { $items[] = $dj_item( $i, $overrides[ $i ] ?? array() ); }
		return array( 200, wp_json_encode( array( 'products' => $items, 'total' => $total, 'skip' => $skip, 'limit' => 100 ) ) );
	};
};
/* Nexora-format feed ("My own API"): $pages pages of $per items linked by "next"; no total. */
$OWN = 'https://api.acme.example/v1/products';
$nx_feed = function ( $pages, $per, array $overrides = array() ) use ( $OWN ) {
	return function ( $url ) use ( $pages, $per, $overrides, $OWN ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$page  = (int) ( $q['page'] ?? 1 );
		$items = array();
		for ( $i = 1; $i <= $per; $i++ ) { $n = ( $page - 1 ) * $per + $i; $items[] = array_merge( array( 'id' => "o$n", 'name' => "Own $n", 'price' => 50, 'stock' => 3, 'category' => 'other' ), $overrides[ $n ] ?? array() ); }
		$body = array( 'products' => $items );
		if ( $page < $pages ) { $body['next'] = $OWN . '?page=' . ( $page + 1 ); }
		return array( 200, wp_json_encode( $body ) );
	};
};
$preview = function ( $uid, array $post ) use ( $call ) { return $call( $uid, 'nexora_api_preview', $post ); };

/* ---------- the allow-list ---------- */
nx_assert_same( array( 20, 40, 100, 500 ), Importer::LIMITS, 'the allowed counts are 20, 40, 100 and 500 (All, up to 500)' );
nx_assert_same( 20, Importer::DEFAULT_LIMIT, 'the default is 20' );
foreach ( array( 20, 40, 100, 500 ) as $n ) {
	nx_assert_same( $n, Importer::limit_of( (string) $n ), "limit_of('$n') is $n" );
}
foreach ( array( 'nothing' => null, 'empty' => '', 'not on the list' => '25', 'zero' => '0', 'negative' => '-40', 'decimal' => '40.0', 'exponent' => '1e2', 'leading space' => ' 40', 'trailing text' => '100x', 'padded' => '040', 'the word all' => 'all', 'too big' => '501', 'huge' => str_repeat( '9', 30 ), 'array' => array( '40' ), 'bool' => true, 'float' => 40.5, 'script' => '<script>' ) as $label => $bad ) {
	nx_assert_same( 20, Importer::limit_of( $bad ), "an invalid value ($label) falls back to 20" );
}

/* ---------- each allowed value, platform source (DummyJSON, 250 items, 100 per page) ---------- */
$expect = array( 20 => array( 20, 1 ), 40 => array( 40, 1 ), 100 => array( 100, 1 ), 500 => array( 250, 3 ) );
foreach ( $expect as $n => $want ) {
	$f = $mock( $dj_feed( 250 ) ); $flush();
	$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => (string) $n ) );
	$unmock( $f );
	nx_assert( ! empty( $r['success'] ) && $want[0] === $r['data']['summary']['create'], "limit $n: the preview plans {$want[0]} products" );
	nx_assert_same( $want[1], count( $GLOBALS['nx_http'] ), "limit $n: {$want[1]} request(s) to the feed" );
	nx_assert( '' !== $r['data']['token'], "limit $n: there is a token" );
}

/* ---------- invalid values: the default 20 ---------- */
foreach ( array( '' => 'missing', '25' => '25', '501' => '501', '-1' => '-1', 'all' => 'all', '1e2' => '1e2' ) as $bad => $label ) {
	$f = $mock( $dj_feed( 250 ) ); $flush();
	$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => (string) $bad ) );
	$unmock( $f );
	nx_assert( 20 === $r['data']['summary']['create'] && 1 === count( $GLOBALS['nx_http'] ), "limit '$label' is ignored: 20 products, one request" );
}
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson' ) );
$unmock( $f );
nx_assert( 20 === $r['data']['summary']['create'], 'no limit posted at all: 20' );
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:dummyjson', 'limit' => array( '500' ) ) );
$unmock( $f );
nx_assert( ! empty( $r['success'] ) && 20 === $r['data']['summary']['create'], 'an array for the limit is ignored too' );

/* ---------- early stop: later pages are not requested ---------- */
$f = $mock( $dj_feed( 500 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$unmock( $f );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), 'a count of 20 on a 500-item feed downloads one page, not the whole feed' );
nx_assert( false !== strpos( $GLOBALS['nx_http'][0], 'skip=0' ), 'and it is the first page' );
$f = $mock( $dj_feed( 500 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '100' ) );
$unmock( $f );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), 'a count of 100 that the first page fills exactly does not request the second page' );
$f = $mock( $dj_feed( 500 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '500' ) );
$unmock( $f );
nx_assert_same( 5, count( $GLOBALS['nx_http'] ), 'All (up to 500) reads the five pages' );
nx_assert_same( 500, $r['data']['summary']['create'], '... and plans 500' );

/* ---------- the preview text: "Showing the first N of M" / "at least M" ---------- */
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$unmock( $f );
nx_assert_same( 'Showing the first 20 of 250.', $r['data']['feed_note'] ?? '', 'the feed knows its total (250): "Showing the first 20 of 250."' );
nx_assert( 20 === ( $r['data']['feed']['taken'] ?? 0 ) && 250 === ( $r['data']['feed']['total'] ?? 0 ) && true === ( $r['data']['feed']['more'] ?? null ), 'and the numbers are in the report' );
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '500' ) );
$unmock( $f );
nx_assert( '' === ( $r['data']['feed_note'] ?? 'x' ) && false === ( $r['data']['feed']['more'] ?? null ), 'when everything was taken there is no note' );
$f = $mock( $dj_feed( 20 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$unmock( $f );
nx_assert( '' === ( $r['data']['feed_note'] ?? 'x' ) && 20 === $r['data']['summary']['create'], 'a feed with exactly 20 items and a count of 20 has no note either' );
$f = $mock( $dj_feed( 15 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$unmock( $f );
nx_assert( '' === ( $r['data']['feed_note'] ?? 'x' ) && 15 === $r['data']['summary']['create'], 'a feed with fewer than the count: all taken, no note' );

/* ---------- "My own API" (typed address): early stop, unknown total -> "at least" ---------- */
$own = array( 'source' => 'adhoc', 'url' => $OWN, 'auth_type' => 'none' );
$f = $mock( $nx_feed( 5, 30 ) ); $flush();
$r = $preview( $A['user_id'], $own + array( 'limit' => '40' ) );
$unmock( $f );
nx_assert( ! empty( $r['success'] ) && 40 === $r['data']['summary']['create'], 'my own API, limit 40: 40 products' );
nx_assert_same( 2, count( $GLOBALS['nx_http'] ), 'the fetcher stops after the page that completes the count (2 of 5 pages)' );
nx_assert_same( 'Showing the first 40 of at least 60.', $r['data']['feed_note'] ?? '', 'the total is unknown (no total in the feed), so: "of at least 60" (the 60 entries read so far)' );
nx_assert( array_key_exists( 'total', $r['data']['feed'] ) && null === $r['data']['feed']['total'] && 60 === ( $r['data']['feed']['at_least'] ?? 0 ), 'the report says total unknown and at least 60' );
$f = $mock( $nx_feed( 5, 30 ) ); $flush();
$r = $preview( $A['user_id'], $own + array( 'limit' => '20' ) );
$unmock( $f );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), 'my own API, limit 20: one request' );
nx_assert_same( 'Showing the first 20 of at least 30.', $r['data']['feed_note'] ?? '', 'and "of at least 30"' );
$f = $mock( $nx_feed( 2, 30 ) ); $flush();
$r = $preview( $A['user_id'], $own + array( 'limit' => '100' ) );
$unmock( $f );
nx_assert( 60 === $r['data']['summary']['create'] && '' === ( $r['data']['feed_note'] ?? 'x' ) && 60 === ( $r['data']['feed']['total'] ?? 0 ), 'a feed read to its end knows its total (60) and has no note' );

/* ---------- a saved connection ---------- */
$saved_conn = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'Acme feed', 'url' => $OWN, 'auth_type' => 'none', 'auth_header' => '', 'secret' => '' ) );
$cid = (int) ( $saved_conn['data']['id'] ?? 0 );
$track( $A );
nx_assert( $cid > 0, 'setup: a saved connection' );
$f = $mock( $nx_feed( 5, 30 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'conn:' . $cid, 'limit' => '40' ) );
$unmock( $f );
nx_assert( 40 === $r['data']['summary']['create'] && 2 === count( $GLOBALS['nx_http'] ), 'a saved connection: limit 40 gives 40 products and stops after 2 pages' );
$f = $mock( $nx_feed( 5, 30 ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'conn:' . $cid, 'limit' => '7' ) );
$unmock( $f );
nx_assert( 20 === $r['data']['summary']['create'], 'a saved connection with an invalid limit: 20' );

/* ---------- invalid items are reported but do not use up the count ---------- */
$bad = array();
for ( $i = 1; $i <= 5; $i++ ) { $bad[ $i ] = array( 'price' => 'free' ); }
$f = $mock( $dj_feed( 250, array_map( function () { return array( 'price' => 'free' ); }, array_flip( range( 1, 5 ) ) ) ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$unmock( $f );
nx_assert_same( 20, $r['data']['summary']['create'], '5 invalid items at the front: still 20 valid products are planned' );
nx_assert_same( 5, $r['data']['summary']['error'], '... and the 5 invalid ones are reported as errors' );
nx_assert_same( 25, $r['data']['summary']['total'], '... 25 entries in the preview' );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), '(all within the first page)' );
$f = $mock( $nx_feed( 4, 30, array_fill_keys( range( 1, 40 ), array( 'price' => 0 ) ) ) ); $flush();
$r = $preview( $A['user_id'], $own + array( 'limit' => '40' ) );
$unmock( $f );
nx_assert( 40 === $r['data']['summary']['create'] && 40 === $r['data']['summary']['error'], '40 invalid entries then valid ones (my own API, limit 40): 40 valid are still collected' );
nx_assert_same( 3, count( $GLOBALS['nx_http'] ), '... which meant reading 3 pages instead of 2' );
$mixed = array( 'a string', null, array( 'x' ) );
$f = $mock( function () use ( $mixed ) {
	$items = $mixed; for ( $i = 1; $i <= 30; $i++ ) { $items[] = array( 'id' => "m$i", 'name' => "Mixed $i", 'price' => 5, 'stock' => 1, 'category' => 'other' ); }
	return array( 200, wp_json_encode( array( 'products' => $items ) ) );
} ); $flush();
$r = $preview( $A['user_id'], $own + array( 'limit' => '20' ) );
$unmock( $f );
nx_assert( 20 === $r['data']['summary']['create'] && 3 === $r['data']['summary']['error'], 'entries that are not products are reported and do not count either' );
nx_assert( 'Showing the first 20 of at least 33.' === ( $r['data']['feed_note'] ?? '' ) || 'Showing the first 20 of 33.' === ( $r['data']['feed_note'] ?? '' ), 'and the note counts the feed\'s entries (33), not just the valid ones' );

/* ---------- the token is bound to the chosen count ---------- */
$f = $mock( $dj_feed( 250 ) ); $flush();
$r20 = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
$t20 = $r20['data']['token'];
$before = $count();
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $t20, 'limit' => '100' ) );
nx_assert( nx_rejected( $r ) && $before === $count(), 'a token from a preview of 20 cannot import 100: refused, nothing written' );
nx_assert( false !== stripos( $r['data']['message'] ?? '', 'preview' ), 'the message tells the member to preview again' );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $t20, 'limit' => '40' ) );
nx_assert( nx_rejected( $r ) && $before === $count(), '... or 40' );
$r100 = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '100' ) );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $r100['data']['token'] ) );
nx_assert( nx_rejected( $r ) && $before === $count(), 'a token from a preview of 100 sent without a limit (= 20) is refused' );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $r100['data']['token'], 'limit' => '500' ) );
nx_assert( nx_rejected( $r ) && $before === $count(), 'a token from a preview of 100 cannot import 500' );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $t20, 'limit' => '20' ) );
nx_assert( ! empty( $r['success'] ) && 20 === $r['data']['summary']['created'], 'the right count works with the same token (a refusal did not use it up): 20 created' );
nx_assert_same( $before + 20, $count(), '... exactly 20 products' );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $t20, 'limit' => '20' ) );
nx_assert( nx_rejected( $r ), 'and the token is single use' );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $r100['data']['token'], 'limit' => '100' ) );
nx_assert( ! empty( $r['success'] ) && 20 === $r['data']['summary']['unchanged'] && 80 === $r['data']['summary']['created'], 'the preview of 100 imports 100 with its own count (20 already there, unchanged; 80 new)' );
$unmock( $f );
$track( $A );
nx_assert_same( $before + 100, $count(), 'so there are 100, no duplicates' );

/* ---------- a later, larger import updates what is there: no duplicates ---------- */
$f = $mock( $dj_feed( 250, array( 3 => array( 'price' => 77 ) ) ) ); $flush();
$r = $preview( $A['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '500' ) );
nx_assert( 1 === $r['data']['summary']['update'] && 99 === $r['data']['summary']['unchanged'] && 150 === $r['data']['summary']['create'], 'a larger preview: 1 changed item updates, 99 are unchanged, 150 are new' );
$res = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $r['data']['token'], 'limit' => '500' ) );
$unmock( $f );
$track( $A );
nx_assert( ! empty( $res['success'] ) && 150 === $res['data']['summary']['created'] && 1 === $res['data']['summary']['updated'], 'applying it creates 150 and updates 1' );
nx_assert_same( $before + 250, $count(), 'and there are 250 products, never more' );
nx_assert_same( '77.00', Repository::data( Repository::find_by_sku( $A['vendor_id'], 'LM-3' ) )['price'], 'the changed price landed on the existing product' );
nx_assert_same( 0, count( Repository::ids_for_vendor( $B['vendor_id'] ) ), 'another vendor has nothing' );

/* ---------- the hard limits still apply ---------- */
$f = $mock( $nx_feed( 30, 5 ) ); $flush();
$r = $preview( $B['user_id'], $own + array( 'limit' => '500' ) );
$unmock( $f );
nx_assert( 10 === count( $GLOBALS['nx_http'] ) && '' !== $r['data']['fetch_error'], 'All (up to 500) still reads at most 10 pages and says so' );
nx_assert_same( 50, $r['data']['summary']['create'], '... keeping the 50 items it did read' );
$f = $mock( $dj_feed( 900 ) ); $flush();
$r = $preview( $B['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '500' ) );
$unmock( $f );
nx_assert( 500 === $r['data']['summary']['total'] && 500 === $r['data']['summary']['create'], 'All never takes more than 500' );
$f = $mock( function () { return array( 200, str_repeat( 'x', 3 * MB_IN_BYTES ) ); } ); $flush();
$r = $preview( $B['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '100' ) );
$unmock( $f );
nx_assert( '' !== $r['data']['fetch_error'], 'the 2 MB cap still applies' );
$f = $mock( $dj_feed( 250 ) ); $flush();
$args = array();
add_filter( 'pre_http_request', $spy = function ( $pre, $a ) use ( &$args ) { $args = $a; return $pre; }, 5, 2 );
$preview( $B['user_id'], array( 'source' => 'platform:dummyjson', 'limit' => '20' ) );
remove_filter( 'pre_http_request', $spy, 5 );
$unmock( $f );
nx_assert( 10 === (int) ( $args['timeout'] ?? 0 ) && 0 === (int) ( $args['redirection'] ?? 9 ) && ! empty( $args['limit_response_size'] ), 'the 10 s timeout, no redirects and the size cap are unchanged' );
nx_test_reset_limits();
wp_set_current_user( $B['user_id'] );
$f = $mock( $dj_feed( 250 ) ); $flush();
$blocked = false;
for ( $i = 0; $i < 12; $i++ ) { $x = nx_call_ajax( 'nexora_api_preview', array( 'source' => 'platform:dummyjson', 'limit' => '20' ) + nx_profile_nonce() ); if ( nx_rejected( $x ) ) { $blocked = true; break; } }
$unmock( $f );
wp_set_current_user( 0 );
nx_assert( $blocked && $i <= 10, 'the preview rate limit (10 an hour) still applies' );
nx_test_reset_limits();

/* ---------- the fetcher itself ---------- */
$f = $mock( $dj_feed( 250 ) ); $flush();
$src = Api_Source::from_platform( \Nexora\Imports\Sources::get( 'dummyjson' ) );
$r = Api_Source::fetch( $src, array( 'valid' => 40 ) );
$unmock( $f );
nx_assert( 40 === count( $r['items'] ) && 1 === count( $GLOBALS['nx_http'] ), 'Api_Source::fetch( source, [ valid => 40 ] ) returns 40 items from one request' );
nx_assert( 40 === $r['feed']['taken'] && 250 === $r['feed']['total'] && true === $r['feed']['more'], 'and says what it left behind' );
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = Api_Source::fetch( $src, array( 'valid' => 33 ) );
$unmock( $f );
nx_assert_same( 33, count( $r['items'] ), 'it stops in the middle of a page when enough valid items are in' );
$f = $mock( $dj_feed( 250 ) ); $flush();
$r = Api_Source::fetch( $src );
$unmock( $f );
nx_assert( 250 === count( $r['items'] ) && 3 === count( $GLOBALS['nx_http'] ), 'with no count given it reads the whole feed as before' );

/* ---------- the popup ---------- */
$html = View::render( 'imports/api-popup', array( 'api_import' => array( 'platform' => array( array( 'id' => 'dummyjson', 'name' => 'DummyJSON', 'format' => 'dummyjson' ) ), 'connections' => array(), 'own' => true, 'max' => 5 ) ) );
nx_assert( 1 === preg_match( '/<label[^>]*for="nx-api-limit"[^>]*>\s*How many products\s*<\/label>/', $html ), 'the popup has a "How many products" label for the dropdown' );
preg_match( '/<select[^>]*id="nx-api-limit"[^>]*>(.*?)<\/select>/s', $html, $m );
preg_match_all( '/<option value="(\d+)"([^>]*)>([^<]*)<\/option>/', $m[1] ?? '', $opts, PREG_SET_ORDER );
nx_assert_same( array( '20', '40', '100', '500' ), array_column( $opts, 1 ), 'the options are 20, 40, 100 and 500' );
nx_assert_same( array( '20', '40', '100', 'All (up to 500)' ), array_column( $opts, 3 ), 'labelled 20, 40, 100 and "All (up to 500)"' );
nx_assert( false !== strpos( $opts[0][2], 'selected' ) && 1 === substr_count( $m[1], 'selected' ), 'and 20 is the default' );
$js = file_get_contents( NEXORA_PATH . 'assets/js/imports.js' );
nx_assert( false !== strpos( $js, '.nx-api-limit' ) && false !== strpos( $js, "data.append('limit'" ) && false !== strpos( $js, 'payload.limit' ), 'imports.js sends the chosen count' );
nx_assert( 1 === preg_match( '/\.nx-api-limit[^;]*change[^}]*token\s*=\s*\'\'|change[^}]*\.nx-api-limit[^}]*token\s*=\s*\'\'/s', $js ) || false !== strpos( $js, "'change', '.nx-api-limit'" ), 'changing the dropdown clears the token and hides Import (a new preview is needed)' );
nx_assert( false !== strpos( $js, 'feed_note' ), 'the preview shows the server\'s "Showing the first N of M" line' );
nx_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'eval(' ), 'still no raw HTML and no eval' );

// restore
foreach ( array( 'nexora_api_sources', 'nexora_vendor_apis_enabled', 'nexora_api_host_allowlist' ) as $i => $opt ) {
	if ( '__unset__' === $saved[ $i ] ) { delete_option( $opt ); } else { update_option( $opt, $saved[ $i ] ); }
}
$track( $A ); $track( $B );
nx_test_finish();
