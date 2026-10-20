<?php
// Marketplace Phase 7b: an administrator edits the account records (customer, direct seller, customer-seller). Every value goes through the
// same validators as the front end, status changes run the same logic as the approve/suspend/reject buttons, links and owner stay read-only,
// every change is recorded, and only a genuine admin edit-screen request by manage_options gets in.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\Fields;
use Nexora\Admin_Edit\Saver;
use Nexora\Core\Post_Types;
use Nexora\Vendors\Repository as Sellers;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$C  = nx_test_user( 'aeca' );                 // a customer
$S  = nx_test_vendor( 'aecs', 'pending' );     // a customer who applied (nexora_cust_seller, pending)
$S2 = nx_test_vendor( 'aect', 'approved' );    // another customer-seller (GSTIN uniqueness)
$D  = nx_test_direct_seller( 'aecd', 'pending' ); // a direct seller (nexora_seller)
$img = nx_test_attachment( $admin, 'aec-img' );
$meta  = function ( $id, $key ) { clean_post_cache( $id ); return get_post_meta( $id, $key, true ); };
$rows  = function ( $type, $id ) { return Audit::rows( $type, $id ); };
$edit  = function ( $id, array $input ) { return Saver::save( $id, $input ); };
$roles = function ( $uid ) { $u = get_userdata( $uid ); return $u ? array_values( $u->roles ) : array(); };

/* ---------- what is editable and what is not, per record type (pinned) ---------- */
nx_assert_same( array( 'nexora_customer', 'nexora_seller', 'nexora_cust_seller', 'nexora_product', 'user_connections', 'user_content' ), Fields::types(), 'the six record types have admin field groups' );
$pins = array(
	'nexora_customer' => array(
		'edit' => array( 'first_name', 'last_name', 'phone', 'gender', 'birthdate', 'linkedin_id', 'bio', 'perm_address', 'perm_city', 'perm_state', 'perm_pincode', 'corr_address', 'corr_city', 'corr_state', 'corr_pincode', 'company_name', 'designation', 'company_email', 'company_phone', 'company_address', 'profile_image', 'cover_image' ),
		'readonly' => array( 'user_name', 'email', '_wp_user_id', 'seller_post_id', 'aadhaar_card', 'driving_license', 'company_id_card' ),
	),
	'nexora_seller' => array(
		'edit' => array( 'first_name', 'last_name', 'phone', 'gender', 'birthdate', 'business_name', 'business_type', 'gstin', 'business_email', 'business_phone', 'business_address', 'business_city', 'business_state', 'business_pincode', 'status' ),
		'readonly' => array( 'user_name', 'email', '_wp_user_id' ),
	),
	'nexora_cust_seller' => array(
		'edit' => array( 'business_name', 'business_type', 'gstin', 'business_email', 'business_phone', 'business_address', 'business_city', 'business_state', 'business_pincode', 'status' ),
		'readonly' => array( '_wp_user_id', 'customer_post_id' ),
	),
);
foreach ( $pins as $type => $pin ) {
	nx_assert_same( $pin['edit'], Fields::names( $type, 'edit' ), "$type: the editable fields" );
	nx_assert_same( $pin['readonly'], Fields::names( $type, 'readonly' ), "$type: the read-only fields (identity, owner and links)" );
}
nx_assert_same( 'field_nx_nexora_customer_first_name', Fields::field_key( 'nexora_customer', 'first_name' ), 'ACF field keys are derived from type and name' );

