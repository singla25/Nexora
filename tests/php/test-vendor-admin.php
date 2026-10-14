<?php
// Marketplace Phase 2: the admin side of vendor applications: list columns, details box, approve / suspend /
// reactivate / reject, who is allowed to press the buttons, and the mails to the applicant.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Vendors\Admin;
use Nexora\Vendors\Repository;
use Nexora\Vendors\Service;

global $wpdb;
$mail = &nx_test_capture_mail();
nx_test_reset_limits();
wp_set_current_user( 0 );

$biz = function ( $n, array $over = array() ) {
	$gst = array( '27AAPFU0939F1ZV', '29ABCDE1234F1Z5', '07AAACR5055K1Z8', '33AABCU9603R1ZX' );
	return array_merge( array(
		'business_name' => "Admin Test Shop $n", 'business_type' => 'partnership', 'gstin' => $gst[ $n ], 'business_email' => "adm$n@example.test",
		'business_phone' => '98765432' . sprintf( '%02d', 20 + $n ), 'business_address' => '5 Park Street', 'business_city' => 'Kolkata',
		'business_state' => 'West Bengal', 'business_pincode' => '700016',
	), $over );
};
$applicant = function ( $n ) use ( $biz ) {
	$u = nx_test_user( "vadm$n" );
	$r = Service::apply( $u['user_id'], $biz( $n ) );
	$vid = is_array( $r ) ? (int) ( $r['vendor_id'] ?? 0 ) : 0;
	if ( $vid ) { $GLOBALS['nx_test']['posts'][] = $vid; }
	return array( $u, $vid );
};
list( $u0, $v0 ) = $applicant( 0 );
list( $u1, $v1 ) = $applicant( 1 );
$admin_id = nx_test_admin();
$member   = nx_test_user( 'vadmmember' );
$admin    = new Admin();

/* ---------- wiring ---------- */
nx_assert( has_filter( 'manage_nexora_vendor_posts_columns' ) && has_action( 'manage_nexora_vendor_posts_custom_column' ), 'list-column hooks are registered' );
nx_assert( has_action( 'admin_post_nexora_vendor_status' ) && false === has_action( 'admin_post_nopriv_nexora_vendor_status' ), 'the status action is admin-post for logged-in users only (no nopriv)' );
nx_assert( has_action( 'add_meta_boxes' ), 'meta boxes hook exists' );

/* ---------- list columns ---------- */
$cols = apply_filters( 'manage_nexora_vendor_posts_columns', array( 'cb' => 'x', 'title' => 'Title', 'date' => 'Date' ) );
nx_assert( isset( $cols['cb'], $cols['title'], $cols['gstin'], $cols['owner'], $cols['vendor_status'] ), 'list has checkbox, business, GSTIN, owner and status columns' );
$col = function ( $name, $vid ) use ( $admin ) { ob_start(); $admin->column( $name, $vid ); return ob_get_clean(); };
nx_assert_same( '27AAPFU0939F1ZV', trim( $col( 'gstin', $v0 ) ), 'GSTIN column shows the GSTIN' );
nx_assert( false !== strpos( $col( 'owner', $v0 ), $u0['login'] ), 'owner column shows the account' );
nx_assert( false !== stripos( $col( 'vendor_status', $v0 ), 'pending' ) || false !== stripos( $col( 'vendor_status', $v0 ), 'Pending' ), 'status column shows Pending' );
update_post_meta( $v0, 'gstin', '<script>alert(1)</script>' );
nx_assert( false === strpos( $col( 'gstin', $v0 ), '<script' ), 'list output is escaped' );
update_post_meta( $v0, 'gstin', '27AAPFU0939F1ZV' );

