<?php
// Marketplace Phase 1: account types (customer / vendor). Foundation only, nothing visible changes.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;

global $wpdb;
// This test removes and re-creates the vendor role and edits two settings; the site's originals (the roles table included) come back at the end.
nx_test_options_keep( array( $wpdb->prefix . 'user_roles', 'nexora_accounts_version', 'nexora_delete_data_on_uninstall' ) );

/* ---------- role registration: idempotent, self-upgrading ---------- */
remove_role( Account_Types::ROLE );
delete_option( 'nexora_accounts_version' );
nx_assert( null === get_role( Account_Types::ROLE ), 'setup: vendor role removed' );

Account_Types::maybe_register();
$role = get_role( Account_Types::ROLE );
nx_assert( null !== $role, 'maybe_register creates the vendor role when the stored version is old' );
nx_assert( $role && $role->has_cap( 'nexora_manage_own_products' ) && $role->has_cap( 'nexora_view_own_analytics' ), 'vendor role carries the two narrow caps' );
nx_assert( $role && ! $role->has_cap( 'upload_files' ) && ! $role->has_cap( 'edit_posts' ) && ! $role->has_cap( 'manage_options' ), 'vendor role carries nothing broader' );
nx_assert_same( Account_Types::VERSION, get_option( 'nexora_accounts_version' ), 'the registered version is stored' );

$before = wp_json_encode( get_role( Account_Types::ROLE )->capabilities );
Account_Types::register_role();
Account_Types::register_role();
nx_assert_same( $before, wp_json_encode( get_role( Account_Types::ROLE )->capabilities ), 'register_role is idempotent' );

get_role( Account_Types::ROLE )->remove_cap( 'nexora_view_own_analytics' );
Account_Types::register_role();
nx_assert( get_role( Account_Types::ROLE )->has_cap( 'nexora_view_own_analytics' ), 'register_role restores a drifted capability' );

update_option( 'nexora_accounts_version', '0' );
remove_role( Account_Types::ROLE );
Account_Types::maybe_register();
nx_assert( null !== get_role( Account_Types::ROLE ), 'an upgrade re-creates a deleted role without reactivation' );

remove_role( Account_Types::ROLE );
Account_Types::maybe_register();
nx_assert( null === get_role( Account_Types::ROLE ), 'maybe_register does nothing when the stored version is current' );
Account_Types::register_role();

/* ---------- existing members are customers, untouched ---------- */
$alice = nx_test_user( 'atalice' );
$uid   = $alice['user_id'];
nx_assert_same( 'none', Account_Types::vendor_status( $uid ), 'an existing member has vendor status none' );
nx_assert( ! Account_Types::is_vendor( $uid ), 'an existing member is not a vendor' );
nx_assert( ! Account_Types::can_sell( $uid ), 'an existing member cannot sell' );
nx_assert( ! in_array( Account_Types::ROLE, (array) get_userdata( $uid )->roles, true ), 'an existing member does not carry the vendor role' );
nx_assert( ! Account_Types::is_vendor( 0 ) && 'none' === Account_Types::vendor_status( 0 ) && ! Account_Types::can_sell( 0 ), 'a guest is not a vendor' );
nx_assert( array() === Account_Types::tabs_for( 0 ), 'a guest has no owner tabs' );

/* ---------- status transitions ---------- */
$allowed = array( 'none>pending', 'pending>approved', 'pending>none', 'approved>suspended', 'suspended>approved');
foreach ( array( 'none', 'pending', 'approved', 'suspended' ) as $from ) {
	foreach ( array( 'none', 'pending', 'approved', 'suspended' ) as $to ) {
		nx_assert_same( in_array( "$from>$to", $allowed, true ), Account_Types::transition_allowed( $from, $to ), "transition $from > $to" );
	}
}
nx_assert( ! Account_Types::transition_allowed( 'none', 'administrator' ) && ! Account_Types::transition_allowed( 'bogus', 'approved' ), 'unknown statuses are never allowed' );

$r = Account_Types::set_status( $uid, 'approved' );
nx_assert( is_wp_error( $r ) && 'none' === Account_Types::vendor_status( $uid ), 'none > approved is refused and changes nothing' );
$r = Account_Types::set_status( $uid, 'owner' );
nx_assert( is_wp_error( $r ), 'an unknown status is refused' );

