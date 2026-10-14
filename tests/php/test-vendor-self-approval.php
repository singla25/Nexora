<?php
// Marketplace: nobody can make themselves an approved vendor. No AJAX action, hook, form or other code path
// may change the vendor status or the vendor role; only Account_Types::set_status() may, and only an
// admin-side or system caller will use it (Phase 2 adds the allowed callers to the list below).
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;

global $wp_filter;

/* ---------- 1. every registered Nexora AJAX action, called by a member with tampered input ---------- */
$tamper = array(
	'vendor_status' => 'approved', '_nexora_vendor_status' => 'approved', 'status' => 'approved',
	'role' => Account_Types::ROLE, 'roles' => array( 'administrator', Account_Types::ROLE ), 'account_type' => 'vendor',
	'intent' => 'seller', 'meta_key' => Account_Types::STATUS_META, 'meta_value' => 'approved',
	'user_id' => 0, 'profile_id' => 0, 'connection_id' => 0, 'thread_id' => 0,
);

$actions = array();
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( preg_match( '/^wp_ajax_(?:nopriv_)?(nexora_.+)$/', $hook, $m ) ) {
		$actions[ $m[1] ] = true;
	}
}
unset( $actions['nexora_profile_login'] ); // logs a user in and swaps the current user; covered by the static scan below.
$actions = array_keys( $actions );
nx_assert( count( $actions ) >= 25, 'the sweep sees every Nexora AJAX action (' . count( $actions ) . ')' );

$member    = nx_test_user( 'sa_member' );
$applicant = nx_test_user( 'sa_applicant' );
Account_Types::set_status( $applicant['user_id'], 'pending' );
$mail = &nx_test_capture_mail();

foreach ( array( 'member' => $member, 'pending applicant' => $applicant ) as $who => $u ) {
	$expected = ( 'member' === $who ) ? 'none' : 'pending';
	foreach ( $actions as $action ) {
		foreach ( array( nx_profile_nonce(), nx_chat_nonce() ) as $nonce ) {
			nx_test_reset_limits();
			wp_set_current_user( $u['user_id'] );
			nx_call_ajax( $action, $tamper + $nonce );
		}
	}
	wp_set_current_user( 0 );
	clean_user_cache( $u['user_id'] );
	$roles = (array) get_userdata( $u['user_id'] )->roles;
	nx_assert_same( $expected, Account_Types::vendor_status( $u['user_id'] ), "$who: status unchanged after tampering with all " . count( $actions ) . ' AJAX actions' );
	nx_assert( ! in_array( Account_Types::ROLE, $roles, true ) && ! in_array( 'administrator', $roles, true ), "$who: no vendor or admin role gained" );
	nx_assert( ! Account_Types::can_sell( $u['user_id'] ), "$who: cannot sell" );
}

// Same through the profile page's own save endpoints with otherwise valid data.
wp_set_current_user( $member['user_id'] );
nx_test_reset_limits();
nx_call_ajax( 'nexora_update_personal_info', $tamper + array( 'first_name' => 'A', 'last_name' => 'B' ) + nx_profile_nonce() );
wp_set_current_user( 0 );
nx_assert_same( 'none', Account_Types::vendor_status( $member['user_id'] ), 'a valid profile save with extra vendor fields changes nothing' );

/* ---------- 2. the status cannot be written around set_status() ---------- */
wp_set_current_user( $member['user_id'] );
nx_assert( false === update_user_meta( $member['user_id'], Account_Types::STATUS_META, 'approved' ), 'a member cannot write the status meta' );
nx_assert( false === add_user_meta( $member['user_id'], Account_Types::STATUS_META, 'approved' ), 'a member cannot add the status meta' );
wp_set_current_user( 0 );
$legacy = get_userdata( $member['user_id'] );
$legacy->add_role( 'subscriber' );
nx_assert( ! user_can( $member['user_id'], Account_Types::CAP_PRODUCTS ), 'no vendor capability without the role' );

/* ---------- 3. no other code path in the plugin can change a status or a role ---------- */
$allowed = array(
	'src/Accounts/Account_Types.php'  => 'owns the status and the role',
	'src/Database/Uninstaller.php'    => 'removes them on a data-deleting uninstall',
	'src/Profile/Upload_Policy.php'   => 'strips the old upload_files cap from the subscriber role (reviewed: removal only)',
);
$src   = NEXORA_PATH . 'src';
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
$hits  = array();
$dyn   = array();
foreach ( $files as $f ) {
	if ( 'php' !== $f->getExtension() ) { continue; }
	$rel  = 'src/' . ltrim( substr( $f->getPathname(), strlen( $src ) ), '/' );
	$code = file_get_contents( $f->getPathname() );
	$code = preg_replace( '#/\*.*?\*/#s', '', $code ); // block comments
	$code = preg_replace( '#^\s*//.*$#m', '', $code ); // line comments
	if ( ! isset( $allowed[ $rel ] ) && preg_match( '/Account_Types::set_status|STATUS_META|_nexora_vendor_status|nexora_vendor|->add_role\(|->set_role\(|->remove_role\(|add_role\(|remove_role\(|->add_cap\(|->remove_cap\(|add_cap\(/', $code, $mm ) ) {
		$hits[] = $rel . ' (' . $mm[0] . ')';
	}
	// A client-chosen meta key or role would be the dangerous shape.
	if ( preg_match( '/(?:update|add|delete)_user_meta\(\s*[^,]+,\s*\$/', $code ) ) { $dyn[] = $rel; }
	if ( preg_match( '/wp_(?:insert|update)_user\([^;]*[\'"]role[\'"]/s', $code ) ) { $dyn[] = $rel . ' (role in user array)'; }
}
nx_assert_same( array(), $hits, 'only the allowed files touch the vendor status or role: ' . wp_json_encode( $hits ) );
nx_assert_same( array(), $dyn, 'no code writes user meta under a variable key or sets a role from an array: ' . wp_json_encode( $dyn ) );

/* ---------- 4. hooks: nothing else listens on the status or lets a role be chosen ---------- */
$guards = 0;
foreach ( array( 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata' ) as $h ) {
	foreach ( $wp_filter[ $h ]->callbacks ?? array() as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && 'Nexora\\Accounts\\Account_Types' === $cb['function'][0] ) { $guards++; }
		}
	}
}
nx_assert_same( 3, $guards, 'the meta guard is registered on add, update and delete' );
nx_assert( false === has_filter( 'pre_option_default_role' ), 'nothing overrides the default role' );
nx_assert_same( 'subscriber', get_option( 'default_role' ), 'the default role is still subscriber' );

nx_test_finish();
