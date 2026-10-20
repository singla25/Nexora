<?php
// Marketplace Phase 7b: the Notifications admin screen (a custom table, not posts) gets edit and delete: manage_options, a nonce per row
// and action, validated input; the actor, receiver, type and time stay read-only; changes go into the history.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\Notifications_Admin;

global $wpdb;
$table = $wpdb->prefix . 'nexora_notifications';
$admin = nx_test_admin();
wp_set_current_user( $admin );
$A = nx_test_user( 'aena' );
$B = nx_test_user( 'aenb' );
$repo = new \Nexora\Notifications\Repository();
$repo->insert( array( 'actor_user_id' => $A['user_id'], 'actor_user_name' => $A['login'], 'receiver_user_id' => $B['user_id'], 'receiver_user_name' => $B['login'], 'type' => 'nxtest', 'connection_id' => 0, 'message' => 'Hello <b>there</b>' ) );
$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE actor_user_id = %d AND type = 'nxtest' ORDER BY id DESC LIMIT 1", $A['user_id'] ) );
$row = function () use ( $wpdb, $table, $id ) { return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); };
$req = function ( $op, array $extra = array(), $nonce = true ) use ( $id ) {
	$base = array( 'id' => $id );
	if ( $nonce ) { $base['_wpnonce'] = wp_create_nonce( 'nexora_notification_' . $op . '_' . $id ); }
	return $extra + $base;
};
nx_assert( $id > 0, 'setup: a notification row exists' );
$start = $row();

/* ---------- update ---------- */
$r = Notifications_Admin::process( 'update', $req( 'update', array( 'message' => "New text\nline 2", 'is_read' => '1' ) ) );
nx_assert( true === $r, 'an administrator edits the message and the read flag' );
$now = $row();
nx_assert_same( array( "New text\nline 2", '1' ), array( $now['message'], $now['is_read'] ), 'they are stored' );
nx_assert_same( array( $start['actor_user_id'], $start['actor_user_name'], $start['receiver_user_id'], $start['receiver_user_name'], $start['type'], $start['created_at'] ), array( $now['actor_user_id'], $now['actor_user_name'], $now['receiver_user_id'], $now['receiver_user_name'], $now['type'], $now['created_at'] ), 'actor, receiver, type and time are untouched' );
$rows = Audit::rows( 'nexora_notification', $id );
nx_assert_same( array( 'is_read', 'message' ), array_values( array_intersect( array( 'is_read', 'message' ), array_column( $rows, 'field' ) ) ), 'the change is in the history' );
$r = Notifications_Admin::process( 'update', $req( 'update', array( 'message' => 'Cleaned <b>x</b> text', 'is_read' => '0' ) ) );
nx_assert( true === $r && 'Cleaned x text' === $row()['message'], 'the message is cleaned to plain text' );
$r = Notifications_Admin::process( 'update', $req( 'update', array( 'message' => 'Cleaned x text', 'is_read' => '0', 'type' => 'hacked', 'receiver_user_id' => 1, 'actor_user_id' => 1, 'created_at' => '2001-01-01 00:00:00' ) ) );
nx_assert( true === $r && 'nxtest' === $row()['type'] && (string) $B['user_id'] === $row()['receiver_user_id'] && (string) $A['user_id'] === $row()['actor_user_id'] && $start['created_at'] === $row()['created_at'], 'read-only columns named in the request are ignored' );
foreach ( array( array( 'message' => '   ' ), array( 'message' => str_repeat( 'm', 1001 ) ), array( 'is_read' => '2' ), array( 'is_read' => 'yes' ), array( 'is_read' => '-1' ) ) as $bad ) {
	$before = $row();
	$r = Notifications_Admin::process( 'update', $req( 'update', $bad + array( 'message' => 'ok', 'is_read' => '0' ) ) );
	nx_assert( is_wp_error( $r ) && $before === $row(), 'invalid input ' . wp_json_encode( array_map( function ( $v ) { return substr( $v, 0, 10 ); }, $bad ) ) . ' is refused and nothing is saved' );
}
nx_assert( is_wp_error( Notifications_Admin::process( 'update', array( 'id' => 999999999, '_wpnonce' => wp_create_nonce( 'nexora_notification_update_999999999' ), 'message' => 'x', 'is_read' => '0' ) ) ), 'a notification that does not exist is refused' );
nx_assert( is_wp_error( Notifications_Admin::process( 'explode', $req( 'explode' ) ) ), 'an unknown operation is refused' );