nx_assert( true === Account_Types::set_status( $uid, 'pending' ), 'none > pending works' );
nx_assert_same( 'pending', Account_Types::vendor_status( $uid ), 'status is stored' );
nx_assert( Account_Types::is_vendor( $uid ) && ! Account_Types::can_sell( $uid ), 'pending: a vendor applicant who cannot sell' );
nx_assert( ! user_can( $uid, 'nexora_manage_own_products' ), 'pending: no vendor caps' );

nx_assert( true === Account_Types::set_status( $uid, 'approved' ), 'pending > approved works' );
$u = get_userdata( $uid );
nx_assert( Account_Types::can_sell( $uid ) && user_can( $uid, 'nexora_manage_own_products' ) && user_can( $uid, 'nexora_view_own_analytics' ), 'approved: can sell, has both caps' );
nx_assert( in_array( 'subscriber', (array) $u->roles, true ) && in_array( Account_Types::ROLE, (array) $u->roles, true ), 'approved: vendor role is ADDED, the original role stays' );
nx_assert( user_can( $uid, 'read' ), 'approved: customer abilities are kept' );
$twin = nx_test_user( 'attwin' );
nx_assert_same( user_can( $twin['user_id'], 'upload_files' ), user_can( $uid, 'upload_files' ), 'approved: upload rights are exactly a customer\'s (the vendor role adds none)' );
nx_assert( ! user_can( $uid, 'manage_options' ) && ! user_can( $uid, 'edit_posts' ), 'approved: no admin or editor abilities' );

nx_assert( true === Account_Types::set_status( $uid, 'suspended' ), 'approved > suspended works' );
nx_assert( ! Account_Types::can_sell( $uid ) && ! user_can( $uid, 'nexora_manage_own_products' ), 'suspended: cannot sell and has no caps' );
nx_assert( in_array( 'subscriber', (array) get_userdata( $uid )->roles, true ) && user_can( $uid, 'read' ), 'suspended: still a normal member' );
nx_assert( Account_Types::is_vendor( $uid ), 'suspended: still known as a vendor (history stays)' );

nx_assert( true === Account_Types::set_status( $uid, 'approved' ), 'suspended > approved works' );
nx_assert( Account_Types::can_sell( $uid ), 're-approved: can sell again' );

// A status written straight into user meta without the role cannot sell.
get_userdata( $uid )->remove_role( Account_Types::ROLE );
clean_user_cache( $uid );
nx_assert( ! Account_Types::can_sell( $uid ), 'approved status without the role cannot sell (both are required)' );
$wpdb->update( $wpdb->usermeta, array( 'meta_value' => 'garbage' ), array( 'user_id' => $uid, 'meta_key' => Account_Types::STATUS_META ) ); // phpcs:ignore
clean_user_cache( $uid ); wp_cache_delete( $uid, 'user_meta' );
nx_assert_same( 'none', Account_Types::vendor_status( $uid ), 'a corrupt stored status reads as none' );

/* ---------- the client never chooses the account type ---------- */
nx_assert_same( 'seller', Account_Types::sanitize_intent( 'seller' ), 'intent: seller' );
nx_assert_same( 'customer', Account_Types::sanitize_intent( 'customer' ), 'intent: customer' );
foreach ( array( 'administrator', 'editor', 'nexora_vendor', 'SELLER ', '', null, array( 'seller' ), 5 ) as $junk ) {
	nx_assert_same( 'customer', Account_Types::sanitize_intent( $junk ), 'intent: ' . wp_json_encode( $junk ) . ' falls back to customer' );
}

// A sign-up that tries to pick its own role / status is a plain customer.
nx_test_reset_limits();
$tag = strtolower( wp_generate_password( 6, false ) );
$reg = array( 'email' => "at$tag@example.test", 'user_name' => "nxtest_at_$tag", 'password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x',
	'first_name' => 'At', 'last_name' => 'Tester', 'phone' => '9999999999', 'gender' => 'other', 'birthdate' => '1995-01-31',
	'role' => 'administrator', 'roles' => array( 'administrator' ), 'account_type' => 'administrator', 'vendor_status' => 'approved', '_nexora_vendor_status' => 'approved' );
wp_set_current_user( 0 );
$r = nx_call_ajax( 'profile_register', $reg + nx_profile_nonce(), true );
$new = get_user_by( 'login', $reg['user_name'] );
nx_assert( $new instanceof WP_User, 'tampered sign-up still creates a normal member' );
if ( $new ) {
	$GLOBALS['nx_test']['users'][] = $new->ID;
	$pid = (int) get_user_meta( $new->ID, '_profile_id', true );
	if ( $pid ) { $GLOBALS['nx_test']['posts'][] = $pid; }
	nx_assert_same( array( 'subscriber' ), array_values( (array) $new->roles ), 'tampered sign-up: role is the site default only' );
	nx_assert_same( 'none', Account_Types::vendor_status( $new->ID ), 'tampered sign-up: vendor status is none' );
}

