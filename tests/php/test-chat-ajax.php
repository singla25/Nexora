<?php
// Characterization of chat/class-chat-ajax.php + chat/class-chat-db.php. Nonce action: nexora_chat_nonce.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;

$alice = nx_test_user( 'calice' );
$bob   = nx_test_user( 'cbob' );
$eve   = nx_test_user( 'ceve' );
$conn  = nx_test_connection( $alice, $bob, 'accepted' );
$pend  = nx_test_connection( $alice, $eve, 'pending' );
$thread = nx_test_thread( $alice, $bob, $conn );
$cn = 'nx_chat_nonce';

/* guard matrix */
nx_assert_guard_matrix( 'nexora_search_users', array( 'keyword' => '' ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_get_messages', array( 'thread_id' => $thread ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_get_user_threads', array(), $alice, $cn );
nx_assert_guard_matrix( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => 'hi' ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_create_thread_with_subject', array( 'user_id' => $bob['user_id'], 'subject' => 's', 'connection_id' => $conn ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_get_thread_subject', array( 'thread_id' => $thread ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_update_subject', array( 'thread_id' => $thread, 'subject' => 'new' ), $alice, $cn );
nx_assert_guard_matrix( 'nexora_get_latest_thread_between_users', array( 'connection_id' => $conn ), $alice, $cn );
nx_assert_same( 0, count( ( new NEXORA_CHAT_DB() )->get_latest_messages( $thread ) ), 'rejected guard calls wrote no messages' );

/* search: only accepted connections of the caller */
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'nexora_search_users', array( 'keyword' => '' ) + nx_chat_nonce() );
$names = array_column( $r['data'], 'username' );
nx_assert( in_array( $bob['login'], $names, true ), 'search lists accepted connections' );
nx_assert( ! in_array( $eve['login'], $names, true ), 'search hides pending connections' );
$r = nx_call_ajax( 'nexora_search_users', array( 'keyword' => 'cbob' ) + nx_chat_nonce() );
nx_assert( 1 === count( $r['data'] ) && $bob['user_id'] === $r['data'][0]['user_id'], 'search keyword filters by username' );
$r = nx_call_ajax( 'nexora_search_users', array( 'keyword' => "x' OR 1=1 --" ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && 0 === count( $r['data'] ), 'search treats injection text as a plain keyword' );

/* messaging as participant */
$r = nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => "  Hello <b>Bob</b>\n" ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && ! empty( $r['data']['message_id'] ), 'participant sends a message' );
$db   = new NEXORA_CHAT_DB();
$msgs = $db->get_latest_messages( $thread );
nx_assert_same( 'Hello Bob', $msgs[0]->message, 'message is sanitized and trimmed' );
nx_assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT unread_count FROM {$wpdb->prefix}nexora_thread_participants WHERE thread_id=%d AND user_id=%d", $thread, $bob['user_id'] ) ), 'recipient unread count incremented' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => '   ' ) + nx_chat_nonce() ) ), 'blank message rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => str_repeat( 'a', 2001 ) ) + nx_chat_nonce() ) ), 'message over 2000 chars rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_send_message', array( 'thread_id' => 'abc', 'message' => 'x' ) + nx_chat_nonce() ) ), 'malformed thread id rejected' );

wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'nexora_get_messages', array( 'thread_id' => $thread ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && 1 === count( $r['data'] ) && 'Hello Bob' === $r['data'][0]['message'], 'recipient reads the message' );
nx_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT unread_count FROM {$wpdb->prefix}nexora_thread_participants WHERE thread_id=%d AND user_id=%d", $thread, $bob['user_id'] ) ), 'reading marks the thread read' );

/* outsider cannot touch the thread */
wp_set_current_user( $eve['user_id'] );
foreach ( array(
	array( 'nexora_get_messages', array( 'thread_id' => $thread ) ),
	array( 'nexora_get_thread_subject', array( 'thread_id' => $thread ) ),
	array( 'nexora_update_subject', array( 'thread_id' => $thread, 'subject' => 'pwned' ) ),
	array( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => 'intruder' ) ),
	array( 'nexora_get_latest_thread_between_users', array( 'connection_id' => $conn ) ),
) as $case ) {
	nx_assert( nx_rejected( nx_call_ajax( $case[0], $case[1] + nx_chat_nonce() ) ), "IDOR: non-participant cannot call {$case[0]}" );
}
nx_assert_same( 'Test subject', $db->get_thread_subject( $thread ), 'subject unchanged by outsider' );
nx_assert_same( 1, count( $db->get_latest_messages( $thread ) ), 'no intruder message stored' );
$r = nx_call_ajax( 'nexora_get_user_threads', nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && 0 === count( $r['data'] ), 'thread list only shows own threads' );

/* subject */
wp_set_current_user( $bob['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_update_subject', array( 'thread_id' => $thread, 'subject' => '' ) + nx_chat_nonce() ) ), 'empty subject rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_update_subject', array( 'thread_id' => $thread, 'subject' => str_repeat( 's', 256 ) ) + nx_chat_nonce() ) ), 'subject over 255 rejected' );
$r = nx_call_ajax( 'nexora_update_subject', array( 'thread_id' => $thread, 'subject' => 'Plan <i>B</i>' ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && 'Plan B' === $db->get_thread_subject( $thread ), 'participant renames subject (sanitized)' );
$r = nx_call_ajax( 'nexora_get_thread_subject', array( 'thread_id' => $thread ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && 'Plan B' === $r['data']['subject'], 'subject readable by participant' );

/* thread creation rules */
wp_set_current_user( $alice['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_create_thread_with_subject', array( 'user_id' => $eve['user_id'], 'subject' => 'x', 'connection_id' => $pend ) + nx_chat_nonce() ) ), 'cannot start a chat on a pending connection' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_create_thread_with_subject', array( 'user_id' => $eve['user_id'], 'subject' => 'x', 'connection_id' => $conn ) + nx_chat_nonce() ) ), 'user_id must be the other party of the connection' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_create_thread_with_subject', array( 'user_id' => $bob['user_id'], 'subject' => '', 'connection_id' => $conn ) + nx_chat_nonce() ) ), 'blank subject rejected' );
wp_set_current_user( $eve['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_create_thread_with_subject', array( 'user_id' => $bob['user_id'], 'subject' => 'x', 'connection_id' => $conn ) + nx_chat_nonce() ) ), 'third party cannot start a chat on others\' connection' );
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'nexora_create_thread_with_subject', array( 'user_id' => $bob['user_id'], 'subject' => 'Second', 'connection_id' => $conn ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && ! empty( $r['data']['thread_id'] ), 'connected members create a thread' );
$GLOBALS['nx_test']['threads'][] = (int) ( $r['data']['thread_id'] ?? 0 );
$r = nx_call_ajax( 'nexora_get_latest_thread_between_users', array( 'connection_id' => $conn ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ) && ! empty( $r['data']['thread_id'] ), 'latest thread lookup works for a party' );

/* removing the connection closes the thread and blocks sending */
wp_set_current_user( $alice['user_id'] );
nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn, 'status' => 'removed' ) + nx_profile_nonce() );
nx_assert_same( 'inactive', $db->get_thread_status( $thread )->status, 'removing a connection deactivates its threads' );
nx_assert( nx_rejected( nx_call_ajax( 'nexora_send_message', array( 'thread_id' => $thread, 'message' => 'late' ) + nx_chat_nonce() ) ), 'closed conversation rejects new messages' );
$r = nx_call_ajax( 'nexora_get_messages', array( 'thread_id' => $thread ) + nx_chat_nonce() );
nx_assert( ! empty( $r['success'] ), 'participants can still read a closed conversation' );

nx_test_finish();
