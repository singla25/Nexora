<?php
// Marketplace Phase 5: the CSV upload endpoints over real HTTP (a real multipart upload, as the browser sends it):
// file checks, dry run, import, who may use it, and that the member upload policy is NOT loosened for CSV.
// Skipped when the site cannot be reached from the CLI.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Products\Repository;

global $wpdb;
$base = untrailingslashit( home_url() );

if ( ! function_exists( 'curl_init' ) ) { echo "curl extension missing: skipped\n"; nx_test_finish(); return; }

$http = function ( $url, array $opts = array() ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, $opts + array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false ) );
	$out  = curl_exec( $ch );
	$info = curl_getinfo( $ch );
	curl_close( $ch );
	if ( false === $out ) { return array( 'status' => 0, 'headers' => '', 'body' => '' ); }
	return array( 'status' => (int) $info['http_code'], 'headers' => substr( $out, 0, $info['header_size'] ), 'body' => substr( $out, $info['header_size'] ) );
};
if ( ! $http( $base . '/wp-login.php', array( CURLOPT_NOBODY => true ) )['status'] ) { echo "site not reachable from the CLI: skipped\n"; nx_test_finish(); return; }

$A = nx_test_vendor( 'iha', 'approved' );
$B = nx_test_vendor( 'ihb', 'approved' );
$C = nx_test_user( 'ihc' );
$pw = 'Tmp#Pass' . wp_rand( 1000, 9999 ) . 'x';
foreach ( array( $A, $B, $C ) as $u ) { wp_set_password( $pw, $u['user_id'] ); }

$session = function ( $u ) use ( $http, $base, $pw ) {
	$jar = tempnam( sys_get_temp_dir(), 'nxjar' );
	$r   = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_POSTFIELDS => http_build_query( array( 'action' => 'nexora_profile_login', 'nonce' => wp_create_nonce( 'profile_nonce' ), 'user_name' => $u['login'], 'password' => $pw ) ), CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar ) );
	if ( empty( json_decode( $r['body'], true )['success'] ) ) { return array( 'jar' => '', 'nonce' => '', 'media' => '' ); }
	$page = $http( home_url( '/profile-page/' . rawurlencode( $u['login'] ) ), array( CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true ) );
	preg_match( '/var nexoraMarketplace = (\{.*?\});/s', $page['body'], $m );
	preg_match( '/"_wpnonce":"([a-f0-9]+)"/', $page['body'], $w );
	return array( 'jar' => $jar, 'nonce' => json_decode( $m[1] ?? '{}', true )['nonce'] ?? '', 'media' => $w[1] ?? '' );
};
$post = function ( $s, $action, array $fields = array(), $file = null ) use ( $http, $base ) {
	$fields = array_merge( array( 'action' => $action, 'nonce' => $s['nonce'] ), $fields );
	if ( $file ) { $fields['file'] = new CURLFile( $file[0], 'text/csv', $file[1] ); }
	$r = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_COOKIEFILE => $s['jar'], CURLOPT_POSTFIELDS => $fields ) );
	$r['json'] = json_decode( $r['body'], true );
	return $r;
};
$tmp = array();
$file = function ( $content ) use ( &$tmp ) { $p = tempnam( sys_get_temp_dir(), 'nxcsv' ); file_put_contents( $p, $content ); $tmp[] = $p; return $p; };
$HEAD  = "sku,name,description,price,stock,category,image_url\n";
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$attachments = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); };

$sa = $session( $A );
$sb = $session( $B );
$sc = $session( $C );
nx_assert( $sa['jar'] && $sa['nonce'] && $sc['jar'], 'setup: members log in over HTTP and get the page nonce' );

