<?php

if (!defined('ABSPATH')) exit;

class NEXORA_CHAT_AJAX {

    public function __construct() {

        add_action('wp_ajax_nexora_search_users', [$this, 'search_users']);

        add_action('wp_ajax_nexora_get_messages', [$this, 'get_messages']);
        add_action('wp_ajax_nexora_get_user_threads', [$this, 'get_user_threads']);
        add_action('wp_ajax_nexora_send_message', [$this, 'send_message']);
        
        add_action('wp_ajax_nexora_create_thread_with_subject', [$this, 'create_thread_with_subject']);
        add_action('wp_ajax_nexora_get_thread_subject', [$this, 'get_thread_subject']);
        add_action('wp_ajax_nexora_update_subject', [$this, 'update_subject']);
        add_action('wp_ajax_nexora_get_latest_thread_between_users', [$this, 'get_latest_thread_between_users']);
    }

    /* ===============================
       SEARCH USERS
    =============================== */
    public function search_users() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        $keyword = sanitize_text_field($_POST['keyword']);
        $user_id = get_current_user_id();

        $profile_id = get_user_meta($user_id, '_profile_id', true);

        $connections = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => 'status', 'value' => 'accepted']
            ]
        ]);

        $results = [];

        foreach ($connections as $conn) {

            $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
            $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

            if ($sender == $profile_id) {
                $other = $receiver;
            } elseif ($receiver == $profile_id) {
                $other = $sender;
            } else {
                continue;
            }

            $username = get_post_meta($other, 'user_name', true);

            if (stripos($username, $keyword) !== false) {
                $wp_user_id = get_post_meta($other, '_wp_user_id', true);

                $results[] = [
                    'user_id' => $wp_user_id,   
                    'username' => $username,
                    'connection_id' => $conn->ID,
                    'status' => get_post_meta($conn->ID, 'status', true)
                ];
            }
        }

        wp_send_json_success($results);
    }

    /* ===============================
       GET LATEST THREAD 
    =============================== */
    public function get_latest_thread_between_users() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        $user1 = get_current_user_id();
        $user2 = intval($_POST['user_id']);
        $connection_id = intval($_POST['connection_id']);

        if (!$user2) {
            wp_send_json_error();
        }

        global $wpdb;

        $chat_db = new NEXORA_CHAT_DB();

        // ✅ CLEAN (NO DIRECT QUERY)
        $thread = $chat_db->get_thread_by_connection($connection_id);

        wp_send_json_success([
            'thread_id' => $thread ? $thread->id : null,
            'status'    => $thread ? $thread->status : null
        ]);
    }

    /* ===============================
       GET MESSAGES
    =============================== */
    public function get_messages() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }

        $thread_id = intval($_POST['thread_id']);

        $chat_db = new NEXORA_CHAT_DB();
        $messages = $chat_db->get_latest_messages($thread_id);
        $chat_db->mark_as_read_chat($thread_id, get_current_user_id());

        foreach ($messages as &$msg) {
            $user = get_userdata($msg->sender_id);
            $msg->sender_name = $user ? $user->display_name : 'User';
        }

        wp_send_json_success($messages);
    }

    /* ===============================
       GET USER THREADS
    =============================== */
    public function get_user_threads() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        $user_id = get_current_user_id();

        $chat_db = new NEXORA_CHAT_DB();
        $threads = $chat_db->get_user_threads($user_id);

        wp_send_json_success($threads);
    }

    /* ===============================
       SEND MESSAGE
    =============================== */
    public function send_message() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        // Auth check
        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }

        $thread_id = intval($_POST['thread_id']);
        $message   = sanitize_text_field($_POST['message']);
        $user_id   = get_current_user_id();

        // Basic validation
        if (!$thread_id || empty($message)) {
            wp_send_json_error('Invalid data');
        }

        global $wpdb;
        $chat_db = new NEXORA_CHAT_DB();

        /* ===============================
            CHECK THREAD EXISTS
        =============================== */
        $thread = $chat_db->get_thread_status($thread_id);

        if (!$thread) {
            wp_send_json_error('Thread not found');
        }

        /* ===============================
            CHECK THREAD STATUS
        =============================== */
        if ($thread->status !== 'active') {
            wp_send_json_error('This conversation is closed');
        }

        /* ===============================
            CHECK USER IS PARTICIPANT
        =============================== */
        $is_participant = $chat_db->is_user_in_thread($thread_id, $user_id);

        if (!$is_participant) {
            wp_send_json_error('Access denied');
        }

        /* ===============================
            SEND MESSAGE
        =============================== */
        $message_id = $chat_db->send_message($thread_id, $user_id, $message);

        wp_send_json_success([
            'message_id' => $message_id
        ]);
    }

    

    /* ===============================
       GET OR CREATE THREAD
    =============================== */
    public function create_thread_with_subject() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }

        $user1 = get_current_user_id();
        $user2 = intval($_POST['user_id']);
        $subject = sanitize_text_field($_POST['subject']);
        $connection_id = intval($_POST['connection_id']);

        // get status from connection
        $status = get_post_meta($connection_id, 'status', true);

        // thread status logic
        $thread_status = ($status === 'accepted') ? 'active' : 'inactive';

        if (!$user2 || !$subject) {
            wp_send_json_error('Invalid data');
        }

        $chat_db = new NEXORA_CHAT_DB();

        // ✅ ALWAYS CREATE NEW THREAD $type = 'private', $subject = ''
        $thread_id = $chat_db->create_thread([$user1, $user2], $connection_id, $thread_status, 'private', $subject);

        wp_send_json_success([
            'thread_id' => $thread_id
        ]);
    }

    /* ===============================
       GET THREAD SUBJECT
    =============================== */
    public function get_thread_subject() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        $thread_id = intval($_POST['thread_id']);

        $chat_db = new NEXORA_CHAT_DB();
        $subject = $chat_db->get_thread_subject($thread_id);

        wp_send_json_success([
            'subject' => $subject
        ]);
    }

    /* ===============================
       UPDATE THREAD SUBJECT
    =============================== */
    public function update_subject() {

        check_ajax_referer('nexora_chat_nonce', 'nonce');

        $thread_id = intval($_POST['thread_id']);
        $subject   = sanitize_text_field($_POST['subject']);

        $chat_db = new NEXORA_CHAT_DB();

        $chat_db->update_thread_subject($thread_id, $subject);

        wp_send_json_success();
    }
}