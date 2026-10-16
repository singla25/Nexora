<?php
// Marketplace Phase 5: the import endpoints (preview / import for CSV and API): gating, tokens, rate limits, history.
// The file upload part of the CSV endpoints is exercised over real HTTP in test-import-http.php.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Importer;
use Nexora\Imports\Log_Repository;
use Nexora\Products\Repository;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();
add_filter( 'nexora_resolve_host', function () { return array( '93.184.216.34' ); } ); // no real DNS in tests

$A = nx_test_vendor( 'iaa', 'approved' );
$B = nx_test_vendor( 'iab', 'approved' );
$P = nx_test_vendor( 'iap', 'pending' );
$S = nx_test_vendor( 'ias', 'suspended' );
$C = nx_test_user( 'iac' );
$admin = nx_test_admin();
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$track = function ( $v ) { foreach ( Repository::ids_for_vendor( $v['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } };

$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$forbidden = function ( $r ) { return nx_rejected( $r ) && is_array( $r['data'] ?? null ) && 'forbidden' === ( $r['data']['code'] ?? '' ); };

$item = function ( $id ) { return array( 'id' => $id, 'title' => "Imported $id", 'description' => 'd', 'category' => 'beauty', 'price' => 5.5, 'stock' => 7, 'sku' => "IA-$id", 'thumbnail' => '' ); };
$mock = function ( array $items ) {
	$f = function () use ( $items ) { return array( 'headers' => array(), 'body' => wp_json_encode( array( 'products' => $items, 'total' => count( $items ) ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null ); };
	add_filter( 'pre_http_request', $f, 10, 3 );
	return $f;
};
$flush = function () { nx_test_flush_api_cache(); };

/* ---------- gating: all four actions ---------- */
foreach ( array( 'nexora_csv_preview', 'nexora_csv_import', 'nexora_api_preview', 'nexora_api_import' ) as $action ) {
	nx_assert( nx_rejected( $call( 0, $action ) ), "$action: a guest is rejected" );
	nx_assert( nx_rejected( $call( $A['user_id'], $action, array(), false ) ), "$action: no nonce is rejected" );
	foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor' => $S['user_id'] ) as $label => $uid ) {
		nx_assert( $forbidden( $call( $uid, $action, array( 'token' => str_repeat( 'a', 32 ) ) ) ), "$action: $label is forbidden" );
	}
	nx_assert( nx_rejected( $call( $admin, $action ) ), "$action: an administrator (not a seller) is rejected" );
}

/* ---------- API preview / import with a token ---------- */
$f = $mock( array( $item( 1 ), $item( 2 ), $item( 3 ) ) );
$flush();
$before = $count();
$r = $call( $A['user_id'], 'nexora_api_preview' );
nx_assert( ! empty( $r['success'] ) && 3 === $r['data']['summary']['create'] && 0 === $r['data']['summary']['error'], 'api preview: reports three creates' );
nx_assert( is_string( $r['data']['token'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $r['data']['token'] ), 'api preview: returns a 32-character token' );
nx_assert_same( $before, $count(), 'api preview: writes no product' );
nx_assert( 3 === count( $r['data']['rows'] ) && isset( $r['data']['rows'][0]['ref'], $r['data']['rows'][0]['action'], $r['data']['rows'][0]['sku'] ), 'api preview: lists the rows (reference, action, sku)' );
$token = $r['data']['token'];
$json  = wp_json_encode( $r );
nx_assert( false === strpos( $json, 'raw' ) && false === strpos( $json, 'vendor_id' ) && false === strpos( $json, '"user_id"' ), 'api preview: the reply carries no internal data (vendor_id, raw rows)' );

nx_assert( $forbidden( $call( $B['user_id'], 'nexora_api_import', array( 'token' => $token ) ) ) || nx_rejected( $call( $B['user_id'], 'nexora_api_import', array( 'token' => $token ) ) ), "api import: another vendor cannot use this vendor's token" );
nx_assert_same( $before, $count(), "api import: and nothing was created by their attempt" );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_import', array( 'token' => 'garbage' ) ) ) && nx_rejected( $call( $A['user_id'], 'nexora_api_import', array() ) ) && nx_rejected( $call( $A['user_id'], 'nexora_api_import', array( 'token' => array( 'x' ) ) ) ), 'api import: a missing, garbage or array token is rejected cleanly' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_csv_import', array( 'token' => $token ) ) ), "csv import: an API token cannot be used on the CSV action" );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $token ) );
$track( $A );
nx_assert( ! empty( $r['success'] ) && 3 === $r['data']['summary']['created'], 'api import: the token applies the previewed plan' );
nx_assert_same( $before + 3, $count(), 'api import: three products now exist' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_import', array( 'token' => $token ) ) ) && $before + 3 === $count(), 'api import: a token works once' );
$hist = Log_Repository::for_vendor( $A['vendor_id'], 10 );
nx_assert( 1 === count( $hist ) && 'api' === $hist[0]['source'] && 3 === $hist[0]['created'], 'the import is in the history' );
nx_assert_same( array(), Log_Repository::for_vendor( $B['vendor_id'], 10 ), "and not in another vendor's history" );

// Expired token.
$r = $call( $A['user_id'], 'nexora_api_preview' );
$tok = $r['data']['token'];
delete_transient( 'nexora_import_' . $tok );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_import', array( 'token' => $tok ) ) ), 'api import: an expired token is rejected' );

// The plan is rechecked when applied: a vendor suspended between preview and import cannot apply it.
$r   = $call( $B['user_id'], 'nexora_api_preview' );
$tok = $r['data']['token'];
wp_set_current_user( $admin );
\Nexora\Vendors\Service::change_status( $B['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
$n = $count();
nx_assert( $forbidden( $call( $B['user_id'], 'nexora_api_import', array( 'token' => $tok ) ) ) && $n === $count(), 'api import: a vendor suspended after the preview cannot apply it' );
wp_set_current_user( $admin );
\Nexora\Vendors\Service::change_status( $B['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );
remove_filter( 'pre_http_request', $f, 10 );

/* ---------- upstream failure through the endpoint ---------- */
$bad = function () { return new WP_Error( 'http_request_failed', 'cURL error 28: secret internal detail' ); };
add_filter( 'pre_http_request', $bad, 10, 3 );
$flush();
$r = $call( $A['user_id'], 'nexora_api_preview' );
remove_filter( 'pre_http_request', $bad, 10 );
nx_assert( ! empty( $r['success'] ) && '' !== (string) ( $r['data']['fetch_error'] ?? '' ) && 0 === $r['data']['summary']['create'], 'api preview: an upstream failure is reported, not a crash' );
nx_assert( false === strpos( wp_json_encode( $r ), 'secret internal detail' ), 'api preview: the raw transport error is not shown' );
nx_assert( empty( $r['data']['token'] ) || 0 === $r['data']['summary']['create'], 'api preview: nothing to import means nothing to confirm' );

/* ---------- csv endpoints without a file ---------- */
$r = $call( $A['user_id'], 'nexora_csv_preview' );
nx_assert( nx_rejected( $r ) && is_array( $r['data'] ) && ! empty( $r['data']['message'] ), 'csv preview: no file gives a clear error' );
$tok = Importer::stash( $A['user_id'], Importer::plan_csv( $A['user_id'], array( array( 'line' => 2, 'cells' => array( 'sku' => 'CSV-T1', 'name' => 'Token csv', 'description' => '', 'price' => '10', 'stock' => '1', 'category' => 'other' ) ) ), 'file.csv' ) );
nx_assert( is_string( $tok ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $tok ), 'a plan can be stashed under a token' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_import', array( 'token' => $tok ) ) ), 'api import: a CSV token cannot be used on the API action' );
$r = $call( $A['user_id'], 'nexora_csv_import', array( 'token' => $tok ) );
$track( $A );
nx_assert( ! empty( $r['success'] ) && 1 === $r['data']['summary']['created'] && Repository::find_by_sku( $A['vendor_id'], 'CSV-T1' ) > 0, 'csv import: the stashed plan applies' );
$hist = Log_Repository::for_vendor( $A['vendor_id'], 10 );
nx_assert( 'csv' === $hist[0]['source'] && 'file.csv' === $hist[0]['label'], 'the history keeps the file name of a CSV import' );

/* ---------- rate limits ---------- */
$f = $mock( array( $item( 9 ) ) );
add_filter( 'nexora_rate_limits', function ( $l ) { $l['api_import'] = array( 2, HOUR_IN_SECONDS ); $l['csv_import'] = array( 2, HOUR_IN_SECONDS ); return $l; } );
nx_test_reset_limits();
$R = nx_test_vendor( 'iar', 'approved' );
wp_set_current_user( $R['user_id'] );
$res = array();
$csvr = array();
for ( $i = 0; $i < 3; $i++ ) { $res[] = nx_call_ajax( 'nexora_api_preview', nx_profile_nonce() ); }
for ( $i = 0; $i < 3; $i++ ) { $csvr[] = nx_call_ajax( 'nexora_csv_preview', nx_profile_nonce() ); }
wp_set_current_user( 0 );
nx_assert( ! empty( $res[0]['success'] ) && ! empty( $res[1]['success'] ) && nx_rejected( $res[2] ) && false !== stripos( (string) ( $res[2]['data']['message'] ?? '' ), 'too many' ), 'rate limit: the 3rd API preview in the window is refused' );
nx_assert( false !== stripos( (string) ( $csvr[2]['data']['message'] ?? '' ), 'too many' ), 'rate limit: the 3rd CSV preview in the window is refused' );
remove_all_filters( 'nexora_rate_limits' );
remove_filter( 'pre_http_request', $f, 10 );
nx_test_reset_limits();

/* ---------- history is guarded like the other private post types ---------- */
$log = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nexora_import_log' ORDER BY ID DESC LIMIT 1" );
wp_set_current_user( $admin );
wp_trash_post( (int) $log );
wp_delete_post( (int) $log, true );
$forged = wp_insert_post( array( 'post_type' => 'nexora_import_log', 'post_title' => 'forged', 'post_status' => 'publish' ) );
wp_set_current_user( 0 );
nx_assert( null !== get_post( (int) $log ) && 'publish' === get_post_status( (int) $log ), 'a history entry cannot be trashed or deleted from outside' );
nx_assert( ! $forged || is_wp_error( $forged ), 'and cannot be forged from outside' );
nx_assert( ! current_user_can( get_post_type_object( 'nexora_import_log' )->cap->create_posts ), 'there is no Add New for history entries' );

$flush(); // leave no mocked catalog behind in the real cache
nx_test_finish();
