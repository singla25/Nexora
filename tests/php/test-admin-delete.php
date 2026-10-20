<?php
// Marketplace Phase 7b: the delete rules. Trashing a seller hides them and unpublishes their products but leaves every order alone;
// permanent deletion is refused while ANY order line refers to the seller; a seller with no orders can be deleted (products and API
// connections go, the member keeps the account as a plain customer); a customer post that still has a seller record cannot be deleted;
// deleting a WordPress user is refused if their seller record has orders, otherwise it cascades to their Nexora posts. Every one of
// these needs the admin gate (manage_options + a genuine wp-admin request + the nonce); the order rule holds even without it.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\Deletion;
use Nexora\Admin_Edit\Users;
use Nexora\Imports\Connection_Repository;
use Nexora\Orders\Earnings;
use Nexora\Orders\Lines;
use Nexora\Vendors\Repository as Sellers;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$buyer = nx_test_user( 'aedbuy' );
$exists = function ( $id ) { clean_post_cache( $id ); $p = get_post( $id ); return $p && 'auto-draft' !== $p->post_status; };
$status = function ( $id ) { clean_post_cache( $id ); return get_post_status( $id ); };
$roles  = function ( $uid ) { clean_user_cache( $uid ); $u = get_userdata( $uid ); return $u ? array_values( $u->roles ) : array(); };
$trash  = function ( $id, array $over = array() ) { nx_test_admin_request( $id, 'trash', $over ); $r = wp_trash_post( $id ); nx_test_admin_request_end(); wp_set_current_user( nx_test_admin() ); return $r; };
$delete = function ( $id, array $over = array() ) { nx_test_admin_request( $id, 'delete', $over ); $r = wp_delete_post( $id, true ); nx_test_admin_request_end(); wp_set_current_user( nx_test_admin() ); return $r; };
$api    = function ( $user, $vendor_id ) { $id = Connection_Repository::create( $user, $vendor_id, array( 'name' => 'nxtest conn', 'url' => 'https://example.com/api', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '' ) ); return is_wp_error( $id ) ? 0 : nx_test_track_post( $id ); };

/* ---------- a seller WITH orders: trash is allowed, permanent delete is not ---------- */
$S  = nx_test_vendor( 'aeds', 'approved' );
$p1 = nx_test_product( $S, 'AED-1', '40.00', '9' );
$p2 = nx_test_product( $S, 'AED-2', '10.00', '9' );
$c1 = $api( $S['user_id'], $S['vendor_id'] );
$o  = nx_test_order( $buyer, array( array( $p1, 2 ) ), array( 'payment' => 'cod' ) );
nx_assert( $o['order'] && 'processing' === $o['order']->get_status(), 'setup: an order exists for the seller (' . $o['error'] . ')' );
$lines0 = Lines::of( wc_get_order( $o['order']->get_id() ) );
$earn0  = Earnings::summary( $S['vendor_id'] );
nx_assert( Earnings::has_orders( $S['vendor_id'] ), 'Earnings::has_orders() sees an order line of the seller' );
nx_assert( ! Earnings::has_orders( 987654321 ), '... and none for an unknown seller' );

$r = $trash( $S['vendor_id'] );
nx_assert( $r && 'trash' === $status( $S['vendor_id'] ), 'a seller with orders can be trashed by a genuine admin request' );
nx_assert_same( array( 'draft', 'draft' ), array( $status( $p1 ), $status( $p2 ) ), 'trashing unpublishes every product of the seller (they stay, as drafts)' );
nx_assert_same( 0, Sellers::id_for_user( $S['user_id'] ), 'the seller is hidden: no live record for the member' );
nx_assert( ! Account_Types::can_sell( $S['user_id'] ) || 0 === Sellers::id_for_user( $S['user_id'] ), 'and cannot sell while trashed' );
nx_assert( $exists( $c1 ), 'API connections are kept by a trash (it can be undone)' );
nx_assert_same( $lines0, Lines::of( wc_get_order( $o['order']->get_id() ) ), 'the order lines are untouched' );
nx_assert_same( $earn0, Earnings::summary( $S['vendor_id'] ), 'and so are the seller\'s earnings figures' );
nx_assert( Earnings::has_orders( $S['vendor_id'] ), 'the order still refers to the (trashed) seller' );
$hist = array_column( Audit::rows( 'nexora_cust_seller', $S['vendor_id'] ), 'field' );
nx_assert( in_array( 'post_status', $hist, true ), 'the trash is in the change history' );

