<?php
// Phase 7a: three roles, three record types, the two helpers, the links between records, and the flows
// (customer, direct seller, Become a seller, approve, reject and re-apply, suspend, record deleted).
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Accounts\Links;
use Nexora\Core\Post_Types;
use Nexora\Vendors\Repository;
use Nexora\Vendors\Service;

global $wpdb;
nx_test_options_keep( array( $wpdb->prefix . 'user_roles', 'nexora_accounts_version' ) );
wp_set_current_user( 0 );

$roles_of = function ( $uid ) { clean_user_cache( $uid ); return array_values( (array) get_userdata( $uid )->roles ); };
$admin_do = function ( $record_id, $action ) {
	$was = get_current_user_id();
	wp_set_current_user( nx_test_admin() );
	$r = Service::change_status( $record_id, $action );
	wp_set_current_user( $was );
	return $r;
};
$biz = function ( $tag ) {
	return array(
		'business_name' => 'Model Shop ' . $tag, 'business_type' => 'other', 'gstin' => sprintf( '27MODEL%04dA1Z5', wp_rand( 0, 9999 ) ), 'business_email' => $tag . '@example.test',
		'business_phone' => '9811100003', 'business_address' => '3 Test Road', 'business_city' => 'Delhi', 'business_state' => 'Delhi', 'business_pincode' => '110001',
	);
};

/* ---------- the three record types ---------- */
nx_assert_same( 'nexora_customer', Post_Types::CUSTOMER, 'customer record type' );
nx_assert_same( 'nexora_seller', Post_Types::SELLER, 'direct-seller record type' );
nx_assert_same( 'nexora_cust_seller', Post_Types::CUST_SELLER, 'customer-who-became-a-seller record type' );
nx_assert_same( array( 'nexora_customer', 'nexora_seller' ), Post_Types::profiles(), 'the profile types: customer and direct seller (one list)' );
nx_assert_same( array( 'nexora_seller', 'nexora_cust_seller' ), Post_Types::sellers(), 'the seller-record types: direct seller and customer-seller (one list)' );
foreach ( array( Post_Types::CUSTOMER, Post_Types::SELLER, Post_Types::CUST_SELLER, 'user_connections', 'user_content', 'nexora_product' ) as $slug ) {
	nx_assert( post_type_exists( $slug ) && strlen( $slug ) <= 20, "$slug is registered and fits the 20-character limit" );
}
nx_assert( ! post_type_exists( 'user_profile' ) && ! post_type_exists( 'nexora_vendor' ), 'the old user_profile and nexora_vendor types are gone' );
foreach ( array( Post_Types::SELLER, Post_Types::CUST_SELLER ) as $slug ) {
	$pt = get_post_type_object( $slug );
	nx_assert( $pt && false === $pt->publicly_queryable && true === $pt->exclude_from_search && ! $pt->has_archive, "$slug has no front-end URL" );
}

/* ---------- a customer: no seller record ---------- */
$cust = nx_test_user( 'mcust' );
nx_assert( ! Account_Types::has_seller_record( $cust['user_id'] ) && ! Account_Types::can_sell( $cust['user_id'] ), 'customer: no seller record, cannot sell' );
nx_assert_same( array( 'subscriber' ), $roles_of( $cust['user_id'] ), 'customer: the core subscriber role' );
nx_assert_same( 'nexora_customer', get_post_type( $cust['profile_id'] ), 'customer: the profile is a nexora_customer post' );

