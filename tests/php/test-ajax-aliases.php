<?php
// Phase 1e: every generic AJAX action gets a nexora_ name; the old name stays as a deprecated alias for one release.
require_once __DIR__ . '/../bootstrap.php';

$members = array(
	'update_personal_info', 'update_address_info', 'update_work_info', 'update_documents_info', 'update_profile_password',
	'get_add_new_users', 'send_connection_request', 'get_requests', 'update_connection_status', 'get_history',
	'view_all_connection', 'view_mutual_connection', 'mark_notification_read', 'save_user_content', 'get_user_content_history',
);
$guests = array( 'profile_login', 'send_otp', 'verify_otp', 'reset_password', 'profile_register' );

nx_assert( class_exists( 'Nexora_Ajax' ), 'Nexora_Ajax guard/registrar exists' );

foreach ( $members as $a ) {
	nx_assert( (bool) has_action( "wp_ajax_nexora_$a" ) && (bool) has_action( "wp_ajax_$a" ), "member action $a: new name and legacy alias registered" );
	nx_assert( ! has_action( "wp_ajax_nopriv_nexora_$a" ) && ! has_action( "wp_ajax_nopriv_$a" ), "member action $a: closed to guests under both names" );
}
foreach ( $guests as $a ) {
	nx_assert( (bool) has_action( "wp_ajax_nopriv_nexora_$a" ) && (bool) has_action( "wp_ajax_nopriv_$a" ), "guest action $a: nopriv under both names" );
	nx_assert( (bool) has_action( "wp_ajax_nexora_$a" ) && (bool) has_action( "wp_ajax_$a" ), "guest action $a: logged-in hook under both names" );
}

/* both names reach the same handler */
$alice = nx_test_user( 'alalice' );
wp_set_current_user( $alice['user_id'] );
$deprecated = array();
add_action( 'nexora_deprecated_ajax_action', function ( $old, $new ) use ( &$deprecated ) { $deprecated[] = array( $old, $new ); }, 10, 2 );
$new = nx_call_ajax( 'nexora_get_requests', nx_profile_nonce() );
nx_assert( ! empty( $new['success'] ) && array() === $deprecated, 'new name works and is not reported as deprecated' );
$old = nx_call_ajax( 'get_requests', nx_profile_nonce() );
nx_assert( ! empty( $old['success'] ) && $old === $new, 'legacy name still works with an identical reply' );
nx_assert_same( array( array( 'get_requests', 'nexora_get_requests' ) ), $deprecated, 'legacy call fires nexora_deprecated_ajax_action(old, new)' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_get_requests', array( 'nonce' => 'bad' ) ) ), 'new name enforces the nonce' );
wp_set_current_user( 0 );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_get_requests', nx_profile_nonce() ) ), 'new name enforces login' );
$g = nx_call_ajax( 'nexora_send_otp', array( 'username' => 'nobody', 'email' => 'n@example.test' ) + nx_profile_nonce(), true );
nx_assert( ! empty( $g['success'] ), 'guest action reachable under the new name' );

/* shared guard */
wp_set_current_user( $alice['user_id'] );
$_REQUEST['nonce'] = wp_create_nonce( 'profile_nonce' );
$ctx = Nexora_Ajax::member( 'profile_nonce', true, 'x' );
unset( $_REQUEST['nonce'] );
nx_assert_same( array( 'user_id' => $alice['user_id'], 'profile_id' => $alice['profile_id'] ), $ctx, 'Nexora_Ajax::member() returns user and profile ids' );

/* the front end only calls the new names */
$dir = NEXORA_PATH . 'assets/js/';
$js  = file_get_contents( $dir . 'profile-page.js' ) . file_get_contents( $dir . 'profile-login.js' ) . file_get_contents( $dir . 'profile-registration.js' );
foreach ( array_merge( $members, $guests ) as $a ) {
	nx_assert( 1 !== preg_match( "/['\"]" . preg_quote( $a, '/' ) . "['\"]/", $js ), "JS no longer uses the legacy action '$a'" );
	nx_assert( false !== strpos( $js, "nexora_$a" ), "JS uses nexora_$a" );
}

nx_test_finish();
