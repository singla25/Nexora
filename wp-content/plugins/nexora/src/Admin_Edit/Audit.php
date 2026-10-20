<?php

namespace Nexora\Admin_Edit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change history of what an administrator changes: one row per field changed (object type, object id, who, field, old value, new
 * value, when). The table is append-only: this class can add a row and read rows, nothing else, and a row outlives the object it is
 * about. Secrets and keys are never written down: for them the row says THAT the field changed, with empty values.
 */
class Audit {

	const VALUE_MAX = 2000;

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nexora_audit';
	}

	/**
	 * Creates the table through dbDelta (safe to run repeatedly). Called by Database\Migrations.
	 *
	 * @return string[] What dbDelta changed.
	 */
	public static function create_table() {

		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, KEY (not INDEX), no comments.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(40) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  field varchar(100) NOT NULL,
  old_value text,
  new_value text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_object (object_type,object_id),
  KEY idx_user (user_id)
) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( $sql );
	}

	/**
	 * Writes one change down.
	 *
	 * @param string $type  Object type: a post type, or `nexora_notification`.
	 * @param int    $id    Object ID.
	 * @param string $field The field that changed.
	 * @param mixed  $before Value before.
	 * @param mixed  $after  Value after.
	 * @return int The new row ID, or 0 when nothing was written (malformed input, or no change).
	 */
	public static function record( $type, $id, $field, $before, $after ) {

		global $wpdb;

		$id     = (int) $id;
		$field  = (string) $field;
		$before = is_scalar( $before ) || null === $before ? (string) $before : '';
		$after  = is_scalar( $after ) || null === $after ? (string) $after : '';

		if ( ! preg_match( '/^[a-z0-9_]{1,40}$/', (string) $type ) || $id <= 0 || '' === $field || $before === $after ) {
			return 0;
		}

		if ( self::is_secret_field( $field ) ) {
			$before = '';
			$after  = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the audit table is ours; a row is added, never changed.
		$added = $wpdb->insert(
			self::table(),
			array(
				'object_type' => (string) $type,
				'object_id'   => $id,
				'user_id'     => get_current_user_id(),
				'field'       => substr( $field, 0, 100 ),
				'old_value'   => mb_substr( $before, 0, self::VALUE_MAX ),
				'new_value'   => mb_substr( $after, 0, self::VALUE_MAX ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $added ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The history of one object, newest first.
	 *
	 * @param string $type  Object type.
	 * @param int    $id    Object ID.
	 * @param int    $limit Most rows to return.
	 * @return array[] Rows: id, object_type, object_id, user_id, field, old_value, new_value, created_at.
	 */
	public static function rows( $type, $id, $limit = 200 ) {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d',
				(string) $type,
				(int) $id,
				(int) $limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return $rows ? $rows : array();
	}

	/**
	 * Whether a field holds a secret, a key or a private document: its values are never written down.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public static function is_secret_field( $field ) {

		$field = strtolower( (string) $field );

		$exact = array( 'ifsc', 'iban', 'swift', 'account_number', 'card_number', 'aadhaar_card', 'driving_license', 'company_id_card' );

		return in_array( $field, $exact, true ) || 1 === preg_match( '/secret|api_?key|token|passw(or)?d|private_key|auth_header|bank_account/', $field );
	}
}