/* ---------- customer: every editable field, with audit ---------- */
$cid   = (int) get_user_meta( $C['user_id'], '_profile_id', true );
$good  = array(
	'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9811122233', 'gender' => 'female', 'birthdate' => '1990-05-17', 'linkedin_id' => 'asha-rao', 'bio' => "Short bio\nsecond line",
	'perm_address' => '1 Perm Road', 'perm_city' => 'Pune', 'perm_state' => 'MH', 'perm_pincode' => '411001', 'corr_address' => '2 Corr Road', 'corr_city' => 'Delhi', 'corr_state' => 'DL', 'corr_pincode' => '110001',
	'company_name' => 'Acme', 'designation' => 'Engineer', 'company_email' => 'hr@acme.example', 'company_phone' => '0112233', 'company_address' => 'Acme Park', 'profile_image' => $img, 'cover_image' => $img,
);
$res = $edit( $cid, $good );
nx_assert( ! is_wp_error( $res ), 'an administrator edits a customer (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'ok' ) . ')' );
foreach ( $good as $k => $v ) { nx_assert_same( (string) $v, (string) $meta( $cid, $k ), "customer: $k was saved" ); }
$changed = array_column( $rows( 'nexora_customer', $cid ), 'field' );
sort( $changed ); $want = array_keys( $good ); sort( $want );
nx_assert_same( $want, $changed, 'one change-history row per changed field' );
$one = array_values( array_filter( $rows( 'nexora_customer', $cid ), function ( $r ) { return 'first_name' === $r['field']; } ) )[0];
nx_assert_same( array( 'Asha', (string) $admin ), array( $one['new_value'], (string) $one['user_id'] ), 'with the new value and WHO (the administrator)' );
$n = count( $rows( 'nexora_customer', $cid ) );
$res = $edit( $cid, $good );
nx_assert( array() === $res && $n === count( $rows( 'nexora_customer', $cid ) ), 'saving the same values again changes nothing and records nothing' );

/* ---------- customer: invalid values are refused, all or nothing ---------- */
$bad = array(
	'gender' => array( 'robot', 'Gender' ), 'birthdate' => array( '2999-01-01', 'date of birth' ), 'company_email' => array( 'not-an-email', 'email' ), 'profile_image' => array( '999999999', 'image' ),
);
foreach ( $bad as $field => $pair ) {
	$before = $meta( $cid, 'first_name' );
	$res    = $edit( $cid, array( 'first_name' => 'ShouldNotStick', $field => $pair[0] ) );
	nx_assert( is_wp_error( $res ) && isset( $res->get_error_data()['fields'][ $field ] ) && '' !== $res->get_error_data()['fields'][ $field ], "customer: $field '{$pair[0]}' is refused with a message on the field" );
	nx_assert_same( $before, $meta( $cid, 'first_name' ), "...and the valid first_name sent with it was NOT saved either (all or nothing)" );
}
foreach ( array( '1990-13-45', '31/12/1990', '1990-5-17', 'tomorrow' ) as $d ) { nx_assert( is_wp_error( $edit( $cid, array( 'birthdate' => $d ) ) ), "birthdate '$d' is refused" ); }
$post_attachment = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'not an image', 'post_status' => 'publish' ) ); $GLOBALS['nx_test']['posts'][] = $post_attachment;
nx_assert( is_wp_error( $edit( $cid, array( 'cover_image' => (string) $post_attachment ) ) ), 'an image field must be an actual image attachment, not any post' );
nx_assert( ! is_wp_error( $edit( $cid, array( 'gender' => '' ) ) ), 'clearing the gender is allowed (as on the front end)' );

/* ---------- identity, owner and links are read-only; unknown keys are ignored ---------- */
$links_before = array( $meta( $cid, '_wp_user_id' ), $meta( $cid, 'user_name' ), $meta( $cid, 'email' ), $meta( $cid, 'seller_post_id' ), $meta( $cid, 'aadhaar_card' ) );
$n = count( $rows( 'nexora_customer', $cid ) );
$res = $edit( $cid, array( '_wp_user_id' => 1, 'user_name' => 'hacked', 'email' => 'x@evil.example', 'seller_post_id' => 99999, 'aadhaar_card' => 123, 'driving_license' => 5, 'company_id_card' => 6, 'is_admin' => 1, 'post_author' => 1, 'bio' => 'ok bio' ) );
nx_assert( ! is_wp_error( $res ) && array( 'bio' ) === $res, 'a request that also names read-only and unknown keys changes only the editable one' );
nx_assert_same( $links_before, array( $meta( $cid, '_wp_user_id' ), $meta( $cid, 'user_name' ), $meta( $cid, 'email' ), $meta( $cid, 'seller_post_id' ), $meta( $cid, 'aadhaar_card' ) ), 'owner link, user name, e-mail, seller link and ID documents are untouched' );
nx_assert_same( $n + 1, count( $rows( 'nexora_customer', $cid ) ), 'and only the one real change is recorded' );

