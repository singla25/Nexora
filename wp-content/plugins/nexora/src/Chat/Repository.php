<?php

namespace Nexora\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for chat: the threads, participants, messages and message_meta tables.
 */
class Repository {

	const CACHE_GROUP = 'nexora_chat';

	/**
	 * Version stamp of the cached thread lists; changing it retires every cached list.
	 *
	 * @return string
	 */
	private static function cache_version() {

		$version = wp_cache_get( 'version', self::CACHE_GROUP );

		if ( false === $version ) {
			$version = (string) microtime( true );
			wp_cache_set( 'version', $version, self::CACHE_GROUP );
		}

		return $version;
	}

	/**
	 * Retires the cached thread lists (any thread, message or read-state change).
	 */
	private static function flush_cache() {
		wp_cache_set( 'version', (string) microtime( true ), self::CACHE_GROUP );
	}

	/**
	 * Threads table name.
	 *
	 * @var string
	 */
	private $threads_table;

	/**
	 * Thread participants table name.
	 *
	 * @var string
	 */
	private $participants_table;

	/**
	 * Messages table name.
	 *
	 * @var string
	 */
	private $messages_table;

	/**
	 * Message meta table name.
	 *
	 * @var string
	 */
	private $message_meta_table;

	/**
	 * Resolves the table names.
	 */
	public function __construct() {
		global $wpdb;

		$this->threads_table      = $wpdb->prefix . 'nexora_threads';
		$this->participants_table = $wpdb->prefix . 'nexora_thread_participants';
		$this->messages_table     = $wpdb->prefix . 'nexora_messages';
		$this->message_meta_table = $wpdb->prefix . 'nexora_message_meta';
	}

