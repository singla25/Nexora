<?php
// Phase 5: every AJAX endpoint is listed here and gets the standard cases (no nonce, bad nonce,
// logged-out, malformed input). A new endpoint that is not in the manifest fails this test.
require_once __DIR__ . '/../bootstrap.php';

global $wp_filter;

$member = array(
	'get_add_new_users', 'send_connection_request', 'get_requests', 'update_connection_status', 'get_history',
	'view_all_connection', 'view_mutual_connection', 'mark_notification_read', 'save_user_content', 'get_user_content_history',
	'update_personal_info', 'update_address_info', 'update_work_info', 'update_documents_info', 'update_profile_password',
);
$chat = array(
	'search_users', 'get_messages', 'get_user_threads', 'send_message', 'create_thread_with_subject',
	'get_thread_subject', 'update_subject', 'get_latest_thread_between_users',
);
$guest = array( 'profile_login', 'send_otp', 'verify_otp', 'reset_password', 'profile_register' );

$registered = array();
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( 0 === strpos( $hook, 'wp_ajax_nexora_' ) ) {
		$registered[] = substr( $hook, strlen( 'wp_ajax_nexora_' ) );
	}
}
// File download, not JSON: its owner/admin gating is covered in test-private-documents.php.
$download = array( 'document' );
$expected = array_merge( $member, $chat, $guest, $download );
sort( $registered );
sort( $expected );
nx_assert_same( $expected, $registered, 'every nexora AJAX endpoint is in the test manifest' );

$alice = nx_test_user( 'matrix' );

// Malformed input must be answered cleanly: no PHP warning/notice/fatal, whatever the payload.
$payloads = array(
	'empty'       => array(),
	'arrays'      => array_fill_keys( array( 'id', 'profile_id', 'connection_id', 'receiver_profile_id', 'thread_id', 'user_id', 'status', 'message', 'subject', 'title', 'otp', 'email', 'username', 'password', 'ref', 'token' ), array( 'x' ) ),
	'huge/hostile' => array_fill_keys( array( 'id', 'profile_id', 'connection_id', 'receiver_profile_id', 'thread_id', 'user_id', 'status', 'message', 'subject', 'title', 'otp', 'email', 'username', 'password', 'ref', 'token' ), str_repeat( "'\"<script>", 500 ) ),
	'negative'    => array_fill_keys( array( 'id', 'profile_id', 'connection_id', 'receiver_profile_id', 'thread_id', 'user_id' ), '-5' ),
);

$run = function ( $action, $post, $guest_call = false ) {
	$errors = array();
	set_error_handler( function ( $no, $str ) use ( &$errors ) {
		$errors[] = $str;
		return true;
	} );
	try {
		$reply = nx_call_ajax( $action, $post, $guest_call );
	} finally {
		restore_error_handler();
	}
	return array( $reply, $errors );
};

foreach ( array_merge( $member, $chat ) as $action ) {

	$is_chat  = in_array( $action, $chat, true );
	$name     = $is_chat ? 'nexora_' . $action : $action;
	$nonce_fn = $is_chat ? 'nx_chat_nonce' : 'nx_profile_nonce';

	nx_assert_guard_matrix( $name, array( 'id' => 1 ), $alice, $nonce_fn );
	nx_assert( has_action( 'wp_ajax_nopriv_' . ( $is_chat ? '' : 'nexora_' ) . $name ) === false, "$action is not reachable by logged-out visitors" );

	wp_set_current_user( $alice['user_id'] );
	foreach ( $payloads as $label => $payload ) {
		list( $reply, $errors ) = $run( $name, $payload + $nonce_fn() );
		nx_assert( array() === $errors, "$action: $label payload raises no PHP warning" . ( $errors ? ' (' . $errors[0] . ')' : '' ) );
		nx_assert( isset( $reply['success'] ) || isset( $reply['died'] ), "$action: $label payload gets a well-formed reply" );
	}
}

// The deprecated un-prefixed alias is as strict as the new name for the profile endpoints.
foreach ( $member as $action ) {
	wp_set_current_user( 0 );
	nx_assert( nx_rejected( nx_call_ajax( $action, array( 'id' => 1 ) + nx_profile_nonce() ) ), "$action (deprecated name): logged-out rejected" );
	wp_set_current_user( $alice['user_id'] );
	nx_assert( nx_rejected( nx_call_ajax( $action, array( 'id' => 1 ) ) ), "$action (deprecated name): no nonce rejected" );
}

// Guest endpoints: nonce required, malformed payloads rejected, never a warning.
foreach ( $guest as $action ) {
	wp_set_current_user( 0 );
	nx_assert( nx_rejected( nx_call_ajax( $action, array(), true ) ), "$action: no nonce rejected" );
	nx_assert( nx_rejected( nx_call_ajax( $action, array( 'nonce' => 'bad' ), true ) ), "$action: wrong nonce rejected" );
	foreach ( $payloads as $label => $payload ) {
		list( $reply, $errors ) = $run( $action, $payload, true );
		nx_assert( array() === $errors, "$action: $label payload raises no PHP warning" . ( $errors ? ' (' . $errors[0] . ')' : '' ) );
		nx_assert( nx_rejected( $reply ), "$action: $label payload without a nonce is rejected" );
	}
}

nx_test_finish();
