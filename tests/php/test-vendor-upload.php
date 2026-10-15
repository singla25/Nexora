<?php
// A member (customer or approved vendor) can upload an image through the WordPress media modal and a vendor can attach it
// to a product. This one goes over real HTTP (the upload endpoint, wp-admin/async-upload.php, runs plugin hooks that an
// in-process call never reaches: WooCommerce's admin lock-down is registered only for real wp-admin requests).
// Skipped when the site cannot be reached from the CLI.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Products\Repository;

$base = untrailingslashit( home_url() );

if ( ! function_exists( 'curl_init' ) ) {
	echo "curl extension missing: skipped\n";
	nx_test_finish();
	return;
}

$http = function ( $url, array $opts = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, $opts + array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false ) );
	$out  = curl_exec( $ch );
	$info = curl_getinfo( $ch );
	curl_close( $ch );
	if ( false === $out ) { return array( 'status' => 0, 'headers' => '', 'body' => '' ); }
	return array( 'status' => (int) $info['http_code'], 'headers' => substr( $out, 0, $info['header_size'] ), 'body' => substr( $out, $info['header_size'] ), 'redirect' => (string) ( $info['redirect_url'] ?? '' ) );
};

$ping = $http( $base . '/wp-login.php', array( CURLOPT_NOBODY => true ) );
if ( ! $ping['status'] ) {
	echo "site not reachable from the CLI: skipped\n";
	nx_test_finish();
	return;
}

/** Log a throwaway member in through the app's own login endpoint; returns the cookie jar path. */
$login = function ( $user, $pw ) use ( $http, $base ) {
	$jar = tempnam( sys_get_temp_dir(), 'nxjar' );
	$r   = $http( $base . '/wp-admin/admin-ajax.php', array(
		CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_profile_login', 'nonce' => wp_create_nonce( 'profile_nonce' ), 'user_name' => $user['login'], 'password' => $pw ) ),
		CURLOPT_COOKIEJAR  => $jar,
		CURLOPT_COOKIEFILE => $jar,
	) );
	$j = json_decode( $r['body'], true );
	return empty( $j['success'] ) ? '' : $jar;
};

$jpeg = function () {
	$tmp = tempnam( sys_get_temp_dir(), 'nxjpg' );
	$im  = imagecreatetruecolor( 64, 48 );
	imagejpeg( $im, $tmp, 80 );
	imagedestroy( $im );
	return $tmp;
};

$customer = nx_test_user( 'httpcust' );
$vendor   = nx_test_vendor( 'httpvend', 'approved' );
$other    = nx_test_vendor( 'httpother', 'approved' );
$pw       = 'Tmp#Pass' . wp_rand( 1000, 9999 ) . 'x';
foreach ( array( $customer, $vendor, $other ) as $u ) { wp_set_password( $pw, $u['user_id'] ); }

$upload = function ( $user ) use ( $http, $base, $login, $pw, $jpeg ) {
	$jar = $login( $user, $pw );
	if ( '' === $jar ) { return array( 'login' => false ); }
	// The media modal's settings (with its nonce) are printed on the member's profile page.
	$page = $http( home_url( '/profile-page/' . rawurlencode( $user['login'] ) ), array( CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true ) );
	preg_match( '/"_wpnonce":"([a-f0-9]+)"/', $page['body'], $m );
	$src = $jpeg();
	$r   = $http( $base . '/wp-admin/async-upload.php', array(
		CURLOPT_COOKIEFILE => $jar,
		CURLOPT_POSTFIELDS => array( 'name' => 'images (4).jpeg', 'action' => 'upload-attachment', '_wpnonce' => $m[1] ?? '', 'async-upload' => new CURLFile( $src, 'image/jpeg', 'images (4).jpeg' ) ),
	) );
	@unlink( $src );
	$json = json_decode( $r['body'], true );
	if ( ! empty( $json['data']['id'] ) ) { $GLOBALS['nx_test']['posts'][] = (int) $json['data']['id']; }
	return array( 'login' => true, 'jar' => $jar, 'nonce' => $m[1] ?? '', 'response' => $r, 'json' => $json );
};

foreach ( array( 'a plain customer' => $customer, 'an approved vendor' => $vendor ) as $label => $u ) {
	$r = $upload( $u );
	nx_assert( $r['login'], "$label: logs in over HTTP" );
	nx_assert( ! empty( $r['nonce'] ), "$label: the profile page carries the media modal nonce" );
	$resp = $r['response'] ?? array( 'status' => 0, 'redirect' => '', 'body' => '', 'headers' => '' );
	nx_assert( 200 === $resp['status'] && '' === $resp['redirect'], "$label: the upload is answered directly, not redirected (status {$resp['status']}" . ( $resp['redirect'] ? ", to {$resp['redirect']}" : '' ) . ')' );
	nx_assert( is_array( json_decode( $resp['body'], true ) ), "$label: the reply body is one valid JSON document" );
	nx_assert( is_array( $r['json'] ?? null ) && ! empty( $r['json']['success'] ), "$label: the upload succeeds (" . substr( preg_replace( '/\s+/', ' ', $resp['body'] ), 0, 120 ) . ')' );
	nx_assert( 0 === strpos( ltrim( $resp['body'] ), '{' ), "$label: nothing is printed before the JSON (no PHP notice)" );
	$id = (int) ( $r['json']['data']['id'] ?? 0 );
	nx_assert( $id > 0 && 'attachment' === get_post_type( $id ) && (int) get_post_field( 'post_author', $id ) === $u['user_id'] && wp_attachment_is_image( $id ), "$label: an image attachment owned by them was created" );
	@unlink( $r['jar'] ?? '' );
}

