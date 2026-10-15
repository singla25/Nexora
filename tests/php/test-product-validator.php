<?php
// Marketplace Phase 4: the shared product validator (manual, CSV and API all use it). Pure: writes nothing.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Products\Fields;
use Nexora\Products\Validator;

$ok = array(
	'name'        => 'Cotton Kurta',
	'description' => 'Soft cotton, hand block printed.',
	'price'       => '1299.50',
	'stock'       => '25',
	'sku'         => 'KURTA-001',
	'category'    => 'fashion',
	'image_id'    => '0',
);
$run = function ( array $over = array(), $unset = array() ) use ( $ok ) {
	$in = array_merge( $ok, $over );
	foreach ( (array) $unset as $k ) { unset( $in[ $k ] ); }
	return Validator::validate( $in );
};

nx_assert_same( array( 'name', 'description', 'price', 'stock', 'sku', 'category', 'image_id' ), Fields::keys(), 'the product fields, in a fixed order' );
nx_assert( isset( Fields::categories()['fashion'] ) && isset( Fields::categories()['other'] ), 'the category list has the defaults' );
nx_assert_same( array( 'electronics', 'fashion', 'home-kitchen', 'beauty', 'groceries', 'books', 'toys', 'sports', 'other' ), array_keys( Fields::categories() ), 'the default categories (a fixed list)' );

/* ---------- valid ---------- */
$r = $run();
nx_assert_same( array(), $r['errors'], 'a valid product has no errors' );
nx_assert_same( array( 'name' => 'Cotton Kurta', 'description' => 'Soft cotton, hand block printed.', 'price' => '1299.50', 'stock' => 25, 'sku' => 'KURTA-001', 'category' => 'fashion', 'image_id' => 0 ), $r['data'], 'data comes back cleaned and typed (stock and image_id are integers, price has two decimals)' );

/* ---------- required fields ---------- */
foreach ( array( 'name', 'price', 'stock', 'sku', 'category' ) as $k ) {
	nx_assert( isset( $run( array(), array( $k ) )['errors'][ $k ] ), "missing $k is reported" );
	nx_assert( isset( $run( array( $k => '   ' ) )['errors'][ $k ] ), "blank $k is rejected" );
	nx_assert( isset( $run( array( $k => array( 'x' ) ) )['errors'][ $k ] ), "array given for $k is rejected without a notice" );
}
nx_assert( empty( $run( array(), array( 'description', 'image_id' ) )['errors'] ), 'description and image are optional' );
nx_assert_same( '', $run( array( 'description' => '' ) )['data']['description'], 'an empty description is kept empty' );

/* ---------- price ---------- */
foreach ( array( '0.01' => '0.01', '1' => '1.00', '1000000' => '1000000.00', '999999.99' => '999999.99', '12.5' => '12.50', ' 99 ' => '99.00' ) as $in => $out ) {
	$r = $run( array( 'price' => $in ) );
	nx_assert( empty( $r['errors'] ) && $out === $r['data']['price'], "price '$in' is accepted as $out" );
}
foreach ( array( '0', '0.00', '0.001', '-5', '1000000.01', '10000000', '1e3', 'abc', '1,299', '12.345', '.5', '5.', 'NaN', 'INF', '१२३', '0x1F', '1 2' ) as $bad ) {
	nx_assert( isset( $run( array( 'price' => $bad ) )['errors']['price'] ), "price rejected: " . wp_json_encode( $bad ) );
}

/* ---------- stock ---------- */
foreach ( array( '0' => 0, '1' => 1, '100000' => 100000, ' 7 ' => 7 ) as $in => $out ) {
	$r = $run( array( 'stock' => $in ) );
	nx_assert( empty( $r['errors'] ) && $out === $r['data']['stock'], "stock '$in' is accepted as $out" );
}
foreach ( array( '-1', '100001', '1.5', 'ten', '1e2', '', '0x10', '999999999999999999999' ) as $bad ) {
	nx_assert( isset( $run( array( 'stock' => $bad ) )['errors']['stock'] ), 'stock rejected: ' . wp_json_encode( $bad ) );
}

