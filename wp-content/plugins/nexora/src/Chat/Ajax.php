<?php

namespace Nexora\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat AJAX actions: search connections, list conversations, read and send messages.
 */
class Ajax {

	/**
	 * Registers the chat AJAX actions.
	 */
	public function __construct() {

		add_action( 'wp_ajax_nexora_search_users', array( $this, 'search_users' ) );

		add_action( 'wp_ajax_nexora_get_messages', array( $this, 'get_messages' ) );
		add_action( 'wp_ajax_nexora_get_user_threads', array( $this, 'get_user_threads' ) );
		add_action( 'wp_ajax_nexora_send_message', array( $this, 'send_message' ) );

		add_action( 'wp_ajax_nexora_create_thread_with_subject', array( $this, 'create_thread_with_subject' ) );
		add_action( 'wp_ajax_nexora_get_thread_subject', array( $this, 'get_thread_subject' ) );
		add_action( 'wp_ajax_nexora_update_subject', array( $this, 'update_subject' ) );
		add_action( 'wp_ajax_nexora_get_latest_thread_between_users', array( $this, 'get_latest_thread_between_users' ) );
	}

	/**
	 * Nonce and login check shared by every chat action.
	 *
	 * @return int ID of the logged-in user.
	 */
	private function authorize() {

		return \Nexora\Http\Ajax::member( 'nexora_chat_nonce', false, __( 'Unauthorized', 'nexora' ) )['user_id'];
	}

	/**
	 * Stops the request unless the user is a participant of the thread.
	 *
	 * @param int $thread_id Chat thread ID.
	 * @param int $user_id User ID.
	 * @return \Nexora\Chat\Repository Chat data access, once the user is confirmed a participant.
	 */
	private function require_participant( $thread_id, $user_id ) {

		if ( ! $thread_id ) {
			wp_send_json_error( __( 'Invalid thread', 'nexora' ) );
		}

		$chat_db = new \Nexora\Chat\Repository();

		if ( ! $chat_db->is_user_in_thread( $thread_id, $user_id ) ) {
			wp_send_json_error( __( 'Access denied', 'nexora' ) );
		}

		return $chat_db;
	}

