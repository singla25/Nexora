<?php
// Phase 10: the rule "a user whose seller record has order lines cannot be deleted" holds for EVERY route that deletes a user, not only the
// Users screen: a plain wp_delete_user() call, the REST endpoint (DELETE /wp/v2/users/<id>), WP-CLI (which calls the same function), a plugin.
// WordPress offers no filter to refuse a deletion, but its `delete_user` action fires before anything is removed, so ending the request
// there (wp_die) leaves everything exactly as it was. A user whose seller record has no orders is deleted as before, with their Nexora records.
require_once __DIR__ . '/../bootstrap.php';

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();
require_once ABSPATH . 'wp-admin/includes/user.php';

$admin = nx_test_admin();
wp_set_current_user( $admin );
$S = nx_test_vendor( 'udga', 'approved' );     // has an order
$T = nx_test_vendor( 'udgb', 'approved' );     // no orders
$B = nx_test_user( 'udgbuy' );                  // a buyer (orders as a customer only)
$ps = nx_test_product( $S, 'UD-1', '10.00', '50' );
$pt = nx_test_product( $T, 'UD-2', '10.00', '50' );
$o  = nx_test_order( $B, array( array( $ps, 1 ) ), array( 'payment' => 'cod' ) );
nx_assert( (bool) $o['order'], 'setup: the seller has an order line' );
$po = nx_test_product( $S, 'UD-3', '10.00', '50' );

// wp_die() must not kill the test: it becomes an exception whose message we read.
$die = function () { return function ( $message ) { throw new RuntimeException( is_scalar( $message ) ? (string) $message : ( $message instanceof WP_Error ? $message->get_error_message() : 'died' ) ); }; };
add_filter( 'wp_die_handler', $die, 99 );
add_filter( 'wp_die_ajax_handler', $die, 99 );
add_filter( 'wp_die_json_handler', $die, 99 );
$attempt = function ( callable $fn ) { try { $fn(); return ''; } catch ( RuntimeException $e ) { return $e->getMessage(); } };
$intact = function () use ( $S, $ps, $po ) {
	clean_post_cache( $S['vendor_id'] ); clean_user_cache( $S['user_id'] );
	return (bool) get_userdata( $S['user_id'] ) && null !== get_post( $S['vendor_id'] ) && null !== get_post( $S['profile_id'] ) && null !== get_post( $ps ) && null !== get_post( $po );
};

/* ---------- a plain call ---------- */
$msg = $attempt( function () use ( $S ) { wp_delete_user( $S['user_id'] ); } );
nx_assert( '' !== $msg && false !== stripos( $msg, 'order' ), 'wp_delete_user() on such a seller is refused with a plain message (' . $msg . ')' );
nx_assert( $intact(), '... and nothing at all was deleted: user, seller record, customer post, products' );
$msg = $attempt( function () use ( $S, $admin ) { wp_delete_user( $S['user_id'], $admin ); } );
nx_assert( '' !== $msg && $intact(), 'also with posts to be reassigned to another user' );

/* ---------- the REST route ---------- */
$req = new WP_REST_Request( 'DELETE', '/wp/v2/users/' . $S['user_id'] );
$req->set_param( 'force', true );
$req->set_param( 'reassign', $admin );
$status = null; $msg = $attempt( function () use ( $req, &$status ) { $r = rest_do_request( $req ); $status = $r->get_status(); } );
nx_assert( ( '' !== $msg || ( null !== $status && $status >= 400 ) ) && $intact(), 'DELETE /wp/v2/users/<id> is refused too and deletes nothing' );

/* ---------- as another route: a plugin hooking in at the same moment cannot sneak past ---------- */
nx_assert( false !== has_action( 'delete_user' ), 'the guard hangs on the delete_user action' );

/* ---------- the trash does not matter: the orders still refer to the record ---------- */
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_status' => 'trash' ), array( 'ID' => $S['vendor_id'] ) );
clean_post_cache( $S['vendor_id'] );
nx_assert( '' !== $attempt( function () use ( $S ) { wp_delete_user( $S['user_id'] ); } ) && null !== get_userdata( $S['user_id'] ), 'a seller whose record is in the trash is still protected' );
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_status' => 'publish' ), array( 'ID' => $S['vendor_id'] ) );
clean_post_cache( $S['vendor_id'] );

/* ---------- everyone else is deleted as before ---------- */
$msg = $attempt( function () use ( $T ) { wp_delete_user( $T['user_id'] ); } );
nx_assert( '' === $msg && ! get_userdata( $T['user_id'] ), 'a seller with no orders is deleted' );
nx_assert( null === get_post( $T['vendor_id'] ) && null === get_post( $T['profile_id'] ) && null === get_post( $pt ), '... with their seller record, customer post and products' );
$msg = $attempt( function () use ( $B ) { wp_delete_user( $B['user_id'] ); } );
nx_assert( '' === $msg && ! get_userdata( $B['user_id'] ), 'a buyer (orders as a customer, not a seller) is deleted as before' );
nx_assert( null !== get_post( $S['vendor_id'] ) && $intact(), '... and the protected seller is still whole' );

remove_filter( 'wp_die_handler', $die, 99 ); remove_filter( 'wp_die_ajax_handler', $die, 99 ); remove_filter( 'wp_die_json_handler', $die, 99 );
nx_test_finish();