/* ---------- name, description, sku, category ---------- */
nx_assert( isset( $run( array( 'name' => 'A' ) )['errors']['name'] ), 'name under 2 characters rejected' );
nx_assert( isset( $run( array( 'name' => str_repeat( 'a', 121 ) ) )['errors']['name'] ), 'name over 120 characters rejected' );
nx_assert( empty( $run( array( 'name' => str_repeat( 'é', 120 ) ) )['errors'] ), 'the name limit counts characters, not bytes' );
nx_assert( isset( $run( array( 'description' => str_repeat( 'a', 2001 ) ) )['errors']['description'] ), 'description over 2,000 characters rejected' );
nx_assert( empty( $run( array( 'description' => str_repeat( 'a', 2000 ) ) )['errors'] ), 'description of exactly 2,000 accepted' );
foreach ( array( 'ab c', 'sku/1', 'ß-1', 'a', str_repeat( 'a', 41 ), '<b>', "A\n1" ) as $bad ) {
	nx_assert( isset( $run( array( 'sku' => $bad ) )['errors']['sku'] ), 'sku rejected: ' . wp_json_encode( $bad ) );
}
$r = $run( array( 'sku' => ' kurta-001 ' ) );
nx_assert( empty( $r['errors'] ) && 'KURTA-001' === $r['data']['sku'], 'sku is trimmed and upper-cased (so KURTA-001 and kurta-001 are the same product)' );
foreach ( array( 'Fashion', 'cars', 'administrator', '../etc', '' ) as $bad ) {
	nx_assert( isset( $run( array( 'category' => $bad ) )['errors']['category'] ), 'category outside the list rejected: ' . wp_json_encode( $bad ) );
}
add_filter( 'nexora_product_categories', function ( $c ) { $c['gadgets'] = 'Gadgets'; return $c; } );
nx_assert( empty( $run( array( 'category' => 'gadgets' ) )['errors'] ), 'the category list comes from a setting / filter' );
remove_all_filters( 'nexora_product_categories' );

/* ---------- image id ---------- */
nx_assert_same( 42, $run( array( 'image_id' => '42' ) )['data']['image_id'], 'image id is an integer' );
foreach ( array( '-1', 'abc', '1.5', '99999999999999999999' ) as $bad ) {
	nx_assert( isset( $run( array( 'image_id' => $bad ) )['errors']['image_id'] ), 'image id rejected: ' . wp_json_encode( $bad ) );
}

/* ---------- hostile input ---------- */
$r = $run( array( 'name' => '<script>alert(1)</script>Silk <b>Saree</b>', 'description' => "<img src=x onerror=alert(1)>Line one\nLine two<iframe src=//evil></iframe>" ) );
nx_assert( false === strpos( $r['data']['name'], '<' ) && false === stripos( $r['data']['name'], 'alert' ), 'markup and script contents are stripped from the name' );
nx_assert( false === strpos( $r['data']['description'], '<' ) && false === stripos( $r['data']['description'], 'onerror' ), 'markup is stripped from the description' );
nx_assert( false !== strpos( $r['data']['description'], "Line one\nLine two" ), 'a description keeps its line breaks' );
$r = $run( array( 'name' => "Line1\r\nBcc: x@example.test" ) );
nx_assert( false === strpbrk( $r['data']['name'], "\r\n" ), 'line breaks are flattened in the name' );
$r = Validator::validate( $ok + array( 'vendor_id' => 99, 'source' => 'api', 'user_id' => 1, 'post_status' => 'trash', 'id' => 5, 'external_id' => 'x' ) );
nx_assert_same( array_keys( $ok ), array_keys( $r['data'] ), 'unknown keys (vendor_id, source, status, id) never reach the data' );
nx_assert_same( array( 'description' => '', 'image_id' => 0 ), Validator::validate( array() )['data'], 'an empty submission yields only the optional fields, empty' );
nx_assert_same( 5, count( Validator::validate( array() )['errors'] ), 'an empty submission reports the five required fields' );

/* ---------- partial ---------- */
$r = Validator::validate( array( 'price' => '10' ), array( 'price' ) );
nx_assert( empty( $r['errors'] ) && array( 'price' => '10.00' ) === $r['data'], 'partial validation checks only the named keys' );

nx_test_finish();