/* ---------- who may ---------- */
$guard = $row();
foreach ( array( 'the receiver' => $B['user_id'], 'the actor' => $A['user_id'], 'a guest' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	$u = Notifications_Admin::process( 'update', $req( 'update', array( 'message' => 'Hijack', 'is_read' => '1' ) ) );
	$d = Notifications_Admin::process( 'delete', $req( 'delete' ) );
	nx_assert( is_wp_error( $u ) && is_wp_error( $d ) && $guard === $row(), "$who can neither edit nor delete" );
}
wp_set_current_user( $admin );
nx_assert( is_wp_error( Notifications_Admin::process( 'update', $req( 'update', array( 'message' => 'x', 'is_read' => '1' ), false ) ) ) && $guard === $row(), 'no nonce: refused' );
nx_assert( is_wp_error( Notifications_Admin::process( 'update', array( 'id' => $id, '_wpnonce' => 'forged', 'message' => 'x', 'is_read' => '1' ) ) ) && $guard === $row(), 'a forged nonce: refused' );
nx_assert( is_wp_error( Notifications_Admin::process( 'update', array( 'id' => $id, '_wpnonce' => wp_create_nonce( 'nexora_notification_delete_' . $id ), 'message' => 'x', 'is_read' => '1' ) ) ) && $guard === $row(), 'a nonce made for another action: refused' );
nx_assert( is_wp_error( Notifications_Admin::process( 'update', array( 'id' => $id + 1, '_wpnonce' => wp_create_nonce( 'nexora_notification_update_' . $id ), 'message' => 'x', 'is_read' => '1' ) ) ), 'a nonce made for another row: refused' );

/* ---------- the screen ---------- */
ob_start();
( new \Nexora\Admin\Pages() )->notifications_page();
$html = ob_get_clean();
nx_assert( false !== strpos( $html, 'name="message"' ) && false !== strpos( $html, wp_create_nonce( 'nexora_notification_update_' . $id ) ), 'the screen has an edit form per row with its own nonce' );
nx_assert( false !== strpos( $html, wp_create_nonce( 'nexora_notification_delete_' . $id ) ), 'and a delete link with its own nonce' );
nx_assert( false === strpos( $html, '<b>there</b>' ), 'messages are escaped' );
nx_assert( 0 === preg_match( '/name="(actor_user_id|receiver_user_id|type|created_at)"/', $html ), 'actor, receiver, type and time are shown but have no input' );
wp_set_current_user( $B['user_id'] );
ob_start(); ( new \Nexora\Admin\Pages() )->notifications_page(); $other = ob_get_clean();
nx_assert( false === strpos( $other, 'name="message"' ) && false === strpos( $other, 'nexora_notification_delete_' ), 'a non-administrator sees no edit or delete controls' );
wp_set_current_user( $admin );

/* ---------- delete ---------- */
$r = Notifications_Admin::process( 'delete', $req( 'delete' ) );
nx_assert( true === $r && null === $row(), 'an administrator deletes a notification' );
nx_assert( in_array( 'deleted', array_column( Audit::rows( 'nexora_notification', $id ), 'field' ), true ), 'and it is in the history, which stays after the row is gone' );
nx_assert( is_wp_error( Notifications_Admin::process( 'delete', $req( 'delete' ) ) ), 'deleting it again is refused (not found)' );

nx_test_finish();