/* ---------- Become a seller: pending nexora_cust_seller, role unchanged until approval ---------- */
$r   = Service::apply( $cust['user_id'], $biz( 'a' ) );
$sid = is_array( $r ) ? $r['vendor_id'] : 0;
$GLOBALS['nx_test']['posts'][] = $sid;
nx_assert( $sid && Post_Types::CUST_SELLER === get_post_type( $sid ), 'Become a seller: creates a nexora_cust_seller post' );
nx_assert_same( 'pending', Account_Types::vendor_status( $cust['user_id'] ), 'Become a seller: status pending' );
nx_assert_same( array( 'subscriber' ), $roles_of( $cust['user_id'] ), 'Become a seller: still subscriber until approved' );
nx_assert( Account_Types::has_seller_record( $cust['user_id'] ) && ! Account_Types::can_sell( $cust['user_id'] ), 'pending: has a seller record, cannot sell' );
nx_assert_same( $cust['profile_id'], (int) get_post_meta( $sid, 'customer_post_id', true ), 'link: the seller record names the customer post' );
nx_assert_same( $sid, (int) get_post_meta( $cust['profile_id'], 'seller_post_id', true ), 'link: the customer post names the seller record' );
nx_assert_same( $cust['profile_id'], (int) get_user_meta( $cust['user_id'], '_profile_id', true ), 'link: _profile_id stays the customer post' );
nx_assert_same( $sid, (int) get_user_meta( $cust['user_id'], '_seller_post_id', true ), 'link: _seller_post_id is the seller record' );
nx_assert_same( $sid, (int) get_user_meta( $cust['user_id'], '_vendor_id', true ), 'link: _vendor_id is kept' );
nx_assert_same( $sid, Repository::id_for_user( $cust['user_id'] ), 'Repository finds the record for the user' );

// the single linking method repairs a broken side and is idempotent
delete_post_meta( $cust['profile_id'], 'seller_post_id' );
delete_user_meta( $cust['user_id'], '_seller_post_id' );
Links::link( $sid );
Links::link( $sid );
nx_assert( $sid === (int) get_post_meta( $cust['profile_id'], 'seller_post_id', true ) && $sid === (int) get_user_meta( $cust['user_id'], '_seller_post_id', true ), 'Links::link repairs both sides and can be repeated' );
nx_assert_same( 1, count( get_post_meta( $cust['profile_id'], 'seller_post_id' ) ), 'Links::link repeated leaves one value' );

/* ---------- approve promotes; suspend keeps the role ---------- */
nx_assert( true === $admin_do( $sid, 'approve' ), 'approve works' );
nx_assert_same( array( Account_Types::ROLE_CUSTOMER_SELLER ), $roles_of( $cust['user_id'] ), 'approved: promoted to nexora_customer_seller' );
nx_assert( Account_Types::can_sell( $cust['user_id'] ), 'approved: can_sell' );
$admin_do( $sid, 'suspend' );
nx_assert_same( array( Account_Types::ROLE_CUSTOMER_SELLER ), $roles_of( $cust['user_id'] ), 'suspended: keeps nexora_customer_seller' );
nx_assert( Account_Types::has_seller_record( $cust['user_id'] ) && ! Account_Types::can_sell( $cust['user_id'] ) && Account_Types::can_view_earnings( $cust['user_id'] ), 'suspended: has a record, cannot sell, still reads earnings' );
nx_assert( user_can( $cust['user_id'], 'read' ), 'suspended: can still buy and browse (read)' );
$admin_do( $sid, 'reactivate' );
nx_assert( Account_Types::can_sell( $cust['user_id'] ), 'reactivated: can sell again' );

/* ---------- reject a customer: stays subscriber, record kept as rejected, can re-apply ---------- */
$c2   = nx_test_user( 'mrej' );
$b2   = $biz( 'rej' );
$r2   = Service::apply( $c2['user_id'], $b2 );
$sid2 = $r2['vendor_id'];
$GLOBALS['nx_test']['posts'][] = $sid2;
nx_assert( true === $admin_do( $sid2, 'reject' ), 'reject works' );
nx_assert_same( 'rejected', Account_Types::vendor_status( $c2['user_id'] ), 'rejected: the status is stored' );
nx_assert_same( array( 'subscriber' ), $roles_of( $c2['user_id'] ), 'rejected customer stays subscriber' );
nx_assert( 'publish' === get_post_status( $sid2 ) && Post_Types::CUST_SELLER === get_post_type( $sid2 ), 'rejected: the record is kept (not trashed)' );
nx_assert( Account_Types::has_seller_record( $c2['user_id'] ) && ! Account_Types::can_sell( $c2['user_id'] ), 'rejected: has a record, cannot sell' );
nx_assert_same( $sid2, Repository::id_for_user( $c2['user_id'] ), 'rejected: still linked' );
$again = Service::apply( $c2['user_id'], array_merge( $b2, array( 'business_name' => 'Second Try Shop' ) ) );
nx_assert( is_array( $again ) && $sid2 === $again['vendor_id'], 're-apply: reuses the same record' );
nx_assert_same( 'pending', Account_Types::vendor_status( $c2['user_id'] ), 're-apply: pending again' );
nx_assert_same( 'Second Try Shop', get_post_meta( $sid2, 'business_name', true ), 're-apply: the new details are stored' );
nx_assert( is_wp_error( Service::apply( $c2['user_id'], $b2 ) ), 'a pending applicant cannot apply again' );
$admin_do( $sid2, 'reject' );
$c3   = nx_test_user( 'mother' );
$take = Service::apply( $c3['user_id'], array_merge( $biz( 'o' ), array( 'gstin' => $b2['gstin'] ) ) );
nx_assert( is_array( $take ), 'a rejected record does not hold its GSTIN against other applicants' );
if ( is_array( $take ) ) { $GLOBALS['nx_test']['posts'][] = $take['vendor_id']; }

