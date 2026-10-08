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

$GLOBALS['nx_test'] = array( 'pass' => 0, 'fail' => 0, 'users' => array(), 'posts' => array() );

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
 * Returns array( 'success' => bool, 'data' => mixed ) or array( 'died' => message ).
 */
function nx_call_ajax( $action, array $post = array() ) {
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
		do_action( 'wp_ajax_' . $action );
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	}
	$raw = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $handler, 99 );
	remove_filter( 'wp_die_handler', $handler, 99 );
	$json = json_decode( $raw, true );
	return is_array( $json ) ? $json : array( 'died' => $died, 'raw' => $raw );
}

function nx_test_cleanup() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/user.php';
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