/* ---------- details box ---------- */
$box = function ( $vid ) use ( $admin ) { ob_start(); $admin->render_details_box( get_post( $vid ) ); return ob_get_clean(); };
$html = $box( $v0 );
foreach ( array( 'Admin Test Shop 0', '27AAPFU0939F1ZV', 'adm0@example.test', '5 Park Street', 'Kolkata', '700016' ) as $s ) {
	nx_assert( false !== strpos( $html, $s ), "details box shows $s" );
}
nx_assert( false !== strpos( $html, 'do=approve' ) && false !== strpos( $html, 'do=reject' ) && false === strpos( $html, 'do=suspend' ), 'pending: Approve and Reject, not Suspend' );
nx_assert( 1 === preg_match( '/_wpnonce=[a-f0-9]{10}/', $html ), 'action links carry a nonce' );

/* ---------- who may press the buttons ---------- */
$run = function ( $uid, $vid, $do, array $over = array() ) use ( $admin ) {
	wp_set_current_user( $uid );
	$_GET = $_REQUEST = array_merge( array( 'vendor_id' => $vid, 'do' => $do, '_wpnonce' => wp_create_nonce( "nexora_vendor_status_{$vid}_{$do}" ) ), $over );
	$to_throw = function ( $location ) { throw new RuntimeException( 'redirect:' . $location ); };
	$die      = function () use ( $to_throw ) { return function ( $m ) { throw new RuntimeException( 'die:' . ( is_scalar( $m ) ? $m : 'x' ) ); }; };
	add_filter( 'wp_redirect', $to_throw, 1 );
	add_filter( 'wp_die_handler', $die, 99 );
	$out = '';
	try { $admin->handle_status_request(); $out = 'returned'; } catch ( RuntimeException $e ) { $out = $e->getMessage(); }
	remove_filter( 'wp_redirect', $to_throw, 1 );
	remove_filter( 'wp_die_handler', $die, 99 );
	wp_set_current_user( 0 );
	$_GET = $_REQUEST = array();
	return $out;
};
$status = function ( $u ) { clean_user_cache( $u['user_id'] ); return Account_Types::vendor_status( $u['user_id'] ); };

foreach ( array( 'guest' => 0, 'member' => $member['user_id'], 'the applicant' => $u0['user_id'] ) as $who => $uid ) {
	$out = $run( $uid, $v0, 'approve' );
	nx_assert( 0 === strpos( $out, 'die:' ), "$who with a valid nonce is refused (not an administrator)" );
	nx_assert_same( 'pending', $status( $u0 ), "$who: the status did not change" );
}
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve', array( '_wpnonce' => '' ) ), 'die:' ), 'admin: a missing nonce is refused' );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve', array( '_wpnonce' => 'abcdef1234' ) ), 'die:' ), 'admin: a wrong nonce is refused' );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve', array( '_wpnonce' => wp_create_nonce( "nexora_vendor_status_{$v0}_reject" ) ) ), 'die:' ), 'admin: a nonce made for another action is refused' );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve', array( '_wpnonce' => wp_create_nonce( "nexora_vendor_status_{$v1}_approve" ) ) ), 'die:' ), 'admin: a nonce made for another vendor is refused' );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'promote' ), 'die:' ), 'admin: an unknown action is refused' );
nx_assert( 0 === strpos( $run( $admin_id, 0, 'approve' ), 'die:' ), 'admin: a missing vendor is refused' );
nx_assert( 0 === strpos( $run( $admin_id, $u0['profile_id'], 'approve' ), 'die:' ), 'admin: a post that is not a vendor application is refused' );
nx_assert( 0 === strpos( $run( $admin_id, 999999, 'approve' ), 'die:' ), 'admin: an unknown ID is refused' );
nx_assert_same( 'pending', $status( $u0 ), 'none of the refused requests changed the status' );
nx_assert_same( 'none', Account_Types::vendor_status( $member['user_id'] ), 'the plain member is still a customer' );

// Service itself also refuses a non-admin caller.
wp_set_current_user( $member['user_id'] );
$r = Service::change_status( $v0, 'approve' );
wp_set_current_user( 0 );
nx_assert( is_wp_error( $r ) && 'pending' === $status( $u0 ), 'Service::change_status refuses a non-administrator on its own' );