/* ---------- a direct seller: one nexora_seller post, no customer post ---------- */
$ds  = nx_test_direct_seller( 'mdirect' );
$dsr = $ds['seller_id'];
nx_assert_same( Post_Types::SELLER, get_post_type( $dsr ), 'direct seller: a nexora_seller post' );
nx_assert_same( 0, count( get_posts( array( 'post_type' => Post_Types::CUSTOMER, 'post_status' => 'any', 'meta_key' => '_wp_user_id', 'meta_value' => $ds['user_id'], 'fields' => 'ids' ) ) ), 'direct seller: no customer post' );
nx_assert_same( $dsr, (int) get_user_meta( $ds['user_id'], '_profile_id', true ), 'direct seller: _profile_id is the seller post' );
nx_assert_same( $dsr, (int) get_user_meta( $ds['user_id'], '_seller_post_id', true ), 'direct seller: _seller_post_id is also set (same post)' );
nx_assert_same( array( Account_Types::ROLE_SELLER ), $roles_of( $ds['user_id'] ), 'direct seller: role nexora_seller from registration' );
nx_assert_same( 'pending', Account_Types::vendor_status( $ds['user_id'] ), 'direct seller: pending' );
nx_assert_same( 'Dir', get_post_meta( $dsr, 'first_name', true ), 'direct seller: the personal details live on the seller post' );
nx_assert( '' !== get_post_meta( $dsr, 'business_name', true ) && '' !== get_post_meta( $dsr, 'gstin', true ), 'direct seller: and so do the business details' );
nx_assert( ! Account_Types::can_sell( $ds['user_id'] ) && Account_Types::has_seller_record( $ds['user_id'] ), 'direct seller pending: cannot sell yet' );
$admin_do( $dsr, 'approve' );
nx_assert( Account_Types::can_sell( $ds['user_id'] ), 'direct seller approved: can sell' );
nx_assert_same( array( Account_Types::ROLE_SELLER ), $roles_of( $ds['user_id'] ), 'direct seller approved: role unchanged' );
$admin_do( $dsr, 'suspend' );
nx_assert_same( array( Account_Types::ROLE_SELLER ), $roles_of( $ds['user_id'] ), 'direct seller suspended: keeps the role' );
$admin_do( $dsr, 'reactivate' );

$dr = nx_test_direct_seller( 'mdirrej', 'rejected' );
nx_assert_same( 'rejected', Account_Types::vendor_status( $dr['user_id'] ), 'rejected direct seller: status rejected' );
nx_assert_same( array( Account_Types::ROLE_SELLER ), $roles_of( $dr['user_id'] ), 'rejected direct seller keeps nexora_seller' );
nx_assert( 'publish' === get_post_status( $dr['seller_id'] ) && ! Account_Types::can_sell( $dr['user_id'] ), 'rejected direct seller: record kept, cannot sell' );
nx_assert( user_can( $dr['user_id'], 'read' ), 'rejected direct seller: can still buy' );
$ra = Service::apply( $dr['user_id'], array_merge( $biz( 'dr' ), array( 'gstin' => $dr['gstin'] ) ) );
nx_assert( is_array( $ra ) && $dr['seller_id'] === $ra['vendor_id'] && 'pending' === Account_Types::vendor_status( $dr['user_id'] ), 'rejected direct seller can re-apply on the same record' );

