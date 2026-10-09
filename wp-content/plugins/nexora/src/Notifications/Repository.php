<?php

namespace Nexora\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for the notifications table.
 */
class Repository {

	/**
	 * Notifications table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Resolves the table name.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nexora_notifications';
	}

	/**
	 * Creates or updates the notifications table (dbDelta: safe to run repeatedly).
	 *
	 * @return string[] What dbDelta changed (empty when the table is already current).
	 */
	public function create_table() {

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, KEY (not INDEX), no comments.
		$sql = "CREATE TABLE {$this->table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_user_id bigint(20) unsigned NOT NULL,
  actor_user_name varchar(100) NOT NULL,
  receiver_user_id bigint(20) unsigned NOT NULL,
  receiver_user_name varchar(100) NOT NULL,
  type varchar(50) NOT NULL,
  connection_id bigint(20) unsigned DEFAULT NULL,
  message text,
  is_read tinyint(1) DEFAULT 0,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_receiver (receiver_user_id),
  KEY idx_actor (actor_user_id),
  KEY idx_receiver_read (receiver_user_id,is_read),
  KEY idx_created (created_at),
  KEY idx_type (type)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( $sql );
	}

	/**
	 * Stores a notification.
	 *
	 * @param array $data Notification fields: actor_/receiver_ user_id and user_name, type, connection_id, message.
	 */
	public function insert( $data ) {

		global $wpdb;

		$wpdb->insert(
			$this->table,
			array(
				'actor_user_id'      => $data['actor_user_id'],
				'actor_user_name'    => $data['actor_user_name'],

				'receiver_user_id'   => $data['receiver_user_id'],
				'receiver_user_name' => $data['receiver_user_name'],

				'type'               => $data['type'],
				'connection_id'      => $data['connection_id'] ?? null,

				'message'            => $data['message'] ?? '',
				'is_read'            => 0,
			),
			array(
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
			)
		);
	}

	/**
	 * Every notification, newest first (admin overview).
	 *
	 * @return object[]
	 */
	public function get_all() {

		global $wpdb;

		return $wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY created_at DESC"
		);
	}

	/**
	 * A member's notifications, unread first then newest.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Maximum number of notifications.
	 * @return object[]
	 */
	public function get_notifications( $user_id, $limit = 50 ) {

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

	/**
	 * One notification by id.
	 *
	 * @param int $id Notification ID.
	 * @return object|null
	 */
	public function get_row( $id ) {

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Number of unread notifications of a member.
	 *
	 * @param int $user_id User ID.
	 * @return string|null Count as returned by the database.
	 */
	public function get_unread_count( $user_id ) {

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

	/**
	 * Marks a notification as read.
	 *
	 * @param int $id Notification ID.
	 */
	public function mark_as_read( $id ) {

		global $wpdb;

		$wpdb->update(
			$this->table,
			array( 'is_read' => 1 ),
			array( 'id' => $id )
		);
	}
}
