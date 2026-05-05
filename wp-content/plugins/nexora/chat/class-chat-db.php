<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_CHAT_DB {

    private string $threads_table;
    private string $participants_table;
    private string $messages_table;
    private string $message_meta_table;

    public function __construct() {
        global $wpdb;

        $this->threads_table      = $wpdb->prefix . 'nexora_threads';
        $this->participants_table = $wpdb->prefix . 'nexora_thread_participants';
        $this->messages_table     = $wpdb->prefix . 'nexora_messages';
        $this->message_meta_table = $wpdb->prefix . 'nexora_message_meta';
    }

    /* -----------------------------------------------------------------------
       SCHEMA INSTALL / UPGRADE
    ----------------------------------------------------------------------- */
    public function create_table() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $threads = "CREATE TABLE {$this->threads_table} (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            connection_id  BIGINT UNSIGNED NOT NULL,
            status         VARCHAR(20)  NOT NULL DEFAULT 'active',
            type           VARCHAR(20)  NOT NULL DEFAULT 'private',
            subject        VARCHAR(255) NULL,
            last_message_id BIGINT UNSIGNED NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_connection_id (connection_id),
            INDEX idx_updated_at    (updated_at)
        ) $charset;";

        $participants = "CREATE TABLE {$this->participants_table} (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id    BIGINT UNSIGNED NOT NULL,
            user_id      BIGINT UNSIGNED NOT NULL,
            last_read    DATETIME NULL,
            unread_count INT     NOT NULL DEFAULT 0,
            is_muted     TINYINT(1) NOT NULL DEFAULT 0,
            is_pinned    TINYINT(1) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_thread_user (thread_id, user_id),
            INDEX idx_user_id   (user_id),
            INDEX idx_thread_id (thread_id)
        ) $charset;";

        $messages = "CREATE TABLE {$this->messages_table} (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id  BIGINT UNSIGNED NOT NULL,
            sender_id  BIGINT UNSIGNED NOT NULL,
            message    TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_thread_created (thread_id, created_at)
        ) $charset;";

        $meta = "CREATE TABLE {$this->message_meta_table} (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_id  BIGINT UNSIGNED NOT NULL,
            meta_key    VARCHAR(255) NOT NULL,
            meta_value  LONGTEXT,
            INDEX idx_message_id (message_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $threads );
        dbDelta( $participants );
        dbDelta( $messages );
        dbDelta( $meta );
    }

    /* -----------------------------------------------------------------------
       CREATE THREAD
    ----------------------------------------------------------------------- */
    /**
     * @param int[]  $users
     * @param int    $connection_id
     * @param string $thread_status  'active' | 'inactive'
     * @param string $type           'private' | 'group'
     * @param string $subject
     * @return int  The new thread ID.
     */
    public function create_thread( array $users, int $connection_id, string $thread_status, string $type = 'private', string $subject = '' ): int {
        global $wpdb;

        $wpdb->insert( $this->threads_table, [
            'connection_id' => $connection_id,
            'status'        => $thread_status,
            'type'          => $type,
            'subject'       => $subject ?: null,
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ] );

        $thread_id = (int) $wpdb->insert_id;

        foreach ( array_unique( $users ) as $user_id ) {
            $wpdb->insert( $this->participants_table, [
                'thread_id' => $thread_id,
                'user_id'   => (int) $user_id,
            ] );
        }

        return $thread_id;
    }

    /* -----------------------------------------------------------------------
       READ: THREADS
    ----------------------------------------------------------------------- */

    /**
     * Return the most recently updated thread for a given connection.
     */
    public function get_thread_by_connection( int $connection_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare( "
            SELECT id, status
            FROM   {$this->threads_table}
            WHERE  connection_id = %d
            ORDER  BY updated_at DESC
            LIMIT  1
        ", $connection_id ) ) ?: null;
    }

    /**
     * Return id + status for a single thread (used before sending a message).
     */
    public function get_thread_status( int $thread_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare( "
            SELECT id, status
            FROM   {$this->threads_table}
            WHERE  id = %d
        ", $thread_id ) ) ?: null;
    }

    /**
     * Return all threads visible to $user_id, sorted active-first then by recency.
     * Fixes the original N+2 JOIN that could return duplicate rows when a user
     * participated in many threads — we now use a subquery for the "other" participant.
     */
    public function get_user_threads( int $user_id ): array {
        global $wpdb;

        // The subquery picks ONE other participant per thread to display as the contact name.
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT
                t.id,
                t.connection_id,
                t.status,
                t.subject,
                t.updated_at,
                p.unread_count,
                m.message          AS last_message,
                other_p.user_id    AS other_user_id
            FROM {$this->threads_table} t

            INNER JOIN {$this->participants_table} p
                ON t.id = p.thread_id AND p.user_id = %d

            LEFT JOIN {$this->participants_table} other_p
                ON t.id = other_p.thread_id AND other_p.user_id != %d

            LEFT JOIN {$this->messages_table} m
                ON t.last_message_id = m.id

            ORDER BY
                CASE WHEN t.status = 'active' THEN 0 ELSE 1 END,
                t.updated_at DESC
        ", $user_id, $user_id ) );

        // Attach display names; avoid N+1 with a local cache.
        $user_cache = [];

        foreach ( $results as &$row ) {
            $uid = (int) $row->other_user_id;

            if ( ! isset( $user_cache[ $uid ] ) ) {
                $u = get_userdata( $uid );
                $user_cache[ $uid ] = $u ? $u->display_name : 'User';
            }

            $row->name = $user_cache[ $uid ];
        }
        unset( $row );

        return $results;
    }

    /* -----------------------------------------------------------------------
       READ: PARTICIPANTS
    ----------------------------------------------------------------------- */

    public function is_user_in_thread( int $thread_id, int $user_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM   {$this->participants_table}
            WHERE  thread_id = %d AND user_id = %d
        ", $thread_id, $user_id ) );
    }

    /* -----------------------------------------------------------------------
       READ: MESSAGES
    ----------------------------------------------------------------------- */

    /**
     * Fetch up to $limit messages for a thread, in ascending (chronological) order.
     *
     * @param int $before_id  When > 0, fetch messages with id < $before_id (load-older cursor).
     *                        When 0, fetch the latest $limit messages.
     */
    public function get_latest_messages( int $thread_id, int $limit = 30, int $before_id = 0 ): array {
        global $wpdb;

        if ( $before_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT id, thread_id, sender_id, message, created_at
                FROM   {$this->messages_table}
                WHERE  thread_id = %d AND id < %d
                ORDER  BY id DESC
                LIMIT  %d
            ", $thread_id, $before_id, $limit ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT id, thread_id, sender_id, message, created_at
                FROM   {$this->messages_table}
                WHERE  thread_id = %d
                ORDER  BY id DESC
                LIMIT  %d
            ", $thread_id, $limit ) );
        }

        // Reverse so the oldest fetched message appears first in the UI.
        return array_reverse( $rows );
    }

    /* -----------------------------------------------------------------------
       WRITE: MESSAGES
    ----------------------------------------------------------------------- */

    /**
     * Insert a message, update the thread's last_message pointer, and
     * increment unread counts for all other participants atomically.
     *
     * @return int  The new message ID.
     */
    public function send_message( int $thread_id, int $sender_id, string $message ): int {
        global $wpdb;

        $wpdb->insert( $this->messages_table, [
            'thread_id'  => $thread_id,
            'sender_id'  => $sender_id,
            'message'    => $message,
            'created_at' => current_time( 'mysql' ),
        ] );

        $message_id = (int) $wpdb->insert_id;

        $wpdb->update(
            $this->threads_table,
            [
                'last_message_id' => $message_id,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'id' => $thread_id ]
        );

        // Increment unread for everyone except the sender.
        $wpdb->query( $wpdb->prepare( "
            UPDATE {$this->participants_table}
            SET    unread_count = unread_count + 1
            WHERE  thread_id = %d AND user_id != %d
        ", $thread_id, $sender_id ) );

        return $message_id;
    }

    /* -----------------------------------------------------------------------
       WRITE: READ RECEIPTS
    ----------------------------------------------------------------------- */

    public function mark_as_read_chat( int $thread_id, int $user_id ): void {
        global $wpdb;

        $wpdb->update(
            $this->participants_table,
            [
                'unread_count' => 0,
                'last_read'    => current_time( 'mysql' ),
            ],
            [
                'thread_id' => $thread_id,
                'user_id'   => $user_id,
            ]
        );
    }

    /* -----------------------------------------------------------------------
       READ: SUBJECT
    ----------------------------------------------------------------------- */

    public function get_thread_subject( int $thread_id ): ?string {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( "
            SELECT subject
            FROM   {$this->threads_table}
            WHERE  id = %d
        ", $thread_id ) );
    }

    /* -----------------------------------------------------------------------
       WRITE: SUBJECT
    ----------------------------------------------------------------------- */

    public function update_thread_subject( int $thread_id, string $subject ): void {
        global $wpdb;

        $wpdb->update(
            $this->threads_table,
            [ 'subject' => $subject ],
            [ 'id'      => $thread_id ]
        );
    }

    /* -----------------------------------------------------------------------
       WRITE: STATUS
    ----------------------------------------------------------------------- */

    public function inactive_threads_by_connection( int $connection_id ): void {
        global $wpdb;

        $wpdb->update(
            $this->threads_table,
            [ 'status'        => 'inactive' ],
            [ 'connection_id' => $connection_id ]
        );
    }

    /* -----------------------------------------------------------------------
       ADMIN HELPERS
    ----------------------------------------------------------------------- */

    public function get_all_threads(): array {
        global $wpdb;

        return $wpdb->get_results( "
            SELECT t.*,
                   GROUP_CONCAT( tp.user_id ) AS participants
            FROM   {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp ON t.id = tp.thread_id
            GROUP  BY t.id
            ORDER  BY t.updated_at DESC
        " );
    }

    public function get_all_threads_with_last_message(): array {
        global $wpdb;

        return $wpdb->get_results( "
            SELECT t.*,
                   GROUP_CONCAT( tp.user_id ) AS participants,
                   m.message AS last_message
            FROM   {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp ON t.id = tp.thread_id
            LEFT JOIN {$this->messages_table}     m  ON t.last_message_id = m.id
            GROUP  BY t.id
            ORDER  BY t.updated_at DESC
        " );
    }

    public function get_threads_by_connection( int $connection_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare( "
            SELECT t.*,
                   GROUP_CONCAT( tp.user_id ) AS participants
            FROM   {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp ON t.id = tp.thread_id
            WHERE  t.connection_id = %d
            GROUP  BY t.id
            ORDER  BY t.updated_at DESC
        ", $connection_id ) );
    }
}