/* ---------- tab-visibility map (owner's own profile) ---------- */
$base = array( 'user-info', 'connections', 'content', 'notifications', 'marketplace', 'my-orders' );
$p = nx_test_user( 'atpending' );
$a = nx_test_user( 'atapproved' );
$s = nx_test_user( 'atsuspended' );
Account_Types::set_status( $p['user_id'], 'pending' );
Account_Types::set_status( $a['user_id'], 'pending' );
Account_Types::set_status( $a['user_id'], 'approved' );
Account_Types::set_status( $s['user_id'], 'pending' );
Account_Types::set_status( $s['user_id'], 'approved' );
Account_Types::set_status( $s['user_id'], 'suspended' );
$plain = nx_test_user( 'atplain' );
nx_assert_same( $base, Account_Types::tabs_for( $plain['user_id'] ), 'customer: User Information, Connections, Content, Notifications, Marketplace, My Orders' );
nx_assert_same( array_merge( $base, array( 'vendor-status' ) ), Account_Types::tabs_for( $p['user_id'] ), 'pending vendor: customer tabs + application status' );
nx_assert_same( array_merge( $base, array( 'my-products', 'sales' ) ), Account_Types::tabs_for( $a['user_id'] ), 'approved vendor: customer tabs + My Products + Sales' );
nx_assert_same( array_merge( $base, array( 'sales', 'vendor-status' ) ), Account_Types::tabs_for( $s['user_id'] ), 'suspended vendor: customer tabs + read-only Sales + status' );
$customer_tab_role = nx_test_user( 'atshop' );
( new WP_User( $customer_tab_role['user_id'] ) )->set_role( 'customer' );
nx_assert_same( $base, Account_Types::tabs_for( $customer_tab_role['user_id'] ), 'a customer is any non-vendor member, whatever the WordPress role' );
nx_assert( Account_Types::can_view_earnings( $a['user_id'] ) && Account_Types::can_view_earnings( $s['user_id'] ) && ! Account_Types::can_view_earnings( $p['user_id'] ) && ! Account_Types::can_view_earnings( $plain['user_id'] ), 'earnings are visible to approved and suspended vendors only' );

/* ---------- earnings follow the STATUS, not the role (suspended = read-only history) ---------- */
$e = nx_test_user( 'atearn' );
nx_assert( ! Account_Types::can_view_earnings( $e['user_id'] ), 'earnings: a customer has none' );
Account_Types::set_status( $e['user_id'], 'pending' );
nx_assert( ! Account_Types::can_view_earnings( $e['user_id'] ), 'earnings: a pending applicant has none' );
Account_Types::set_status( $e['user_id'], 'approved' );
nx_assert( Account_Types::can_view_earnings( $e['user_id'] ) && Account_Types::can_sell( $e['user_id'] ), 'earnings: approved can view and sell' );
Account_Types::set_status( $e['user_id'], 'suspended' );
$eu = get_userdata( $e['user_id'] );
nx_assert( ! in_array( Account_Types::ROLE, (array) $eu->roles, true ), 'earnings: suspension removes the vendor role' );
nx_assert( Account_Types::can_view_earnings( $e['user_id'] ), 'earnings: a suspended vendor keeps read-only access to history even without the role' );
nx_assert( Account_Types::can_view_earnings( new WP_User( $e['user_id'] ) ), 'earnings: works with a WP_User object too' );
nx_assert( ! Account_Types::can_sell( $e['user_id'] ) && ! user_can( $e['user_id'], Account_Types::CAP_PRODUCTS ), 'earnings: a suspended vendor still cannot sell or list' );
nx_assert( in_array( 'sales', Account_Types::tabs_for( $e['user_id'] ), true ) && ! in_array( 'my-products', Account_Types::tabs_for( $e['user_id'] ), true ), 'earnings: suspended sees Sales but not My Products' );
Account_Types::set_status( $e['user_id'], 'approved' );
nx_assert( Account_Types::can_view_earnings( $e['user_id'] ), 'earnings: re-approved vendor views earnings' );

