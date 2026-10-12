<?php
// Phase 1b: scripts, nonces and private profile data must only be sent on the pages that need them.
require_once __DIR__ . '/../bootstrap.php';

$alice = nx_test_user( 'asalice' );
update_post_meta( $alice['profile_id'], 'email', 'alice.assets@example.test' );
update_post_meta( $alice['profile_id'], 'phone', '+91-555-0100' );

$handles = array( 'profile-page-js', 'profile-login', 'profile-registration', 'sweetalert2', 'google-recaptcha' );

/** Load $path ('' = front page, 'login-page', ...) as $uid and return enqueued handles + inline data. */
$load = function ( $uid, $slug ) use ( $handles ) {
	$GLOBALS['wp_scripts'] = null;
	$GLOBALS['post'] = null;
	$GLOBALS['wp_styles']  = null;
	wp_set_current_user( $uid );
	$q = $slug ? new WP_Query( array( 'pagename' => $slug ) ) : new WP_Query( array( 'p' => 1 ) );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
	if ( $q->have_posts() ) { $q->the_post(); $GLOBALS['post'] = $q->post; }
	set_query_var( 'username', '' );
	do_action( 'wp_enqueue_scripts' );
	$out = array( 'enqueued' => array(), 'data' => '', 'media' => wp_script_is( 'media-editor', 'enqueued' ) || wp_script_is( 'media-views', 'enqueued' ) );
	foreach ( $handles as $h ) { if ( wp_script_is( $h, 'enqueued' ) ) { $out['enqueued'][] = $h; } }
	foreach ( wp_scripts()->queue as $h ) { $out['data'] .= (string) wp_scripts()->get_data( $h, 'data' ); }
	$out['src'] = isset( wp_scripts()->registered['sweetalert2'] ) ? wp_scripts()->registered['sweetalert2']->src : '';
	return $out;
};

/* ---- any other page: none of the plugin's page scripts, no nonce, no private data ---- */
foreach ( array( 'home' => '', 'sample' => 'sample-page' ) as $label => $slug ) {
	foreach ( array( 'guest' => 0, 'member' => $alice['user_id'] ) as $who => $uid ) {
		$r = $load( $uid, $slug );
		$page_scripts = array_diff( $r['enqueued'], array( 'x' ) );
		nx_assert_same( array(), $page_scripts, "$label page as $who: no profile/login/registration/sweetalert/recaptcha scripts" );
		nx_assert( false === strpos( $r['data'], 'profilePageData' ) && false === strpos( $r['data'], 'profileData' ), "$label page as $who: no plugin nonce/data object" );
		nx_assert( false === strpos( $r['data'], 'alice.assets@example.test' ) && false === strpos( $r['data'], '555-0100' ), "$label page as $who: owner's email/phone not in any script data" );
		nx_assert( ! $r['media'], "$label page as $who: media library not loaded" );
	}
}

/* ---- profile page ---- */
$r = $load( $alice['user_id'], 'profile-page' );
nx_assert( in_array( 'profile-page-js', $r['enqueued'], true ), 'profile page: profile script enqueued' );
nx_assert( false !== strpos( $r['data'], 'profilePageData' ) && false !== strpos( $r['data'], 'alice.assets@example.test' ), 'profile page (owner): data present on this page only' );
nx_assert( $r['media'], 'profile page (owner): media library loaded for uploads' );
nx_assert( ! in_array( 'profile-login', $r['enqueued'], true ) && ! in_array( 'profile-registration', $r['enqueued'], true ), 'profile page: no login/registration scripts' );
$r = $load( 0, 'profile-page' );
nx_assert( false === strpos( $r['data'], 'alice.assets@example.test' ) && ! $r['media'], 'profile page (guest): no private data, no media library' );

/* ---- login / registration pages ---- */
$r = $load( 0, 'login-page' );
nx_assert( in_array( 'profile-login', $r['enqueued'], true ) && false !== strpos( $r['data'], 'profileData' ), 'login page: login script + nonce object' );
nx_assert( ! in_array( 'profile-page-js', $r['enqueued'], true ) && ! in_array( 'profile-registration', $r['enqueued'], true ), 'login page: nothing else' );
$r = $load( 0, 'registration-page' );
nx_assert( in_array( 'profile-registration', $r['enqueued'], true ) && false !== strpos( $r['data'], 'profileData' ), 'registration page: registration script + nonce object' );
nx_assert( ! in_array( 'profile-login', $r['enqueued'], true ), 'registration page: no login script' );

/* ---- SweetAlert is self-hosted, pinned, and every plugin script has a real version ---- */
$r = $load( 0, 'login-page' );
nx_assert( false !== strpos( $r['src'], '/nexora/assets/lib/sweetalert2/' ) && false === strpos( $r['src'], 'jsdelivr' ), 'sweetalert2 served from the plugin, not a floating CDN tag' );
$s = wp_scripts()->registered;
foreach ( array( 'profile-login', 'sweetalert2' ) as $h ) {
	nx_assert( ! empty( $s[ $h ]->ver ), "script $h has a version (cache busting works)" );
}
$GLOBALS['wp_scripts'] = null; $GLOBALS['wp_styles'] = null;
$r = $load( $alice['user_id'], 'profile-page' );
$s = wp_scripts()->registered;
nx_assert( ! empty( $s['profile-page-js']->ver ) && ! empty( $s['sweetalert2']->ver ), 'profile scripts have versions' );
nx_assert( file_exists( NEXORA_PATH . 'assets/lib/sweetalert2/sweetalert2.all.min.js' ), 'sweetalert2 file shipped with the plugin' );

nx_test_finish();