$r = $delete( $S['vendor_id'] );
nx_assert( ! $r && $exists( $S['vendor_id'] ), 'permanent delete is REFUSED while an order line refers to the seller' );
nx_assert( $exists( $p1 ) && $exists( $c1 ) && $exists( $S['profile_id'] ), '... and nothing of the seller was removed' );
nx_assert( ! wp_delete_post( $S['vendor_id'], true ) && $exists( $S['vendor_id'] ), 'the order rule holds even with no admin request (cron, a plugin, a crafted call)' );
wp_set_current_user( 0 );
nx_assert( ! wp_delete_post( $S['vendor_id'], true ) && $exists( $S['vendor_id'] ), '... and as a guest' );
wp_set_current_user( $admin );
$del_before = Sellers::user_for( $S['vendor_id'] );
Sellers::delete( $S['vendor_id'] );
nx_assert( $exists( $S['vendor_id'] ) && (int) get_user_meta( $S['user_id'], '_vendor_id', true ) === $S['vendor_id'] && $del_before === $S['user_id'], 'even the Repository\'s own delete refuses (and leaves the links alone)' );
// Cancelled / refunded / trashed orders still refer to the seller.
$o['order']->update_status( 'cancelled' );
nx_assert( Earnings::has_orders( $S['vendor_id'] ) && ! $delete( $S['vendor_id'] ), 'a cancelled order still counts as "refers to the seller"' );
$o['order']->update_status( 'processing' );

$r = $trash( $S['vendor_id'], array() );
nx_test_admin_request( $S['vendor_id'], 'untrash' );
$r = wp_untrash_post( $S['vendor_id'] );
nx_test_admin_request_end(); wp_set_current_user( $admin );
nx_assert( $r && 'publish' === $status( $S['vendor_id'] ), 'a trashed seller can be restored' );
nx_assert_same( array( 'draft', 'draft' ), array( $status( $p1 ), $status( $p2 ) ), '(their products stay drafts until the administrator publishes them again)' );
nx_assert( Sellers::id_for_user( $S['user_id'] ) === $S['vendor_id'], 'the member is the seller again' );

/* ---------- the gate is still needed to trash ---------- */
$T = nx_test_vendor( 'aedt', 'approved' );
nx_assert( ! wp_trash_post( $T['vendor_id'] ) && 'publish' === $status( $T['vendor_id'] ), 'without an admin request nobody can trash a seller' );
nx_assert( ! $trash( $T['vendor_id'], array( 'user' => $T['user_id'] ) ) && 'publish' === $status( $T['vendor_id'] ), 'the seller cannot trash their own record' );
nx_assert( ! $trash( $T['vendor_id'], array( 'user' => $buyer['user_id'] ) ) && 'publish' === $status( $T['vendor_id'] ), 'a customer cannot' );
nx_assert( ! $trash( $T['vendor_id'], array( 'ajax' => true ) ) && 'publish' === $status( $T['vendor_id'] ), 'admin-ajax cannot' );
nx_assert( ! $trash( $T['vendor_id'], array( 'nonce' => false ) ) && 'publish' === $status( $T['vendor_id'] ), 'no nonce cannot' );

/* ---------- a seller with NO orders: deleted for good ---------- */
$U  = nx_test_vendor( 'aedu', 'approved' );
$u1 = nx_test_product( $U, 'AED-U1', '5.00', '2' );
$u2 = nx_test_product( $U, 'AED-U2', '6.00', '2' );
$uc = $api( $U['user_id'], $U['vendor_id'] );
nx_assert( ! Earnings::has_orders( $U['vendor_id'] ), 'setup: a seller with no orders' );
$cust_id = (int) get_post_meta( $U['vendor_id'], 'customer_post_id', true );
nx_assert_same( $U['profile_id'], $cust_id, 'setup: the seller record is linked to the customer post' );
$r = $delete( $U['vendor_id'] );
nx_assert( $r && ! $exists( $U['vendor_id'] ), 'a seller with no orders can be deleted' );
nx_assert( ! $exists( $u1 ) && ! $exists( $u2 ), '... their products go' );
nx_assert( ! $exists( $uc ), '... and their API connections' );
nx_assert( get_userdata( $U['user_id'] ) && $exists( $U['profile_id'] ), 'the member keeps their account and their customer post' );
nx_assert_same( array( 'subscriber' ), $roles( $U['user_id'] ), 'and drops back to a plain customer' );
nx_assert_same( 'none', Account_Types::vendor_status( $U['user_id'] ), 'with no seller status' );
nx_assert_same( '', (string) get_post_meta( $U['profile_id'], 'seller_post_id', true ), 'the customer post no longer points at the deleted record' );
nx_assert( ! Account_Types::has_seller_record( $U['user_id'] ), 'has_seller_record() is false now' );
nx_assert( count( Audit::rows( 'nexora_cust_seller', $U['vendor_id'] ) ) > 0, 'the change history of the deleted record is kept' );