// A vendor attaches the uploaded image to a product, over HTTP like the popup does.
$r  = $upload( $vendor );
$id = (int) ( $r['json']['data']['id'] ?? 0 );
$jar = $r['jar'] ?? '';
$save = $http( $base . '/wp-admin/admin-ajax.php', array(
	CURLOPT_COOKIEFILE => $jar,
	CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_product_save', 'nonce' => wp_create_nonce( 'profile_nonce' ), 'name' => 'Uploaded Pic Product', 'description' => '', 'price' => '99', 'stock' => '4', 'sku' => 'UP-1', 'category' => 'other', 'image_id' => (string) $id, 'product_id' => '' ) ),
) );
// The member's nonce depends on their session, so take it from their profile page instead of the guest one.
$page = $http( home_url( '/profile-page/' . rawurlencode( $vendor['login'] ) ), array( CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true ) );
preg_match( '/var nexoraMarketplace = (\{.*?\});/s', $page['body'], $m );
$mk = json_decode( $m[1] ?? '{}', true );
$save = $http( $base . '/wp-admin/admin-ajax.php', array(
	CURLOPT_COOKIEFILE => $jar,
	CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_product_save', 'nonce' => $mk['nonce'] ?? '', 'name' => 'Uploaded Pic Product', 'description' => '', 'price' => '99', 'stock' => '4', 'sku' => 'UP-1', 'category' => 'other', 'image_id' => (string) $id, 'product_id' => '' ) ),
) );
$sj  = json_decode( $save['body'], true );
nx_assert( ! empty( $sj['success'] ), 'the vendor saves a product with the uploaded image (' . substr( preg_replace( '/\s+/', ' ', $save['body'] ), 0, 120 ) . ')' );
$pid = Repository::find_by_sku( $vendor['vendor_id'], 'UP-1' );
if ( $pid ) { $GLOBALS['nx_test']['posts'][] = $pid; }
nx_assert( $pid > 0 && $id === Repository::data( $pid )['image_id'] && '' !== (string) wp_get_attachment_image_url( $id, 'medium' ), 'the product carries the image and it has a URL' );

// The media library listing inside the modal works too and shows only the vendor's own files.
$q = $http( $base . '/wp-admin/admin-ajax.php', array(
	CURLOPT_COOKIEFILE => $jar,
	CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'query-attachments', '_ajax_nonce' => $r['nonce'], 'query' => array( 'posts_per_page' => 40, 'paged' => 1 ) ) ),
) );
$qj = json_decode( $q['body'], true );
nx_assert( 200 === $q['status'] && ! empty( $qj['success'] ), 'the media library query is answered (status ' . $q['status'] . ')' );
nx_assert( ! empty( $qj['data'] ) && array() === array_filter( $qj['data'], function ( $a ) use ( $vendor ) { return (int) $a['author'] !== $vendor['user_id']; } ), 'and lists only the vendor\'s own files' );
@unlink( $jar );

// Another vendor still cannot use this upload as their own product image (ownership rule is unchanged).
$x = \Nexora\Products\Service::save( $other['user_id'], array( 'name' => 'Steal', 'description' => '', 'price' => '10', 'stock' => '3', 'sku' => 'UP-2', 'category' => 'other', 'image_id' => (string) $id ) );
nx_assert( is_wp_error( $x ) && isset( $x->get_error_data()['fields']['image_id'] ), "another vendor cannot use someone else's upload" );

// WooCommerce's admin lock-down must leave upload requests alone, but still bounce members from real admin pages.
if ( class_exists( 'WC_Admin' ) ) {
	$admin = new WC_Admin();
	$run   = function ( $uid, $script, $ajax ) use ( $admin ) {
		wp_set_current_user( $uid );
		$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-admin/' . $script;
		$throw = function ( $location ) { throw new RuntimeException( $location ); };
		add_filter( 'wp_redirect', $throw, 1 );
		if ( $ajax ) { add_filter( 'wp_doing_ajax', '__return_true' ); }
		$out = 'none';
		try { $admin->prevent_admin_access(); } catch ( RuntimeException $e ) { $out = $e->getMessage(); }
		remove_filter( 'wp_redirect', $throw, 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		wp_set_current_user( 0 );
		return $out;
	};
	foreach ( array( 'a customer' => $customer['user_id'], 'an approved vendor' => $vendor['user_id'] ) as $label => $uid ) {
		nx_assert_same( 'none', $run( $uid, 'async-upload.php', true ), "WooCommerce does not redirect $label away from async-upload.php" );
		nx_assert( 'none' !== $run( $uid, 'profile.php', false ), "WooCommerce still keeps $label out of an ordinary admin page" );
		nx_assert( 'none' !== $run( $uid, 'async-upload.php', false ), "and a non-AJAX request to async-upload.php is still bounced for $label" );
	}
}

nx_test_finish();
