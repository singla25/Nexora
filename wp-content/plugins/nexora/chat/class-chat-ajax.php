<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_CHAT_AJAX {

    /** Messages per page for pagination */
    const MESSAGES_PER_PAGE = 30;

    /** Max message length (characters) */
    const MAX_MESSAGE_LENGTH = 2000;

    public function __construct() {

        add_action( 'wp_ajax_nexora_search_users',                    [ $this, 'search_users' ] );
        add_action( 'wp_ajax_nexora_get_messages',                    [ $this, 'get_messages' ] );
        add_action( 'wp_ajax_nexora_get_user_threads',                [ $this, 'get_user_threads' ] );
        add_action( 'wp_ajax_nexora_send_message',                    [ $this, 'send_message' ] );
        add_action( 'wp_ajax_nexora_create_thread_with_subject',      [ $this, 'create_thread_with_subject' ] );
        add_action( 'wp_ajax_nexora_get_thread_subject',              [ $this, 'get_thread_subject' ] );
        add_action( 'wp_ajax_nexora_update_subject',                  [ $this, 'update_subject' ] );
        add_action( 'wp_ajax_nexora_get_latest_thread_between_users', [ $this, 'get_latest_thread_between_users' ] );
    }

    /* -----------------------------------------------------------------------
       AUTH HELPERS
    ----------------------------------------------------------------------- */

    /**
     * Abort with 401 if the current request is not from a logged-in user.
     * Calls wp_die() so execution cannot continue past this point.
     */
    private function require_login() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'code' => 'unauthorized', 'message' => 'You must be logged in.' ], 401 );
            wp_die();
        }
    }

    /**
     * Abort if the current user is not a participant in $thread_id.
     * Admins (manage_options) are always allowed read-only access to any thread.
     */
    private function assert_thread_access( int $thread_id, NEXORA_CHAT_DB $chat_db ) {
        if ( ! $thread_id ) {
            wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'Access denied.' ], 403 );
            wp_die();
        }

        // Admins can view any thread (read-only — send_message still checks is_user_in_thread).
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! $chat_db->is_user_in_thread( $thread_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'Access denied.' ], 403 );
            wp_die();
        }
    }

    /**
     * Abort if the connection is invalid or the current user has no relation to it.
     */
    private function assert_connection_access( int $connection_id, int $other_user_id = 0 ) {

        $user_id = get_current_user_id();

        if ( ! $connection_id || get_post_type( $connection_id ) !== 'user_connections' ) {
            wp_send_json_error( [ 'code' => 'invalid_connection', 'message' => 'Invalid connection.' ], 400 );
            wp_die();
        }

        $sender_id   = (int) get_post_meta( $connection_id, 'sender_user_id',   true );
        $receiver_id = (int) get_post_meta( $connection_id, 'receiver_user_id', true );

        if ( $user_id !== $sender_id && $user_id !== $receiver_id ) {
            wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'Access denied.' ], 403 );
            wp_die();
        }

        if ( $other_user_id ) {
            if ( $other_user_id === $user_id ) {
                wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'Cannot chat with yourself.' ], 400 );
                wp_die();
            }
            if ( $other_user_id !== $sender_id && $other_user_id !== $receiver_id ) {
                wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'User is not part of this connection.' ], 403 );
                wp_die();
            }
        }
    }

    /* -----------------------------------------------------------------------
       SEARCH USERS
    ----------------------------------------------------------------------- */
    public function search_users() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $keyword    = sanitize_text_field( $_POST['keyword'] ?? '' );
        $user_id    = get_current_user_id();
        $profile_id = get_user_meta( $user_id, '_profile_id', true );

        if ( ! $profile_id ) {
            wp_send_json_success( [] );
            wp_die();
        }

        $connections = get_posts( [
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'meta_query'     => [
                [ 'key' => 'status', 'value' => 'accepted' ],
            ],
        ] );

        $results = [];

        foreach ( $connections as $conn ) {

            $sender   = get_post_meta( $conn->ID, 'sender_profile_id',   true );
            $receiver = get_post_meta( $conn->ID, 'receiver_profile_id', true );

            if ( (string) $sender === (string) $profile_id ) {
                $other_profile_id = $receiver;
            } elseif ( (string) $receiver === (string) $profile_id ) {
                $other_profile_id = $sender;
            } else {
                continue;
            }

            $username = get_post_meta( $other_profile_id, 'user_name', true );

            if ( $keyword !== '' && stripos( $username, $keyword ) === false ) {
                continue;
            }

            $results[] = [
                'user_id'       => (int) get_post_meta( $other_profile_id, '_wp_user_id', true ),
                'username'      => esc_html( $username ),
                'connection_id' => (int) $conn->ID,
                'status'        => esc_html( get_post_meta( $conn->ID, 'status', true ) ),
            ];
        }

        wp_send_json_success( $results );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       GET LATEST THREAD BETWEEN USERS
    ----------------------------------------------------------------------- */
    public function get_latest_thread_between_users() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $user2         = (int) ( $_POST['user_id']       ?? 0 );
        $connection_id = (int) ( $_POST['connection_id'] ?? 0 );

        if ( ! $user2 ) {
            wp_send_json_error( [ 'code' => 'invalid_data', 'message' => 'User ID required.' ], 400 );
            wp_die();
        }

        $this->assert_connection_access( $connection_id, $user2 );

        $chat_db = new NEXORA_CHAT_DB();
        $thread  = $chat_db->get_thread_by_connection( $connection_id );

        wp_send_json_success( [
            'thread_id' => $thread ? (int) $thread->id          : null,
            'status'    => $thread ? esc_html( $thread->status ) : null,
        ] );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       GET MESSAGES  (supports "before_id" cursor for load-older pagination)
    ----------------------------------------------------------------------- */
    public function get_messages() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $thread_id = (int) ( $_POST['thread_id'] ?? 0 );
        $before_id = (int) ( $_POST['before_id'] ?? 0 );

        $chat_db = new NEXORA_CHAT_DB();
        $this->assert_thread_access( $thread_id, $chat_db );

        $messages = $chat_db->get_latest_messages( $thread_id, self::MESSAGES_PER_PAGE, $before_id );
        $chat_db->mark_as_read_chat( $thread_id, get_current_user_id() );

        // Batch userdata lookups to avoid N+1 queries.
        $user_cache = [];
        $output     = [];

        foreach ( $messages as $msg ) {
            $sid = (int) $msg->sender_id;

            if ( ! isset( $user_cache[ $sid ] ) ) {
                $u = get_userdata( $sid );
                $user_cache[ $sid ] = $u ? esc_html( $u->display_name ) : 'User';
            }

            $output[] = [
                'id'          => (int)  $msg->id,
                'sender_id'   => $sid,
                'sender_name' => $user_cache[ $sid ],
                'message'     => esc_html( $msg->message ),  // XSS fix
                'created_at'  =>          $msg->created_at,
            ];
        }

        wp_send_json_success( $output );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       GET USER THREADS (sidebar list)
    ----------------------------------------------------------------------- */
    public function get_user_threads() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $chat_db = new NEXORA_CHAT_DB();
        $threads = $chat_db->get_user_threads( get_current_user_id() );

        $output = [];
        foreach ( $threads as $t ) {
            $output[] = [
                'id'            => (int)     $t->id,
                'connection_id' => (int)     $t->connection_id,
                'status'        => esc_html( $t->status ),
                'subject'       => esc_html( $t->subject      ?? '' ),
                'last_message'  => esc_html( $t->last_message ?? '' ),
                'unread_count'  => (int)     $t->unread_count,
                'updated_at'    =>           $t->updated_at,
                'other_user_id' => (int)     $t->other_user_id,
                'name'          => esc_html( $t->name         ?? 'User' ),
            ];
        }

        wp_send_json_success( $output );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       SEND MESSAGE
    ----------------------------------------------------------------------- */
    public function send_message() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $thread_id = (int)  ( $_POST['thread_id'] ?? 0 );
        $message   = sanitize_textarea_field( $_POST['message'] ?? '' );  // preserves newlines; strips tags
        $user_id   = get_current_user_id();

        if ( ! $thread_id || $message === '' ) {
            wp_send_json_error( [ 'code' => 'invalid_data', 'message' => 'Thread ID and message are required.' ], 400 );
            wp_die();
        }

        if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
            wp_send_json_error( [ 'code' => 'too_long', 'message' => 'Message exceeds ' . self::MAX_MESSAGE_LENGTH . ' characters.' ], 400 );
            wp_die();
        }

        $chat_db = new NEXORA_CHAT_DB();

        $thread = $chat_db->get_thread_status( $thread_id );
        if ( ! $thread ) {
            wp_send_json_error( [ 'code' => 'not_found', 'message' => 'Thread not found.' ], 404 );
            wp_die();
        }

        if ( $thread->status !== 'active' ) {
            wp_send_json_error( [ 'code' => 'thread_closed', 'message' => 'This conversation is closed.' ], 403 );
            wp_die();
        }

        if ( ! $chat_db->is_user_in_thread( $thread_id, $user_id ) ) {
            wp_send_json_error( [ 'code' => 'forbidden', 'message' => 'Access denied.' ], 403 );
            wp_die();
        }

        $message_id = $chat_db->send_message( $thread_id, $user_id, $message );

        wp_send_json_success( [ 'message_id' => (int) $message_id ] );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       CREATE THREAD WITH SUBJECT
    ----------------------------------------------------------------------- */
    public function create_thread_with_subject() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $user1         = get_current_user_id();
        $user2         = (int)  ( $_POST['user_id']       ?? 0 );
        $subject       = sanitize_text_field( $_POST['subject']       ?? '' );
        $connection_id = (int)  ( $_POST['connection_id'] ?? 0 );

        if ( ! $user2 || ! $subject ) {
            wp_send_json_error( [ 'code' => 'invalid_data', 'message' => 'User ID and subject are required.' ], 400 );
            wp_die();
        }

        $this->assert_connection_access( $connection_id, $user2 );

        $conn_status   = get_post_meta( $connection_id, 'status', true );
        $thread_status = ( $conn_status === 'accepted' ) ? 'active' : 'inactive';

        $chat_db   = new NEXORA_CHAT_DB();
        $thread_id = $chat_db->create_thread( [ $user1, $user2 ], $connection_id, $thread_status, 'private', $subject );

        wp_send_json_success( [ 'thread_id' => (int) $thread_id ] );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       GET THREAD SUBJECT
    ----------------------------------------------------------------------- */
    public function get_thread_subject() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $thread_id = (int) ( $_POST['thread_id'] ?? 0 );
        $chat_db   = new NEXORA_CHAT_DB();

        $this->assert_thread_access( $thread_id, $chat_db );

        $subject = $chat_db->get_thread_subject( $thread_id );

        wp_send_json_success( [ 'subject' => esc_html( $subject ?? '' ) ] );
        wp_die();
    }

    /* -----------------------------------------------------------------------
       UPDATE THREAD SUBJECT
    ----------------------------------------------------------------------- */
    public function update_subject() {

        check_ajax_referer( 'nexora_chat_nonce', 'nonce' );
        $this->require_login();

        $thread_id = (int)  ( $_POST['thread_id'] ?? 0 );
        $subject   = sanitize_text_field( $_POST['subject'] ?? '' );

        if ( ! $subject ) {
            wp_send_json_error( [ 'code' => 'invalid_data', 'message' => 'Subject is required.' ], 400 );
            wp_die();
        }

        $chat_db = new NEXORA_CHAT_DB();
        $this->assert_thread_access( $thread_id, $chat_db );

        $chat_db->update_thread_subject( $thread_id, $subject );

        wp_send_json_success();
        wp_die();
    }
}