/* ---------- customer-seller: business details, GSTIN across both seller types ---------- */
$sid = $S['vendor_id'];
$biz = array( 'business_name' => 'New Trading Co', 'business_type' => 'llp', 'gstin' => '27ABCDE1234F1Z5', 'business_email' => 'biz@example.test', 'business_phone' => '9811100001', 'business_address' => '5 New Street', 'business_city' => 'Mumbai', 'business_state' => 'Maharashtra', 'business_pincode' => '400001' );
$res = $edit( $sid, $biz );
nx_assert( ! is_wp_error( $res ), 'an administrator edits a customer-seller\'s business details (' . ( is_wp_error( $res ) ? wp_json_encode( $res->get_error_data() ) : 'ok' ) . ')' );
foreach ( $biz as $k => $v ) { nx_assert_same( $v, $meta( $sid, $k ), "customer-seller: $k was saved" ); }
nx_assert_same( 'New Trading Co', get_post_field( 'post_title', $sid ), 'the business name is also the record title' );
nx_assert_same( count( $biz ), count( array_filter( $rows( Post_Types::CUST_SELLER, $sid ), function ( $r ) { return 'status' !== $r['field']; } ) ) >= count( $biz ) ? count( $biz ) : 0, 'each business field changed is in the history' );
$res = $edit( $sid, array( 'gstin' => strtolower( '27ABCDE1234F1Z5' ) ) );
nx_assert( array() === $res && '27ABCDE1234F1Z5' === $meta( $sid, 'gstin' ), 'a GSTIN typed in lower case is the same GSTIN (stored upper case, no change)' );
$other_gstin = $meta( $S2['vendor_id'], 'gstin' );
$res = $edit( $sid, array( 'gstin' => $other_gstin ) );
nx_assert( is_wp_error( $res ) && isset( $res->get_error_data()['fields']['gstin'] ), 'a GSTIN already used by another customer-seller is refused' );
nx_assert_same( '27ABCDE1234F1Z5', $meta( $sid, 'gstin' ), '...and the old one stays' );
$res = $edit( $sid, array( 'gstin' => $D['gstin'] ) );
nx_assert( is_wp_error( $res ), 'a GSTIN used by a DIRECT seller is refused too (unique across both seller types)' );
$res = $edit( $D['seller_id'], array( 'gstin' => $other_gstin ) );
nx_assert( is_wp_error( $res ), 'and a direct seller cannot take a customer-seller\'s GSTIN' );
foreach ( array( 'gstin' => array( 'BADGSTIN', '27ABCDE1234F1Z' ), 'business_type' => array( 'cooperative' ), 'business_pincode' => array( '12', 'ABCDEF' ), 'business_email' => array( 'nope' ), 'business_phone' => array( '12' ), 'business_name' => array( '' ) ) as $field => $values ) {
	foreach ( $values as $v ) {
		$res = $edit( $sid, array( $field => $v ) );
		nx_assert( is_wp_error( $res ) && isset( $res->get_error_data()['fields'][ $field ] ), "customer-seller: $field '$v' is refused" );
	}
}

/* ---------- direct seller: personal + business ---------- */
$did = $D['seller_id'];
$res = $edit( $did, array( 'first_name' => 'Dev', 'last_name' => 'Direct', 'phone' => '9899900000', 'gender' => 'male', 'birthdate' => '1985-01-02', 'business_name' => 'Direct Wares', 'business_city' => 'Chennai' ) );
nx_assert( ! is_wp_error( $res ), 'an administrator edits a direct seller\'s personal and business details' );
nx_assert_same( array( 'Dev', 'Direct', 'male', '1985-01-02', 'Direct Wares', 'Chennai' ), array( $meta( $did, 'first_name' ), $meta( $did, 'last_name' ), $meta( $did, 'gender' ), $meta( $did, 'birthdate' ), $meta( $did, 'business_name' ), $meta( $did, 'business_city' ) ), '...and they are saved' );
nx_assert( is_wp_error( $edit( $did, array( 'gender' => 'robot' ) ) ) && is_wp_error( $edit( $did, array( 'birthdate' => '2999-01-01' ) ) ), 'the same personal-field rules apply to a direct seller' );
$res = $edit( $did, array( 'user_name' => 'hacked', 'email' => 'x@evil.example', '_wp_user_id' => 1 ) );
nx_assert( array() === $res && 'hacked' !== $meta( $did, 'user_name' ) && 1 !== (int) $meta( $did, '_wp_user_id' ), 'a direct seller\'s account name, e-mail and owner are read-only too' );

