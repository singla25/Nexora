<?php
/**
 * Minimal test helpers, loaded inside WordPress by `wp eval-file` (see tests/run.sh).
 * No PHPUnit/Composer in this repo. Tests run against the local dev database, so
 * every test must create its own fixtures via nx_test_user()/nx_test_cleanup()
 * and never touch real members' data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// CLI has no headers to send; keep auth-cookie calls from emitting warnings.
add_filter( 'send_auth_cookies', '__return_false' );

$GLOBALS['nx_test'] = array( 'pass' => 0, 'fail' => 0, 'users' => array(), 'posts' => array(), 'threads' => array(), 'mail' => array() );

function nx_assert( $cond, $label ) {
	$GLOBALS['nx_test'][ $cond ? 'pass' : 'fail' ]++;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
}

function nx_assert_same( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	nx_assert( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

/** Create a throwaway WP user plus linked user_profile post (same links as registration). */
function nx_test_user( $name ) {
	$login = 'nxtest_' . $name . '_' . wp_generate_password( 4, false );
	$uid   = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test' ) );
	$pid   = wp_insert_post( array( 'post_type' => 'user_profile', 'post_status' => 'publish', 'post_title' => $login ) );
	update_post_meta( $pid, '_wp_user_id', $uid );
	update_post_meta( $pid, 'user_name', $login );
	update_user_meta( $uid, '_profile_id', $pid );
	$GLOBALS['nx_test']['users'][] = $uid;
	$GLOBALS['nx_test']['posts'][] = $pid;
	return array( 'user_id' => $uid, 'profile_id' => $pid, 'login' => $login );
}

/** Track any extra post (connection, content) for cleanup. */
function nx_test_track_post( $post_id ) {
	$GLOBALS['nx_test']['posts'][] = $post_id;
	return $post_id;
}

/**
 * Invoke a wp_ajax_* handler in-process as the current user and return the decoded JSON reply.
 * wp_send_json_* ends with wp_die(); the filter below turns that into an exception.
 * $guest = true fires the wp_ajax_nopriv_* hook (call wp_set_current_user(0) first).
 * Returns array( 'success' => bool, 'data' => mixed ) or array( 'died' => message ).
 */
function nx_call_ajax( $action, array $post = array(), $guest = false ) {
	$_POST = $_REQUEST = array_merge( array( 'action' => $action ), $post );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$handler = function () {
		return function ( $message ) {
			throw new RuntimeException( is_scalar( $message ) ? (string) $message : 'died' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $handler, 99 );
	add_filter( 'wp_die_handler', $handler, 99 );
	ob_start();
	$died = null;
	try {
		do_action( ( $guest ? 'wp_ajax_nopriv_' : 'wp_ajax_' ) . $action );
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	}
	$raw = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $handler, 99 );
	remove_filter( 'wp_die_handler', $handler, 99 );
	$json = json_decode( $raw, true );
	return is_array( $json ) ? $json : array( 'died' => $died, 'raw' => $raw );
}

/** Create a user_connections post between two nx_test_user() fixtures. */
function nx_test_connection( array $from, array $to, $status = 'accepted' ) {
	$id = nx_test_track_post( wp_insert_post( array( 'post_type' => 'user_connections', 'post_status' => 'publish', 'post_title' => $from['login'] . '->' . $to['login'] ) ) );
	update_post_meta( $id, 'sender_user_id', $from['user_id'] );
	update_post_meta( $id, 'sender_profile_id', $from['profile_id'] );
	update_post_meta( $id, 'sender_user_name', $from['login'] );
	update_post_meta( $id, 'receiver_user_id', $to['user_id'] );
	update_post_meta( $id, 'receiver_profile_id', $to['profile_id'] );
	update_post_meta( $id, 'receiver_user_name', $to['login'] );
	update_post_meta( $id, 'status', $status );
	return $id;
}

/** Create a real 1x1 PNG attachment (file + metadata) authored by $user_id; tracked for cleanup. */
function nx_test_attachment( $user_id, $title = 'nx-test-image' ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . 'nxtest-' . wp_generate_password( 8, false ) . '.png';
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
	$id = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $user_id ), $file );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	return nx_test_track_post( $id );
}

