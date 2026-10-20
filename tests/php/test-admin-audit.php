<?php
// Marketplace Phase 7b: the change history. An append-only table (object type, object id, user, field, old value, new value, time) that
// survives the deletion of the object it is about, created through Database\Migrations, shown as a read-only "Change history" box. Secrets
// and keys are never recorded: for them only the fact that the field changed.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\History_Box;
use Nexora\Database\Migrations;
use Nexora\Database\Uninstaller;

global $wpdb;
$table = $wpdb->prefix . 'nexora_audit';
$count = function () use ( $wpdb, $table ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); };

/* ---------- the table: created by the migrations, listed for uninstall ---------- */
nx_assert( version_compare( Migrations::DB_VERSION, '1.1.0', '>' ), 'DB_VERSION was bumped (' . Migrations::DB_VERSION . ') so the table is created on upgrade' );
Migrations::sync_schema();
nx_assert_same( $table, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'the nexora_audit table exists after the migrations ran' );
$cols = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
foreach ( array( 'object_type', 'object_id', 'user_id', 'field', 'old_value', 'new_value', 'created_at' ) as $c ) { nx_assert( in_array( $c, $cols, true ), "it has the column $c" ); }
nx_assert( in_array( $table, Uninstaller::defaults()['tables'], true ), 'the uninstaller lists it (dropped only when the owner asked to delete the data)' );

/* ---------- recording ---------- */
$admin = nx_test_admin();
wp_set_current_user( $admin );
$tag = 'nxaud_' . wp_generate_password( 6, false, false );
$id  = Audit::record( 'nexora_product', 987654, 'price', '10.00', '12.50' );
nx_assert( $id > 0, 'record() stores a row and returns its id' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
nx_assert_same( array( 'nexora_product', '987654', (string) $admin, 'price', '10.00', '12.50' ), array( $row['object_type'], $row['object_id'], $row['user_id'], $row['field'], $row['old_value'], $row['new_value'] ), 'the row has the type, the object, WHO (the logged-in administrator), the field and both values' );
nx_assert( $row['created_at'] && abs( strtotime( $row['created_at'] . ' UTC' ) - time() ) < 300, 'and the time' );
Audit::record( 'nexora_product', 987654, 'stock', '5', '9' );
$rows = Audit::rows( 'nexora_product', 987654 );
nx_assert_same( array( 'stock', 'price' ), array_column( $rows, 'field' ), 'rows() lists a history newest first' );
nx_assert_same( array(), Audit::rows( 'nexora_product', 111 ), 'and nothing for another object' );
nx_assert_same( array(), Audit::rows( 'nexora_seller', 987654 ), '... or the same id of another type' );
$long = str_repeat( 'x', 5000 );
Audit::record( 'nexora_product', 987654, 'description', 'short', $long );
$d = Audit::rows( 'nexora_product', 987654 )[0];
nx_assert( strlen( $d['new_value'] ) <= 2100 && 'short' === $d['old_value'], 'a very long value is cut, not stored whole' );
nx_assert_same( 0, Audit::record( 'bad type!', 1, 'f', 'a', 'b' ), 'a malformed object type is refused' );
nx_assert_same( 0, Audit::record( 'nexora_product', 0, 'f', 'a', 'b' ), '... and so is no object' );
nx_assert_same( 0, Audit::record( 'nexora_product', 1, '', 'a', 'b' ), '... and no field' );
nx_assert_same( 0, Audit::record( 'nexora_product', 1, 'price', '10', '10' ), 'a change from a value to the same value is not a change, so it is not recorded' );
nx_assert( Audit::record( 'nexora_product', 987654, 'description', 'it\'s <b>&</b> "quoted"', 'x' ) > 0, 'quotes and markup are stored safely (prepared statements)' );

/* ---------- append-only ---------- */
$methods = array_map( function ( $m ) { return strtolower( $m->getName() ); }, ( new ReflectionClass( Audit::class ) )->getMethods() );
$bad     = array_filter( $methods, function ( $m ) { return preg_match( '/update|delete|remove|clear|trunc|purge|erase|edit|reset|prune/', $m ); } );
nx_assert_same( array(), array_values( $bad ), 'the Audit class has no method that changes or removes a row' );
$src = file_get_contents( NEXORA_PATH . 'src/Admin_Edit/Audit.php' );
nx_assert( 0 === preg_match( '/\bUPDATE\s|\bDELETE\s+FROM\b|\bTRUNC[A]TE\b|->update\(|->delete\(|->replace\(/i', $src ), 'and its code contains no statement that changes or removes a row' );

/* ---------- it survives the deletion of the object ---------- */
$S    = nx_test_vendor( 'auds', 'approved' );
$sid  = $S['vendor_id'];
Audit::record( \Nexora\Core\Post_Types::CUST_SELLER, $sid, 'business_name', 'Old Name', 'New Name' );
$before = count( Audit::rows( \Nexora\Core\Post_Types::CUST_SELLER, $sid ) );
\Nexora\Vendors\Repository::delete( $sid );
nx_assert_same( null, get_post( $sid ), 'setup: the seller record is deleted' );
nx_assert_same( $before, count( Audit::rows( \Nexora\Core\Post_Types::CUST_SELLER, $sid ) ), 'its change history is still there' );

/* ---------- secrets and keys are never recorded ---------- */
$secrets = array( 'secret', 'api_key', 'apikey', 'auth_header', 'token', 'access_token', 'reset_token', 'password', 'passwd', 'bank_account', 'bank_account_number', 'ifsc', 'iban', 'swift', 'account_number', 'card_number', 'aadhaar_card', 'driving_license', 'company_id_card', 'private_key', 'client_secret', '_nx_secret' );
foreach ( $secrets as $field ) {
	$sid2 = Audit::record( 'nexora_api_conn', 424242, $field, 'OLDVALUE-sk_live_AAAA1111', 'NEWVALUE-sk_live_BBBB2222' );
	$r    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $sid2 ), ARRAY_A );
	nx_assert( $sid2 > 0 && $field === $r['field'] && false === strpos( wp_json_encode( $r ), 'sk_live' ) && false === strpos( wp_json_encode( $r ), 'VALUE' ), "'$field': the change is recorded, the values are not" );
	nx_assert( Audit::is_secret_field( $field ), "'$field' is a secret field" );
}
foreach ( array( 'price', 'stock', 'sku', 'business_name', 'gstin', 'status', 'first_name', 'company_email', 'bio', 'category', 'monkey' ) as $field ) {
	nx_assert( ! Audit::is_secret_field( $field ), "'$field' is an ordinary field and its values are recorded" );
}
$ok = Audit::record( 'nexora_product', 55, 'sku', 'AAA', 'BBB' );
nx_assert_same( array( 'AAA', 'BBB' ), array_values( array_intersect_key( $wpdb->get_row( $wpdb->prepare( "SELECT old_value,new_value FROM $table WHERE id = %d", $ok ), ARRAY_A ), array( 'old_value' => 1, 'new_value' => 1 ) ) ), 'an ordinary field keeps both values' );
$all = wp_json_encode( $wpdb->get_results( "SELECT * FROM $table WHERE object_id IN (424242)", ARRAY_A ) );
nx_assert( false === strpos( $all, 'sk_live' ), 'nothing resembling the secret values is anywhere in the table' );

