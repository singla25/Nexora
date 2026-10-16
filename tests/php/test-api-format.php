<?php
// Marketplace Phase 5b: the published "Nexora format" and the fetcher that reads it (and the DummyJSON adapter):
// required and optional fields, pagination on the same host, authentication, no redirects, SSRF checks on every request,
// size/time/page/item limits, generic errors.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Api_Source;
use Nexora\Imports\Importer;

global $wpdb;
nx_test_reset_limits();
$dns = array( 'api.acme.example' => array( '93.184.216.34' ), 'cdn.acme.example' => array( '93.184.216.35' ), 'other.example' => array( '93.184.216.36' ), 'private.acme.example' => array( '10.0.0.7' ) );
add_filter( 'nexora_resolve_host', function ( $null, $host ) use ( $dns ) { return $dns[ $host ] ?? array( '93.184.216.34' ); }, 10, 2 );

$A = nx_test_vendor( 'afa', 'approved' );
$src = function ( array $o = array() ) {
	return $o + array( 'key' => 'p-acme', 'name' => 'Acme', 'url' => 'https://api.acme.example/v1/products', 'format' => 'nexora', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'kind' => 'platform', 'host_allowlist' => array() );
};
$item = function ( $id, array $over = array() ) {
	return array_merge( array( 'id' => $id, 'name' => "Item $id", 'price' => 100, 'stock' => 5, 'category' => 'fashion' ), $over );
};
$flush = function () { nx_test_flush_api_cache(); };

