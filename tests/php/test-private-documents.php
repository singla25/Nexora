<?php
// Phase 1a: ID documents (aadhaar_card, driving_license, company_id_card) must not be publicly fetchable.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;

$up    = wp_upload_dir();
$alice = nx_test_user( 'pdalice' );
$bob   = nx_test_user( 'pdbob' );
$admin = wp_insert_user( array( 'user_login' => 'nxtest_pdad_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'pdad' . wp_generate_password( 4, false ) . '@example.test', 'role' => 'administrator' ) );
$GLOBALS['nx_test']['users'][] = $admin;

nx_assert( class_exists( 'Nexora_Private_Documents' ), 'Nexora_Private_Documents class exists' );
if ( ! class_exists( 'Nexora_Private_Documents' ) ) { nx_test_finish(); return; }

$img = nx_test_attachment( $alice['user_id'], 'aadhaar scan' );
$public_file = get_attached_file( $img );
nx_assert( file_exists( $public_file ) && 0 === strpos( wp_get_attachment_url( $img ), $up['baseurl'] ), 'fixture starts as a normal public upload' );

/* ---- linking as an ID document protects the file ---- */
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'update_documents_info', array( 'aadhaar_card' => (string) $img ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'owner can still link the document through the existing AJAX action' );
nx_assert( '1' === get_post_meta( $img, '_nexora_private', true ), 'attachment is flagged private' );
$new_file = get_attached_file( $img );
nx_assert( 0 === strpos( $new_file, $up['basedir'] . '/nexora-private/' ) && file_exists( $new_file ), 'file now lives in uploads/nexora-private/' );
nx_assert( ! file_exists( $public_file ), 'original public file is gone' );
nx_assert( false === strpos( basename( $new_file ), 'aadhaar' ) && strlen( basename( $new_file ) ) > 20, 'private filename is unguessable (no original name)' );
$url = wp_get_attachment_url( $img );
nx_assert( false !== strpos( $url, 'admin-ajax.php' ) && false !== strpos( $url, 'action=nexora_document' ) && false === strpos( $url, 'nexora-private' ), 'attachment URL is the gated download URL, not a file path' );
nx_assert( file_exists( $up['basedir'] . '/nexora-private/.htaccess' ) && false !== stripos( file_get_contents( $up['basedir'] . '/nexora-private/.htaccess' ), 'denied' ) . '' || false !== stripos( file_get_contents( $up['basedir'] . '/nexora-private/.htaccess' ), 'deny' ), '.htaccess deny rule written' );
nx_assert( file_exists( $up['basedir'] . '/nexora-private/index.php' ), 'index.php guard written' );
$js = wp_prepare_attachment_for_js( $img );
nx_assert( is_array( $js ) && false !== strpos( $js['url'], 'nexora_document' ), 'media modal receives the gated URL' );

/* ---- authorization ---- */
nx_assert( Nexora_Private_Documents::can_view( $img, $alice['user_id'] ), 'owner may view' );
nx_assert( ! Nexora_Private_Documents::can_view( $img, $bob['user_id'] ), 'other member may NOT view' );
nx_assert( Nexora_Private_Documents::can_view( $img, $admin ), 'administrator may view' );
nx_assert( ! Nexora_Private_Documents::can_view( $img, 0 ), 'guest may NOT view' );
nx_assert( ! Nexora_Private_Documents::can_view( 999999999, $alice['user_id'] ), 'unknown id refused' );
$normal = nx_test_attachment( $alice['user_id'], 'normal' );
nx_assert( ! Nexora_Private_Documents::can_view( $normal, $alice['user_id'] ), 'non-private attachments are not served by the document endpoint' );

/* ---- path resolution is traversal-safe ---- */
nx_assert_same( $new_file, Nexora_Private_Documents::resolve_path( $img, '' ), 'full-size path resolves' );
nx_assert_same( null, Nexora_Private_Documents::resolve_path( $img, '../../../wp-config' ), 'traversal in size name refused' );
nx_assert_same( null, Nexora_Private_Documents::resolve_path( $img, 'nosuchsize' ), 'unknown size refused' );

/* ---- served through the endpoint (child process) ---- */
$serve = function ( $uid, $id ) {
	putenv( 'NX_USER=' . $uid ); putenv( 'NX_ID=' . $id ); putenv( 'NX_SIZE=' );
	return (string) shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval-file tests/child/serve-document.php --path=. 2>/dev/null' );
};
$bytes = file_get_contents( $new_file );

$out = $serve( $alice['user_id'], $img );
nx_assert( false !== strpos( $out, $bytes ) && false !== strpos( $out, 'H Content-Type: image/png' ), 'owner downloads the exact file with image/png' );
nx_assert( false !== strpos( $out, 'H X-Content-Type-Options: nosniff' ) && false !== strpos( $out, 'no-store' ), 'served with nosniff and private/no-store caching' );
$out = $serve( $bob['user_id'], $img );
nx_assert( false === strpos( $out, $bytes ) && false !== strpos( $out, 'DIED 403' ), 'other member gets 403 and no bytes' );
$out = $serve( $admin, $img );
nx_assert( false !== strpos( $out, $bytes ), 'administrator downloads the file' );
$out = $serve( 0, $img );
nx_assert( false === strpos( $out, $bytes ) && false !== strpos( $out, 'GUEST_HOOK=no' ), 'guests: no wp_ajax_nopriv hook, no bytes' );

/* ---- public vs private fields cannot be mixed ---- */
$pub = nx_test_attachment( $alice['user_id'], 'avatar' );
nx_call_ajax( 'update_documents_info', array( 'profile_image' => (string) $pub ) + nx_profile_nonce() );
nx_assert( '' === (string) get_post_meta( $pub, '_nexora_private', true ) && 0 === strpos( wp_get_attachment_url( $pub ), $up['baseurl'] ), 'profile/cover images stay public' );
$r = nx_call_ajax( 'update_documents_info', array( 'driving_license' => (string) $pub ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ) && '' === (string) get_post_meta( $pub, '_nexora_private', true ), 'a file used as profile image cannot be re-used as an ID document' );
$r = nx_call_ajax( 'update_documents_info', array( 'cover_image' => (string) $img ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'a private document cannot be used as a public cover image' );
$r = nx_call_ajax( 'save_user_content', array( 'title' => 'T', 'image' => (string) $img ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'a private document cannot be attached to a public post' );
wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'update_documents_info', array( 'company_id_card' => (string) $img ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), "another member cannot link someone else's private document" );

/* ---- removing the link keeps the file private ---- */
wp_set_current_user( $alice['user_id'] );
nx_call_ajax( 'update_documents_info', array( 'aadhaar_card' => '' ) + nx_profile_nonce() );
nx_assert( '1' === get_post_meta( $img, '_nexora_private', true ) && file_exists( $new_file ), 'unlinked document stays private' );

/* ---- legacy migration (documents linked before this feature) ---- */
$legacy = nx_test_attachment( $alice['user_id'], 'legacy-licence' );
$legacy_file = get_attached_file( $legacy );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $alice['profile_id'], 'meta_key' => 'driving_license', 'meta_value' => $legacy ) ); // bypass the update hook
nx_assert( '' === (string) get_post_meta( $legacy, '_nexora_private', true ), 'legacy doc is still public before migration' );
$dry = \Nexora\Profile\Documents_Migration::migrate_all( true );
nx_assert( 1 <= $dry['would_move'] && file_exists( $legacy_file ) && '' === (string) get_post_meta( $legacy, '_nexora_private', true ), 'dry run reports but changes nothing' );
$res = \Nexora\Profile\Documents_Migration::migrate_all( false );
nx_assert( $res['moved'] >= 1 && '1' === get_post_meta( $legacy, '_nexora_private', true ) && ! file_exists( $legacy_file ) && file_exists( get_attached_file( $legacy ) ), 'migration moves the legacy document' );
$again = \Nexora\Profile\Documents_Migration::migrate_all( false );
nx_assert_same( 0, $again['moved'], 'migration is idempotent (second run moves nothing)' );
// missing file: reported, nothing broken
$ghost = nx_test_attachment( $alice['user_id'], 'ghost' );
unlink( get_attached_file( $ghost ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $alice['profile_id'], 'meta_key' => 'company_id_card', 'meta_value' => $ghost ) );
$res = \Nexora\Profile\Documents_Migration::migrate_all( false );
nx_assert( $res['failed'] >= 1 && '' === (string) get_post_meta( $ghost, '_nexora_private', true ), 'missing file is reported as failed and left untouched' );

nx_test_finish();