/* ---------- the status can only change through set_status() ---------- */
$g = nx_test_user( 'atguard' );
nx_assert( false === update_user_meta( $g['user_id'], Account_Types::STATUS_META, 'approved' ), 'guard: a direct meta write of the status is refused' );
nx_assert( false === add_user_meta( $g['user_id'], Account_Types::STATUS_META, 'approved', true ), 'guard: add_user_meta of the status is refused' );
nx_assert_same( 'none', Account_Types::vendor_status( $g['user_id'] ), 'guard: nothing was stored' );
Account_Types::set_status( $g['user_id'], 'pending' );
nx_assert( false === update_user_meta( $g['user_id'], Account_Types::STATUS_META, 'approved' ), 'guard: a pending applicant cannot meta-write approved' );
nx_assert( ! Account_Types::can_sell( $g['user_id'] ) && 'pending' === Account_Types::vendor_status( $g['user_id'] ), 'guard: still pending' );
delete_user_meta( $g['user_id'], Account_Types::STATUS_META );
nx_assert_same( 'pending', Account_Types::vendor_status( $g['user_id'] ), 'guard: a plain delete_user_meta cannot reset the status either' );

/* ---------- deleting a user (sign-up rollback relies on wp_delete_user) ---------- */
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( 'approved', 'pending' ) as $state ) {
	$d = nx_test_user( 'atdel' . $state );
	Account_Types::set_status( $d['user_id'], 'pending' );
	if ( 'approved' === $state ) { Account_Types::set_status( $d['user_id'], 'approved' ); }
	nx_assert_same( $state, Account_Types::vendor_status( $d['user_id'] ), "delete: setup $state" );
	nx_assert( true === wp_delete_user( $d['user_id'] ), "delete: wp_delete_user succeeds for a $state vendor" );
	nx_assert( false === get_userdata( $d['user_id'] ), "delete: the $state vendor is gone" );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $d['user_id'], Account_Types::STATUS_META ) );
	nx_assert_same( 0, $left, "delete: no orphaned status meta for a $state vendor" );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $d['user_id'] ) );
	nx_assert_same( 0, $left, "delete: no usermeta rows of any kind remain for a $state vendor" );
}
// Same through WordPress' own multisite-free delete helper used by tools.
$d = nx_test_user( 'atdelraw' );
Account_Types::set_status( $d['user_id'], 'pending' );
nx_assert( true === wp_delete_user( $d['user_id'], 0 ), 'delete: wp_delete_user with no reassign works' );
nx_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $d['user_id'], Account_Types::STATUS_META ) ), 'delete: still no orphaned status meta' );

/* ---------- wiring: no markup change, no new hooks on the customer path ---------- */
nx_assert( class_exists( 'Nexora\\Accounts\\Account_Types' ), 'class autoloads' );
nx_assert( false !== has_action( 'plugins_loaded', array( 'Nexora\\Accounts\\Account_Types', 'maybe_register' ) ), 'Plugin::boot() wires the self-upgrade' );
nx_assert( false === strpos( do_shortcode( '[profile_login]' ), 'marketplace' ), 'login markup mentions no marketplace' );

/* ---------- uninstall (only when the admin ticked "delete data") ---------- */
$defaults = \Nexora\Database\Uninstaller::defaults();
nx_assert( in_array( Account_Types::STATUS_META, $defaults['user_meta'], true ), 'uninstall lists the vendor status user meta' );
nx_assert( in_array( Account_Types::OPTION, $defaults['options'], true ), 'uninstall lists the accounts version option' );
nx_assert( in_array( Account_Types::ROLE, $defaults['roles'] ?? array(), true ), 'uninstall lists the vendor role' );
$setting = get_option( \Nexora\Database\Uninstaller::SETTING );
$scratch = array( 'tables' => array(), 'options' => array(), 'user_meta' => array(), 'post_types' => array(), 'dirs' => array(), 'transient_prefixes' => array(), 'roles' => array( Account_Types::ROLE ) );
update_option( \Nexora\Database\Uninstaller::SETTING, 0 );
( new \Nexora\Database\Uninstaller( $scratch ) )->run();
nx_assert( null !== get_role( Account_Types::ROLE ), 'uninstall keeps the role while the setting is off' );
update_option( \Nexora\Database\Uninstaller::SETTING, 1 );
( new \Nexora\Database\Uninstaller( $scratch ) )->run();
nx_assert( null === get_role( Account_Types::ROLE ), 'uninstall removes the role when the setting is on' );
update_option( \Nexora\Database\Uninstaller::SETTING, $setting );

// restore shared state
Account_Types::register_role();
update_option( 'nexora_accounts_version', Account_Types::VERSION );
nx_test_finish();