// A direct seller: they get a plain customer post (it was their only profile).
$D  = nx_test_direct_seller( 'aedd', 'approved' );
$d1 = nx_test_product( $D, 'AED-D1', '5.00', '2' );
nx_assert( ! (int) get_user_meta( $D['user_id'], '_seller_post_id', true ) === false, 'setup: a direct seller' );
$r = $delete( $D['seller_id'] );
nx_assert( $r && ! $exists( $D['seller_id'] ) && ! $exists( $d1 ), 'a direct seller with no orders is deleted with their products' );
$new_profile = (int) get_user_meta( $D['user_id'], '_profile_id', true );
$GLOBALS['nx_test']['posts'][] = $new_profile;
nx_assert( $new_profile && 'nexora_customer' === get_post_type( $new_profile ) && $new_profile !== $D['seller_id'], 'the user gets a plain customer post' );
nx_assert_same( array( 'subscriber' ), $roles( $D['user_id'] ), 'and the customer role' );
nx_assert( 'Dir' === get_post_meta( $new_profile, 'first_name', true ), 'carrying over their personal details' );

/* ---------- a customer post that still has a seller record ---------- */
$L = nx_test_vendor( 'aedl', 'approved' );
nx_assert( ! $trash( $L['profile_id'] ) && 'publish' === $status( $L['profile_id'] ), 'a customer post linked to a seller record cannot be trashed' );
nx_assert( ! $delete( $L['profile_id'] ) && $exists( $L['profile_id'] ), 'nor deleted' );
nx_assert( ! wp_delete_post( $L['profile_id'], true ) && $exists( $L['profile_id'] ), '... by any route, admin request or not' );
$msg = Deletion::customer_blocked( $L['profile_id'] );
nx_assert( '' !== $msg && false !== stripos( $msg, 'seller' ), 'with a plain explanation (' . $msg . ')' );
$delete( $L['vendor_id'] );
nx_assert( ! $exists( $L['vendor_id'] ) && '' === Deletion::customer_blocked( $L['profile_id'] ), 'once the seller record is handled, the block is gone' );
$R = nx_test_user( 'aedr' );
nx_assert( '' === Deletion::customer_blocked( $R['profile_id'] ), 'a customer who was never a seller has no block' );

/* ---------- other products / connections are never touched ---------- */
nx_assert( $exists( $T['vendor_id'] ), 'an unrelated seller was not affected by any of this' );

/* ---------- deleting a WordPress user ---------- */
$W  = nx_test_vendor( 'aedw', 'approved' );
$ow = nx_test_product( $W, 'AED-W1', '9.00', '5' );
$o2 = nx_test_order( $buyer, array( array( $ow, 1 ) ), array( 'payment' => 'cod' ) );
nx_assert( (bool) $o2['order'], 'setup: a seller whose order exists' );
$why = Users::refusal( $W['user_id'] );
nx_assert( '' !== $why && false !== stripos( $why, 'order' ), 'deleting a user whose seller record has orders is refused with a clear message (' . $why . ')' );
nx_assert( ! user_can( $admin, 'delete_user', $W['user_id'] ), 'WordPress does not offer / allow the delete for them' );
$_REQUEST = array( 'action' => 'dodelete', 'users' => array( $W['user_id'] ), 'delete_option' => 'delete' );
nx_assert_same( $why, Users::refusal_for_request( $_REQUEST ), 'the Users screen request is caught (single and bulk)' );
$_REQUEST = array( 'action' => 'dodelete', 'users' => array( $buyer['user_id'] ) );
nx_assert_same( '', Users::refusal_for_request( $_REQUEST ), 'a user with no seller orders passes' );
$_REQUEST = array();
nx_assert( get_userdata( $W['user_id'] ) && $exists( $W['vendor_id'] ), 'nothing was deleted by asking' );

