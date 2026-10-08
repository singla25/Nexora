<?php
// Phase 1d: one atomic rate limiter, per-user limits on abusable endpoints, trusted-proxy aware client IP.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;
nx_test_reset_limits();

nx_assert( class_exists( 'Nexora_Rate_Limiter' ), 'Nexora_Rate_Limiter exists' );
if ( ! class_exists( 'Nexora_Rate_Limiter' ) ) { nx_test_finish(); return; }

/* ---- core behaviour ---- */
add_filter( 'nexora_rate_limits', function ( $c ) { $c['unit'] = array( 3, 60 ); return $c; } );
$R = 'Nexora_Rate_Limiter';
nx_assert( false === $R::hit( 'unit', 'a' ) && false === $R::hit( 'unit', 'a' ) && false === $R::hit( 'unit', 'a' ), 'first 3 hits allowed' );
nx_assert( true === $R::hit( 'unit', 'a' ), '4th hit exceeds the limit' );
nx_assert( true === $R::blocked( 'unit', 'a' ), 'blocked() reports the state without counting' );
$c = $R::count( 'unit', 'a' );
$R::blocked( 'unit', 'a' ); $R::blocked( 'unit', 'a' );
nx_assert_same( $c, $R::count( 'unit', 'a' ), 'blocked() does not increment' );
nx_assert( false === $R::blocked( 'unit', 'b' ) && false === $R::hit( 'unit', 'b' ), 'other subjects are independent' );
nx_assert( false === $R::blocked( 'nonexistent_bucket', 'a' ), 'unknown bucket is never limited (no fatal)' );

// counting is exact (single atomic statement, not read-modify-write)
nx_test_reset_limits();
for ( $i = 0; $i < 25; $i++ ) { $R::hit( 'unit', 'many' ); }
nx_assert_same( 25, $R::count( 'unit', 'many' ), '25 hits counted exactly' );

// window rollover
nx_test_reset_limits();
$R::$time_override = 1000000;
for ( $i = 0; $i < 4; $i++ ) { $R::hit( 'unit', 'w' ); }
nx_assert( $R::blocked( 'unit', 'w' ), 'blocked inside the window' );
$R::$time_override = 1000000 + 61;
nx_assert( ! $R::blocked( 'unit', 'w' ), 'allowed again after the window passes' );
$R::$time_override = null;

/* ---- client IP and trusted proxy header ---- */
$_SERVER['REMOTE_ADDR'] = '10.1.1.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.1.1.1';
nx_assert_same( '10.1.1.1', $R::client_ip(), 'client IP is REMOTE_ADDR by default (forwarded headers ignored)' );
add_filter( 'nexora_client_ip_header', function () { return 'HTTP_X_FORWARDED_FOR'; } );
nx_assert_same( '203.0.113.9', $R::client_ip(), 'with a configured proxy header the first valid IP is used' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
nx_assert_same( '10.1.1.1', $R::client_ip(), 'invalid header value falls back to REMOTE_ADDR' );
remove_all_filters( 'nexora_client_ip_header' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

/* ---- endpoints ---- */
$alice = nx_test_user( 'rlalice' );
$bob   = nx_test_user( 'rlbob' );
$carol = nx_test_user( 'rlcarol' );
$conn  = nx_test_connection( $alice, $bob, 'accepted' );
$thread = nx_test_thread( $alice, $bob, $conn );
add_filter( 'nexora_rate_limits', function ( $c ) {
	$c['password_change']   = array( 2, 900 );
	$c['connection_request'] = array( 1, 3600 );
	$c['content_save']      = array( 1, 3600 );
	$c['chat_message']      = array( 2, 60 );
	$c['reset_password']    = array( 2, 900 );
	return $c;
} );
nx_test_reset_limits();
wp_set_current_user( $alice['user_id'] );

// chat flooding
foreach ( array( 'one', 'two' ) as $m ) { nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => $m ) + nx_chat_nonce() ); }
$r = nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => 'three' ) + nx_chat_nonce() );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ), 'chat: 3rd message in the window is refused' );
nx_assert_same( 2, count( ( new NEXORA_CHAT_DB() )->get_latest_messages( $thread ) ), 'chat: refused message was not stored' );
wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => 'bob is unaffected' ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ), 'chat: the limit is per user, not shared' );

// connection requests
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $carol['profile_id'] ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'connection request: first allowed' );
foreach ( get_posts( array( 'post_type' => 'user_connections', 'fields' => 'ids', 'meta_key' => 'sender_profile_id', 'meta_value' => $alice['profile_id'], 'numberposts' => -1 ) ) as $id ) { nx_test_track_post( $id ); }
$dave = nx_test_user( 'rldave' );
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $dave['profile_id'] ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ), 'connection request: second in the window is refused' );

// content spam
$r = nx_call_ajax( 'save_user_content', array( 'title' => 'first' ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'content: first post allowed' );
foreach ( get_posts( array( 'post_type' => 'user_content', 'fields' => 'ids', 'meta_key' => 'user_profile_id', 'meta_value' => $alice['profile_id'], 'numberposts' => -1 ) ) as $id ) { nx_test_track_post( $id ); }
$r = nx_call_ajax( 'save_user_content', array( 'title' => 'second' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ), 'content: second post in the window is refused' );

// password guessing from a hijacked session
wp_set_password( 'Start#Pass123', $alice['user_id'] );
$bad = array( 'current_password' => 'wrong', 'new_password' => 'Another#Pass1', 'confirm_password' => 'Another#Pass1' );
nx_call_ajax( 'update_profile_password', $bad + nx_profile_nonce() );
nx_call_ajax( 'update_profile_password', $bad + nx_profile_nonce() );
$r = nx_call_ajax( 'update_profile_password', array( 'current_password' => 'Start#Pass123', 'new_password' => 'Another#Pass1', 'confirm_password' => 'Another#Pass1' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ) && wp_check_password( 'Start#Pass123', get_userdata( $alice['user_id'] )->user_pass ), 'password change: after repeated attempts even the right password is refused for a while' );

// reset_password is limited per IP
wp_set_current_user( 0 );
nx_test_reset_limits();
foreach ( array( 1, 2 ) as $i ) { nx_call_ajax( 'reset_password', array( 'user_id' => 'x', 'token' => 'y', 'password' => 'Brand#New123' ) + nx_profile_nonce(), true ); }
$r = nx_call_ajax( 'reset_password', array( 'user_id' => 'x', 'token' => 'y', 'password' => 'Brand#New123' ) + nx_profile_nonce(), true );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ), 'reset_password: limited per client IP' );

nx_test_finish();