/* ---------- GSTIN is unique across both seller types; "all sellers" covers both ---------- */
$g_cust = $biz( 'gc' );
$other  = nx_test_user( 'mgst' );
$res    = Service::apply( $other['user_id'], array_merge( $g_cust, array( 'gstin' => $ds['gstin'] ) ) );
nx_assert( is_wp_error( $res ), 'a customer cannot use the GSTIN of a direct seller' );
$res2 = Service::register_seller( ( $x = nx_test_user_only( 'mgst2' ) ), array( 'user_name' => 'x', 'email' => 'x@example.test' ), array_merge( $biz( 'gd' ), array( 'gstin' => get_post_meta( $sid, 'gstin', true ) ) ) );
nx_assert( is_wp_error( $res2 ), 'a direct seller cannot use the GSTIN of a customer-seller' );
nx_assert_same( $dsr, Repository::find_by_gstin( strtolower( $ds['gstin'] ) ), 'find_by_gstin sees direct sellers' );
nx_assert_same( $sid, Repository::find_by_gstin( get_post_meta( $sid, 'gstin', true ) ), 'find_by_gstin sees customer-sellers' );
$approved = Repository::approved_ids();
nx_assert( in_array( $sid, $approved, true ) && in_array( $dsr, $approved, true ), 'approved_ids covers both seller types' );
nx_assert( ! in_array( $dr['seller_id'], $approved, true ), 'approved_ids leaves out a pending seller of either type' );

/* ---------- the shared guards accept both profile types ---------- */
add_action( 'wp_ajax_nx_tmp_member', function () { wp_send_json_success( \Nexora\Http\Ajax::member( 'profile_nonce' ) ); } );
foreach ( array( 'customer' => $cust, 'direct seller' => $ds ) as $label => $who ) {
	wp_set_current_user( $who['user_id'] );
	$reply = nx_call_ajax( 'nx_tmp_member', nx_profile_nonce() );
	nx_assert( ! empty( $reply['success'] ) && (int) $reply['data']['profile_id'] === (int) get_user_meta( $who['user_id'], '_profile_id', true ), "Ajax::member lets a $label through with their own profile" );
}
nx_assert_same( $ds['profile_id'], \Nexora\Profile\Repository::id_for_user( $ds['user_id'] ), 'Profile\Repository finds the direct seller profile' );
nx_assert_same( $ds['profile_id'], \Nexora\Profile\Repository::id_by_username( $ds['login'] ), 'and finds it by username' );
// Connections work between a customer and a direct seller.
wp_set_current_user( $cust['user_id'] );
$sent = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $ds['profile_id'] ) + nx_profile_nonce() );
nx_assert( ! empty( $sent['success'] ), 'a customer can send a connection request to a direct seller' );
wp_set_current_user( 0 );

/* ---------- the record is deleted: demotion, and a plain customer post for a direct seller ---------- */
$cd = nx_test_user( 'mdelc' );
$cr = Service::apply( $cd['user_id'], $biz( 'dc' ) );
$admin_do( $cr['vendor_id'], 'approve' );
Repository::delete( $cr['vendor_id'] );
nx_assert_same( array( 'subscriber' ), $roles_of( $cd['user_id'] ), 'record deleted: a customer-seller is demoted to subscriber' );
nx_assert( 'none' === Account_Types::vendor_status( $cd['user_id'] ) && ! Account_Types::has_seller_record( $cd['user_id'] ), 'record deleted: status none, no record' );
nx_assert( '' === (string) get_post_meta( $cd['profile_id'], 'seller_post_id', true ) && '' === (string) get_user_meta( $cd['user_id'], '_seller_post_id', true ) && '' === (string) get_user_meta( $cd['user_id'], '_vendor_id', true ), 'record deleted: every link is cleared' );
nx_assert_same( $cd['profile_id'], (int) get_user_meta( $cd['user_id'], '_profile_id', true ), 'record deleted: the customer profile is untouched' );

