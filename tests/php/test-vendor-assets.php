<?php
// Marketplace Phase 2: the vendor script/style load only where the forms are, and carry no business data.
require_once __DIR__ . '/../bootstrap.php';

$alice = nx_test_user( 'vasalice' );
\Nexora\Accounts\Account_Types::set_status( $alice['user_id'], 'pending' );
update_post_meta( $alice['profile_id'], 'email', 'alice.vassets@example.test' );

$load = function ( $uid, $slug ) {
	$GLOBALS['wp_scripts'] = null;
	$GLOBALS['wp_styles']  = null;
	$GLOBALS['post']       = null;
	wp_set_current_user( $uid );
	$q = $slug ? new WP_Query( array( 'pagename' => $slug ) ) : new WP_Query( array( 'p' => 1 ) );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
	if ( $q->have_posts() ) { $q->the_post(); $GLOBALS['post'] = $q->post; }
	set_query_var( 'username', '' );
	do_action( 'wp_enqueue_scripts' );
	$data = '';
	foreach ( wp_scripts()->queue as $h ) { $data .= (string) wp_scripts()->get_data( $h, 'data' ); }
	return array( 'js' => wp_script_is( 'nexora-vendor', 'enqueued' ), 'css' => wp_style_is( 'nexora-vendor', 'enqueued' ), 'data' => $data );
};

foreach ( array( 'home' => '', 'sample page' => 'sample-page', 'login' => 'login-page' ) as $label => $slug ) {
	foreach ( array( 'guest' => 0, 'member' => $alice['user_id'] ) as $who => $uid ) {
		$r = $load( $uid, $slug );
		nx_assert( ! $r['js'] && ! $r['css'], "$label as $who: no vendor script or style" );
		nx_assert( false === strpos( $r['data'], 'nexoraVendor' ), "$label as $who: no vendor data object" );
	}
}

$r = $load( 0, 'registration-page' );
nx_assert( $r['js'] && $r['css'], 'registration page: vendor script and style load (Seller choice)' );
$r = $load( $alice['user_id'], 'profile-page' );
nx_assert( $r['js'] && $r['css'], 'profile page (member): vendor script and style load (Become a seller)' );
nx_assert( false !== strpos( $r['data'], 'nexoraVendor' ) && false !== strpos( $r['data'], 'nonce' ), 'profile page (member): the vendor script gets the ajax URL and nonce' );
nx_assert( false === strpos( $r['data'], 'alice.vassets' ) || false !== strpos( $r['data'], 'profilePageData' ), 'the vendor data object itself carries no personal data' );
preg_match( '/var nexoraVendor = (\{.*?\});/s', $r['data'], $m );
$obj = $m ? json_decode( $m[1], true ) : array();
nx_assert_same( array( 'ajaxUrl', 'nonce' ), array_keys( $obj ?: array() ), 'nexoraVendor holds only ajaxUrl and nonce' );
$r = $load( 0, 'profile-page' );
nx_assert( ! $r['js'] && ! $r['css'], 'profile page (guest): nothing loaded (login wall only)' );

nx_assert( file_exists( NEXORA_PATH . 'assets/js/vendor.js' ) && file_exists( NEXORA_PATH . 'assets/css/vendor.css' ), 'the asset files exist' );
$js = file_get_contents( NEXORA_PATH . 'assets/js/vendor.js' );
nx_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'document.write' ) && false === strpos( $js, 'eval(' ), 'vendor.js writes no raw HTML and uses no eval' );
nx_assert( false !== strpos( $js, 'nexora_vendor_apply' ) || false !== strpos( $js, 'data-action' ), 'vendor.js posts to the vendor actions' );
nx_assert( 1 !== preg_match( '/data\.(role|roles|status|vendor_status|account_type)\b|[\'"](roles?|vendor_status|account_type)[\'"]\s*:/', $js ), 'vendor.js never sends a role or a status' );

// The repo's blanket `vendor/` ignore rule once swallowed templates/vendor/: no plugin source file may be git-ignored.
if ( trim( (string) shell_exec( 'command -v git' ) ) ) {
	$ignored = array();
	$root    = rtrim( ABSPATH, '/' );
	foreach ( array( 'src', 'templates', 'assets' ) as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NEXORA_PATH . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( false !== strpos( $f->getPathname(), '/assets/lib/' ) ) { continue; }
			$out = shell_exec( 'cd ' . escapeshellarg( $root ) . ' && git check-ignore -q ' . escapeshellarg( $f->getPathname() ) . ' && echo ignored' );
			if ( $out && false !== strpos( $out, 'ignored' ) ) { $ignored[] = substr( $f->getPathname(), strlen( NEXORA_PATH ) ); }
		}
	}
	nx_assert_same( array(), $ignored, 'no plugin source, template or asset file is git-ignored: ' . wp_json_encode( $ignored ) );
}

nx_test_finish();
