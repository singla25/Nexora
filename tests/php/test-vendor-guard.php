<?php
// A vendor application can only be changed through Vendors\Service: no Publish box, no Trash (single or bulk),
// no restore, no creating a vendor post by hand, no changing a post's status or title from outside.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Vendors\Admin;
use Nexora\Vendors\Repository;
use Nexora\Vendors\Service;

global $wp_meta_boxes;
nx_test_capture_mail();

$biz = function ( $gst ) {
	return array(
		'business_name' => 'Guarded Shop', 'business_type' => 'llp', 'gstin' => $gst, 'business_email' => 'guard@example.test', 'business_phone' => '9811100001',
		'business_address' => '9 Guard Street', 'business_city' => 'Delhi', 'business_state' => 'Delhi', 'business_pincode' => '110001',
	);
};
$admin_id = nx_test_admin();
$u        = nx_test_user( 'guard' );
$r        = Service::apply( $u['user_id'], $biz( '27AAPFU0939F1ZV' ) );
$vid      = (int) ( $r['vendor_id'] ?? 0 );
$GLOBALS['nx_test']['posts'][] = $vid;
$pt = get_post_type_object( 'nexora_vendor' );

/* ---------- no Publish box ---------- */
$wp_meta_boxes = array();
wp_set_current_user( $admin_id );
add_meta_box( 'submitdiv', 'Publish', '__return_null', 'nexora_vendor', 'side', 'core' );
do_action( 'add_meta_boxes', 'nexora_vendor', get_post( $vid ) );
wp_set_current_user( 0 );
nx_assert( empty( $wp_meta_boxes['nexora_vendor']['side']['core']['submitdiv'] ), 'the Publish/Update box is removed from the vendor screen' );
nx_assert( isset( $wp_meta_boxes['nexora_vendor']['side']['high']['nexora_vendor_decision'] ), 'the decision box is still there' );

// Other post types keep their Publish box.
$wp_meta_boxes = array();
add_meta_box( 'submitdiv', 'Publish', '__return_null', 'user_profile', 'side', 'core' );
wp_set_current_user( $admin_id );
do_action( 'add_meta_boxes', 'user_profile', get_post( $u['profile_id'] ) );
wp_set_current_user( 0 );
nx_assert( ! empty( $wp_meta_boxes['user_profile']['side']['core']['submitdiv'] ), 'other post types keep their Publish box' );

/* ---------- list screen: no bulk actions, no Trash/Restore/Quick Edit row links ---------- */
$bulk = apply_filters( 'bulk_actions-edit-nexora_vendor', array( 'edit' => 'Edit', 'trash' => 'Move to Trash', 'untrash' => 'Restore', 'delete' => 'Delete permanently' ) );
nx_assert_same( array(), $bulk, 'the bulk actions are gone (no bulk Trash)' );
$all = array( 'edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit', 'trash' => 'Trash', 'untrash' => 'Restore', 'delete' => 'Delete Permanently', 'view' => 'View' );
wp_set_current_user( $admin_id );
$live = apply_filters( 'post_row_actions', $all, get_post( $vid ) );
nx_assert( ! array_intersect( array_keys( $live ), array( 'trash', 'untrash', 'delete', 'inline hide-if-no-js', 'view' ) ), 'a live row has no Trash / Restore / Delete / Quick Edit / View link' );
nx_assert( isset( $live['edit'] ) && isset( $live['approve'] ), 'a live row keeps Edit and the decision links' );
$list = apply_filters( 'post_row_actions', $all, get_post( $vid ) );
Service::change_status( $vid, 'reject' );
$dead = apply_filters( 'post_row_actions', $all, get_post( $vid ) );
nx_assert( ! array_intersect( array_keys( $dead ), array( 'trash', 'untrash', 'delete', 'inline hide-if-no-js', 'view', 'approve', 'reject' ) ), 'a rejected row has no Restore / Delete / decision link' );
wp_set_current_user( 0 );
nx_assert_same( 'trash', get_post_status( $vid ), 'setup: the application was rejected through Service (trash)' );