$dd = nx_test_direct_seller( 'mdeld', 'approved' );
Repository::delete( $dd['seller_id'] );
$pid = (int) get_user_meta( $dd['user_id'], '_profile_id', true );
if ( $pid ) { $GLOBALS['nx_test']['posts'][] = $pid; }
nx_assert_same( array( 'subscriber' ), $roles_of( $dd['user_id'] ), 'record deleted: a direct seller is demoted to subscriber' );
nx_assert( $pid && Post_Types::CUSTOMER === get_post_type( $pid ) && $pid !== $dd['seller_id'], 'record deleted: a plain nexora_customer post is created for them' );
nx_assert_same( $dd['user_id'], (int) get_post_meta( $pid, '_wp_user_id', true ), 'the new customer post belongs to the user' );
nx_assert_same( $dd['login'], get_post_meta( $pid, 'user_name', true ), 'the new customer post keeps the username (their profile address still works)' );
nx_assert_same( 'Dir', get_post_meta( $pid, 'first_name', true ), 'the new customer post keeps the personal details' );
nx_assert( '' === (string) get_user_meta( $dd['user_id'], '_seller_post_id', true ) && 'none' === Account_Types::vendor_status( $dd['user_id'] ), 'record deleted: no seller link, status none' );
Repository::delete( $dd['seller_id'] );
nx_assert_same( 1, count( get_posts( array( 'post_type' => Post_Types::CUSTOMER, 'post_status' => 'any', 'meta_key' => '_wp_user_id', 'meta_value' => $dd['user_id'], 'fields' => 'ids' ) ) ), 'deleting twice makes no second customer post' );

/* ---------- the customer admin screen: a read-only "Seller account" box ---------- */
$box = function ( $post_id ) {
	wp_set_current_user( nx_test_admin() );
	ob_start();
	( new \Nexora\Vendors\Admin() )->render_seller_account_box( get_post( $post_id ) );
	return ob_get_clean();
};
$html = $box( $cust['profile_id'] );
nx_assert( false !== strpos( $html, 'Model Shop a' ) && false !== strpos( $html, 'Approved' ) && false !== strpos( $html, 'post=' . $sid ), 'seller box: shows the business, the status and links to the seller record' );
nx_assert( false === stripos( $html, '<input' ) && false === stripos( $html, '<select' ), 'seller box: read-only' );
$plain = nx_test_user( 'mnotseller' );
nx_assert( false !== stripos( $box( $plain['profile_id'] ), 'not a seller' ), 'seller box: says so for a customer who is not a seller' );
$sbox = function ( $post_id ) { ob_start(); ( new \Nexora\Vendors\Admin() )->render_details_box( get_post( $post_id ) ); return ob_get_clean(); };
nx_assert( false !== strpos( $sbox( $sid ), 'post=' . $cust['profile_id'] ), 'the seller record links back to the customer post' );
global $wp_meta_boxes;
do_action( 'add_meta_boxes', Post_Types::CUSTOMER, get_post( $cust['profile_id'] ) );
nx_assert( isset( $wp_meta_boxes[ Post_Types::CUSTOMER ]['side']['default']['nexora_seller_account'] ) || isset( $wp_meta_boxes[ Post_Types::CUSTOMER ]['side']['high']['nexora_seller_account'] ) || isset( $wp_meta_boxes[ Post_Types::CUSTOMER ]['normal']['default']['nexora_seller_account'] ), 'seller box: registered on the customer screen' );

/* ---------- nobody reaches another account's business data ---------- */
$a = nx_test_vendor( 'miso_a', 'approved' );
$b = nx_test_vendor( 'miso_b', 'approved' );
$c = nx_test_direct_seller( 'miso_c', 'approved' );
foreach ( array( 'customer-seller' => array( $a, $b ), 'direct seller' => array( $c, $b ) ) as $label => $pair ) {
	wp_set_current_user( $pair[0]['user_id'] );
	$ok = Service::update_details( $pair[0]['user_id'], array( 'business_city' => 'Mumbai' ) );
	nx_assert( true === $ok && 'Mumbai' === get_post_meta( $pair[0]['vendor_id'], 'business_city', true ), "$label: edits their own record" );
	nx_assert( 'Mumbai' !== get_post_meta( $pair[1]['vendor_id'], 'business_city', true ), "$label: the other seller's record is untouched" );
}
$script = \Nexora\Profile\Privacy::script_data( $c['profile_id'], 'viewer' );
nx_assert( false === strpos( wp_json_encode( $script ), $c['gstin'] ) && false === strpos( wp_json_encode( $script ), 'business_name' ), 'a viewer of a direct seller profile gets no business data' );
wp_set_current_user( 0 );

nx_test_finish();
