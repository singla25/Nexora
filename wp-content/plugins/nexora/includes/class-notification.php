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

            actor_user_id BIGINT UNSIGNED NOT NULL,
            actor_user_name VARCHAR(100) NOT NULL,

            receiver_user_id BIGINT UNSIGNED NOT NULL,
            receiver_user_name VARCHAR(100) NOT NULL,

            type VARCHAR(50) NOT NULL,
            connection_id BIGINT UNSIGNED DEFAULT NULL, -- user_connectioon post id

            message TEXT,

            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            -- 🔥 INDEXES (VERY IMPORTANT)

            INDEX idx_receiver (receiver_user_id),
            INDEX idx_actor (actor_user_id),

            INDEX idx_receiver_read (receiver_user_id, is_read),

            INDEX idx_created (created_at),

            INDEX idx_type (type)

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
                'actor_user_id'      => $data['actor_user_id'],
                'actor_user_name'    => $data['actor_user_name'],

                'receiver_user_id'   => $data['receiver_user_id'],
                'receiver_user_name' => $data['receiver_user_name'],

                'type'          => $data['type'],
                'connection_id' => $data['connection_id'] ?? null,

                'message'       => $data['message'] ?? '',
                'is_read'       => 0
            ],
            [
                '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d'
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

    public function get_notifications($user_id, $limit = 50) {

        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE receiver_user_id = %d
                ORDER BY is_read ASC, created_at DESC
                LIMIT %d",
                $user_id,
                $limit
            )
        );
    }

    // Fetch row on the basis of Id
    public function get_row($id) {

        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $id
            )
        );
    }

    // Get Unread Notification Count
    public function get_unread_count($user_id) {

        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE receiver_user_id = %d
                AND is_read = 0",
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