/* ---------- the transitions ---------- */
$sent = count( $mail );
$out  = $run( $admin_id, $v0, 'approve' );
nx_assert( 0 === strpos( $out, 'redirect:' ), 'admin: approve redirects back' );
nx_assert_same( 'approved', $status( $u0 ), 'approve: pending becomes approved' );
nx_assert( Account_Types::can_sell( $u0['user_id'] ) && in_array( Account_Types::ROLE, (array) get_userdata( $u0['user_id'] )->roles, true ), 'approve: the vendor role is added' );
nx_assert( in_array( 'subscriber', (array) get_userdata( $u0['user_id'] )->roles, true ), 'approve: the original role stays' );
$m = array_slice( $mail, $sent );
nx_assert( 1 === count( $m ) && get_userdata( $u0['user_id'] )->user_email === ( is_array( $m[0]['to'] ) ? $m[0]['to'][0] : $m[0]['to'] ), 'approve: the applicant is told by mail' );
nx_assert( false === strpbrk( $m[0]['subject'] ?? "\n", "\r\n" ), 'approve: mail subject is one line' );

nx_assert( false !== strpos( $box( $v0 ), 'do=suspend' ) && false === strpos( $box( $v0 ), 'do=approve' ), 'approved: only Suspend is offered' );
$sent = count( $mail );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve' ), 'redirect:' ) && 'approved' === $status( $u0 ) && count( $mail ) === $sent, 'approving twice is harmless: no change, no second mail' );
nx_assert( 'approved' === $status( $u0 ) && 0 === strpos( $run( $admin_id, $v0, 'reject' ), 'redirect:' ) && 'approved' === $status( $u0 ), 'an approved vendor cannot be "rejected" (suspend instead)' );

$out = $run( $admin_id, $v0, 'suspend' );
nx_assert_same( 'suspended', $status( $u0 ), 'suspend: approved becomes suspended' );
nx_assert( ! Account_Types::can_sell( $u0['user_id'] ) && Account_Types::can_view_earnings( $u0['user_id'] ), 'suspend: cannot sell, may still read earnings' );
nx_assert( false !== strpos( $box( $v0 ), 'do=reactivate' ), 'suspended: Reactivate is offered' );
$out = $run( $admin_id, $v0, 'reactivate' );
nx_assert( 'approved' === $status( $u0 ) && Account_Types::can_sell( $u0['user_id'] ), 'reactivate: suspended becomes approved and can sell again' );

nx_assert( 0 === strpos( $run( $admin_id, $v1, 'suspend' ), 'redirect:' ) && 'pending' === $status( $u1 ), 'a pending application cannot be suspended' );
nx_assert( 0 === strpos( $run( $admin_id, $v1, 'reactivate' ), 'redirect:' ) && 'pending' === $status( $u1 ), 'a pending application cannot be reactivated' );
$sent = count( $mail );
$run( $admin_id, $v1, 'reject' );
nx_assert_same( 'none', $status( $u1 ), 'reject: pending goes back to none' );
nx_assert( count( $mail ) === $sent + 1, 'reject: the applicant is told by mail' );
nx_assert( 'trash' === get_post_status( $v1 ), 'reject: the application moves to the trash' );
nx_assert( 0 === strpos( $run( $admin_id, $v1, 'approve' ), 'die:' ) || 'none' === $status( $u1 ), 'a trashed application cannot be approved' );

/* ---------- the edit screen cannot be used to change a status ---------- */
$_POST = array( 'vendor_status' => 'approved', 'status' => 'approved', 'post_status' => 'publish', 'role' => 'administrator' );
wp_set_current_user( $admin_id );
do_action( 'save_post', $v1, get_post( $v1 ), true );
wp_set_current_user( 0 );
$_POST = array();
nx_assert_same( 'none', $status( $u1 ), 'saving the vendor post cannot change the status or role' );


nx_test_finish();
