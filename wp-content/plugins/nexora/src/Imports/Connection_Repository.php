<?php

namespace Nexora\Imports;

use Nexora\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A vendor's saved API connections (up to 5): a private post each, written only through here (Core\Post_Guard). The key
 * is kept encrypted in post meta and is only ever handed to the fetcher; every listing method leaves it out.
 */
class Connection_Repository {

	use \Nexora\Core\Internal_Writes;

	const POST_TYPE = 'nexora_api_conn';
	const MAX       = 5;

	/**
	 * Creates a connection.
	 *
	 * @param int   $user_id   The vendor's user ID (the author).
	 * @param int   $vendor_id The vendor's application post ID.
	 * @param array $data      name, url, auth_type, auth_header, secret (PLAIN or '').
	 * @return int|\WP_Error
	 */
	public static function create( $user_id, $vendor_id, array $data ) {

		$id = self::internally(
			function () use ( $user_id, $data ) {
				return wp_insert_post(
					array(
						'post_type'   => self::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $data['name'],
						'post_author' => (int) $user_id,
					),
					true
				);
			}
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'nexora_save_failed', 'Could not save.' );
		}

		update_post_meta( $id, 'vendor_id', (int) $vendor_id );
		update_post_meta( $id, 'last_import', '' );
		update_post_meta( $id, 'last_status', '' );
		self::write( $id, $data, true );

		return (int) $id;
	}

	/**
	 * Updates a connection. A missing "secret" keeps the saved key; "clear_secret" removes it.
	 *
	 * @param int   $id   Connection ID.
	 * @param array $data name, url, auth_type, auth_header, secret (optional), clear_secret (optional).
	 */
	public static function update( $id, array $data ) {

		self::internally(
			function () use ( $id, $data ) {
				wp_update_post(
					array(
						'ID'         => (int) $id,
						'post_title' => $data['name'],
					)
				);
			}
		);

		self::write( $id, $data, false );
	}

	/**
	 * Deletes a connection.
	 *
	 * @param int $id Connection ID.
	 */
	public static function delete( $id ) {

		self::internally(
			function () use ( $id ) {
				wp_delete_post( (int) $id, true );
			}
		);
	}

	/**
	 * A vendor's connections, oldest first, WITHOUT the key.
	 *
	 * @param int $vendor_id The vendor's application post ID.
	 * @return array[] id, name, url, auth_type, auth_header, has_secret, last_import, last_status
	 */
	public static function for_vendor( $vendor_id ) {

		$out = array();

		foreach ( self::ids( (int) $vendor_id ) as $id ) {
			$row = self::row( $id );
			unset( $row['secret'] );
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * One of a vendor's connections including the (encrypted) key, or null when it is not theirs.
	 *
	 * @param int $id        Connection ID.
	 * @param int $vendor_id The vendor's application post ID.
	 * @return array|null
	 */
	public static function find( $id, $vendor_id ) {

		$id = (int) $id;

		if ( $id <= 0 || get_post_type( $id ) !== self::POST_TYPE || (int) get_post_meta( $id, 'vendor_id', true ) !== (int) $vendor_id ) {
			return null;
		}

		return self::row( $id );
	}

	/**
	 * How many connections a vendor has.
	 *
	 * @param int $vendor_id The vendor's application post ID.
	 * @return int
	 */
	public static function count_for_vendor( $vendor_id ) {
		return count( self::ids( (int) $vendor_id ) );
	}

	/**
	 * Records the outcome of the last import through a connection.
	 *
	 * @param int    $id     Connection ID.
	 * @param string $status ok | error.
	 */
	public static function touch( $id, $status ) {

		if ( get_post_type( $id ) !== self::POST_TYPE ) {
			return;
		}

		update_post_meta( $id, 'last_import', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, 'last_status', 'ok' === $status ? 'ok' : 'error' );
	}

	/**
	 * The stored fields of a connection.
	 *
	 * @param int $id Connection ID.
	 * @return array
	 */
	private static function row( $id ) {

		$secret = (string) get_post_meta( $id, 'secret', true );

		return array(
			'id'          => (int) $id,
			'name'        => (string) get_post_field( 'post_title', $id ),
			'url'         => (string) get_post_meta( $id, 'url', true ),
			'auth_type'   => (string) get_post_meta( $id, 'auth_type', true ),
			'auth_header' => (string) get_post_meta( $id, 'auth_header', true ),
			'secret'      => $secret,
			'has_secret'  => '' !== $secret,
			'last_import' => (string) get_post_meta( $id, 'last_import', true ),
			'last_status' => (string) get_post_meta( $id, 'last_status', true ),
		);
	}

	/**
	 * Writes the fields.
	 *
	 * @param int   $id     Connection ID.
	 * @param array $data   Fields.
	 * @param bool  $is_new Whether the connection is new.
	 */
	private static function write( $id, array $data, $is_new ) {

		update_post_meta( $id, 'url', $data['url'] );
		update_post_meta( $id, 'auth_type', $data['auth_type'] );
		update_post_meta( $id, 'auth_header', 'header' === $data['auth_type'] ? $data['auth_header'] : '' );

		if ( 'none' === $data['auth_type'] || ! empty( $data['clear_secret'] ) ) {
			update_post_meta( $id, 'secret', '' );
		} elseif ( isset( $data['secret'] ) && '' !== $data['secret'] ) {
			update_post_meta( $id, 'secret', Secrets::encrypt( $data['secret'] ) );
		} elseif ( $is_new ) {
			update_post_meta( $id, 'secret', '' );
		}
	}

	/**
	 * Connection IDs of a vendor, oldest first.
	 *
	 * @param int $vendor_id The vendor's application post ID.
	 * @return int[]
	 */
	private static function ids( $vendor_id ) {

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'posts_per_page'   => 50,
					'orderby'          => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the connections of one vendor.
					'meta_query'       => array(
						array(
							'key'   => 'vendor_id',
							'value' => (int) $vendor_id,
						),
					),
				)
			)
		);
	}
}