/** Mock HTTP: $routes maps a full URL to array( code, body[, headers] ) or WP_Error. Records every request. */
$mock = function ( array $routes ) {
	$GLOBALS['nx_http'] = array();
	$GLOBALS['nx_curl_hook_during'] = array();
	add_filter( 'pre_http_request', $f = function ( $pre, $args, $url ) use ( $routes ) {
		$GLOBALS['nx_http'][] = array( 'url' => $url, 'args' => $args );
		$GLOBALS['nx_curl_hook_during'][] = false !== has_action( 'http_api_curl' );
		$r = $routes[ $url ] ?? array( 404, '{}' );
		if ( is_wp_error( $r ) ) { return $r; }
		return array( 'headers' => $r[2] ?? array( 'content-type' => 'application/json' ), 'body' => $r[1], 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	return $f;
};
$unmock = function ( $f ) { remove_filter( 'pre_http_request', $f, 10 ); };
$page   = function ( array $items, $next = null ) { return wp_json_encode( array_filter( array( 'products' => $items, 'next' => $next ), function ( $v ) { return null !== $v; } ) ); };
$URL    = 'https://api.acme.example/v1/products';
$go     = function ( $source ) use ( $flush ) { $flush(); return Api_Source::fetch( $source ); }; // fresh cache each time, except in the cache section

/* ---------- the published format: required and optional fields ---------- */
$f = $mock( array( $URL => array( 200, $page( array(
	$item( 'A1' ),
	$item( 'A2', array( 'sku' => 'kurta-red', 'description' => 'Soft cotton', 'image' => 'https://img.api.acme.example/img/a2.jpg', 'price' => '499.50', 'stock' => '12' ) ),
	$item( 'A3', array( 'image' => 'https://other.example/a3.jpg' ) ),
	$item( 'A3b', array( 'image' => 'https://cdn.acme.example/a3b.jpg' ) ),
	$item( 'A4', array( 'image' => 'http://cdn.acme.example/a4.jpg' ) ),
	$item( 'A5', array( 'category' => 'Home & Kitchen' ) ),
	$item( 'A6', array( 'category' => 'cars' ) ),
	$item( 7 ),
) ) ) ) );
$r  = $go( $src() );
$by = array(); foreach ( $r['items'] as $it ) { $by[ $it['external_id'] ] = $it; }
$unmock( $f );
nx_assert_same( '', $r['fetch_error'], 'a valid page has no fetch error' );
nx_assert_same( 8, count( $r['items'] ), 'every item is returned (validation of the fields comes next)' );
nx_assert( isset( $by['p-acme:A1'] ), 'the external id is the item id, prefixed with the source so two sources never collide' );
nx_assert_same( array( 'name' => 'Item A1', 'description' => '', 'price' => '100', 'stock' => '5', 'sku' => 'A1', 'category' => 'fashion', 'image_id' => '0' ), $by['p-acme:A1']['raw'], 'minimal item: the five required fields; sku defaults to the id; description empty' );
nx_assert_same( array( 'description' => 'Soft cotton', 'price' => '499.50', 'stock' => '12', 'sku' => 'kurta-red' ), array_intersect_key( $by['p-acme:A2']['raw'], array_flip( array( 'sku', 'description', 'price', 'stock' ) ) ), 'optional sku and description are used; price and stock may be numbers or numeric text' );
nx_assert_same( 'https://img.api.acme.example/img/a2.jpg', $by['p-acme:A2']['image_url'], 'an https image on the source\'s own host or a subdomain of it is kept as an external URL' );
nx_assert( '' === $by['p-acme:A3']['image_url'] && '' === $by['p-acme:A3b']['image_url'] && '' === $by['p-acme:A4']['image_url'], 'an image from another site (even a sibling subdomain), or over plain http, is dropped (the product still imports)' );
nx_assert_same( 'home-kitchen', $by['p-acme:A5']['raw']['category'], 'a category may be given by its label' );
nx_assert_same( 'cars', $by['p-acme:A6']['raw']['category'], 'an unknown category is passed on to the validator, which refuses it in plain words' );
nx_assert( isset( $by['p-acme:7'] ) && 'Item 7' === $by['p-acme:7']['raw']['name'], 'a numeric id works' );

$plan = Importer::plan_api( $A['user_id'], array( 'items' => array( $by['p-acme:A1'], $by['p-acme:A6'] ), 'errors' => array(), 'fetch_error' => '', 'label' => 'Acme' ) );
nx_assert( 'create' === $plan['rows'][0]['action'] && 'error' === $plan['rows'][1]['action'] && isset( $plan['rows'][1]['errors']['category'] ), 'items go through the shared validator: a good one plans a create, a bad category is an error on category' );
nx_assert_same( 'Acme', $plan['label'], 'the plan carries the source name for the history' );

// Required fields: each one missing is an error on that field (the shared validator), a missing id is a structural error.
foreach ( array( 'name', 'price', 'stock', 'category' ) as $field ) {
	$broken = $item( 'M1' ); unset( $broken[ $field ] );
	$f = $mock( array( $URL => array( 200, $page( array( $broken ) ) ) ) );
	$fetched = $go( $src() );
	$unmock( $f );
	$pl = Importer::plan_api( $A['user_id'], $fetched );
	nx_assert( 'error' === $pl['rows'][0]['action'] && isset( $pl['rows'][0]['errors'][ $field ] ), "a missing $field is an error on $field" );
}
$bad_ids = array( 'no id' => array( 'name' => 'x', 'price' => 1, 'stock' => 1, 'category' => 'other' ), 'empty id' => $item( '' ), 'array id' => $item( array( 1 ) ), 'null id' => $item( null ), 'float id' => $item( 1.5 ), 'negative id' => $item( -3 ), 'spaces' => $item( 'a b' ), 'html' => $item( '<b>1</b>' ), 'long' => $item( str_repeat( 'a', 65 ) ), 'bool' => $item( true ), 'not an object' => 'text' );
$f = $mock( array( $URL => array( 200, $page( array_values( $bad_ids ) ) ) ) );
$r = $go( $src() );
$unmock( $f );
nx_assert_same( 0, count( $r['items'] ), 'items without a usable id are not imported' );
nx_assert_same( count( $bad_ids ), count( $r['errors'] ), 'and each one is reported' );

/* ---------- pagination: an optional "next" address on the same host ---------- */
$P2 = 'https://api.acme.example/v1/products?page=2';
$P3 = 'https://api.acme.example/v1/products?page=3';
$f = $mock( array( $URL => array( 200, $page( array( $item( 'P1' ) ), $P2 ) ), $P2 => array( 200, $page( array( $item( 'P2' ) ), $P3 ) ), $P3 => array( 200, $page( array( $item( 'P3' ) ) ) ) ) );
$r = $go( $src() );
$unmock( $f );
nx_assert( 3 === count( $r['items'] ) && 3 === count( $GLOBALS['nx_http'] ) && '' === $r['fetch_error'], 'the next page is followed until there is none' );
foreach ( array( 'another host' => 'https://other.example/v1/products?page=2', 'plain http' => 'http://api.acme.example/v1/products?page=2', 'a relative path' => '/v1/products?page=2', 'a private host' => 'https://private.acme.example/v1/products', 'javascript' => 'javascript:alert(1)', 'with credentials' => 'https://u:p@api.acme.example/v1/products?page=2', 'a number' => 5, 'a list' => array( 'x' ) ) as $label => $next ) {
	$f = $mock( array( $URL => array( 200, wp_json_encode( array( 'products' => array( $item( 'N1' ) ), 'next' => $next ) ) ), 'https://other.example/v1/products?page=2' => array( 200, $page( array( $item( 'N2' ) ) ) ) ) );
	$r = $go( $src() );
	$unmock( $f );
	nx_assert( 1 === count( $r['items'] ) && '' !== $r['fetch_error'] && 1 === count( $GLOBALS['nx_http'] ), "next page refused ($label): the first page is kept, nothing else is requested, a generic error is reported" );
}
$f = $mock( array( $URL => array( 200, $page( array( $item( 'L1' ) ), $URL ) ) ) );
$r = $go( $src() );
$unmock( $f );
nx_assert( 1 === count( $GLOBALS['nx_http'] ) && '' !== $r['fetch_error'], 'a next page that points back at a page already read is a loop and stops' );
$routes = array(); for ( $i = 1; $i <= 30; $i++ ) { $u = $i > 1 ? 'https://api.acme.example/v1/products?page=' . $i : $URL; $routes[ $u ] = array( 200, $page( array( $item( "PG$i" ) ), 'https://api.acme.example/v1/products?page=' . ( $i + 1 ) ) ); }
$f = $mock( $routes );
$r = $go( $src() );
$unmock( $f );
nx_assert( 10 === count( $GLOBALS['nx_http'] ) && 10 === count( $r['items'] ) && '' !== $r['fetch_error'], 'at most 10 pages are read, then it stops with an error' );
$many = array(); for ( $i = 1; $i <= 300; $i++ ) { $many[] = $item( "X$i" ); }
$f = $mock( array( $URL => array( 200, $page( $many, $P2 ) ), $P2 => array( 200, $page( array_map( function ( $i ) use ( $item ) { return $item( "Y$i" ); }, range( 1, 300 ) ) ) ) ) );
$r = $go( $src() );
$unmock( $f );
nx_assert_same( 500, count( $r['items'] ), 'at most 500 items are taken' );

/* ---------- authentication ---------- */
$f = $mock( array( $URL => array( 200, $page( array( $item( 'U1' ) ), $P2 ) ), $P2 => array( 200, $page( array( $item( 'U2' ) ) ) ) ) );
$go( $src( array( 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => 'sk_secret_123' ) ) );
$h1 = $GLOBALS['nx_http'][0]['args']['headers']; $h2 = $GLOBALS['nx_http'][1]['args']['headers'];
$unmock( $f );
nx_assert( 'sk_secret_123' === ( $h1['X-Shop-Key'] ?? '' ) && 'sk_secret_123' === ( $h2['X-Shop-Key'] ?? '' ) && empty( $h1['Authorization'] ), 'header auth: the key goes in the chosen header, on every page of the same host, and not in Authorization' );
$f = $mock( array( $URL => array( 200, $page( array( $item( 'U1' ) ) ) ) ) );
$go( $src( array( 'auth_type' => 'bearer', 'secret' => 'tok_abc' ) ) );
$hb = $GLOBALS['nx_http'][0]['args']['headers'];
$unmock( $f );
nx_assert( 'Bearer tok_abc' === ( $hb['Authorization'] ?? '' ), 'bearer auth: Authorization: Bearer <token>' );
$f = $mock( array( $URL => array( 200, $page( array( $item( 'U1' ) ) ) ) ) );
$go( $src() );
$hn = $GLOBALS['nx_http'][0]['args']['headers'];
$unmock( $f );
nx_assert( array() === array_diff( array_keys( $hn ), array( 'Accept' ) ), 'no auth: no credential header at all (only Accept)' );
// The key is never in anything returned.
$f = $mock( array( $URL => array( 500, '{"echo":"sk_secret_123"}' ) ) );
$r = $go( $src( array( 'auth_type' => 'bearer', 'secret' => 'sk_secret_123' ) ) );
$unmock( $f );
nx_assert( false === strpos( wp_json_encode( $r ), 'sk_secret_123' ), 'the key (and an upstream body that echoes it) never appears in the result' );
$f = $mock( array( $URL => array( 200, $page( array( $item( 'BAD', array( 'price' => 'sk_secret_123' ) ) ) ) ) ) );
$r  = $go( $src( array( 'auth_type' => 'bearer', 'secret' => 'sk_secret_123' ) ) );
$pl = Importer::plan_api( $A['user_id'], $r );
$unmock( $f );
nx_assert( false === strpos( wp_json_encode( $r ), 'Bearer sk_secret_123' ), 'the Authorization value is not in the fetched result' );

/* ---------- the request itself ---------- */
$hooks  = function () { global $wp_filter; return isset( $wp_filter['http_api_curl'] ) ? count( $wp_filter['http_api_curl']->callbacks, COUNT_RECURSIVE ) : 0; };
$before = $hooks();
$seen_during = -1;
$spy = function ( $pre ) use ( $hooks, &$seen_during ) { $seen_during = $hooks(); return $pre; };
add_filter( 'pre_http_request', $spy, 5 );
$f = $mock( array( $URL => array( 200, $page( array( $item( 'R1' ) ) ) ) ) );
$go( $src() );
$a = $GLOBALS['nx_http'][0]['args'];
$unmock( $f );
remove_filter( 'pre_http_request', $spy, 5 );
nx_assert_same( 10, (int) $a['timeout'], 'the timeout is 10 seconds' );
nx_assert_same( 0, (int) $a['redirection'], 'no redirect is ever followed' );
nx_assert( ! empty( $a['reject_unsafe_urls'] ), 'WordPress\'s own unsafe-URL check is on too' );
nx_assert_same( 2 * 1024 * 1024, (int) $a['limit_response_size'], 'the response is capped at 2 MB' );
nx_assert( ! isset( $a['sslverify'] ) || true === $a['sslverify'], 'the certificate is verified' );
nx_assert( $seen_during > $before && $hooks() === $before, 'the request is pinned to the checked address (a curl hook exists only while the request runs)' );
foreach ( array( 301, 302, 303, 307, 308 ) as $code ) {
	$f = $mock( array( $URL => array( $code, '', array( 'location' => 'https://other.example/v1/products' ) ), 'https://other.example/v1/products' => array( 200, $page( array( $item( 'RD' ) ) ) ) ) );
	$r = $go( $src() );
	$unmock( $f );
	nx_assert( 1 === count( $GLOBALS['nx_http'] ) && array() === $r['items'] && '' !== $r['fetch_error'], "a $code redirect is not followed and is an error" );
}

/* ---------- SSRF: the source address and the allow-list ---------- */
foreach ( array( 'https://private.acme.example/v1/products', 'https://localhost/p', 'https://127.0.0.1/p', 'http://api.acme.example/p', 'https://169.254.169.254/latest/meta-data' ) as $bad ) {
	$f = $mock( array() );
	$r = $go( $src( array( 'url' => $bad ) ) );
	$unmock( $f );
	nx_assert( array() === $GLOBALS['nx_http'] && array() === $r['items'] && \Nexora\Imports\Url_Guard::MESSAGE === $r['fetch_error'], "no request is made to $bad" );
}
$f = $mock( array( $URL => array( 200, $page( array( $item( 'W1' ) ) ) ) ) );
$r = $go( $src( array( 'kind' => 'vendor', 'host_allowlist' => array( 'acme.example' ) ) ) );
nx_assert( 1 === count( $r['items'] ), 'a vendor API on the allow-list works' );
$GLOBALS['nx_http'] = array();
$r = $go( $src( array( 'kind' => 'vendor', 'url' => 'https://other.example/p', 'host_allowlist' => array( 'acme.example' ) ) ) );
nx_assert( array() === $GLOBALS['nx_http'] && \Nexora\Imports\Url_Guard::MESSAGE === $r['fetch_error'], 'a vendor API outside the allow-list is refused without a request' );
$GLOBALS['nx_http'] = array();
$r = $go( $src( array( 'kind' => 'platform', 'url' => 'https://other.example/p', 'host_allowlist' => array( 'acme.example' ) ) ) );
nx_assert( 1 === count( $GLOBALS['nx_http'] ), 'the allow-list applies to vendors\' own APIs, not to sources the administrator chose' );
$unmock( $f );

/* ---------- failures upstream: generic, never the body or the address ---------- */
$cases = array( 'a transport error' => new WP_Error( 'http_request_failed', 'cURL error 6: could not resolve host api.acme.example' ), 'a 500' => array( 500, '{"debug":"stack trace /var/www/secret.php"}' ), 'a 401' => array( 401, '{"error":"bad key"}' ), 'not JSON' => array( 200, '<html>login</html>' ), 'a list instead of an object' => array( 200, '[1,2,3]' ), 'no products' => array( 200, '{"items":[]}' ), 'products not a list' => array( 200, '{"products":{"a":1}}' ), 'an empty body' => array( 200, '' ), 'too big' => array( 200, str_repeat( 'x', 3 * 1024 * 1024 ) ) );
foreach ( $cases as $label => $route ) {
	$f = $mock( array( $URL => $route ) );
	$r = $go( $src() );
	$unmock( $f );
	nx_assert( array() === $r['items'] && '' !== $r['fetch_error'], "$label: nothing imported, an error reported" );
	$text = wp_json_encode( $r );
	nx_assert( false === strpos( $text, 'stack trace' ) && false === strpos( $text, 'secret.php' ) && false === strpos( $text, 'bad key' ) && false === strpos( $text, 'could not resolve' ) && false === strpos( $text, 'api.acme.example' ) && false === strpos( $text, '93.184' ), "$label: the error does not reveal the upstream body, the address or the lookup" );
}

/* ---------- cache: platform sources only ---------- */
$flush();
$f = $mock( array( $URL => array( 200, $page( array( $item( 'C1' ) ) ) ) ) );
Api_Source::fetch( $src() ); Api_Source::fetch( $src() );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), 'a platform source is cached for ten minutes (one request for two fetches)' );
Api_Source::fetch( $src( array( 'kind' => 'vendor' ) ) ); Api_Source::fetch( $src( array( 'kind' => 'vendor' ) ) );
nx_assert_same( 3, count( $GLOBALS['nx_http'] ), 'a vendor\'s own API is never cached (its data and key are theirs)' );
$flush();
$unmock( $f );

/* ---------- the DummyJSON adapter still maps (the default platform source) ---------- */
$DJ = 'https://dummyjson.com/products?limit=100&skip=0';
$f = $mock( array( $DJ => array( 200, wp_json_encode( array( 'products' => array( array( 'id' => 1, 'title' => 'Essence', 'description' => 'd', 'category' => 'beauty', 'price' => 9.99, 'stock' => 5, 'sku' => 'BEA-1', 'thumbnail' => 'https://cdn.dummyjson.com/x.webp' ) ), 'total' => 1 ) ) ) ) );
$flush();
$r = Api_Source::fetch();
$unmock( $f );
nx_assert( 1 === count( $r['items'] ) && '1' === $r['items'][0]['external_id'] && 'Essence' === $r['items'][0]['raw']['name'] && 'https://cdn.dummyjson.com/x.webp' === $r['items'][0]['image_url'], 'with no argument, fetch() reads the default platform source with the DummyJSON adapter (external id stays the plain id)' );
$flush();

/* ---------- the published guide cannot drift from the code ---------- */
$sample = json_decode( \Nexora\Imports\Format_Guide::sample_json(), true );
nx_assert( is_array( $sample ) && ! empty( $sample['products'] ) && ! empty( $sample['next'] ), 'the downloadable sample is valid JSON with products and next' );
$f = $mock( array( $URL => array( 200, wp_json_encode( array( 'products' => $sample['products'] ) ) ) ) );
$r = $go( $src() );
$unmock( $f );
nx_assert( 2 === count( $r['items'] ) && '' === $r['fetch_error'], 'the sample is read by the real fetcher' );
foreach ( $r['items'] as $it ) {
	nx_assert( array() === \Nexora\Products\Validator::validate( $it['raw'] )['errors'], 'sample item ' . $it['ref'] . ' passes the shared product validator' );
}
nx_assert( 'https://img.api.acme.example/x' !== $r['items'][0]['image_url'] && '' === $r['items'][0]['image_url'] && ! empty( $r['items'][0]['notes'] ), 'the sample image (on shop.example.com) is on another domain than the test source, so it is ignored with a note: the guide says images must be on the API\'s own domain' );
$guide_fields = array_column( \Nexora\Imports\Format_Guide::fields(), 0 );
nx_assert_same( array( 'id', 'name', 'price', 'stock', 'category', 'sku', 'description', 'image' ), $guide_fields, 'the guide lists the eight fields' );
$required = array_column( array_filter( \Nexora\Imports\Format_Guide::fields(), function ( $f ) { return $f[1]; } ), 0 );
nx_assert_same( array( 'id', 'name', 'price', 'stock', 'category' ), array_values( $required ), 'and marks exactly id, name, price, stock, category as required' );
$spec = file_get_contents( ABSPATH . 'docs/SPEC.md' );
nx_assert( false !== strpos( $spec, 'Nexora format' ) && false !== strpos( $spec, '"products"' ), 'the format is documented in SPEC.md' );
foreach ( $guide_fields as $field ) { nx_assert( false !== strpos( $spec, '`' . $field . '`' ), "SPEC.md documents the field $field" ); }

nx_test_finish();
