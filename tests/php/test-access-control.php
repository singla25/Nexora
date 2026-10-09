<?php
// Characterization of nexora.php access control: wp-admin / wp-login bounce rules and login redirects.
require_once __DIR__ . '/../bootstrap.php';

$member = nx_test_user( 'acmember' );
$admin  = wp_insert_user( array( 'user_login' => 'nxtest_ad_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'ad' . wp_generate_password( 4, false ) . '@example.test', 'role' => 'administrator' ) );
$GLOBALS['nx_test']['users'][] = $admin;

$run = function ( $method, $uid, $uri = '/wp-admin/', $script = '/wp-admin/index.php', array $get = array() ) {
	putenv( 'NX_CALL=' . $method ); putenv( 'NX_USER=' . $uid ); putenv( 'NX_URI=' . $uri ); putenv( 'NX_SCRIPT=' . $script ); putenv( 'NX_GET=' . wp_json_encode( $get ) );
	return (string) shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval-file tests/child/redirect.php --path=. 2>/dev/null' );
};
$login   = home_url( '/login-page' );
$profile = home_url( '/profile-page/' . rawurlencode( $member['login'] ) );

/* wp-admin */
nx_assert( false !== strpos( $run( 'block_wp_admin', 0 ), "REDIRECT $login" ), 'wp-admin: guest -> /login-page' );
nx_assert( false !== strpos( $run( 'block_wp_admin', $member['user_id'] ), "REDIRECT $profile" ), 'wp-admin: member -> own profile page' );
nx_assert( false !== strpos( $run( 'block_wp_admin', $admin ), 'NO_REDIRECT' ), 'wp-admin: administrator allowed' );
nx_assert( false !== strpos( $run( 'block_wp_admin', 0, '/wp-admin/admin-post.php', '/wp-admin/admin-post.php' ), 'NO_REDIRECT' ), 'wp-admin: admin-post.php stays open to guests' );
nx_assert( false !== strpos( $run( 'block_wp_admin', 0, '/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php' ), 'NO_REDIRECT' ), 'wp-admin: admin-ajax.php stays open to guests' );

/* wp-login.php */
nx_assert( false !== strpos( $run( 'block_wp_login', 0, '/wp-login.php' ), "REDIRECT $login" ), 'wp-login: guest -> /login-page' );
nx_assert( false !== strpos( $run( 'block_wp_login', 0, '/wp-login.php?action=lostpassword', '', array( 'action' => 'lostpassword' ) ), "REDIRECT $login" ), 'wp-login: core lost-password screen is also redirected' );
nx_assert( false !== strpos( $run( 'block_wp_login', 0, '/wp-login.php?action=logout', '', array( 'action' => 'logout' ) ), 'NO_REDIRECT' ), 'wp-login: logout action allowed' );
nx_assert( false !== strpos( $run( 'block_wp_login', $member['user_id'], '/wp-login.php' ), "REDIRECT $profile" ), 'wp-login: logged-in member -> own profile' );
nx_assert( false !== strpos( $run( 'block_wp_login', $admin, '/wp-login.php' ), 'NO_REDIRECT' ), 'wp-login: administrator allowed' );
nx_assert( false !== strpos( $run( 'block_wp_login', 0, '/some-page/' ), 'NO_REDIRECT' ), 'wp-login: other URLs untouched' );

/* login_redirect filter + admin bar */
wp_set_current_user( 0 );
nx_assert_same( home_url( '/profile-page/' . rawurlencode( $member['login'] ) ), apply_filters( 'login_redirect', '/x', '', get_userdata( $member['user_id'] ) ), 'login_redirect: member -> own profile' );
nx_assert_same( home_url( '/profile-page' ), apply_filters( 'login_redirect', '/x', '', get_userdata( $admin ) ), 'login_redirect: admin -> /profile-page' );
nx_assert_same( '/x', apply_filters( 'login_redirect', '/x', '', new WP_Error( 'e' ) ), 'login_redirect: failed login left untouched' );
wp_set_current_user( $member['user_id'] );
( new \Nexora\Core\Access_Control() )->hide_admin_bar();
nx_assert_same( false, $GLOBALS['show_admin_bar'] ?? null, 'members: show_admin_bar(false) applied' );
wp_set_current_user( $admin );
unset( $GLOBALS['show_admin_bar'] );
( new \Nexora\Core\Access_Control() )->hide_admin_bar();
nx_assert( ! isset( $GLOBALS['show_admin_bar'] ) || false !== $GLOBALS['show_admin_bar'], 'administrators keep the admin bar' );

nx_test_finish();
