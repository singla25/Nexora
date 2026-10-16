<?php
// Marketplace: the "Fake Store adapter", a third format for Product API sources (https://fakestoreapi.com/products: a bare JSON
// list of id, title, price, description, category, image, rating - no stock). The HTTP layer is mocked; the 20 items come from
// tests/fixtures/fakestore-products.json.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Api_Source;
use Nexora\Imports\Importer;
use Nexora\Imports\Sources;
use Nexora\Products\Fields;
use Nexora\Products\Repository;

global $wpdb;
nx_test_reset_limits();
add_filter( 'nexora_resolve_host', function () { return array( '93.184.216.34' ); } ); // no real DNS in tests

nx_test_options( array( 'nexora_api_sources' => null, Fields::OPTION => null ) );

$A       = nx_test_vendor( 'fsa', 'approved' );
$fixture = json_decode( file_get_contents( __DIR__ . '/../fixtures/fakestore-products.json' ), true );
nx_assert_same( 20, count( $fixture ), 'setup: the fixture has 20 items' );
nx_assert( ! isset( $fixture[0]['stock'] ) && isset( $fixture[0]['rating'] ), 'setup: and, like the real API, no stock but a rating' );

$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$track = function ( $v ) { foreach ( Repository::ids_for_vendor( $v['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } };
$row   = function ( array $over = array() ) {
	return array_merge( array( 'id' => 'fakestore', 'name' => 'Fake Store', 'url' => 'https://fakestoreapi.com/products', 'format' => 'fakestore', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'enabled' => true, 'stock_default' => 10, 'create_categories' => true ), $over );
};
$mock = function ( $body, $code = 200 ) {
	$GLOBALS['nx_http'] = array();
	add_filter( 'pre_http_request', $f = function ( $pre, $args, $url ) use ( $body, $code ) {
		$GLOBALS['nx_http'][] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	return $f;
};
$unmock = function ( $f ) { remove_filter( 'pre_http_request', $f, 10 ); };
$flush = function () { nx_test_flush_api_cache(); };
$fetch  = function ( $body, array $over = array(), $code = 200 ) use ( $mock, $unmock, $flush, $row ) {
	$f = $mock( $body, $code ); $flush();
	$r = Api_Source::fetch( Api_Source::from_platform( $row( $over ) ) );
	$unmock( $f );
	return $r;
};
$by = function ( array $r ) { $o = array(); foreach ( $r['items'] as $it ) { $o[ $it['ref'] ] = $it; } return $o; };

/* ---------- the format exists, and a disabled "Fake Store" source is seeded ---------- */
nx_assert( in_array( 'fakestore', Sources::FORMATS, true ), 'fakestore is a known format' );
$all = Sources::all();
$ids = array_column( $all, 'id' );
nx_assert( in_array( 'dummyjson', $ids, true ) && in_array( 'fakestore', $ids, true ), 'out of the box there are two sources: DummyJSON and Fake Store' );
$fs = Sources::get( 'fakestore' );
nx_assert( 'Fake Store' === $fs['name'] && 'https://fakestoreapi.com/products' === $fs['url'] && 'fakestore' === $fs['format'] && 'none' === $fs['auth_type'] && false === $fs['enabled'], 'the seeded Fake Store source is disabled, no auth' );
nx_assert_same( array( 'dummyjson' ), array_column( Sources::enabled(), 'id' ), 'so vendors still see only DummyJSON until an administrator enables it' );
nx_assert( 10 === $fs['stock_default'] && true === $fs['create_categories'], 'its settings: stock for items without stock 10, create missing categories on' );

/* ---------- the two per-source settings: saved, clamped, only for this format ---------- */
$post = function ( array $over = array() ) { return array_merge( array( 'id' => '', 'name' => 'FS copy', 'url' => 'https://fakestoreapi.com/products', 'format' => 'fakestore', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'enabled' => '1', 'stock_default' => '25', 'create_categories' => '1' ), $over ); };
$clean = Sources::sanitize( array( $post() ) );
nx_assert( 'fakestore' === $clean[0]['format'] && 25 === $clean[0]['stock_default'] && true === $clean[0]['create_categories'], 'a valid row keeps the format, the stock number and the checkbox' );
$clean = Sources::sanitize( array( $post( array( 'create_categories' => '' ) ) ) );
nx_assert_same( false, $clean[0]['create_categories'], 'an unticked "Create missing categories" is saved as off' );
foreach ( array( '0' => 0, '100000' => 100000, '100001' => 10, '-1' => 10, 'abc' => 10, '' => 10, '1.5' => 10, '1e3' => 10, ' 7 ' => 10 ) as $in => $want ) {
	nx_assert_same( $want, Sources::sanitize( array( $post( array( 'stock_default' => (string) $in ) ) ) )[0]['stock_default'], "stock for items without stock '$in' is saved as $want (0-100,000, whole numbers, else 10)" );
}
nx_assert_same( 10, Sources::sanitize( array( $post( array( 'stock_default' => array( '5' ) ) ) ) )[0]['stock_default'], 'an array is not a number: 10' );
$other = Sources::sanitize( array( $post( array( 'format' => 'nexora', 'stock_default' => '77', 'create_categories' => '' ) ) ) );
nx_assert( 10 === $other[0]['stock_default'] && true === $other[0]['create_categories'], 'for the other formats the two settings are not kept (they stay at their defaults)' );
$legacy = array( array( 'id' => 'old', 'name' => 'Old', 'url' => 'https://old.example/p', 'format' => 'nexora', 'auth_type' => 'none', 'enabled' => true ) );
update_option( 'nexora_api_sources', $legacy );
nx_assert( 10 === Sources::get( 'old' )['stock_default'] && true === Sources::get( 'old' )['create_categories'], 'a source saved before this change reads with the defaults' );
delete_option( 'nexora_api_sources' );

/* ---------- the settings screen ---------- */
wp_set_current_user( nx_test_admin() );
set_current_screen( 'dashboard' );
ob_start();
( new \Nexora\Admin\Settings() )->settings_page();
$html = ob_get_clean();
wp_set_current_user( 0 );
nx_assert( false !== strpos( $html, 'value="fakestore"' ) && false !== strpos( $html, 'Fake Store adapter' ), 'the format dropdown offers "Fake Store adapter"' );
nx_assert( preg_match_all( '/name="nexora_api_sources\[\d+\]\[stock_default\]"/', $html ) >= 1 && preg_match_all( '/name="nexora_api_sources\[\d+\]\[create_categories\]"/', $html ) >= 1, 'the two fields are on the screen' );
nx_assert( false !== strpos( $html, 'Stock for items without stock' ) && false !== strpos( $html, 'Create missing categories' ), 'with their labels' );
nx_assert( 1 === preg_match( '/<[^>]*nx-fakestore-only[^>]*\shidden[^>]*>/', $html ) || 1 === preg_match( '/<[^>]*\shidden[^>]*nx-fakestore-only[^>]*>/', $html ), 'for a source that is not Fake Store the two fields are hidden' );
nx_assert( 1 === preg_match( '/<[^>]*nx-fakestore-only(?![^>]*\shidden)[^>]*>/', $html ), 'and shown for the Fake Store source' );
nx_assert( false !== strpos( $html, "querySelectorAll('.nx-source-row')" ) || false !== strpos( $html, 'nx-fakestore-only' ) && false !== strpos( $html, 'addEventListener' ), 'and a small script shows them when the format dropdown is switched to Fake Store' );

/* ---------- the request: the same fetcher, one request, nothing new ---------- */
$f = $mock( $fixture ); $flush();
$r = Api_Source::fetch( Api_Source::from_platform( $row() ) );
$req = $GLOBALS['nx_http'][0] ?? array();
$unmock( $f );
nx_assert_same( 1, count( $GLOBALS['nx_http'] ), 'a bare list is one request (no paging)' );
nx_assert_same( 'https://fakestoreapi.com/products', $req['url'] ?? '', 'it asks the source address as saved, with no limit or skip added' );
nx_assert( 10 === (int) ( $req['args']['timeout'] ?? 0 ) && 0 === (int) ( $req['args']['redirection'] ?? 9 ) && ! empty( $req['args']['reject_unsafe_urls'] ) && ! empty( $req['args']['limit_response_size'] ), 'same limits as the other formats: 10 s, no redirects, unsafe URLs rejected, size cap' );
nx_assert_same( '', $r['fetch_error'], 'a good answer has no fetch error' );
nx_assert_same( 20, count( $r['items'] ), 'and gives the 20 items' );
nx_assert_same( array(), $r['errors'], 'none of them is an error (categories are created)' );

/* ---------- the mapping ---------- */
$m = $by( $r );
nx_assert_same( 'p-fakestore:1', $m['1']['external_id'], 'the id becomes the external id (prefixed with the source, so it can never meet a DummyJSON id)' );
nx_assert_same( 'Fjallraven - Foldsack No. 1 Backpack, Fits 15 Laptops', $m['1']['raw']['name'], 'title becomes name' );
nx_assert_same( '109.95', $m['1']['raw']['price'], 'price passes through' );
nx_assert_same( 'Fixture description for product 1.', $m['1']['raw']['description'], 'description passes through' );
nx_assert_same( 'electronics', $m['9']['raw']['category'], 'a category that is already in the list (electronics) is used as it is' );
nx_assert_same( 'FS-1', $m['1']['raw']['sku'], 'there is no sku in the API, so FS-{id}' );
nx_assert_same( 'https://fakestoreapi.com/img/fixture-1.jpg', $m['1']['image_url'], 'the image is kept (https, the source\'s own host)' );
nx_assert( ! array_key_exists( 'rating', $m['1']['raw'] ) && false === strpos( wp_json_encode( $m['1'] ), 'rating' ), 'rating is ignored' );
nx_assert_same( '0', $m['1']['raw']['image_id'], 'and no attachment is involved' );

/* ---------- stock for items without stock ---------- */
nx_assert_same( '10', $m['1']['raw']['stock'], 'every item has stock 10 by default' );
$r25 = $fetch( $fixture, array( 'stock_default' => 25 ) );
nx_assert_same( '25', $by( $r25 )['3']['raw']['stock'], 'the source\'s setting is used (25)' );
$r0 = $fetch( $fixture, array( 'stock_default' => 0 ) );
nx_assert_same( '0', $by( $r0 )['3']['raw']['stock'], 'and 0 is allowed (listed, out of stock)' );
$withstock = $fixture; $withstock[2]['stock'] = 999;
nx_assert_same( '25', $by( $fetch( $withstock, array( 'stock_default' => 25 ) ) )['3']['raw']['stock'], 'a stock the API happens to send is not trusted: the setting is used' );
$wild = $fetch( $fixture, array( 'stock_default' => 'lots' ) );
nx_assert_same( '10', $by( $wild )['3']['raw']['stock'], 'a damaged setting falls back to 10' );

/* ---------- other fields are judged by the shared validator ---------- */
$bad = $fixture;
$bad[0]['price'] = 'free'; $bad[1]['price'] = 0; $bad[2]['description'] = str_repeat( 'd', 2500 ); $bad[3]['image'] = 'http://fakestoreapi.com/img/x.jpg'; $bad[4]['image'] = 'https://evil.example/x.jpg';
$bad[5]['title'] = '<script>alert(1)</script>Safe <b>name</b>';
$rb = $fetch( $bad );
$mb = $by( $rb );
foreach ( array( '1' => 'price "free"', '2' => 'price 0' ) as $id => $why ) {
	$plan = Importer::plan_api( $A['user_id'], array( 'items' => array( $mb[ $id ] ), 'errors' => array(), 'fetch_error' => '' ) );
	nx_assert_same( 'error', $plan['rows'][0]['action'], "an item with $why is an error row in the plan" );
}
nx_assert( 2000 === mb_strlen( $mb['3']['raw']['description'] ), 'a long description is cut to 2,000 characters' );
nx_assert( '' === $mb['4']['image_url'] && '' === $mb['5']['image_url'], 'an http image, or one from another host, is dropped (the product still imports)' );

/* ---------- a missing title ---------- */
$notitle = $fixture;
unset( $notitle[6]['title'] ); $notitle[7]['title'] = ''; $notitle[8]['title'] = '   '; $notitle[9]['title'] = array( 'x' ); $notitle[10]['title'] = 12345;
$rt = $fetch( $notitle );
nx_assert_same( 20, count( $rt['items'] ) + count( $rt['errors'] ), 'every entry is accounted for' );
$plan = Importer::plan_api( $A['user_id'], $rt );
$act  = array(); foreach ( $plan['rows'] as $pr ) { $act[ $pr['ref'] ] = $pr['action']; }
foreach ( array( '7', '8', '9', '10' ) as $id ) { nx_assert_same( 'error', $act[ $id ] ?? 'missing', "item $id (no usable title) fails with the others still planned" ); }
nx_assert_same( 'create', $act['11'] ?? '', 'a title that is a number is text, so item 11 imports' );
$errs7 = array(); foreach ( $plan['rows'] as $pr ) { if ( '7' === $pr['ref'] ) { $errs7 = $pr['errors']; } }
nx_assert( array( 'name' ) === array_keys( $errs7 ) && 'This field is required.' === $errs7['name'], 'the missing title is reported on the name field, in the validator\'s own plain words' );
nx_assert( 4 === $plan['summary']['error'] && 16 === $plan['summary']['create'], 'four items fail (the title ones), 16 are planned' );

/* ---------- a response that is not a list ---------- */
foreach ( array(
	'an object with products (the DummyJSON shape)' => '{"products":[],"total":0}',
	'an object'                                     => '{"id":1,"title":"x"}',
	'a string'                                      => '"hello"',
	'a number'                                      => '42',
	'null'                                          => 'null',
	'not JSON'                                      => '<html>Down for maintenance</html>',
	'empty'                                         => '',
) as $label => $body ) {
	$rn = $fetch( $body );
	nx_assert( '' !== $rn['fetch_error'] && array() === $rn['items'], "$label: a plain fetch error and no items" );
	nx_assert( false === strpos( $rn['fetch_error'], 'maintenance' ) && false === strpos( $rn['fetch_error'], 'fakestoreapi' ), "$label: the upstream text and address are not echoed" );
}
$rn = $fetch( '[]' );
nx_assert( '' === $rn['fetch_error'] && array() === $rn['items'], 'an empty list is fine: nothing to import' );
$rn = $fetch( array( 'a string', 7, null, array( array( 'x' ) ), array( 'title' => 'no id' ) ) );
nx_assert( array() === $rn['items'] && 5 === count( $rn['errors'] ), 'a list of things that are not products gives one error each' );
$rn = $fetch( '{}', array(), 500 );
nx_assert( '' !== $rn['fetch_error'], 'an HTTP error is a fetch error' );
$rn = $fetch( array_fill( 0, 600, $fixture[0] ) );
nx_assert( count( $rn['items'] ) + count( $rn['errors'] ) <= 500, 'no more than 500 entries are read' );

/* ---------- categories: create missing ---------- */
nx_assert( ! isset( Fields::categories()['mens-clothing'] ), 'setup: "men\'s clothing" is not a category yet' );
$before = $count();
$plan   = Importer::plan_api( $A['user_id'], $r );
nx_assert_same( array( 'create' => 20, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'total' => 20 ), $plan['summary'], 'with creation on, the preview plans all 20 (the new categories count as valid)' );
nx_assert( $before === $count() && ! isset( Fields::categories()['mens-clothing'] ) && '' === (string) get_option( Fields::OPTION, '' ), 'a preview writes nothing: no product and no category' );
$res = Importer::apply( $A['user_id'], $plan );
$track( $A );
nx_assert_same( 20, $res['summary']['created'], 'applying creates the 20 products' );
$cats = Fields::categories();
nx_assert( isset( $cats['mens-clothing'], $cats['womens-clothing'], $cats['jewelery'], $cats['electronics'], $cats['other'] ) && isset( $cats['fashion'] ), 'the three missing categories were added; the existing ones are all still there' );
nx_assert_same( "Men's Clothing", $cats['mens-clothing'], 'the label is the API\'s name with capitals (Men\'s Clothing)' );
nx_assert_same( 'mens-clothing', Repository::data( Repository::find_by_sku( $A['vendor_id'], 'FS-1' ) )['category'], 'a product is filed under its new category' );
nx_assert_same( 'electronics', Repository::data( Repository::find_by_sku( $A['vendor_id'], 'FS-9' ) )['category'], '... and an existing one under the existing category' );
nx_assert_same( 'Fjallraven - Foldsack No. 1 Backpack, Fits 15 Laptops', Repository::data( Repository::find_by_sku( $A['vendor_id'], 'FS-1' ) )['name'], 'and the name is the title' );
nx_assert_same( 10, Repository::data( Repository::find_by_sku( $A['vendor_id'], 'FS-1' ) )['stock'], 'and the stock is the default 10' );

/* ---------- idempotent re-import ---------- */
$lines_before = preg_split( '/\R/', trim( (string) get_option( Fields::OPTION, '' ) ) );
$again = Importer::plan_api( $A['user_id'], $r );
nx_assert_same( array( 'create' => 0, 'update' => 0, 'unchanged' => 20, 'error' => 0, 'total' => 20 ), $again['summary'], 're-importing the same answer plans nothing to change');
Importer::apply( $A['user_id'], $again );
nx_assert( $before + 20 === $count(), 'no duplicates' );
nx_assert_same( $lines_before, preg_split( '/\R/', trim( (string) get_option( Fields::OPTION, '' ) ) ), 'and no category is added twice' );
$changed = $fixture; $changed[0]['price'] = 120.5; $changed[0]['title'] = 'Backpack v2';
$rc = $fetch( $changed );
$pc = Importer::plan_api( $A['user_id'], $rc );
nx_assert( 1 === $pc['summary']['update'] && 19 === $pc['summary']['unchanged'], 'a changed price and title plan one update' );
$pid = Repository::find_by_sku( $A['vendor_id'], 'FS-1' );
Importer::apply( $A['user_id'], $pc );
nx_assert( $pid === Repository::find_by_sku( $A['vendor_id'], 'FS-1' ) && 'Backpack v2' === Repository::data( $pid )['name'] && '120.50' === Repository::data( $pid )['price'] && $before + 20 === $count(), 'the same id updates the same product (upsert by vendor + external id)' );

/* ---------- categories: create missing OFF ---------- */
foreach ( Repository::ids_for_vendor( $A['vendor_id'] ) as $id ) { Repository::delete( $id ); }
delete_option( Fields::OPTION );
wp_cache_flush();
$roff = $fetch( $fixture, array( 'create_categories' => false ) );
nx_assert( 14 === count( $roff['errors'] ) && 6 === count( $roff['items'] ), 'with creation off, the 14 items whose category is unknown fail and the 6 electronics items stay' );
$e = $roff['errors'][0];
nx_assert( false !== strpos( $e['message'], "men's clothing" ) && false !== stripos( $e['message'], 'category' ) && isset( $e['ref'] ), 'the message names the category in plain words' );
nx_assert( false === strpos( $e['message'], '{' ) && false === strpos( $e['message'], 'Array' ), 'and is not a dump' );
$planoff = Importer::plan_api( $A['user_id'], $roff );
nx_assert_same( array( 'create' => 6, 'update' => 0, 'unchanged' => 0, 'error' => 14, 'total' => 20 ), $planoff['summary'], 'the preview shows 6 to create and 14 errors');
$errs = array_filter( $planoff['rows'], function ( $x ) { return 'error' === $x['action']; } );
nx_assert( 14 === count( $errs ) && false !== strpos( implode( ' ', array_map( function ( $x ) { return implode( ' ', $x['errors'] ); }, $errs ) ), 'jewelery' ), 'and those error rows are in the preview with the message' );
Importer::apply( $A['user_id'], $planoff );
$track( $A );
nx_assert( ! isset( Fields::categories()['mens-clothing'] ) && '' === (string) get_option( Fields::OPTION, '' ), 'nothing was added to the category list' );
nx_assert_same( 6, count( Repository::ids_for_vendor( $A['vendor_id'] ) ), 'only the 6 products were created' );
foreach ( Repository::ids_for_vendor( $A['vendor_id'] ) as $id ) { Repository::delete( $id ); }

/* ---------- categories: hostile and odd values, and limits ---------- */
$odd = array();
foreach ( array( '<script>alert(1)</script>Toys & Co', "Line\nBreak", '', '   ', '12345', str_repeat( 'long ', 30 ), '!!!', 'ünïcödé wear' ) as $i => $cat ) {
	$odd[] = array_merge( $fixture[0], array( 'id' => 100 + $i, 'category' => $cat ) );
}
$odd[] = array_merge( $fixture[0], array( 'id' => 200, 'category' => array( 'x' ) ) );
$odd[] = array_merge( $fixture[0], array( 'id' => 201, 'category' => null ) );
$ro    = $fetch( $odd );
$mo    = $by( $ro );
nx_assert( isset( $mo['100'] ) && false === strpos( $mo['100']['new_category']['label'] ?? '', '<' ) && false === stripos( $mo['100']['new_category']['label'] ?? '', 'alert' ), 'markup in a category name is stripped from the new label' );
foreach ( array( '102', '103', '200', '201', '106' ) as $id ) { nx_assert( ! isset( $mo[ $id ] ), "item $id (empty, blank, not text or no usable name) is an error, not a new category" ); }
nx_assert( count( $ro['errors'] ) >= 5, 'those are reported' );
foreach ( $ro['items'] as $it ) {
	$label = $it['new_category']['label'] ?? '';
	nx_assert( '' === $label || ( mb_strlen( $label ) <= 40 && sanitize_title( $label ) === $it['new_category']['slug'] ), 'every new label is short and its slug is what the category list will derive from it' );
}
$many = array();
for ( $i = 1; $i <= 15; $i++ ) { $many[] = array_merge( $fixture[0], array( 'id' => 300 + $i, 'category' => "brand new category $i" ) ); }
$rm = $fetch( $many );
$new = array_unique( array_filter( array_map( function ( $it ) { return $it['new_category']['slug'] ?? ''; }, $rm['items'] ) ) );
nx_assert( count( $new ) <= 10 && count( $rm['errors'] ) >= 5, 'at most 10 new categories come from one import; the items beyond that fail with a message' );
$planm = Importer::plan_api( $A['user_id'], $rm );
nx_assert_same( 5, $planm['summary']['error'], 'and the preview shows them as errors' );
$cap = array(); for ( $i = 1; $i <= 60; $i++ ) { $cap[] = "Cat $i"; }
update_option( Fields::OPTION, implode( "\n", $cap ) );
$rcap = $fetch( array( array_merge( $fixture[0], array( 'id' => 400, 'category' => 'one more' ) ) ) );
nx_assert( 1 === count( $rcap['errors'] ) && array() === $rcap['items'], 'when the list already has 50 or more categories no more are created' );
delete_option( Fields::OPTION );

/* ---------- the security rules of the other formats still apply ---------- */
$f = $mock( $fixture ); $flush();
$rs = Api_Source::fetch( Api_Source::from_platform( $row( array( 'url' => 'http://fakestoreapi.com/products' ) ) ) );
$rs2 = Api_Source::fetch( Api_Source::from_platform( $row( array( 'url' => 'https://127.0.0.1/products' ) ) ) );
$rs3 = Api_Source::fetch( Api_Source::from_platform( $row( array( 'url' => 'https://user:pw@fakestoreapi.com/products' ) ) ) );
nx_assert( 0 === count( $GLOBALS['nx_http'] ) && '' !== $rs['fetch_error'] && '' !== $rs2['fetch_error'] && '' !== $rs3['fetch_error'] && array() === $rs['items'], 'http, a private address and credentials in the address are refused before any request, with the one generic message' );
$unmock( $f );
$f = $mock( $fixture ); $flush();
$keyed = $row( array( 'auth_type' => 'bearer', 'secret' => \Nexora\Core\Secrets::encrypt( 'TopSecretFakeKey123' ) ) );
$rk = Api_Source::fetch( Api_Source::from_platform( $keyed ) );
nx_assert_same( 'Bearer TopSecretFakeKey123', $GLOBALS['nx_http'][0]['args']['headers']['Authorization'] ?? '', 'a key is sent as configured' );
nx_assert( false === strpos( wp_json_encode( $rk ), 'TopSecretFakeKey123' ), 'and never appears in what fetch() returns' );
$unmock( $f );
$f = $mock( '', 302 ); $flush();
$rr = Api_Source::fetch( Api_Source::from_platform( $row() ) );
$unmock( $f );
nx_assert( '' !== $rr['fetch_error'] && false !== stripos( $rr['fetch_error'], 'redirect' ), 'a redirect is not followed' );
$src = file_get_contents( NEXORA_PATH . 'src/Imports/Api_Source.php' );
nx_assert( 1 === substr_count( $src, 'wp_remote_get(' ) && false === strpos( $src, 'file_get_contents' ) && false === strpos( $src, 'curl_exec' ), 'there is still exactly one place that makes a request' );

/* ---------- a vendor's own API is never a Fake Store source ---------- */
$vendor_src = array( 'key' => 'c-1', 'name' => 'Mine', 'url' => 'https://fakestoreapi.com/products', 'format' => 'fakestore', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'kind' => 'vendor', 'host_allowlist' => array(), 'stock_default' => 10, 'create_categories' => true );
$f = $mock( $fixture ); $flush();
$rv = Api_Source::fetch( $vendor_src );
$unmock( $f );
nx_assert( array() === $rv['items'] && '' !== $rv['fetch_error'] && 0 === count( $GLOBALS['nx_http'] ), 'a vendor-owned API cannot use the Fake Store format (and can never create categories): refused before any request' );
nx_assert( 1 === preg_match( "/'format'\\s*=>\\s*'nexora'/", file_get_contents( NEXORA_PATH . 'src/Imports/Connections.php' ) ), 'vendor-owned connections are built with the Nexora format only' );

nx_test_finish();