/* ---------- the links survive a business edit ---------- */
$link = function () use ( $S, $sid ) { return array( get_post_meta( $sid, 'customer_post_id', true ), get_post_meta( $sid, '_wp_user_id', true ), get_user_meta( $S['user_id'], '_seller_post_id', true ), get_user_meta( $S['user_id'], '_profile_id', true ), get_user_meta( $S['user_id'], '_vendor_id', true ) ); };
$before = $link();
$edit( $sid, array( 'business_name' => 'Renamed Again', 'customer_post_id' => 5, '_wp_user_id' => 1 ) );
nx_assert_same( $before, $link(), 'customer_post_id, the owner and the user-meta links are exactly as they were after editing the record' );

/* ---------- status: the same logic as the approve / suspend / reject buttons ---------- */
$mails = function () { return count( $GLOBALS['nx_test']['mail'] ); };
$m0    = $mails();
nx_assert_same( array( 'subscriber' ), $roles( $S['user_id'] ), 'setup: a pending customer-applicant is still a plain customer' );
$res = $edit( $sid, array( 'status' => 'approved' ) );
nx_assert( ! is_wp_error( $res ) && array( 'status' ) === $res, 'setting a pending seller to approved works (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'ok' ) . ')' );
nx_assert( Account_Types::can_sell( $S['user_id'] ) && 'approved' === Account_Types::vendor_status( $S['user_id'] ), 'it ran the approve logic: the seller can sell' );
nx_assert_same( array( 'nexora_customer_seller' ), $roles( $S['user_id'] ), '...and the role was promoted exactly as the approve button does' );
nx_assert( $mails() > $m0, '...and the applicant was told (the same mail the button sends)' );
$status_row = $rows( Post_Types::CUST_SELLER, $sid )[0];
nx_assert_same( array( 'status', 'pending', 'approved' ), array( $status_row['field'], $status_row['old_value'], $status_row['new_value'] ), 'the history records pending -> approved' );
$m1  = $mails();
$res = $edit( $sid, array( 'status' => 'approved' ) );
nx_assert( array() === $res && $mails() === $m1, 'approving again changes nothing and sends nothing' );
$res = $edit( $sid, array( 'status' => 'suspended' ) );
nx_assert( ! is_wp_error( $res ) && ! Account_Types::can_sell( $S['user_id'] ) && array( 'nexora_customer_seller' ) === $roles( $S['user_id'] ), 'suspending runs the suspend logic: cannot sell, keeps the role (as the button does)' );
$res = $edit( $sid, array( 'status' => 'approved' ) );
nx_assert( ! is_wp_error( $res ) && Account_Types::can_sell( $S['user_id'] ), 'suspended -> approved is the reactivate logic' );
$P = nx_test_vendor( 'aecr', 'pending' );
$res = $edit( $P['vendor_id'], array( 'status' => 'rejected' ) );
nx_assert( ! is_wp_error( $res ) && 'rejected' === Account_Types::vendor_status( $P['user_id'] ), 'pending -> rejected works' );
nx_assert( null !== get_post( $P['vendor_id'] ) && 'publish' === get_post_status( $P['vendor_id'] ) && array( 'subscriber' ) === $roles( $P['user_id'] ), 'rejecting keeps the record, and the customer stays a subscriber' );
foreach ( array( array( $P['vendor_id'], 'approved', 'rejected -> approved' ), array( $sid, 'pending', 'approved -> pending' ), array( $sid, 'rejected', 'approved -> rejected' ), array( $sid, 'banana', 'an unknown status' ), array( $sid, '', 'an empty status' ), array( $sid, 'none', 'none' ) ) as $case ) {
	$before = Account_Types::vendor_status( Sellers::user_for( $case[0] ) );
	$res    = $edit( $case[0], array( 'status' => $case[1] ) );
	nx_assert( is_wp_error( $res ) && isset( $res->get_error_data()['fields']['status'] ), "status {$case[2]} is refused with a message" );
	nx_assert_same( $before, Account_Types::vendor_status( Sellers::user_for( $case[0] ) ), '...and the status is unchanged' );
}
$res = $edit( $did, array( 'status' => 'approved' ) );
nx_assert( ! is_wp_error( $res ) && Account_Types::can_sell( $D['user_id'] ) && array( 'nexora_seller' ) === $roles( $D['user_id'] ), 'a direct seller approved by the admin: can sell, role stays nexora_seller' );

