<?php

namespace Nexora\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax {

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

	/*
	===============================
		HELPERS
	=============================== */
	private function authorize() {

		return \Nexora\Http\Ajax::member( 'nexora_chat_nonce', false, 'Unauthorized' )['user_id'];
	}

	/**
	 * Stops the request unless the user is a participant of the thread.
	 */
	private function require_participant( $thread_id, $user_id ) {

		if ( ! $thread_id ) {
			wp_send_json_error( 'Invalid thread' );
		}

		$chat_db = new \Nexora\Chat\Repository();

		if ( ! $chat_db->is_user_in_thread( $thread_id, $user_id ) ) {
			wp_send_json_error( 'Access denied' );
		}

		return $chat_db;
	}

	/*
	===============================
		SEARCH USERS
	=============================== */
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

			if ( $keyword === '' || stripos( $username, $keyword ) !== false ) {

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

	/*
	===============================
		GET LATEST THREAD
	=============================== */
	public function get_latest_thread_between_users() {

		$user_id       = $this->authorize();
		$connection_id = absint( $_POST['connection_id'] ?? 0 );

		if ( ! $connection_id || ! $this->user_in_connection( $connection_id, $user_id ) ) {
			wp_send_json_error( 'Access denied' );
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
	 */
	private function user_in_connection( $connection_id, $user_id ) {

		if ( get_post_type( $connection_id ) !== 'user_connections' ) {
			return false;
		}

		return (int) get_post_meta( $connection_id, 'sender_user_id', true ) === (int) $user_id
			|| (int) get_post_meta( $connection_id, 'receiver_user_id', true ) === (int) $user_id;
	}

	/*
	===============================
		GET MESSAGES
	=============================== */
	public function get_messages() {

		$user_id   = $this->authorize();
		$thread_id = absint( $_POST['thread_id'] ?? 0 );

		$chat_db = $this->require_participant( $thread_id, $user_id );

		$messages = $chat_db->get_latest_messages( $thread_id );
		$chat_db->mark_as_read_chat( $thread_id, $user_id );

		foreach ( $messages as $msg ) {
			$user             = get_userdata( $msg->sender_id );
			$msg->sender_name = $user ? $user->display_name : 'User';
		}

		wp_send_json_success( $messages );
	}

	/*
	===============================
		GET USER THREADS
	=============================== */
	public function get_user_threads() {

		$user_id = $this->authorize();

		$chat_db = new \Nexora\Chat\Repository();

		wp_send_json_success( $chat_db->get_user_threads( $user_id ) );
	}

	/*
	===============================
		SEND MESSAGE
	=============================== */
	public function send_message() {

		$user_id = $this->authorize();

		$thread_id = absint( $_POST['thread_id'] ?? 0 );
		$message   = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );

		if ( ! $thread_id || $message === '' ) {
			wp_send_json_error( 'Invalid data' );
		}

		if ( mb_strlen( $message ) > 2000 ) {
			wp_send_json_error( 'Message is too long' );
		}

		$chat_db = $this->require_participant( $thread_id, $user_id );

		if ( \Nexora\Http\Rate_Limiter::hit( 'chat_message', 'u' . $user_id ) ) {
			wp_send_json_error( \Nexora\Http\Rate_Limiter::message() );
		}

		$thread = $chat_db->get_thread_status( $thread_id );

		if ( ! $thread ) {
			wp_send_json_error( 'Thread not found' );
		}

		if ( $thread->status !== 'active' ) {
			wp_send_json_error( 'This conversation is closed' );
		}

		$message_id = $chat_db->send_message( $thread_id, $user_id, $message );

		wp_send_json_success(
			array(
				'message_id' => $message_id,
			)
		);
	}

	/*
	===============================
		GET OR CREATE THREAD
	=============================== */
	public function create_thread_with_subject() {

		$user1 = $this->authorize();

		$user2         = absint( $_POST['user_id'] ?? 0 );
		$subject       = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$connection_id = absint( $_POST['connection_id'] ?? 0 );

		if ( ! $user2 || $subject === '' || ! $connection_id ) {
			wp_send_json_error( 'Invalid data' );
		}

		if ( mb_strlen( $subject ) > 255 ) {
			wp_send_json_error( 'Subject is too long' );
		}

		// The connection must be accepted and link exactly these two users
		if ( ! $this->user_in_connection( $connection_id, $user1 ) ) {
			wp_send_json_error( 'Access denied' );
		}

		$sender   = (int) get_post_meta( $connection_id, 'sender_user_id', true );
		$receiver = (int) get_post_meta( $connection_id, 'receiver_user_id', true );
		$other    = ( $sender === $user1 ) ? $receiver : $sender;

		if ( $other !== $user2 || get_post_meta( $connection_id, 'status', true ) !== 'accepted' ) {
			wp_send_json_error( 'You can only chat with your connections' );
		}

		$chat_db   = new \Nexora\Chat\Repository();
		$thread_id = $chat_db->create_thread( array( $user1, $user2 ), $connection_id, 'active', 'private', $subject );

		if ( ! $thread_id ) {
			wp_send_json_error( 'Could not create conversation' );
		}

		wp_send_json_success(
			array(
				'thread_id' => $thread_id,
			)
		);
	}

	/*
	===============================
		GET THREAD SUBJECT
	=============================== */
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

	/*
	===============================
		UPDATE THREAD SUBJECT
	=============================== */
	public function update_subject() {

		$user_id   = $this->authorize();
		$thread_id = absint( $_POST['thread_id'] ?? 0 );
		$subject   = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );

		if ( $subject === '' || mb_strlen( $subject ) > 255 ) {
			wp_send_json_error( 'Invalid subject' );
		}

		$chat_db = $this->require_participant( $thread_id, $user_id );

		$chat_db->update_thread_subject( $thread_id, $subject );

		wp_send_json_success();
	}
}
