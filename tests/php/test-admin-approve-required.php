<?php
// Marketplace Phase 7b follow-up: approving a seller, by ANY route (the decision button's Service call, the admin edit form's status
// field, a record an administrator created by hand), needs the same business details registration asks for. Nothing is approved
// half-filled, nothing is sent, the status stays.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Admin_Edit\Saver;
use Nexora\Admin_Edit\Users;
use Nexora\Vendors\Fields;
use Nexora\Vendors\Service;

nx_test_capture_mail();
$admin = nx_test_admin();
wp_set_current_user( $admin );
$mails = function () { return count( $GLOBALS['nx_test']['mail'] ); };

/* ---------- every required field, one at a time ---------- */
foreach ( Fields::keys() as $key ) {
	foreach ( array( 'vendor' => nx_test_vendor( 'apr' . substr( $key, 9, 3 ), 'pending' ), 'direct' => nx_test_direct_seller( 'apd' . substr( $key, 9, 3 ), 'pending' ) ) as $kind => $S ) {
		$id = $S['vendor_id'];
		update_post_meta( $id, $key, '' );
		$m0 = $mails();

		$res = Service::change_status( $id, 'approve' );
		nx_assert( is_wp_error( $res ) && ! empty( $res->get_error_data()['fields'][ $key ] ) && 'pending' === Account_Types::vendor_status( $S['user_id'] ), "$kind: the approve button/service refuses an empty $key and says which field" );

		$res = Saver::save( $id, array( 'status' => 'approved' ) );
		nx_assert( is_wp_error( $res ) && 'pending' === Account_Types::vendor_status( $S['user_id'] ) && ! Account_Types::can_sell( $S['user_id'] ), "$kind: the admin edit form's status field refuses it too" );
		nx_assert_same( $m0, $mails(), "$kind: nothing was sent" );
	}
}

/* ---------- a bad value stored is refused like a missing one ---------- */
$S = nx_test_vendor( 'aprbad', 'pending' );
update_post_meta( $S['vendor_id'], 'gstin', 'NOT-A-GSTIN' );
nx_assert( is_wp_error( Service::change_status( $S['vendor_id'], 'approve' ) ), 'an invalid stored GSTIN cannot be approved either' );

/* ---------- a complete record is approved, as before ---------- */
$G = nx_test_vendor( 'aprok', 'pending' );
nx_assert( true === Service::change_status( $G['vendor_id'], 'approve' ) && Account_Types::can_sell( $G['user_id'] ), 'a complete record is approved' );
$H = nx_test_direct_seller( 'aprok2', 'pending' );
nx_assert( ! is_wp_error( Saver::save( $H['vendor_id'], array( 'status' => 'approved' ) ) ) && Account_Types::can_sell( $H['user_id'] ), 'and so is a complete direct seller, through the edit form' );

/* ---------- an administrator-made empty record: filled in first, then approvable ---------- */
$u  = nx_test_user_only( 'aprblank' );
$id = Users::create_seller_record( $u );
$GLOBALS['nx_test']['posts'][] = $id;
nx_assert( is_int( $id ) && is_wp_error( Service::change_status( $id, 'approve' ) ) && 'pending' === Account_Types::vendor_status( $u ), 'an empty record made from the Users screen cannot be approved' );
$res = Saver::save( $id, array( 'business_name' => 'Partial', 'status' => 'approved' ) );
clean_post_cache( $id );
nx_assert( is_wp_error( $res ) && 'Partial' !== get_post_meta( $id, 'business_name', true ), 'a save that tries to approve an incomplete record saves nothing (not even the name sent with it)' );
$res = Saver::save( $id, array( 'business_name' => 'Hand Made Co', 'business_type' => 'other', 'gstin' => '27ABCDE9999F1Z5', 'business_email' => 'hand@example.test', 'business_phone' => '9811100099', 'business_address' => '9 Road', 'business_city' => 'Pune', 'business_state' => 'MH', 'business_pincode' => '411001', 'status' => 'approved' ) );
nx_assert( ! is_wp_error( $res ) && Account_Types::can_sell( $u ), 'once the details are filled in (even in the same save) it can be approved' );

/* ---------- other transitions are not held up by it ---------- */
$R = nx_test_vendor( 'aprrej', 'pending' );
update_post_meta( $R['vendor_id'], 'business_city', '' );
nx_assert( true === Service::change_status( $R['vendor_id'], 'reject' ), 'rejecting an incomplete record is still allowed' );

nx_test_finish();
