<?php

class NEXORA_Notification {

    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'nexora_notifications';
    }

    // CREATE TABLE
    public function create_table() {

        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            sender_user_id BIGINT NOT NULL,
            sender_profile_id BIGINT NOT NULL,
            sender_user_name VARCHAR(100) NOT NULL,

            receiver_user_id BIGINT NOT NULL,
            receiver_profile_id BIGINT NOT NULL,
            receiver_user_name VARCHAR(100) NOT NULL,

            type VARCHAR(50) NOT NULL,
            actor_user_id BIGINT NOT NULL,  -- mainly store id of user who remove connection
            reference_id BIGINT DEFAULT NULL,

            message TEXT,

            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            -- INDEXES
            KEY receiver_read_time (receiver_user_id, is_read, created_at),
            KEY sender_time (sender_user_id, created_at),
            KEY type (type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // INSERT
    public function insert($data) {

        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'sender_user_id'      => $data['sender_user_id'],
                'sender_profile_id'   => $data['sender_profile_id'],
                'sender_user_name'    => $data['sender_user_name'],

                'receiver_user_id'    => $data['receiver_user_id'],
                'receiver_profile_id' => $data['receiver_profile_id'],
                'receiver_user_name'  => $data['receiver_user_name'],

                'type'          => $data['type'],
                'actor_user_id' => $data['actor_user_id'],
                'reference_id'  => $data['reference_id'] ?? null,
                'message'       => $data['message'] ?? '',

                'is_read' => 0
            ]
        );
    }

    // FETCH (Latest First)
    public function get_all() {

        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC"
        );
    }

    // Get Unread Notification Count
    public function get_unread_count($user_id) {

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE 
                    (receiver_user_id = %d OR actor_user_id = %d)
                    AND is_read = 0",
                $user_id,
                $user_id
            )
        );
    }

    // RECEIVED
    // public function get_received($user_id) {
    //     global $wpdb;

    //     return $wpdb->get_results(
    //         $wpdb->prepare(
    //             "SELECT * FROM {$this->table}
    //             WHERE receiver_user_id = %d
    //             ORDER BY is_read ASC, created_at DESC
    //             LIMIT 50",
    //             $user_id
    //         )
    //     );
    // }

    // SENT
    // public function get_sent($user_id) {
    //     global $wpdb;

    //     return $wpdb->get_results(
    //         $wpdb->prepare(
    //             "SELECT * FROM {$this->table}
    //             WHERE sender_user_id = %d
    //             ORDER BY created_at DESC
    //             LIMIT 50",
    //             $user_id
    //         )
    //     );
    // }

    public function get_notifications($user_id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE sender_user_id = %d OR receiver_user_id = %d
                ORDER BY created_at DESC
                LIMIT 50",
                $user_id,
                $user_id
            )
        );
    }

    // MARK AS READ
    public function mark_as_read($id) {

        global $wpdb;

        $wpdb->update(
            $this->table,
            ['is_read' => 1],
            ['id' => $id]
        );
    }
}