/* ---------- restoring a rejected application would re-reserve its GSTIN: refused ---------- */
wp_set_current_user( $admin_id );
wp_untrash_post( $vid );
wp_set_current_user( 0 );
nx_assert_same( 'trash', get_post_status( $vid ), 'wp_untrash_post cannot restore a rejected application' );
nx_assert_same( 0, Repository::find_by_gstin( '27AAPFU0939F1ZV' ), 'the GSTIN is still free after a refused restore' );

/* ---------- a live application cannot be trashed, deleted or edited around Service ---------- */
$u2   = nx_test_user( 'guard2' );
$r2   = Service::apply( $u2['user_id'], $biz( '29ABCDE1234F1Z5' ) );
$vid2 = (int) ( $r2['vendor_id'] ?? 0 );
$GLOBALS['nx_test']['posts'][] = $vid2;
nx_assert( $vid2 > 0 && 'pending' === Account_Types::vendor_status( $u2['user_id'] ), 'setup: a live pending application' );

wp_set_current_user( $admin_id );
nx_assert( ! current_user_can( 'delete_post', $vid2 ), 'admin: the capability to trash a live application is withdrawn (no Trash link, post.php?action=trash fails)' );
nx_assert( ! current_user_can( $pt->cap->create_posts ), 'admin: cannot create a vendor application by hand (no Add New)' );
nx_assert( current_user_can( 'edit_post', $vid2 ), 'admin: can still open the application to read it' );

wp_trash_post( $vid2 );
nx_assert_same( 'publish', get_post_status( $vid2 ), 'wp_trash_post is refused for a live application' );
wp_delete_post( $vid2, true );
nx_assert( null !== get_post( $vid2 ), 'wp_delete_post is refused for a live application' );
wp_update_post( array( 'ID' => $vid2, 'post_status' => 'trash' ) );
nx_assert_same( 'publish', get_post_status( $vid2 ), 'wp_update_post cannot set the status to trash' );
wp_update_post( array( 'ID' => $vid2, 'post_status' => 'draft', 'post_title' => 'Renamed from outside' ) );
nx_assert( 'publish' === get_post_status( $vid2 ) && 'Guarded Shop' === get_the_title( $vid2 ), 'wp_update_post cannot change the status or the title (only Service does)' );
wp_set_current_user( 0 );
nx_assert( Repository::id_for_user( $u2['user_id'] ) === $vid2 && 'pending' === Account_Types::vendor_status( $u2['user_id'] ), 'the application is intact and still pending' );

// The sanctioned paths still work.
wp_set_current_user( $admin_id );
nx_assert( true === Service::change_status( $vid2, 'approve' ), 'Service can still approve' );
Repository::save( $vid2, array( 'business_name' => 'Renamed By Service' ) );
nx_assert_same( 'Renamed By Service', get_the_title( $vid2 ), 'the Repository (Service) can still rename the post' );
wp_set_current_user( 0 );

// Deleting the member removes their application (delete_with_user); this is not blocked.
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $u2['user_id'] );
nx_assert_same( null, get_post( $vid2 ), 'deleting the member still deletes their application' );
// Rolling back a failed application still works (it deletes through the Repository).
$u3 = nx_test_user( 'guard3' );
$id = Repository::create( $u3['user_id'], $biz( '07AAACR5055K1Z8' ) );
Repository::delete( $id );
nx_assert_same( null, get_post( $id ), 'Repository::delete (rollback) is allowed' );
nx_assert_same( 0, Repository::id_for_user( $u3['user_id'] ), 'and unlinks the user' );

/* ---------- the guard is wired ---------- */
nx_assert( false !== has_filter( 'pre_trash_post' ) && false !== has_filter( 'pre_untrash_post' ) && false !== has_filter( 'pre_delete_post' ) && false !== has_filter( 'wp_insert_post_data' ), 'the guard filters are registered' );

nx_test_finish();