/* ---------- the read-only "Change history" box ---------- */
$p = nx_test_product( nx_test_vendor( 'audp', 'approved' ), 'AU-1', '10.00', '3' );
Audit::record( 'nexora_product', $p, 'price', '10.00', '<script>alert(1)</script>' );
Audit::record( 'nexora_product', $p, 'secret', 'a', 'b' );
ob_start();
History_Box::render( get_post( $p ) );
$html = ob_get_clean();
nx_assert( false !== strpos( $html, 'price' ) && false !== strpos( $html, '10.00' ), 'the box lists the field and its old and new value' );
nx_assert( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ), 'values are escaped' );
nx_assert( 0 === preg_match( '/<(input|textarea|select|button|form)\b/i', $html ), 'it has no form controls: read-only' );
nx_assert( false !== strpos( $html, wp_get_current_user()->display_name ) || false !== strpos( $html, 'user' ), 'and says who changed it' );
nx_assert( false !== strpos( $html, 'secret' ) && false !== strpos( $html, '[value not recorded]' ), 'a secret field shows that it changed, never its value' );
ob_start(); History_Box::render( get_post( nx_test_product( nx_test_vendor( 'audq', 'approved' ), 'AU-2', '5.00', '1' ) ) ); $empty = ob_get_clean();
nx_assert( false !== stripos( $empty, 'no changes' ), 'a post with no history says so' );

/* ---------- it is on the edit screens of the six record types ---------- */
global $wp_meta_boxes;
$wp_meta_boxes = array();
do_action( 'add_meta_boxes', 'nexora_product', get_post( $p ) );
foreach ( array( 'nexora_customer', 'nexora_seller', 'nexora_cust_seller', 'nexora_product', 'user_connections', 'user_content' ) as $type ) {
	$wp_meta_boxes = array();
	do_action( 'add_meta_boxes', $type, null );
	$found = false;
	foreach ( (array) ( $wp_meta_boxes[ $type ] ?? array() ) as $ctx ) { foreach ( (array) $ctx as $prio ) { if ( isset( $prio['nexora_change_history'] ) ) { $found = true; } } }
	nx_assert( $found, "the Change history box is on the $type edit screen" );
}

nx_test_finish();
