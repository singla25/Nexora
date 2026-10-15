<?php
// Marketplace Phase 3: where a login lands. Everyone goes to their own profile unless a SAFE return URL
// (same site, a shopping or profile page) was asked for.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Core\Urls;

$home = untrailingslashit( home_url() );
$host = wp_parse_url( home_url(), PHP_URL_HOST );

/* ---------- Urls::safe_return ---------- */
$ok = array(
	'cart'              => $home . '/cart/',
	'checkout'          => $home . '/checkout/',
	'shop'              => $home . '/shop/',
	'a profile'         => $home . '/profile-page/someone',
	'cart with a query' => $home . '/cart/?x=1',
	'relative path'     => wp_parse_url( $home, PHP_URL_PATH ) . '/cart/',
);
foreach ( $ok as $label => $url ) {
	$safe = Urls::safe_return( $url );
	nx_assert( '' !== $safe && 0 === strpos( $safe, 'http' ) && $host === wp_parse_url( $safe, PHP_URL_HOST ), "safe return accepted: $label" );
}
$bad = array(
	'other host'            => 'https://evil.example/cart/',
	'protocol-relative'     => '//evil.example/cart/',
	'javascript'            => 'javascript:alert(1)',
	'userinfo trick'        => 'http://' . $host . '@evil.example/cart/',
	'backslash'             => $home . '/cart/\\@evil.example',
	'backslash host'        => '/\\evil.example',
	'header injection'      => $home . "/cart/\r\nLocation: https://evil.example",
	'encoded protocol-rel.' => '%2F%2Fevil.example',
	'wp-admin'              => $home . '/wp-admin/',
	'wp-login'              => $home . '/wp-login.php',
	'the login page itself' => $home . '/login-page/',
	'the registration page' => $home . '/registration-page/',
	'my-account (bounces)'  => $home . '/my-account/',
	'a random page'         => $home . '/sample-page/',
	'upload path'           => $home . '/wp-content/uploads/x.html',
	'empty'                 => '',
	'only spaces'           => '   ',
	'data url'              => 'data:text/html,<script>alert(1)</script>',
	'ftp'                   => 'ftp://' . $host . '/cart/',
);
foreach ( $bad as $label => $url ) {
	nx_assert_same( '', Urls::safe_return( $url ), "safe return refused: $label" );
}
nx_assert_same( '', Urls::safe_return( array( $home . '/cart/' ) ), 'safe return refused: an array' );
nx_assert_same( '', Urls::safe_return( null ), 'safe return refused: null' );
add_filter( 'nexora_return_url_paths', function ( $paths ) { $paths[] = 'sample-page'; return $paths; } );
nx_assert( '' !== Urls::safe_return( $home . '/sample-page/' ), 'the allowed paths can be extended through a filter' );
remove_all_filters( 'nexora_return_url_paths' );

/* ---------- Urls::after_login ---------- */
$u = nx_test_user( 'lrone' );
nx_assert_same( Urls::profile( $u['login'] ), Urls::after_login( $u['user_id'], '' ), 'no return URL: the member\'s own profile' );
nx_assert_same( Urls::profile( $u['login'] ), Urls::after_login( $u['user_id'], 'https://evil.example/' ), 'an unsafe return URL is ignored: own profile' );
nx_assert_same( Urls::safe_return( $home . '/cart/' ), Urls::after_login( $u['user_id'], $home . '/cart/' ), 'a safe return URL is honoured' );
nx_assert_same( Urls::login(), Urls::after_login( 0, $home . '/cart/' ), 'an unknown user never gets a return URL' );

/* ---------- through the real login endpoint, for every account type ---------- */
$pw   = 'Correct#Horse9';
$mk   = function ( $name, $state ) use ( $pw ) {
	$x = nx_test_user( $name );
	wp_set_password( $pw, $x['user_id'] );
	foreach ( array( 'pending' => array( 'pending' ), 'approved' => array( 'pending', 'approved' ), 'suspended' => array( 'pending', 'approved', 'suspended' ) )[ $state ] ?? array() as $step ) {
		Account_Types::set_status( $x['user_id'], $step );
	}
	return $x;
};
$login = function ( $x, array $extra = array() ) use ( $pw ) {
	nx_test_reset_limits();
	wp_set_current_user( 0 );
	return nx_call_ajax( 'nexora_profile_login', array_merge( array( 'user_name' => $x['login'], 'password' => $pw ), $extra ) + nx_profile_nonce(), true );
};
foreach ( array( 'customer' => 'none', 'pending vendor' => 'pending', 'approved vendor' => 'approved', 'suspended vendor' => 'suspended' ) as $label => $state ) {
	$x = $mk( 'lr' . substr( md5( $label ), 0, 5 ), $state );
	$r = $login( $x );
	nx_assert( ! empty( $r['success'] ) && Urls::profile( $x['login'] ) === $r['data']['redirect'], "$label: logs in and lands on their own profile" );
	$r = $login( $x, array( 'redirect_to' => $home . '/cart/' ) );
	nx_assert( ! empty( $r['success'] ) && Urls::safe_return( $home . '/cart/' ) === $r['data']['redirect'], "$label: a safe return URL (cart) is followed" );
	$r = $login( $x, array( 'redirect_to' => 'https://evil.example/cart/' ) );
	nx_assert( ! empty( $r['success'] ) && Urls::profile( $x['login'] ) === $r['data']['redirect'], "$label: an external return URL is ignored" );
	$r = $login( $x, array( 'redirect_to' => array( 'x' ) ) );
	nx_assert( ! empty( $r['success'] ) && Urls::profile( $x['login'] ) === $r['data']['redirect'], "$label: an array return URL is ignored" );
}
$bad_login = $login( $u, array( 'password' => 'wrong', 'redirect_to' => $home . '/cart/' ) );
nx_assert( nx_rejected( $bad_login ) && 'Invalid username or password' === $bad_login['data'], 'a failed login keeps the generic error and never reveals the return URL' );

// An administrator still lands on the bare profile page.
$adm = nx_test_admin();
wp_set_password( $pw, $adm );
$au = get_userdata( $adm );
$r  = $login( array( 'login' => $au->user_login ) );
nx_assert( ! empty( $r['success'] ) && Urls::profile() === $r['data']['redirect'], 'an administrator lands on the bare profile page' );

// The login script passes the return URL on.
$js = file_get_contents( NEXORA_PATH . 'assets/js/profile-login.js' );
nx_assert( false !== strpos( $js, 'redirect_to' ), 'profile-login.js sends redirect_to to the login action' );

nx_test_finish();
