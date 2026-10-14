<?php
// Marketplace Phase 2: the business-details validator (pure: no database writes).
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Vendors\Validator;
use Nexora\Vendors\Fields;

$ok = array(
	'business_name'    => 'Sharma Textiles',
	'business_type'    => 'sole_proprietorship',
	'gstin'            => '27AAPFU0939F1ZV',
	'business_email'   => 'shop@example.test',
	'business_phone'   => '9876543210',
	'business_address' => '12 MG Road',
	'business_city'    => 'Pune',
	'business_state'   => 'Maharashtra',
	'business_pincode' => '411001',
);

$run = function ( array $over = array(), $unset = array() ) use ( $ok ) {
	$in = array_merge( $ok, $over );
	foreach ( (array) $unset as $k ) { unset( $in[ $k ] ); }
	return Validator::validate( $in );
};

/* ---------- the field list ---------- */
nx_assert_same( array_keys( $ok ), Fields::keys(), 'the nine business fields, in a fixed order' );
nx_assert_same( array( 'sole_proprietorship', 'partnership', 'llp', 'private_limited', 'other' ), array_keys( Fields::business_types() ), 'business types are a fixed list' );

/* ---------- valid ---------- */
$r = $run();
nx_assert_same( array(), $r['errors'], 'a fully valid application has no errors' );
nx_assert_same( $ok, $r['data'], 'valid data comes back unchanged' );

/* ---------- required ---------- */
foreach ( array_keys( $ok ) as $k ) {
	$r = $run( array(), array( $k ) );
	nx_assert( isset( $r['errors'][ $k ] ) && 1 === count( $r['errors'] ), "missing $k is reported on that field only" );
	$r = $run( array( $k => '   ' ) );
	nx_assert( isset( $r['errors'][ $k ] ), "blank $k is rejected" );
	$r = $run( array( $k => array( 'x' ) ) );
	nx_assert( isset( $r['errors'][ $k ] ), "array given for $k is rejected, no notice" );
}

/* ---------- GSTIN ---------- */
$r = $run( array( 'gstin' => ' 27aapfu0939f1zv ' ) );
nx_assert( empty( $r['errors'] ) && '27AAPFU0939F1ZV' === $r['data']['gstin'], 'GSTIN is trimmed and upper-cased' );
foreach ( array( '27AAPFU0939F1Z', '27AAPFU0939F1ZVV', 'AAAAAAAAAAAAAAA', '27AAPFU0939F0ZV', '27AAPFU0939F1AV', '2AAAPFU0939F1ZV', '27AAPFU09391FZV', '27AAPF-0939F1ZV', "27AAPFU0939F1ZV\n1" ) as $bad ) {
	$r = $run( array( 'gstin' => $bad ) );
	nx_assert( isset( $r['errors']['gstin'] ), 'invalid GSTIN rejected: ' . wp_json_encode( $bad ) );
}

/* ---------- email / phone / pincode / type ---------- */
foreach ( array( 'nope', 'a@b', '<script>@x.test' ) as $bad ) {
	nx_assert( isset( $run( array( 'business_email' => $bad ) )['errors']['business_email'] ), 'bad business email rejected: ' . $bad );
}
$r = $run( array( 'business_email' => 'A@Example.TEST' ) );
nx_assert( empty( $r['errors'] ), 'a business email may differ from the account email and may have capitals' );
foreach ( array( '98765', 'abcdefghij', '98765 43210 99999', '+91 98765 43210', '12345678901234' ) as $phone ) {
	$r = $run( array( 'business_phone' => $phone ) );
	$valid = in_array( $phone, array( '+91 98765 43210' ), true );
	nx_assert( $valid ? empty( $r['errors'] ) : isset( $r['errors']['business_phone'] ), 'phone ' . $phone . ( $valid ? ' accepted' : ' rejected' ) );
}
$r = $run( array( 'business_phone' => '+91 98765 43210' ) );
nx_assert_same( '+919876543210', $r['data']['business_phone'], 'phone is stored without spaces' );
foreach ( array( '41100', '4110011', '011001', 'abcdef', '41 1001' ) as $bad ) {
	nx_assert( isset( $run( array( 'business_pincode' => $bad ) )['errors']['business_pincode'] ), 'bad pincode rejected: ' . $bad );
}
foreach ( array( 'company', 'administrator', 'SOLE_PROPRIETORSHIP', '' ) as $bad ) {
	nx_assert( isset( $run( array( 'business_type' => $bad ) )['errors']['business_type'] ), 'business type outside the list rejected: ' . $bad );
}

/* ---------- length limits ---------- */
nx_assert( isset( $run( array( 'business_name' => 'A' ) )['errors']['business_name'] ), 'business name under 2 characters rejected' );
nx_assert( isset( $run( array( 'business_name' => str_repeat( 'a', 101 ) ) )['errors']['business_name'] ), 'business name over 100 characters rejected' );
nx_assert( empty( $run( array( 'business_name' => str_repeat( 'é', 100 ) ) )['errors'] ), 'business name limit counts characters, not bytes' );
nx_assert( isset( $run( array( 'business_address' => str_repeat( 'a', 201 ) ) )['errors']['business_address'] ), 'address over 200 characters rejected' );
nx_assert( isset( $run( array( 'business_city' => str_repeat( 'a', 61 ) ) )['errors']['business_city'] ), 'city over 60 characters rejected' );

/* ---------- hostile input ---------- */
$r = $run( array( 'business_name' => '<script>alert(1)</script>Sharma <b>Textiles</b>', 'business_address' => '<img src=x onerror=alert(1)>12 MG Road' ) );
nx_assert( false === strpos( $r['data']['business_name'], '<' ) && false === strpos( $r['data']['business_address'], '<' ), 'HTML is stripped from stored values' );
nx_assert( false === stripos( $r['data']['business_name'], 'alert' ), 'script contents are removed, not just the tags' );
$r = $run( array( 'business_name' => "Line1\r\nBcc: evil@example.test" ) );
nx_assert( false === strpos( $r['data']['business_name'], "\n" ) && false === strpos( $r['data']['business_name'], "\r" ), 'line breaks are flattened (no header injection into mail subjects)' );
$r = Validator::validate( $ok + array( 'role' => 'administrator', 'status' => 'approved', 'user_id' => 1, 'vendor_status' => 'approved' ) );
nx_assert_same( array_keys( $ok ), array_keys( $r['data'] ), 'unknown keys (role, status, user_id) never reach the data' );
nx_assert_same( array(), Validator::validate( array() )['data'], 'an empty submission yields no data (only valid fields are returned)' );
nx_assert_same( count( $ok ), count( Validator::validate( array() )['errors'] ), 'an empty submission reports every field' );

/* ---------- partial validation (profile edits) ---------- */
$r = Validator::validate( array( 'business_phone' => '9123456780' ), array( 'business_phone' ) );
nx_assert( empty( $r['errors'] ) && array( 'business_phone' => '9123456780' ) === $r['data'], 'partial validation checks only the named keys' );
$r = Validator::validate( array( 'business_phone' => '1' ), array( 'business_phone' ) );
nx_assert( isset( $r['errors']['business_phone'] ), 'partial validation still rejects a bad value' );

nx_test_finish();