	/**
	 * Searches the member's accepted connections by username.
	 */
	public function search_users() {

		$user_id = $this->authorize();

		$keyword    = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$profile_id = (int) get_user_meta( $user_id, '_profile_id', true );

		if ( ! $profile_id ) {
			wp_send_json_success( array() );
		}

		$connections = get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'status',
						'value' => 'accepted',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => 'sender_profile_id',
							'value' => $profile_id,
						),
						array(
							'key'   => 'receiver_profile_id',
							'value' => $profile_id,
						),
					),
				),
			)
		);

		$results = array();

		foreach ( $connections as $conn_id ) {

			$sender   = (int) get_post_meta( $conn_id, 'sender_profile_id', true );
			$receiver = (int) get_post_meta( $conn_id, 'receiver_profile_id', true );

			$other = ( $sender === $profile_id ) ? $receiver : $sender;

			$username = get_post_meta( $other, 'user_name', true );

			if ( '' === $keyword || false !== stripos( $username, $keyword ) ) {

				$results[] = array(
					'user_id'       => (int) get_post_meta( $other, '_wp_user_id', true ),
					'username'      => $username,
					'connection_id' => $conn_id,
					'status'        => 'accepted',
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Returns the latest conversation of a connection.
	 */
	public function get_latest_thread_between_users() {

		$user_id       = $this->authorize();
		$connection_id = absint( $_POST['connection_id'] ?? 0 );

		if ( ! $connection_id || ! $this->user_in_connection( $connection_id, $user_id ) ) {
			wp_send_json_error( __( 'Access denied', 'nexora' ) );
		}

		$chat_db = new \Nexora\Chat\Repository();
		$thread  = $chat_db->get_thread_by_connection( $connection_id );

		wp_send_json_success(
			array(
				'thread_id' => $thread ? $thread->id : null,
				'status'    => $thread ? $thread->status : null,
			)
		);
	}

	/**
	 * True when the user is the sender or receiver of the connection post.
	 *
	 * @param int $connection_id Connection (user_connections) post ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function user_in_connection( $connection_id, $user_id ) {

		if ( get_post_type( $connection_id ) !== 'user_connections' ) {
			return false;
		}

		return (int) get_post_meta( $connection_id, 'sender_user_id', true ) === (int) $user_id
			|| (int) get_post_meta( $connection_id, 'receiver_user_id', true ) === (int) $user_id;
	}

	/**
	 * Returns the latest messages of a conversation and marks it read.
	 */
	public function get_messages() {

		$user_id   = $this->authorize();
		$thread_id = absint( $_POST['thread_id'] ?? 0 );

		$chat_db = $this->require_participant( $thread_id, $user_id );

		$messages = $chat_db->get_latest_messages( $thread_id );
		$chat_db->mark_as_read_chat( $thread_id, $user_id );

		foreach ( $messages as $msg ) {
			$user             = get_userdata( $msg->sender_id );
			$msg->sender_name = $user ? $user->display_name : __( 'User', 'nexora' );
		}

		wp_send_json_success( $messages );
	}

	/**
	 * Returns the member's conversations.
	 */
	public function get_user_threads() {

		$user_id = $this->authorize();

		$chat_db = new \Nexora\Chat\Repository();

		wp_send_json_success( $chat_db->get_user_threads( $user_id ) );
	}

	/**
	 * Sends a message into a conversation the member belongs to (rate limited).
	 */
	public function send_message() {

		$user_id = $this->authorize();

		$thread_id = absint( $_POST['thread_id'] ?? 0 );
		$message   = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );

		if ( ! $thread_id || '' === $message ) {
			wp_send_json_error( __( 'Invalid data', 'nexora' ) );
		}

		if ( mb_strlen( $message ) > 2000 ) {
			wp_send_json_error( __( 'Message is too long', 'nexora' ) );
		}

		$chat_db = $this->require_participant( $thread_id, $user_id );

		if ( \Nexora\Http\Rate_Limiter::hit( 'chat_message', 'u' . $user_id ) ) {
			wp_send_json_error( \Nexora\Http\Rate_Limiter::message() );
		}

		$thread = $chat_db->get_thread_status( $thread_id );

		if ( ! $thread ) {
			wp_send_json_error( __( 'Thread not found', 'nexora' ) );
		}

		if ( 'active' !== $thread->status ) {
			wp_send_json_error( __( 'This conversation is closed', 'nexora' ) );
		}

		$message_id = $chat_db->send_message( $thread_id, $user_id, $message );

		wp_send_json_success(
			array(
				'message_id' => $message_id,
			)
		);
	}

	/**
	 * Starts a conversation with an accepted connection.
	 */
	public function create_thread_with_subject() {

		$user1 = $this->authorize();

		$user2         = absint( $_POST['user_id'] ?? 0 );
		$subject       = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$connection_id = absint( $_POST['connection_id'] ?? 0 );

		if ( ! $user2 || '' === $subject || ! $connection_id ) {
			wp_send_json_error( __( 'Invalid data', 'nexora' ) );
		}

		if ( mb_strlen( $subject ) > 255 ) {
			wp_send_json_error( __( 'Subject is too long', 'nexora' ) );
		}

		// The connection must be accepted and link exactly these two users
		if ( ! $this->user_in_connection( $connection_id, $user1 ) ) {
			wp_send_json_error( __( 'Access denied', 'nexora' ) );
		}

		$sender   = (int) get_post_meta( $connection_id, 'sender_user_id', true );
		$receiver = (int) get_post_meta( $connection_id, 'receiver_user_id', true );
		$other    = ( $sender === $user1 ) ? $receiver : $sender;

		if ( $other !== $user2 || get_post_meta( $connection_id, 'status', true ) !== 'accepted' ) {
			wp_send_json_error( __( 'You can only chat with your connections', 'nexora' ) );
		}

		$chat_db   = new \Nexora\Chat\Repository();
		$thread_id = $chat_db->create_thread( array( $user1, $user2 ), $connection_id, 'active', 'private', $subject );

		if ( ! $thread_id ) {
			wp_send_json_error( __( 'Could not create conversation', 'nexora' ) );
		}

		wp_send_json_success(
			array(
				'thread_id' => $thread_id,
			)
		);
	}

	/**
	 * Returns the subject of a conversation.
	 */
	public function get_thread_subject() {

		$user_id   = $this->authorize();
		$thread_id = absint( $_POST['thread_id'] ?? 0 );

		$chat_db = $this->require_participant( $thread_id, $user_id );

		wp_send_json_success(
			array(
				'subject' => $chat_db->get_thread_subject( $thread_id ),
			)
		);
	}

	/**
	 * Renames a conversation.
	 */
	public function update_subject() {

		$user_id   = $this->authorize();
		$thread_id = absint( $_POST['thread_id'] ?? 0 );
		$subject   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );

		if ( '' === $subject || mb_strlen( $subject ) > 255 ) {
			wp_send_json_error( __( 'Invalid subject', 'nexora' ) );
		}

		$chat_db = $this->require_participant( $thread_id, $user_id );

		$chat_db->update_thread_subject( $thread_id, $subject );

		wp_send_json_success();
	}
}
