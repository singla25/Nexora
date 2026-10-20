<?php
// Phase 7a: the in-place conversion of the old model (user_profile / nexora_vendor / nexora_vendor role) to the new one.
// Every case is a fixture this test builds and puts into the OLD shape with raw SQL; the migration is called with a scope that
// names only those fixtures, so the site's real members and sellers are never touched. (The unscoped run is
// `wp nexora migrate-accounts`, rehearsed on a scratch copy of the database.)
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Accounts\Migration;
use Nexora\Core\Post_Types;
use Nexora\Orders\Earnings;
use Nexora\Orders\Lines;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }

global $wpdb;
nx_test_options_keep( array( $wpdb->prefix . 'user_roles', 'nexora_accounts_version', 'nexora_accounts_migration' ) );
nx_test_marketplace_item_scope();
nx_test_reset_limits();
wp_set_current_user( 0 );

$set_type = function ( $post_id, $type ) use ( $wpdb ) {
	$wpdb->update( $wpdb->posts, array( 'post_type' => $type ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	clean_post_cache( $post_id );
};
$roles_of = function ( $uid ) { clean_user_cache( $uid ); return array_values( (array) get_userdata( $uid )->roles ); };

// The old shape: customer post is user_profile, the application is nexora_vendor, no link meta, an approved vendor holds the
// additive nexora_vendor role next to subscriber, a suspended or pending one holds only subscriber.
if ( null === get_role( Account_Types::LEGACY_ROLE ) ) { add_role( Account_Types::LEGACY_ROLE, 'Nexora Vendor', array( 'nexora_manage_own_products' => true ) ); }
$to_legacy = function ( array $u ) use ( $set_type ) {
	$set_type( $u['profile_id'], 'user_profile' );
	delete_post_meta( $u['profile_id'], 'seller_post_id' );
	delete_user_meta( $u['user_id'], '_seller_post_id' );
	if ( ! empty( $u['vendor_id'] ) ) {
		$set_type( $u['vendor_id'], 'nexora_vendor' );
		delete_post_meta( $u['vendor_id'], 'customer_post_id' );
	}
	$user = get_userdata( $u['user_id'] );
	$user->set_role( 'subscriber' );
	if ( 'approved' === Account_Types::vendor_status( $u['user_id'] ) ) { $user->add_role( Account_Types::LEGACY_ROLE ); }
};

$appr = nx_test_vendor( 'mgapproved', 'approved' );
$pend = nx_test_vendor( 'mgpending', 'pending' );
$susp = nx_test_vendor( 'mgsuspended', 'suspended' );
$cust = nx_test_user( 'mgcustomer' );
$rej  = nx_test_vendor( 'mgrejected', 'pending' );
// a rejected application of the old model: trashed, unlinked, status none
$rej_vid = $rej['vendor_id'];
$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $rej['user_id'], 'meta_key' => Account_Types::STATUS_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_user_meta( $rej['user_id'], '_vendor_id' );
delete_user_meta( $rej['user_id'], '_seller_post_id' );
delete_post_meta( $rej_vid, 'customer_post_id' );
delete_post_meta( $rej['profile_id'], 'seller_post_id' );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $rej_vid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
unset( $rej['vendor_id'] );

// A sale to check "nothing about money changes": a product, an order, the vendor's figures.
$buyer = nx_test_user( 'mgbuyer' );
$prod  = nx_test_product( $appr, 'MG-1', '100.00', '10' );
$order = nx_test_order( $buyer, array( array( $prod, 2 ) ), array( 'payment' => 'cod' ) );
$figures = function () use ( $appr ) { return array( Earnings::summary( $appr['vendor_id'] ), \Nexora\Products\Listing::approved_vendor_ids() ? in_array( $appr['vendor_id'], \Nexora\Products\Listing::approved_vendor_ids(), true ) : false, get_post_meta( $GLOBALS['nx_mg_prod'], 'vendor_id', true ) ); };
$GLOBALS['nx_mg_prod'] = $prod;
$line_vendor = function () use ( $order ) { return Lines::vendor_ids( wc_get_order( $order['order']->get_id() ) ); };
$before_figures = $figures();
$before_lines   = $line_vendor();
$before_orders  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$before_prod    = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $prod ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( $appr, $pend, $susp, $cust ) as $u ) { $to_legacy( $u ); }
$set_type( $rej_vid, 'nexora_vendor' );
$set_type( $rej['profile_id'], 'user_profile' );

$scope = array(
	'post_ids' => array( $appr['profile_id'], $appr['vendor_id'], $pend['profile_id'], $pend['vendor_id'], $susp['profile_id'], $susp['vendor_id'], $cust['profile_id'], $rej['profile_id'], $rej_vid ),
	'user_ids' => array( $appr['user_id'], $pend['user_id'], $susp['user_id'], $cust['user_id'], $rej['user_id'] ),
);

/* ---------- the dry-run reads, reports and writes nothing ---------- */
$snapshot = function () use ( $wpdb, $scope ) {
	$ids = implode( ',', array_map( 'intval', $scope['post_ids'] ) );
	return md5( wp_json_encode( array(
		$wpdb->get_results( "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ($ids) ORDER BY ID", ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids) ORDER BY meta_id", ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN (" . implode( ',', array_map( 'intval', $scope['user_ids'] ) ) . ') AND meta_key NOT LIKE "session%" ORDER BY umeta_id', ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	) ) );
};
$state_before = $snapshot();
$roles_before = wp_json_encode( get_option( $wpdb->prefix . 'user_roles' ) );
$plan = Migration::plan( $scope );
nx_assert_same( $state_before, $snapshot(), 'dry-run: not one row changed' );
nx_assert_same( $roles_before, wp_json_encode( get_option( $wpdb->prefix . 'user_roles' ) ), 'dry-run: the role list is untouched' );
nx_assert_same( 5, $plan['customers'], 'dry-run: 5 user_profile posts to become nexora_customer' );
nx_assert_same( 4, $plan['sellers'], 'dry-run: 4 nexora_vendor posts to become nexora_cust_seller (approved, pending, suspended, rejected)' );
nx_assert_same( 2, $plan['roles_to_customer_seller'], 'dry-run: 2 users end as Customer & Seller (approved and suspended)' );
nx_assert_same( 1, $plan['legacy_role_removed'], 'dry-run: 1 user still holds the old nexora_vendor role' );
nx_assert_same( array(), $plan['anomalies'], 'dry-run: nothing odd in these fixtures' );
nx_assert( empty( $plan['applied'] ), 'dry-run: says it applied nothing' );

/* ---------- apply ---------- */
$done = Migration::run( $scope );
nx_assert( ! empty( $done['applied'] ), 'run: reports that it applied' );
nx_assert_same( 5, $done['customers'], 'run: 5 customer posts converted' );
nx_assert_same( 4, $done['sellers'], 'run: 4 seller records converted' );

foreach ( array( $appr, $pend, $susp, $cust, $rej ) as $u ) {
	nx_assert_same( Post_Types::CUSTOMER, get_post_type( $u['profile_id'] ), 'customer post converted in place (same ID)' );
}
foreach ( array( $appr, $pend, $susp ) as $u ) {
	$vid = Nexora\Vendors\Repository::id_for_user( $u['user_id'] );
	nx_assert_same( $u['vendor_id'], $vid, 'the seller record keeps its ID' );
	nx_assert_same( Post_Types::CUST_SELLER, get_post_type( $vid ), 'seller record is now nexora_cust_seller' );
	nx_assert_same( $u['profile_id'], (int) get_post_meta( $vid, 'customer_post_id', true ), 'linked to the customer post (customer_post_id)' );
	nx_assert_same( $vid, (int) get_post_meta( $u['profile_id'], 'seller_post_id', true ), 'linked back (seller_post_id)' );
	nx_assert_same( $vid, (int) get_user_meta( $u['user_id'], '_seller_post_id', true ), 'user meta _seller_post_id set' );
	nx_assert_same( $vid, (int) get_user_meta( $u['user_id'], '_vendor_id', true ), '_vendor_id kept' );
}
nx_assert_same( array( Account_Types::ROLE_CUSTOMER_SELLER ), $roles_of( $appr['user_id'] ), 'approved: nexora_customer_seller (the old role is gone from the user)' );
nx_assert_same( array( Account_Types::ROLE_CUSTOMER_SELLER ), $roles_of( $susp['user_id'] ), 'suspended: nexora_customer_seller (suspension keeps the role)' );
nx_assert_same( array( 'subscriber' ), $roles_of( $pend['user_id'] ), 'pending: subscriber' );
nx_assert_same( array( 'subscriber' ), $roles_of( $cust['user_id'] ), 'customer: subscriber' );
nx_assert_same( 'approved', Account_Types::vendor_status( $appr['user_id'] ), 'statuses are kept (approved)' );
nx_assert_same( 'suspended', Account_Types::vendor_status( $susp['user_id'] ), 'statuses are kept (suspended)' );
nx_assert_same( 'pending', Account_Types::vendor_status( $pend['user_id'] ), 'statuses are kept (pending)' );
nx_assert( Account_Types::can_sell( $appr['user_id'] ) && ! Account_Types::can_sell( $susp['user_id'] ) && ! Account_Types::can_sell( $pend['user_id'] ), 'can_sell is right for each' );
nx_assert_same( Post_Types::CUST_SELLER, get_post_type( $rej_vid ), 'the old rejected (trashed) record is converted too' );
nx_assert_same( 'trash', get_post_status( $rej_vid ), 'and stays in the trash' );
nx_assert_same( '', (string) get_user_meta( $rej['user_id'], '_seller_post_id', true ), 'and is not linked to the user' );
nx_assert_same( 'none', Account_Types::vendor_status( $rej['user_id'] ), 'the user of an old rejection is still status none' );

/* ---------- running it twice changes nothing ---------- */
$state_after = $snapshot();
$again = Migration::run( $scope );
nx_assert_same( $state_after, $snapshot(), 'second run: not one row changed' );
nx_assert_same( array( 0, 0, 0, 0 ), array( $again['customers'], $again['sellers'], $again['roles_to_customer_seller'], $again['legacy_role_removed'] ), 'second run: every count is zero' );
nx_assert_same( array( 0, 0 ), array( Migration::plan( $scope )['customers'], Migration::plan( $scope )['sellers'] ), 'a dry-run after the migration finds nothing to do' );

/* ---------- money, orders and listings are exactly as before ---------- */
nx_assert_same( $before_figures, $figures(), 'earnings, listing and product owner are unchanged' );
nx_assert_same( $before_lines, $line_vendor(), 'the order line still points at the same seller record ID' );
nx_assert_same( $before_orders, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ), 'no order was added or removed' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
nx_assert_same( $before_prod, $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $prod ) ), 'the product is as it was' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/* ---------- the old role goes only when nobody holds it ---------- */
get_userdata( $cust['user_id'] )->add_role( Account_Types::LEGACY_ROLE );
nx_assert( false === Migration::remove_legacy_role(), 'the old role stays while a user still holds it' );
nx_assert( null !== get_role( Account_Types::LEGACY_ROLE ), '... and is still registered' );
get_userdata( $cust['user_id'] )->remove_role( Account_Types::LEGACY_ROLE );

nx_test_finish();