/* ---------- refused uploads: each answered with a clear JSON error, nothing created ---------- */
$good = $HEAD . "H-1,Http one,,10,1,other,\nH-2,Http two,,20,2,toys,\n";
$big  = $HEAD . str_repeat( "H-1,Kurta,,499,10,fashion,\n", 10 ) . str_repeat( 'a', 2 * 1024 * 1024 );
$rows = $HEAD; for ( $i = 1; $i <= 501; $i++ ) { $rows .= "R-$i,Item $i,,10,1,other,\n"; }
$bad  = array(
	'a .txt file'            => array( $file( $good ), 'products.txt' ),
	'a .php file'            => array( $file( $good ), 'products.csv.php' ),
	'a binary file named .csv' => array( $file( "PK\x03\x04\x00\x00" . random_bytes( 200 ) ), 'products.csv' ),
	'a non UTF-8 file'       => array( $file( $HEAD . "L-1,Caf\xE9,,10,1,other,\n" ), 'products.csv' ),
	'a file over 2 MB'       => array( $file( $big ), 'products.csv' ),
	'a file with 501 rows'   => array( $file( $rows ), 'products.csv' ),
	'an empty file'          => array( $file( '' ), 'products.csv' ),
	'a header only'          => array( $file( $HEAD ), 'products.csv' ),
	'a wrong header'         => array( $file( "a,b,c\n1,2,3\n" ), 'products.csv' ),
);
$before = $count();
foreach ( $bad as $label => $f ) {
	$r = $post( $sa, 'nexora_csv_preview', array(), $f );
	nx_assert( 200 === $r['status'] && is_array( $r['json'] ) && false === $r['json']['success'] && ! empty( $r['json']['data']['message'] ), "refused with a message: $label" );
}
nx_test_reset_limits();
$r = $post( $sa, 'nexora_csv_preview', array() );
nx_assert( is_array( $r['json'] ) && false === $r['json']['success'], 'refused: no file at all' );
$r = $post( $sa, 'nexora_csv_preview', array( 'file' => 'not a file, just text' ) );
nx_assert( is_array( $r['json'] ) && false === $r['json']['success'], 'refused: a text field named file instead of an upload' );
nx_assert_same( $before, $count(), 'none of the refused uploads created a product' );

/* ---------- dry run, then import ---------- */
nx_test_reset_limits(); // every attempt, refused or not, counts against the hourly allowance; start the next part fresh
$content = $HEAD . "H-1,Http one,Soft,10,1,other,\nH-2,Http two,,20,2,toys,\nH-3,Bad price,,0,2,toys,\n";
$up = $file( $content );
$att = $attachments();
$r  = $post( $sa, 'nexora_csv_preview', array(), array( $up, 'products.csv' ) );
nx_assert( ! empty( $r['json']['success'] ), 'preview: a valid file is accepted (' . substr( preg_replace( '/\s+/', ' ', $r['body'] ), 0, 140 ) . ')' );
$d = $r['json']['data'] ?? array();
nx_assert( 2 === ( $d['summary']['create'] ?? 0 ) && 1 === ( $d['summary']['error'] ?? 0 ), 'preview: two creates and one error' );
nx_assert( 4 === ( $d['rows'][2]['line'] ?? 0 ) && 'error' === ( $d['rows'][2]['action'] ?? '' ) && ! empty( $d['rows'][2]['errors']['price'] ), 'preview: the error is on line 4, field price' );
nx_assert_same( $before, $count(), 'preview: nothing was created' );
nx_assert_same( $att, $attachments(), 'preview: no attachment (the CSV is not stored in the Media Library)' );
$stray = array_merge( glob( wp_upload_dir()['basedir'] . '/*/*/*.csv' ) ?: array(), glob( wp_upload_dir()['basedir'] . '/*.csv' ) ?: array() );
nx_assert_same( array(), $stray, 'preview: no .csv file was written to the uploads folder' );
$token = $d['token'] ?? '';
nx_assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $token ), 'preview: a token comes back' );