	/**
	 * Creates or updates the chat tables (dbDelta: safe to run repeatedly).
	 *
	 * @return string[] What dbDelta changed (empty when the tables are already current).
	 */
	public function create_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, KEY (not INDEX), no comments.
		$threads = "CREATE TABLE {$this->threads_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) DEFAULT 'active',
  type varchar(20) DEFAULT 'private',
  subject varchar(255) DEFAULT NULL,
  last_message_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_connection_id (connection_id),
  KEY idx_status (status),
  KEY idx_updated_at (updated_at)
) $charset;";

		$participants = "CREATE TABLE {$this->participants_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  last_read datetime DEFAULT NULL,
  unread_count int(11) DEFAULT 0,
  is_muted tinyint(1) DEFAULT 0,
  is_pinned tinyint(1) DEFAULT 0,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY unique_thread_user (thread_id,user_id),
  KEY idx_user_id (user_id),
  KEY idx_thread_id (thread_id)
) $charset;";

		$messages = "CREATE TABLE {$this->messages_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  thread_id bigint(20) unsigned NOT NULL,
  sender_id bigint(20) unsigned NOT NULL,
  message text NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_thread_id (thread_id),
  KEY idx_thread_id_id (thread_id,id),
  KEY idx_created_at (created_at)
) $charset;";

		$meta = "CREATE TABLE {$this->message_meta_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  message_id bigint(20) unsigned NOT NULL,
  meta_key varchar(255) DEFAULT NULL,
  meta_value longtext,
  PRIMARY KEY  (id),
  KEY idx_message_id (message_id)
) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return array_merge(
			dbDelta( $threads ),
			dbDelta( $participants ),
			dbDelta( $messages ),
			dbDelta( $meta )
		);
	}

	/**
	 * Insert New Thread and It's Participants
	 *
	 * @param int[]  $users User IDs of the participants.
	 * @param int    $connection_id Connection (user_connections) post ID.
	 * @param string $thread_status Thread status: active or inactive.
	 * @param string $type Thread type.
	 * @param string $subject Conversation subject.
	 * @return int New thread ID, 0 on failure.
	 */
	public function create_thread( $users, $connection_id, $thread_status, $type = 'private', $subject = '' ) {

		global $wpdb;

		$wpdb->insert(
			$this->threads_table,
			array(
				'connection_id' => (int) $connection_id,
				'status'        => $thread_status,
				'type'          => $type,
				'subject'       => $subject,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);

		$thread_id = (int) $wpdb->insert_id;

		if ( ! $thread_id ) {
			return 0;
		}

		foreach ( $users as $user_id ) {
			$wpdb->insert(
				$this->participants_table,
				array(
					'thread_id' => $thread_id,
					'user_id'   => (int) $user_id,
				)
			);
		}

		self::flush_cache();

		return $thread_id;
	}

	/**
	 * GET LATEST THREAD BETWEEN USERS
	 *
	 * @param int $connection_id Connection (user_connections) post ID.
	 * @return object|null id and status of the latest thread.
	 */
	public function get_thread_by_connection( $connection_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"
            SELECT id, status
            FROM {$this->threads_table}
            WHERE connection_id = %d
            ORDER BY updated_at DESC
            LIMIT 1
        ",
				$connection_id
			)
		);
	}

	/**
	 * GET THREAD STATUS
	 *
	 * @param int $thread_id Chat thread ID.
	 * @return object|null id and status.
	 */
	public function get_thread_status( $thread_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"
            SELECT id, status 
            FROM {$this->threads_table}
            WHERE id = %d
        ",
				$thread_id
			)
		);
	}

	/**
	 * GET USER PARTICIPANTS
	 *
	 * @param int $thread_id Chat thread ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function is_user_in_thread( $thread_id, $user_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"
            SELECT COUNT(*) 
            FROM {$this->participants_table}
            WHERE thread_id = %d AND user_id = %d
        ",
				$thread_id,
				$user_id
			)
		);
	}

	/**
	 * GET USER THREADS (CHAT LIST)
	 *
	 * @param int $user_id User ID.
	 * @return object[] Threads with unread count, other participant, last message and name.
	 */
	public function get_user_threads( $user_id ) {
		global $wpdb;

		$cache_key = 'threads_' . (int) $user_id . '_' . self::cache_version();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$results = array_map(
				function ( $row ) {
					return clone $row;
				},
				$cached
			);
		} else {
			$results = $this->query_user_threads( $user_id );
			wp_cache_set( $cache_key, $results, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		foreach ( $results as $row ) {

			$user = get_userdata( $row->other_user_id );

			$row->name = $user ? $user->display_name : __( 'User', 'nexora' );
		}

		return $results;
	}

	/**
	 * Uncached thread list for a member.
	 *
	 * @param int $user_id User ID.
	 * @return object[] Threads with unread count, other participant and last message.
	 */
	private function query_user_threads( $user_id ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT 
                t.*,
                p.unread_count,
                tp.user_id AS other_user_id,
                m.message AS last_message

            FROM {$this->threads_table} t

            INNER JOIN {$this->participants_table} p 
                ON t.id = p.thread_id AND p.user_id = %d

            INNER JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id AND tp.user_id != %d

            LEFT JOIN {$this->messages_table} m 
                ON t.last_message_id = m.id

            ORDER BY 
                CASE WHEN t.status = 'active' THEN 0 ELSE 1 END,
                t.updated_at DESC
        ",
				$user_id,
				$user_id
			)
		);

		return $results;
	}

	/**
	 * Send Messgae
	 *
	 * @param int    $thread_id Chat thread ID.
	 * @param int    $sender_id User ID of the sender.
	 * @param string $message Message text.
	 * @return int ID of the new message.
	 */
	public function send_message( $thread_id, $sender_id, $message ) {
		global $wpdb;

		// Insert message
		$wpdb->insert(
			$this->messages_table,
			array(
				'thread_id' => $thread_id,
				'sender_id' => $sender_id,
				'message'   => $message,
			)
		);

		$message_id = $wpdb->insert_id;

		// Update thread
		$wpdb->update(
			$this->threads_table,
			array(
				'last_message_id' => $message_id,
				'updated_at'      => current_time( 'mysql' ),
			),
			array(
				'id' => $thread_id,
			)
		);

		// Update unread count (others only)
		$wpdb->query(
			$wpdb->prepare(
				"
            UPDATE {$this->participants_table}
            SET unread_count = unread_count + 1
            WHERE thread_id = %d AND user_id != %d
        ",
				$thread_id,
				$sender_id
			)
		);

		self::flush_cache();

		return $message_id;
	}

	/**
	 * Get Latest Messages (INITIAL LOAD)
	 *
	 * @param int $thread_id Chat thread ID.
	 * @param int $limit Maximum number of messages.
	 * @return object[] Oldest first.
	 */
	public function get_latest_messages( $thread_id, $limit = 20 ) {
		global $wpdb;

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT *
            FROM {$this->messages_table}
            WHERE thread_id = %d
            ORDER BY id DESC
            LIMIT %d
        ",
				$thread_id,
				$limit
			)
		);

		return array_reverse( $messages ); // ✅ important
	}

	/**
	 * MARK AS READ
	 *
	 * @param int $thread_id Chat thread ID.
	 * @param int $user_id User ID.
	 */
	public function mark_as_read_chat( $thread_id, $user_id ) {
		global $wpdb;

		$wpdb->update(
			$this->participants_table,
			array(
				'unread_count' => 0,
				'last_read'    => current_time( 'mysql' ),
			),
			array(
				'thread_id' => $thread_id,
				'user_id'   => $user_id,
			)
		);

		self::flush_cache();
	}

	/**
	 * GET ALL USER THREADS (Thread List For Admin)
	 *
	 * @return object[]
	 */
	public function get_all_threads() {
		global $wpdb;

		return $wpdb->get_results(
			"
            SELECT t.*, 
                GROUP_CONCAT(tp.user_id) as participants
            FROM {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id
            GROUP BY t.id
            ORDER BY t.updated_at DESC
        "
		);
	}

	/**
	 * GET ALL THREADS WITH LAST MESSAGE (ADMIN)
	 *
	 * @return object[]
	 */
	public function get_all_threads_with_last_message() {
		global $wpdb;

		return $wpdb->get_results(
			"
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
        "
		);
	}

	/**
	 * Every conversation of a connection with its participant ids.
	 *
	 * @param int $connection_id Connection (user_connections) post ID.
	 * @return object[]
	 */
	public function get_threads_by_connection( $connection_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT t.*, 
                GROUP_CONCAT(tp.user_id) as participants
            FROM {$this->threads_table} t
            LEFT JOIN {$this->participants_table} tp 
                ON t.id = tp.thread_id
            WHERE t.connection_id = %d
            GROUP BY t.id
            ORDER BY t.updated_at DESC
        ",
				$connection_id
			)
		);
	}

	/**
	 * GET THREAD SUBJECT
	 *
	 * @param int $thread_id Chat thread ID.
	 * @return string|null The subject, or null for an unknown thread.
	 */
	public function get_thread_subject( $thread_id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"
            SELECT subject 
            FROM {$this->threads_table}
            WHERE id = %d
        ",
				$thread_id
			)
		);
	}

	/**
	 * UPDATE THREAD SUBJECT
	 *
	 * @param int    $thread_id Chat thread ID.
	 * @param string $subject Conversation subject.
	 * @return int|false Rows changed, or false on error.
	 */
	public function update_thread_subject( $thread_id, $subject ) {
		global $wpdb;

		$changed = $wpdb->update(
			$this->threads_table,
			array( 'subject' => $subject ),
			array( 'id' => $thread_id )
		);

		self::flush_cache();

		return $changed;
	}

	/**
	 * UPDATE THREAD STATUS
	 *
	 * @param int $connection_id Connection (user_connections) post ID.
	 * @return int|false Rows changed, or false on error.
	 */
	public function inactive_threads_by_connection( $connection_id ) {
		global $wpdb;

		$changed = $wpdb->update(
			$this->threads_table,
			array( 'status' => 'inactive' ),
			array( 'connection_id' => $connection_id )
		);

		self::flush_cache();

		return $changed;
	}
}
