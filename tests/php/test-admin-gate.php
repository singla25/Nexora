<?php
// Marketplace Phase 7b: the one switch that lets an administrator past Core\Post_Guard. It is open only when ALL are true: the user has
// manage_options, the request is a wp-admin edit-screen save or admin row action for THAT post, and the WordPress nonce for that exact
// post and action is valid. Never for the front end, AJAX or REST. Each condition is switched off on its own and must close it.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Admin_Gate;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
global $wpdb;

$S     = nx_test_vendor( 'agta', 'approved' );
$T     = nx_test_vendor( 'agtb', 'approved' );
$C     = nx_test_user( 'agtc' );
$sid   = $S['vendor_id'];
$other = $T['vendor_id'];
$admin = nx_test_admin();
$pid   = nx_test_product( $S, 'AG-1', '10.00', '5' );
$open  = function ( $post, $op ) { return Admin_Gate::open( $post, $op ); };

/* ---------- open: a genuine admin action on this post ---------- */
foreach ( array( 'edit', 'trash', 'untrash', 'delete' ) as $op ) {
	nx_test_admin_request( $sid, $op );
	nx_assert_same( true, $open( $sid, $op ), "open: an administrator's genuine '$op' request for this post" );
	nx_test_admin_request_end();
}

/* ---------- each condition on its own ---------- */
foreach ( array( 'edit', 'trash' ) as $op ) {

	nx_test_admin_request( $sid, $op, array( 'user' => $C['user_id'] ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: a customer is refused (no manage_options), even with a perfect request" );
	nx_test_admin_request( $sid, $op, array( 'user' => $S['user_id'] ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: an approved seller is refused" );
	nx_test_admin_request( $sid, $op, array( 'user' => $T['user_id'] ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: another seller is refused" );
	$editor = wp_insert_user( array( 'user_login' => 'nxtest_editor_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$GLOBALS['nx_test']['users'][] = $editor;
	nx_test_admin_request( $sid, $op, array( 'user' => $editor ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: an editor (edit_posts but not manage_options) is refused" );
	nx_test_admin_request( $sid, $op, array( 'user' => 0 ) );
	wp_set_current_user( 0 );
	nx_assert_same( false, $open( $sid, $op ), "$op: a guest is refused" );

	nx_test_admin_request( $sid, $op, array( 'screen' => false ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: outside wp-admin (front end) is refused" );

	nx_test_admin_request( $sid, $op, array( 'ajax' => true ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: AJAX is refused, even for an administrator" );

	nx_test_admin_request( $sid, $op, array( 'nonce' => false ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: no nonce is refused" );
	nx_test_admin_request( $sid, $op, array( 'nonce' => 'other' ) );
	nx_assert_same( false, $open( $sid, $op ), "$op: a valid nonce made for ANOTHER post is refused" );
	$bad = 'edit' === $op ? '_POST' : '_GET';
	nx_test_admin_request( $sid, $op );
	$GLOBALS[ $bad ]['_wpnonce'] = 'abcdef1234';
	$_REQUEST['_wpnonce']        = 'abcdef1234';
	nx_assert_same( false, $open( $sid, $op ), "$op: a forged nonce is refused" );
	nx_test_admin_request_end();
}

/* ---------- the request must be about THIS post and THIS operation ---------- */
nx_test_admin_request( $sid, 'edit' );
nx_assert_same( false, $open( $other, 'edit' ), 'a valid request for post A does not open post B' );
nx_assert_same( false, $open( $sid, 'trash' ), 'an edit request does not open trash' );
nx_assert_same( false, $open( $sid, 'delete' ), '... or delete' );
nx_test_admin_request( $sid, 'trash' );
nx_assert_same( false, $open( $sid, 'delete' ), 'a trash request does not open delete' );
nx_assert_same( false, $open( $sid, 'untrash' ), '... or untrash' );
nx_assert_same( false, $open( $sid, 'edit' ), '... or an edit save' );
nx_test_admin_request( $sid, 'edit', array( 'post_id_in_request' => $other ) );
nx_assert_same( false, $open( $sid, 'edit' ), 'a request whose post_ID is another post is refused' );
nx_test_admin_request( $sid, 'edit', array( 'method' => 'GET' ) );
nx_assert_same( false, $open( $sid, 'edit' ), 'an edit save must be a POST' );
nx_test_admin_request( $sid, 'trash', array( 'method' => 'POST' ) );
nx_assert_same( false, $open( $sid, 'trash' ), 'a row action is a GET, not a POST' );
nx_assert_same( false, $open( 0, 'edit' ), 'no post, no gate' );
nx_assert_same( false, $open( $sid, 'bogus' ), 'an unknown operation is refused' );
nx_test_admin_request_end();
nx_assert_same( false, $open( $sid, 'edit' ), 'with no request at all (code running on its own) the gate is closed' );

/* ---------- REST (needs its own process: REST_REQUEST is a constant) ---------- */
$rest = function ( $post, $op ) use ( $admin ) {
	return trim( (string) shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && NX_UID=' . (int) $admin . ' NX_POST=' . (int) $post . ' NX_OP=' . escapeshellarg( $op ) . ' wp eval-file tests/child/admin-gate-rest.php --path=. 2>/dev/null' ) );
};
nx_assert_same( 'OPEN=0', $rest( $sid, 'edit' ), 'REST: an administrator with a perfect edit request is refused' );
nx_assert_same( 'OPEN=0', $rest( $sid, 'trash' ), 'REST: ... and a perfect trash request' );

/* ---------- Post_Guard asks the gate (seller record) ---------- */
nx_assert( null === wp_trash_post( $sid ) || false === wp_trash_post( $sid ), 'control: with no admin request a seller record cannot be trashed' );
nx_assert_same( 'publish', get_post_status( $sid ), '...and it is still published' );
wp_set_current_user( $admin );
nx_assert( ! wp_delete_post( $sid, true ), 'control: an administrator calling wp_delete_post from code (no admin request) is refused' );
nx_assert( null !== get_post( $sid ), '...the record is still there' );

nx_test_admin_request( $sid, 'trash' );
nx_assert( (bool) wp_trash_post( $sid ), 'a genuine admin trash request trashes the seller record' );
nx_assert_same( 'trash', get_post_status( $sid ), '...it is in the trash' );
nx_test_admin_request( $sid, 'untrash' );
nx_assert( (bool) wp_untrash_post( $sid ), 'a genuine admin untrash request restores it' );
nx_test_admin_request_end();

$guarded = array( $other => 'seller record B', $pid => 'product' );
foreach ( $guarded as $id => $label ) {
	nx_test_admin_request( $sid, 'trash' );
	nx_assert( ! wp_trash_post( $id ), "a valid request for post A cannot trash $label (post B)" );
	nx_test_admin_request( $id, 'trash', array( 'screen' => false ) );
	nx_assert( ! wp_trash_post( $id ), "$label: the front end cannot trash it, nonce and all" );
	nx_test_admin_request( $id, 'trash', array( 'ajax' => true ) );
	nx_assert( ! wp_trash_post( $id ), "$label: AJAX cannot trash it, even as an administrator" );
	nx_test_admin_request( $id, 'trash', array( 'nonce' => false ) );
	nx_assert( ! wp_trash_post( $id ), "$label: no nonce, no trash" );
	nx_test_admin_request( $id, 'trash', array( 'user' => $C['user_id'] ) );
	nx_assert( ! wp_trash_post( $id ), "$label: a customer cannot trash it" );
	nx_test_admin_request( $id, 'trash', array( 'user' => $S['user_id'] ) );
	nx_assert( ! wp_trash_post( $id ), "$label: not even its own seller" );
	nx_assert( 'trash' !== get_post_status( $id ), "$label: still not trashed after all of that" );
	nx_test_admin_request_end();
}

/* ---------- creating is never lifted ---------- */
nx_test_admin_request( 0, 'edit' );
$made = wp_insert_post( array( 'post_type' => 'nexora_product', 'post_title' => 'Admin made', 'post_status' => 'publish' ) );
nx_assert( ! $made, 'even a genuine admin request cannot create a product by wp_insert_post' );
nx_test_admin_request_end();

/* ---------- editing core fields from outside the Editor stays blocked (keep_fields) ---------- */
$title_before  = get_post_field( 'post_title', $pid );
$status_before = get_post_status( $pid ); // (a trash of its seller earlier in this file made it a draft)
nx_test_admin_request( $pid, 'edit' );
wp_update_post( array( 'ID' => $pid, 'post_title' => 'Smuggled title', 'post_status' => 'publish' === $status_before ? 'draft' : 'publish' ) );
clean_post_cache( $pid );
nx_assert( $title_before === get_post_field( 'post_title', $pid ) && $status_before === get_post_status( $pid ), 'a genuine edit request still cannot rewrite title or status around the validators' );
nx_test_admin_request_end();

nx_test_finish();
