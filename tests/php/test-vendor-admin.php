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
nx_assert( false === strpos( $html, 'do=approve' ) && false === strpos( $html, 'admin-post.php' ) && false === strpos( $html, 'nx-vendor-decisions' ), 'the details box is read-only: no decision links at the bottom any more' );

/* ---------- the "Application decision" box (side column, top right) ---------- */
global $wp_meta_boxes;
$register = function ( $uid ) use ( $admin ) {
	global $wp_meta_boxes;
	$wp_meta_boxes = array();
	wp_set_current_user( $uid );
	$admin->add_meta_boxes();
	wp_set_current_user( 0 );
	return $wp_meta_boxes['nexora_vendor'] ?? array();
};
$boxes = $register( $admin_id );
nx_assert( isset( $boxes['side']['high']['nexora_vendor_decision'] ), 'admin: the decision box is in the side column at high priority (top right)' );
nx_assert_same( 'Application decision', $boxes['side']['high']['nexora_vendor_decision']['title'] ?? '', 'admin: the box is titled "Application decision"' );
nx_assert( isset( $boxes['normal']['high']['nexora_vendor_details'] ), 'admin: the read-only details box is still in the main column' );
$boxes = $register( $member['user_id'] );
nx_assert( ! isset( $boxes['side']['high']['nexora_vendor_decision'] ), 'member: the decision box is not registered' );
$boxes = $register( 0 );
nx_assert( ! isset( $boxes['side']['high']['nexora_vendor_decision'] ), 'guest: the decision box is not registered' );

$decide = function ( $vid, $uid = null ) use ( $admin, $admin_id ) {
	wp_set_current_user( null === $uid ? $admin_id : $uid );
	ob_start();
	$admin->render_decision_box( get_post( $vid ) );
	$out = ob_get_clean();
	wp_set_current_user( 0 );
	return $out;
};
$buttons = function ( $html ) { preg_match_all( '/do=([a-z]+)/', $html, $m ); return array_values( array_unique( $m[1] ) ); };
$html = $decide( $v0 );
nx_assert_same( array( 'approve', 'reject' ), $buttons( $html ), 'pending: Approve and Reject' );
nx_assert( 1 === preg_match( '/<a[^>]*button-primary[^>]*do=approve|<a[^>]*do=approve[^>]*button-primary/', $html ), 'pending: Approve is the primary button' );
nx_assert( 1 === preg_match( '/<a(?![^>]*button-primary)[^>]*do=reject/', $html ), 'pending: Reject is a secondary button' );
nx_assert( 1 === preg_match( '/<a[^>]*do=reject[^>]*onclick="return confirm\(/', $html ), 'pending: Reject asks "Are you sure?" first' );
nx_assert( false === strpos( $html, 'do=approve" onclick' ) && 1 !== preg_match( '/<a[^>]*do=approve[^>]*confirm\(/', $html ), 'pending: Approve does not nag' );
nx_assert( 2 === preg_match_all( '/_wpnonce=[a-f0-9]{10}/', $html ), 'every decision link carries its own nonce' );
nx_assert( false !== strpos( $html, 'Pending' ) && false !== strpos( $html, $u0['login'] ), 'the box shows the current status and the applicant' );
nx_assert( false !== strpos( $html, 'Admin Test Shop 0' ), 'the box names the business' );

nx_assert_same( '', trim( $decide( $v0, $member['user_id'] ) ), 'a member rendering the box gets nothing (no links)' );
nx_assert_same( '', trim( $decide( $v0, 0 ) ), 'a guest rendering the box gets nothing (no links)' );
nx_assert_same( '', trim( $decide( $v0, $u0['user_id'] ) ), 'the applicant rendering the box gets nothing (no links)' );

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

nx_assert_same( array( 'suspend' ), $buttons( $decide( $v0 ) ), 'approved: only Suspend is offered' );
nx_assert( false !== strpos( $decide( $v0 ), 'Approved' ), 'approved: the box shows the status' );
$sent = count( $mail );
nx_assert( 0 === strpos( $run( $admin_id, $v0, 'approve' ), 'redirect:' ) && 'approved' === $status( $u0 ) && count( $mail ) === $sent, 'approving twice is harmless: no change, no second mail' );
nx_assert( 'approved' === $status( $u0 ) && 0 === strpos( $run( $admin_id, $v0, 'reject' ), 'redirect:' ) && 'approved' === $status( $u0 ), 'an approved vendor cannot be "rejected" (suspend instead)' );

$out = $run( $admin_id, $v0, 'suspend' );
nx_assert_same( 'suspended', $status( $u0 ), 'suspend: approved becomes suspended' );
nx_assert( ! Account_Types::can_sell( $u0['user_id'] ) && Account_Types::can_view_earnings( $u0['user_id'] ), 'suspend: cannot sell, may still read earnings' );
nx_assert_same( array( 'reactivate', 'reject' ), $buttons( $decide( $v0 ) ), 'suspended: Reactivate and Reject are offered' );
nx_assert( 1 === preg_match( '/<a[^>]*button-primary[^>]*do=reactivate|<a[^>]*do=reactivate[^>]*button-primary/', $decide( $v0 ) ), 'suspended: Reactivate is the primary button' );
$out = $run( $admin_id, $v0, 'reactivate' );
nx_assert( 'approved' === $status( $u0 ) && Account_Types::can_sell( $u0['user_id'] ), 'reactivate: suspended becomes approved and can sell again' );

// A suspended vendor can be rejected (the box offers it): back to a customer, application trashed, GSTIN free.
list( $u2, $v2 ) = $applicant( 2 );
$run( $admin_id, $v2, 'approve' );
$run( $admin_id, $v2, 'suspend' );
nx_assert_same( 'suspended', $status( $u2 ), 'setup: a suspended vendor' );
$sent = count( $mail );
$run( $admin_id, $v2, 'reject' );
nx_assert_same( 'none', $status( $u2 ), 'reject: a suspended vendor goes back to a plain customer' );
nx_assert( 'trash' === get_post_status( $v2 ) && 0 === Repository::id_for_user( $u2['user_id'] ) && 0 === Repository::find_by_gstin( '07AAACR5055K1Z8' ), 'reject: the application is trashed, unlinked, and the GSTIN is free' );
nx_assert( ! in_array( Account_Types::ROLE, (array) get_userdata( $u2['user_id'] )->roles, true ) && ! Account_Types::can_sell( $u2['user_id'] ), 'reject: no vendor role, cannot sell' );
nx_assert( count( $mail ) === $sent + 1, 'reject: the former vendor is told by mail' );
nx_assert_same( '', trim( $decide( $v2 ) ) === '' ? '' : ( 0 === count( $buttons( $decide( $v2 ) ) ) ? '' : 'links' ), 'a trashed application offers no decision links' );

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