/** Create a chat thread for two fixtures; tracked for cleanup. */
function nx_test_thread( array $a, array $b, $connection_id, $status = 'active', $subject = 'Test subject' ) {
	$db  = new NEXORA_CHAT_DB();
	$tid = $db->create_thread( array( $a['user_id'], $b['user_id'] ), $connection_id, $status, 'private', $subject );
	$GLOBALS['nx_test']['threads'][] = $tid;
	return $tid;
}

/** Clear every rate-limit transient the plugin sets (nx_rl_*, nx_reg_*, nx_contact_*). */
function nx_test_reset_limits() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nx\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nx\\_%'" );
	wp_cache_flush();
}

/** Capture wp_mail() instead of sending. Returns a reference to the captured list. */
function &nx_test_capture_mail() {
	$GLOBALS['nx_test']['mail'] = array();
	add_filter( 'pre_wp_mail', function ( $null, $atts ) {
		$GLOBALS['nx_test']['mail'][] = $atts;
		return true;
	}, 10, 2 );
	return $GLOBALS['nx_test']['mail'];
}

/** Standard nonce payloads. */
function nx_profile_nonce() {
	return array( 'nonce' => wp_create_nonce( 'profile_nonce' ) );
}

function nx_chat_nonce() {
	return array( 'nonce' => wp_create_nonce( 'nexora_chat_nonce' ) );
}

/** True when an nx_call_ajax() reply is a rejection (died, or success=false). */
function nx_rejected( $reply ) {
	return isset( $reply['died'] ) || ( isset( $reply['success'] ) && false === $reply['success'] );
}

/**
 * Standard "who may call this" matrix: no nonce, wrong nonce, logged-out, valid.
 * $valid_post must be a complete valid payload WITHOUT the nonce.
 */
function nx_assert_guard_matrix( $action, array $valid_post, array $member, $nonce_fn = 'nx_profile_nonce' ) {
	wp_set_current_user( $member['user_id'] );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post ) ), "$action: no nonce rejected" );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post + array( 'nonce' => 'bad' ) ) ), "$action: wrong nonce rejected" );
	$nonce = $nonce_fn();
	wp_set_current_user( 0 );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post + $nonce ) ), "$action: logged-out rejected" );
	wp_set_current_user( $member['user_id'] );
}

function nx_test_cleanup() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $GLOBALS['nx_test']['threads'] ?? array() as $tid ) {
		foreach ( array( 'nexora_message_meta' => 'message_id IN (SELECT id FROM %1$snexora_messages WHERE thread_id=%2$d)', 'nexora_messages' => 'thread_id=%2$d', 'nexora_thread_participants' => 'thread_id=%2$d', 'nexora_threads' => 'id=%2$d' ) as $t => $where ) {
			$wpdb->query( sprintf( "DELETE FROM {$wpdb->prefix}$t WHERE " . $where, $wpdb->prefix, (int) $tid ) );
		}
	}
	nx_test_reset_limits();
	wp_set_current_user( 0 );
	foreach ( $GLOBALS['nx_test']['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $GLOBALS['nx_test']['users'] as $id ) {
		$wpdb->delete( $wpdb->prefix . 'nexora_notifications', array( 'receiver_user_id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'nexora_notifications', array( 'actor_user_id' => $id ) );
		wp_delete_user( $id );
	}
}

function nx_test_finish() {
	nx_test_cleanup();
	$t = $GLOBALS['nx_test'];
	echo sprintf( "%d passed, %d failed\n", $t['pass'], $t['fail'] );
	if ( $t['fail'] ) {
		WP_CLI::halt( 1 );
	}
}