/* ---------- nobody else can use the admin path; only a genuine admin edit request does ---------- */
$_acf = function ( $type, array $values ) { $o = array(); foreach ( $values as $name => $v ) { $o[ Fields::field_key( $type, $name ) ] = $v; } return $o; };
$run  = function ( $id, $type, array $values, array $over = array() ) use ( $_acf ) {
	nx_test_admin_request( $id, 'edit', $over );
	if ( array_key_exists( 'user', $over ) && ! $over['user'] ) { wp_set_current_user( 0 ); }
	$_POST['acf'] = $_acf( $type, $values );
	$_REQUEST     = array_merge( $_GET, $_POST );
	return Saver::from_request( $id );
};
$snap = function () use ( $meta, $cid ) { return array( $meta( $cid, 'first_name' ), $meta( $cid, 'phone' ) ); };
$s0   = $snap();
foreach ( array( 'a customer' => $C['user_id'], 'an approved seller' => $S['user_id'], 'another seller' => $S2['user_id'], 'a guest' => 0 ) as $who => $uid ) {
	$res = $run( $cid, 'nexora_customer', array( 'first_name' => 'Hijack' ), array( 'user' => $uid ) );
	nx_assert( is_wp_error( $res ) && $s0 === $snap(), "$who cannot edit through the admin path" );
}
nx_assert( is_wp_error( $run( $cid, 'nexora_customer', array( 'first_name' => 'Hijack' ), array( 'ajax' => true ) ) ) && $s0 === $snap(), 'AJAX cannot, even as an administrator' );
nx_assert( is_wp_error( $run( $cid, 'nexora_customer', array( 'first_name' => 'Hijack' ), array( 'screen' => false ) ) ) && $s0 === $snap(), 'outside wp-admin cannot' );
nx_assert( is_wp_error( $run( $cid, 'nexora_customer', array( 'first_name' => 'Hijack' ), array( 'nonce' => false ) ) ) && $s0 === $snap(), 'no nonce cannot' );
nx_assert( is_wp_error( $run( $cid, 'nexora_customer', array( 'first_name' => 'Hijack' ), array( 'nonce' => 'other' ) ) ) && $s0 === $snap(), 'a nonce for another post cannot' );
nx_test_admin_request( $sid, 'edit' );
$_POST['acf'] = $_acf( 'nexora_customer', array( 'first_name' => 'Hijack' ) );
$_REQUEST     = array_merge( $_GET, $_POST );
nx_assert( is_wp_error( Saver::from_request( $cid ) ) && $s0 === $snap(), 'a request for one record cannot edit another' );
$res = $run( $cid, 'nexora_customer', array( 'first_name' => 'ViaRequest', 'phone' => '9000000000' ) );
nx_assert( ! is_wp_error( $res ) && 'ViaRequest' === $meta( $cid, 'first_name' ) && '9000000000' === $meta( $cid, 'phone' ), 'a genuine admin edit request (values keyed by ACF field keys) saves' );
$res = $run( $cid, 'nexora_customer', array( 'first_name' => 'Bad', 'gender' => 'robot' ) );
nx_assert( is_wp_error( $res ) && 'ViaRequest' === $meta( $cid, 'first_name' ), 'and an invalid value is refused on that path too, nothing saved' );
$_POST['acf'] = array( 'field_not_ours' => 'x', Fields::field_key( 'nexora_customer', 'user_name' ) => 'hacked', Fields::field_key( 'nexora_customer', 'bio' ) => 'last bio' );
$res = Saver::from_request( $cid );
nx_assert( ! is_wp_error( $res ) && 'last bio' === $meta( $cid, 'bio' ) && 'hacked' !== $meta( $cid, 'user_name' ), 'unknown and read-only field keys in the request are ignored' );
nx_test_admin_request_end();
wp_set_current_user( $C['user_id'] );
nx_assert( is_wp_error( Saver::save( $cid, array( 'first_name' => 'Direct' ) ) ), 'even calling the saver directly needs manage_options' );
wp_set_current_user( $admin );

nx_test_finish();
