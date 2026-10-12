<?php
// Phase 1c: members may upload only through the profile UI, within limits; no permanent role capability.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;

$alice = nx_test_user( 'ulalice' );
$admin = wp_insert_user( array( 'user_login' => 'nxtest_ulad_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'ulad' . wp_generate_password( 4, false ) . '@example.test', 'role' => 'administrator' ) );
$GLOBALS['nx_test']['users'][] = $admin;

$sub = get_role( 'subscriber' );
$had_cap = $sub->has_cap( 'upload_files' );

/* ---- no permanent role capability ---- */
Nexora_Upload_Policy::cleanup_role_cap();
nx_assert( ! get_role( 'subscriber' )->has_cap( 'upload_files' ), 'subscriber role no longer carries upload_files in the database' );
Nexora_Upload_Policy::cleanup_role_cap();
nx_assert( ! get_role( 'subscriber' )->has_cap( 'upload_files' ), 'role cleanup is idempotent' );

/* ---- capability only in upload contexts ---- */
wp_set_current_user( $alice['user_id'] );
nx_assert( ! user_can( $alice['user_id'], 'upload_files' ), 'member has no upload_files in an ordinary request' );
nx_assert( user_can( $admin, 'upload_files' ), 'administrator keeps upload_files' );

$_REQUEST['action'] = 'upload-attachment';
add_filter( 'wp_doing_ajax', '__return_true' );
nx_assert( user_can( $alice['user_id'], 'upload_files' ), 'member may upload during the media uploader AJAX request' );
$_REQUEST['action'] = 'query-attachments';
nx_assert( user_can( $alice['user_id'], 'upload_files' ), 'member may browse their library during query-attachments' );
$_REQUEST['action'] = 'delete-post';
nx_assert( ! user_can( $alice['user_id'], 'upload_files' ), 'other AJAX actions do not grant uploads' );
$_REQUEST['action'] = 'upload-attachment';
$noprofile = wp_insert_user( array( 'user_login' => 'nxtest_ulnp_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'ulnp' . wp_generate_password( 4, false ) . '@example.test' ) );
$GLOBALS['nx_test']['users'][] = $noprofile;
nx_assert( ! user_can( $noprofile, 'upload_files' ), 'a WP user without a Nexora profile never gets upload rights' );
remove_filter( 'wp_doing_ajax', '__return_true' );
unset( $_REQUEST['action'] );
nx_assert( ! user_can( $alice['user_id'], 'upload_files' ), 'rights disappear once the upload request is over' );

/* ---- REST media endpoint is closed to members ---- */
$req = new WP_REST_Request( 'POST', '/wp/v2/media' );
$req->set_header( 'content-disposition', 'attachment; filename="x.png"' );
$req->set_header( 'content-type', 'image/png' );
$req->set_body( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
rest_get_server();
$res = rest_do_request( $req );
nx_assert_same( 403, $res->get_status(), 'REST POST /wp/v2/media is forbidden for members' );

/* ---- size limit ---- */
$MB = 1024 * 1024;
nx_assert( apply_filters( 'upload_size_limit', 64 * $MB ) <= 8 * $MB, 'members: upload_size_limit capped at 8 MB' );
$big = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'big.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 9 * $MB ) );
nx_assert( ! empty( $big['error'] ), 'members: 9 MB file refused with an error message' );
$ok = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'ok.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 2 * $MB ) );
nx_assert( empty( $ok['error'] ), 'members: 2 MB file accepted' );
wp_set_current_user( $admin );
nx_assert( apply_filters( 'upload_size_limit', 64 * $MB ) === 64 * $MB, 'administrators: size limit untouched' );
$big = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'big.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 9 * $MB ) );
nx_assert( empty( $big['error'] ), 'administrators: large file not blocked' );

/* ---- file count limit ---- */
wp_set_current_user( $alice['user_id'] );
add_filter( 'nexora_member_max_files', function () { return 2; } );
nx_test_attachment( $alice['user_id'], 'one' );
$r = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 1000 ) );
nx_assert( empty( $r['error'] ), 'members: under the file count limit' );
nx_test_attachment( $alice['user_id'], 'two' );
$r = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 1000 ) );
nx_assert( ! empty( $r['error'] ), 'members: at the file count limit the next upload is refused' );

/* ---- allowed types (characterization) ---- */
$mimes = get_allowed_mime_types( $alice['user_id'] );
$types = array_values( $mimes );
sort( $types );
nx_assert_same( array( 'application/pdf', 'image/gif', 'image/jpeg', 'image/png', 'image/webp' ), $types, 'members: only jpg/png/gif/webp/pdf are allowed' );
nx_assert( ! isset( $mimes['svg'] ) && ! isset( $mimes['php'] ), 'members: no svg / php' );

// restore the starting state of the shared site
if ( $had_cap ) { get_role( 'subscriber' )->add_cap( 'upload_files' ); }
nx_test_finish();
