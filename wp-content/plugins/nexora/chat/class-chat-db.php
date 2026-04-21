<?php

if (!defined('ABSPATH')) exit;

class NEXORA_CHAT_DB {

    private $threads_table;
    private $participants_table;
    private $messages_table;
    private $message_meta_table;

    public function __construct() {
        global $wpdb;

        $this->threads_table = $wpdb->prefix . 'nexora_threads';
        $this->participants_table = $wpdb->prefix . 'nexora_thread_participants';
        $this->messages_table = $wpdb->prefix . 'nexora_messages';
        $this->message_meta_table = $wpdb->prefix . 'nexora_message_meta';
    }

    /**
     * Create Chat Table
     */
    public function create_table() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // THREADS
        $threads = "CREATE TABLE {$this->threads_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connection_id BIGINT,
            status VARCHAR(20) DEFAULT 'active',
            type VARCHAR(20) DEFAULT 'private',
            subject VARCHAR(255) NULL,
            last_message_id BIGINT UNSIGNED NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset;";

        // PARTICIPANTS
        $participants = "CREATE TABLE {$this->participants_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,

            last_read DATETIME NULL,
            unread_count INT DEFAULT 0,

            is_muted TINYINT(1) DEFAULT 0,
            is_pinned TINYINT(1) DEFAULT 0,

            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY unique_thread_user (thread_id, user_id),
            INDEX idx_user_id (user_id),
            INDEX idx_thread_id (thread_id)
        ) $charset;";

        // MESSAGES
        $messages = "CREATE TABLE {$this->messages_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_thread_id (thread_id),
            INDEX idx_created_at (created_at)
        ) $charset;";

        // MESSAGE META
        $meta = "CREATE TABLE {$this->message_meta_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255),
            meta_value LONGTEXT,

            INDEX idx_message_id (message_id)
        ) $charset;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($threads);
        dbDelta($participants);
        dbDelta($messages);
        dbDelta($meta);
    }

    /**
     * Insert New Thread and It's Participants
     */
    public function create_thread($users, $connection_id, $thread_status, $type = 'private', $subject = '') {

        global $wpdb;

        $wpdb->insert($this->threads_table, [
            'connection_id' => $connection_id,
            'status' => $thread_status,
            'type' => $type,
            'subject' => $subject,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        $thread_id = $wpdb->insert_id;

        foreach ($users as $user_id) {
            $wpdb->insert($this->participants_table, [
                'thread_id' => $thread_id,
                'user_id' => $user_id
            ]);
        }

        return $thread_id;
    }

    /**
     * GET LATEST THREAD BETWEEN USERS
     */
    public function get_thread_by_connection($connection_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("
            SELECT id, status
            FROM {$this->threads_table}
            WHERE connection_id = %d
            ORDER BY updated_at DESC
            LIMIT 1
        ", $connection_id));
    }

    /**
     * GET THREAD STATUS
     */
    public function get_thread_status($thread_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare("
            SELECT id, status 
            FROM {$this->threads_table}
            WHERE id = %d
        ", $thread_id));
    }

    /**
     * GET USER PARTICIPANTS
     */
    public function is_user_in_thread($thread_id, $user_id) {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$this->participants_table}
            WHERE thread_id = %d AND user_id = %d
        ", $thread_id, $user_id));
    }

    /**
     * GET USER THREADS (CHAT LIST)
     */
    public function get_user_threads($user_id) {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.*,
                p.unread_count,

                -- Get other participant
                tp.user_id AS other_user_id

            FROM {$this->threads_table} t

            INNER JOIN {$this->participants_table} p 
                ON t.id = p.thread_id AND p.user_id = %d

            INNER JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id AND tp.user_id != %d

            ORDER BY t.updated_at DESC
        ", $user_id, $user_id));

        // 🔥 ADD USER NAME (IMPORTANT)
        foreach ($results as &$row) {

            $user = get_userdata($row->other_user_id);

            $row->name = $user ? $user->display_name : 'User';
        }

        return $results;
    }

    /**
     * Send Messgae
     */
    public function send_message($thread_id, $sender_id, $message) {
        global $wpdb;

        // Insert message
        $wpdb->insert($this->messages_table, [
            'thread_id' => $thread_id,
            'sender_id' => $sender_id,
            'message' => $message
        ]);

        $message_id = $wpdb->insert_id;

        // Update thread
        $wpdb->update($this->threads_table, [
            'last_message_id' => $message_id,
            'updated_at' => current_time('mysql')
        ], [
            'id' => $thread_id
        ]);

        // Update unread count (others only)
        $wpdb->query($wpdb->prepare("
            UPDATE {$this->participants_table}
            SET unread_count = unread_count + 1
            WHERE thread_id = %d AND user_id != %d
        ", $thread_id, $sender_id));

        return $message_id;
    }

    /**
     * Get Latest Messages (INITIAL LOAD)
     */
    public function get_latest_messages($thread_id, $limit = 20) {
        global $wpdb;

        $messages = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM {$this->messages_table}
            WHERE thread_id = %d
            ORDER BY id DESC
            LIMIT %d
        ", $thread_id, $limit));

        return array_reverse($messages); // ✅ important
    }

    /**
     * MARK AS READ
     */
    public function mark_as_read_chat($thread_id, $user_id) {
        global $wpdb;

        $wpdb->update($this->participants_table, [
            'unread_count' => 0,
            'last_read' => current_time('mysql')
        ], [
            'thread_id' => $thread_id,
            'user_id' => $user_id
        ]);
    }

    /**
     * GET ALL USER THREADS (Thread List For Admin)
     */
    public function get_all_threads() {
        global $wpdb;

        return $wpdb->get_results("
            SELECT t.*, 
                GROUP_CONCAT(tp.user_id) as participants
            FROM {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id
            GROUP BY t.id
            ORDER BY t.updated_at DESC
        ");
    }

    /**
     * GET ALL THREADS WITH LAST MESSAGE (ADMIN)
     */
    public function get_all_threads_with_last_message() {
        global $wpdb;

        return $wpdb->get_results("
            SELECT 
                t.*,
                GROUP_CONCAT(tp.user_id) as participants,
                m.message as last_message
            FROM {$this->threads_table} t

            LEFT JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id

            LEFT JOIN {$this->messages_table} m 
                ON t.last_message_id = m.id

            GROUP BY t.id
            ORDER BY t.updated_at DESC
        ");
    }

    public function get_threads_by_connection($connection_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT t.*, 
                GROUP_CONCAT(tp.user_id) as participants
            FROM {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id
            WHERE t.connection_id = %d
            GROUP BY t.id
            ORDER BY t.updated_at DESC
        ", $connection_id));
    }

    /**
     * GET THREAD SUBJECT
     */
    public function get_thread_subject($thread_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("
            SELECT subject 
            FROM {$this->threads_table}
            WHERE id = %d
        ", $thread_id));
    }

    /**
     * UPDATE THREAD SUBJECT
     */
    public function update_thread_subject($thread_id, $subject) {
        global $wpdb;

        return $wpdb->update(
            $this->threads_table,
            ['subject' => $subject],
            ['id' => $thread_id]
        );
    }

    /**
     * UPDATE THREAD STATUS
     */
    public function inactive_threads_by_connection($connection_id) {
        global $wpdb;

        return $wpdb->update(
            $this->threads_table,
            ['status' => 'inactive'],
            ['connection_id' => $connection_id]
        );
    }

    /**
     * Get thread between 2 users
     */
    // public function get_thread_between_users($user1, $user2) {
    //     global $wpdb;

    //     $thread_id = $wpdb->get_var($wpdb->prepare("
    //         SELECT tp1.thread_id
    //         FROM {$this->participants_table} tp1
    //         INNER JOIN {$this->participants_table} tp2 
    //             ON tp1.thread_id = tp2.thread_id
    //         WHERE tp1.user_id = %d 
    //         AND tp2.user_id = %d
    //         LIMIT 1
    //     ", $user1, $user2));

    //     return $thread_id;
    // }

    /**
     * GET OLDER MESSAGES (SCROLL UP)
     */
    // public function get_older_messages($thread_id, $last_message_id, $limit = 20) {
    //     global $wpdb;

    //     return $wpdb->get_results($wpdb->prepare("
    //         SELECT *
    //         FROM {$this->messages_table}
    //         WHERE thread_id = %d
    //         AND id < %d
    //         ORDER BY id DESC
    //         LIMIT %d
    //     ", $thread_id, $last_message_id, $limit));
    // }

    /**
     * GET NEW MESSAGES (POLLING)
     */
    // public function get_new_messages($thread_id, $last_message_id) {
    //     global $wpdb;

    //     return $wpdb->get_results($wpdb->prepare("
    //         SELECT *
    //         FROM {$this->messages_table}
    //         WHERE thread_id = %d
    //         AND id > %d
    //         ORDER BY id ASC
    //     ", $thread_id, $last_message_id));
    // }
}