<?php

namespace Nexora\Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries and writes for the `nexora_vendor` posts. The post is linked to its user both ways, exactly like
 * `user_profile`: `_wp_user_id` post meta and `_vendor_id` user meta. The vendor STATUS is not stored here: it is
 * Accounts\Account_Types' (user meta) and the only copy.
 */
class Repository {

	const POST_TYPE = 'nexora_vendor';
	const USER_META = '_vendor_id';

	/**
	 * True while the Repository itself is writing (Service calls). Vendors\Guard refuses every other
	 * trash, delete, restore or status/title change of a vendor post.
	 *
	 * @var bool
	 */
	private static $internal = false;

	/**
	 * Whether the current write comes from the Repository (and so from Service).
	 *
	 * @return bool
	 */
	public static function is_internal() {
		return self::$internal;
	}

	/**
	 * Runs a write as the Repository, so the Guard lets it through.
	 *
	 * @param callable $write What to do.
	 * @return mixed What the callable returns.
	 */
	private static function internally( callable $write ) {

		$before         = self::$internal;
		self::$internal = true;

		try {
			return $write();
		} finally {
			self::$internal = $before;
		}
	}

	/**
	 * The live vendor post of a user (0 when none, trashed, or not pointing back at that user).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function id_for_user( $user_id ) {

		$id = (int) get_user_meta( (int) $user_id, self::USER_META, true );

		if ( ! $id || get_post_type( $id ) !== self::POST_TYPE || 'trash' === get_post_status( $id ) ) {
			return 0;
		}

		return (int) get_post_meta( $id, '_wp_user_id', true ) === (int) $user_id ? $id : 0;
	}

	/**
	 * The user a vendor post belongs to (0 when unknown).
	 *
	 * @param int $vendor_id Vendor post ID.
	 * @return int
	 */
	public static function user_for( $vendor_id ) {

		if ( get_post_type( $vendor_id ) !== self::POST_TYPE ) {
			return 0;
		}

		return (int) get_post_meta( $vendor_id, '_wp_user_id', true );
	}

	/**
	 * The live vendor post that already uses a GSTIN (case-insensitive; trashed applications do not count).
	 *
	 * @param string $gstin   GSTIN.
	 * @param int    $exclude Vendor post to ignore (the one being edited).
	 * @return int Vendor post ID, or 0.
	 */
	public static function find_by_gstin( $gstin, $exclude = 0 ) {

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'post__not_in'     => $exclude ? array( (int) $exclude ) : array(),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed-by-post lookup per application.
				'meta_query'       => array(
					array(
						'key'   => 'gstin',
						'value' => strtoupper( trim( $gstin ) ),
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The stored business details of a vendor post.
	 *
	 * @param int $vendor_id Vendor post ID.
	 * @return array Field => value, for every field in Fields::keys().
	 */
	public static function data( $vendor_id ) {

		$data = array();

		foreach ( Fields::keys() as $key ) {
			$data[ $key ] = (string) get_post_meta( $vendor_id, $key, true );
		}

		return $data;
	}

	/**
	 * Creates the vendor post and links it to the user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Validated business details.
	 * @return int|\WP_Error New vendor post ID.
	 */
	public static function create( $user_id, array $data ) {

		$id = self::internally(
			function () use ( $user_id, $data ) {
				return wp_insert_post(
					array(
						'post_type'   => self::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $data['business_name'],
						'post_author' => (int) $user_id,
					),
					true
				);
			}
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'nexora_save_failed', 'Could not save.' );
		}

		update_post_meta( $id, '_wp_user_id', (int) $user_id );
		self::save( $id, $data );
		update_user_meta( (int) $user_id, self::USER_META, $id );

		return (int) $id;
	}

	/**
	 * Writes business details (only the keys given) to a vendor post.
	 *
	 * @param int   $vendor_id Vendor post ID.
	 * @param array $data      Validated fields.
	 */
	public static function save( $vendor_id, array $data ) {

		foreach ( Fields::keys() as $key ) {

			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}

			update_post_meta( $vendor_id, $key, $data[ $key ] );
		}

		if ( isset( $data['business_name'] ) ) {
			self::internally(
				function () use ( $vendor_id, $data ) {
					wp_update_post(
						array(
							'ID'         => $vendor_id,
							'post_title' => $data['business_name'],
						)
					);
				}
			);
		}
	}

	/**
	 * Removes a vendor post for good (a failed application being rolled back).
	 *
	 * @param int $vendor_id Vendor post ID.
	 */
	public static function delete( $vendor_id ) {

		$user_id = self::user_for( $vendor_id );

		self::internally(
			function () use ( $vendor_id ) {
				wp_delete_post( $vendor_id, true );
			}
		);

		if ( $user_id && (int) get_user_meta( $user_id, self::USER_META, true ) === (int) $vendor_id ) {
			delete_user_meta( $user_id, self::USER_META );
		}
	}

	/**
	 * Moves a rejected application to the trash (kept for the record) and unlinks the user.
	 *
	 * @param int $vendor_id Vendor post ID.
	 */
	public static function trash( $vendor_id ) {

		$user_id = self::user_for( $vendor_id );

		self::internally(
			function () use ( $vendor_id ) {
				wp_trash_post( $vendor_id );
			}
		);

		if ( $user_id ) {
			delete_user_meta( $user_id, self::USER_META );
		}
	}
}