$X  = nx_test_vendor( 'aedx', 'approved' );
$xp = nx_test_product( $X, 'AED-X1', '9.00', '5' );
$xc = $api( $X['user_id'], $X['vendor_id'] );
$Y  = nx_test_user( 'aedy' );
$cn = nx_test_connection( $X, $Y, 'accepted' );
$ct = \Nexora\Content\Repository::create( $X['user_id'], $X['profile_id'], 'Doomed post', 'text' ); nx_test_track_post( $ct );
$keep = \Nexora\Content\Repository::create( $Y['user_id'], $Y['profile_id'], 'Survivor post', 'text' ); nx_test_track_post( $keep );
$nt = new \Nexora\Notifications\Repository();
$nt->insert( array( 'actor_user_id' => $Y['user_id'], 'actor_user_name' => $Y['login'], 'receiver_user_id' => $X['user_id'], 'receiver_user_name' => $X['login'], 'type' => 'nxtest', 'message' => 'for X' ) );
$nt->insert( array( 'actor_user_id' => $Y['user_id'], 'actor_user_name' => $Y['login'], 'receiver_user_id' => $Y['user_id'], 'receiver_user_name' => $Y['login'], 'type' => 'nxtest', 'message' => 'for Y' ) );
nx_assert_same( '', Users::refusal( $X['user_id'] ), 'a seller with no orders may have their account deleted' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $X['user_id'] );
nx_assert( ! get_userdata( $X['user_id'] ), 'the user is gone' );
nx_assert( ! $exists( $X['profile_id'] ) && ! $exists( $X['vendor_id'] ) && ! $exists( $xp ) && ! $exists( $xc ), 'their customer post, seller record, products and API connections went with them' );
nx_assert( ! $exists( $cn ) && ! $exists( $ct ), 'their connections and their posts went too' );
nx_assert( $exists( $keep ) && $exists( $Y['profile_id'] ) && get_userdata( $Y['user_id'] ), 'the other member and their post are untouched' );
nx_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nexora_notifications WHERE receiver_user_id = %d OR actor_user_id = %d", $X['user_id'], $X['user_id'] ) ), 'their notifications are gone' );
nx_assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nexora_notifications WHERE receiver_user_id = %d AND type = 'nxtest'", $Y['user_id'] ) ), 'and the other member\'s notification stays' );
$wpdb->delete( $wpdb->prefix . 'nexora_notifications', array( 'receiver_user_id' => $Y['user_id'], 'type' => 'nxtest' ) );

/* ---------- Users screen: create a seller record for a user ---------- */
$Z = nx_test_user( 'aedz' );        // has a customer post
$V = nx_test_user_only( 'aedv' );   // no profile at all
$G = nx_test_vendor( 'aedg', 'pending' );
$link_for = function ( $uid ) { $acts = Users::row_actions( array(), get_userdata( $uid ) ); return implode( ' ', $acts ); };
nx_assert( false !== strpos( $link_for( $Z['user_id'] ), '_wpnonce' ) && false !== stripos( $link_for( $Z['user_id'] ), 'Create seller record' ), 'a user without a seller record gets the row action "Create seller record for this user"' );
nx_assert_same( '', $link_for( $G['user_id'] ), 'a user who already has one does not' );
wp_set_current_user( $Z['user_id'] );
nx_assert_same( '', $link_for( $buyer['user_id'] ), 'and a non-administrator sees no such action' );
wp_set_current_user( $admin );
$res = Users::create_seller_record( $Z['user_id'] );
nx_assert( is_int( $res ) && $res > 0, 'creating a seller record for a customer works (' . ( is_wp_error( $res ) ? $res->get_error_message() : 'ok' ) . ')' );
$GLOBALS['nx_test']['posts'][] = $res;
nx_assert_same( 'nexora_cust_seller', get_post_type( $res ), 'a customer who has a profile gets a customer-seller record' );
nx_assert_same( array( $res, $Z['profile_id'], $Z['user_id'] ), array( (int) get_user_meta( $Z['user_id'], '_vendor_id', true ), (int) get_post_meta( $res, 'customer_post_id', true ), (int) get_post_meta( $res, '_wp_user_id', true ) ), 'linked through the shared linking method (user, customer post, record)' );
nx_assert_same( $res, (int) get_post_meta( $Z['profile_id'], 'seller_post_id', true ), '... both ways' );
nx_assert_same( 'pending', Account_Types::vendor_status( $Z['user_id'] ), 'the status is pending: nothing is approved by creating it' );
nx_assert_same( array( 'subscriber' ), $roles( $Z['user_id'] ), '... and the role changes only on approval' );
nx_assert( is_wp_error( Users::create_seller_record( $Z['user_id'] ) ), 'a second one for the same user is refused' );
$res = Users::create_seller_record( $V );
nx_assert( is_int( $res ) && 'nexora_seller' === get_post_type( $res ), 'a user with no profile at all gets a direct seller record (their profile)' );
$GLOBALS['nx_test']['posts'][] = $res;
nx_assert_same( array( $res, $res ), array( (int) get_user_meta( $V, '_profile_id', true ), (int) get_user_meta( $V, '_vendor_id', true ) ), 'which is their profile and their seller record' );
wp_set_current_user( $buyer['user_id'] );
nx_assert( is_wp_error( Users::create_seller_record( nx_test_user_only( 'aedq' ) ) ), 'a non-administrator cannot create one' );
wp_set_current_user( $admin );
nx_assert( is_wp_error( Users::create_seller_record( 987654321 ) ), 'nor for a user that does not exist' );
$nonce = wp_create_nonce( 'nexora_create_seller_' . $buyer['user_id'] );
nx_assert( Users::request_ok( $buyer['user_id'], $nonce ) && ! Users::request_ok( $buyer['user_id'], 'bad' ) && ! Users::request_ok( $Z['user_id'], $nonce ), 'the row action is protected by a nonce per user' );

nx_test_finish();