$r = $post( $sb, 'nexora_csv_import', array( 'token' => $token ) );
nx_assert( false === ( $r['json']['success'] ?? null ) && $before === $count(), "import: another vendor cannot use the token" );
$r = $post( $sa, 'nexora_csv_import', array( 'token' => $token ) );
foreach ( Repository::ids_for_vendor( $A['vendor_id'] ) as $pid ) { $GLOBALS['nx_test']['posts'][] = $pid; }
nx_assert( ! empty( $r['json']['success'] ) && 2 === ( $r['json']['data']['summary']['created'] ?? 0 ) && 1 === ( $r['json']['data']['summary']['skipped'] ?? 0 ), 'import: two products created, one skipped' );
nx_assert_same( $before + 2, $count(), 'import: two products exist' );
nx_assert( Repository::find_by_sku( $A['vendor_id'], 'H-1' ) > 0 && 0 === Repository::find_by_sku( $A['vendor_id'], 'H-3' ), 'import: the valid rows only' );
$r = $post( $sa, 'nexora_csv_import', array( 'token' => $token ) );
nx_assert( false === ( $r['json']['success'] ?? null ) && $before + 2 === $count(), 'import: the token cannot be used twice' );

// The same file again is a no-op.
$r = $post( $sa, 'nexora_csv_preview', array(), array( $up, 'products.csv' ) );
nx_assert( 0 === ( $r['json']['data']['summary']['create'] ?? 9 ) && 2 === ( $r['json']['data']['summary']['unchanged'] ?? 0 ), 'preview of the same file again: nothing to create, two unchanged' );

nx_test_reset_limits();
// Vendor B uploading A's SKUs gets their own products.
$r = $post( $sb, 'nexora_csv_preview', array(), array( $up, 'products.csv' ) );
nx_assert( 2 === ( $r['json']['data']['summary']['create'] ?? 0 ), "vendor B's preview of the same SKUs plans two creates" );
$r = $post( $sb, 'nexora_csv_import', array( 'token' => $r['json']['data']['token'] ?? '' ) );
foreach ( Repository::ids_for_vendor( $B['vendor_id'] ) as $pid ) { $GLOBALS['nx_test']['posts'][] = $pid; }
nx_assert( 2 === count( Repository::ids_for_vendor( $B['vendor_id'] ) ) && 'Http one' === Repository::data( Repository::find_by_sku( $A['vendor_id'], 'H-1' ) )['name'], "B has their own two products and A's are untouched" );

nx_test_reset_limits();
/* ---------- who may use it ---------- */
$r = $post( $sc, 'nexora_csv_preview', array(), array( $up, 'products.csv' ) );
nx_assert( false === ( $r['json']['success'] ?? null ) && 'forbidden' === ( $r['json']['data']['code'] ?? '' ), 'a plain customer is refused (forbidden) even with a perfect file' );
$guest = array( 'jar' => '', 'nonce' => wp_create_nonce( 'profile_nonce' ) );
$r = $http( $base . '/wp-admin/admin-ajax.php', array( CURLOPT_POSTFIELDS => array( 'action' => 'nexora_csv_preview', 'nonce' => $guest['nonce'], 'file' => new CURLFile( $up, 'text/csv', 'products.csv' ) ) ) );
nx_assert( false === ( json_decode( $r['body'], true )['success'] ?? null ) || 400 === $r['status'] || 0 === strpos( trim( $r['body'] ), '0' ), 'a guest is refused' );

/* ---------- the member upload policy is not loosened: CSV is still refused by the media uploader ---------- */
$m = $http( $base . '/wp-admin/async-upload.php', array( CURLOPT_COOKIEFILE => $sa['jar'], CURLOPT_POSTFIELDS => array( 'name' => 'products.csv', 'action' => 'upload-attachment', '_wpnonce' => $sa['media'], 'async-upload' => new CURLFile( $up, 'text/csv', 'products.csv' ) ) ) );
$mj = json_decode( $m['body'], true );
nx_assert( is_array( $mj ) && empty( $mj['success'] ), 'the media uploader still refuses a .csv from a vendor (restrict_member_mimes is unchanged)' );
nx_assert_same( $att, $attachments(), 'and created no attachment' );
$types = array_values( get_allowed_mime_types( $A['user_id'] ) );
sort( $types );
nx_assert_same( array( 'application/pdf', 'image/gif', 'image/jpeg', 'image/png', 'image/webp' ), $types, 'a member\'s allowed upload types are still exactly jpg/png/gif/webp/pdf' );

foreach ( array( $sa, $sb, $sc ) as $s ) { @unlink( $s['jar'] ); }
foreach ( $tmp as $t ) { @unlink( $t ); }
nx_test_finish();
