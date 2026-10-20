<?php

namespace Nexora\Vendors;

use Nexora\Accounts\Account_Types;
use Nexora\Accounts\Links;
use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries and writes for the seller records, both types (Core\Post_Types::sellers(): a direct seller's `nexora_seller`
 * post and a customer's `nexora_cust_seller` post) through one code path. A record is linked to its user both ways:
 * `_wp_user_id` post meta and `_vendor_id` / `_seller_post_id` user meta (Accounts\Links does the writing). The seller
 * STATUS is not stored here: it is Accounts\Account_Types' (user meta) and the only copy.
 */
class Repository {

	use \Nexora\Core\Internal_Writes;

	const USER_META = '_vendor_id';

	/**
	 * The personal details a direct seller's post carries (the same keys a customer post gets at registration).
	 *
	 * @return string[]
	 */
	public static function personal_keys() {
		return array( 'user_name', 'first_name', 'last_name', 'email', 'phone', 'gender', 'birthdate' );
	}

	/**
	 * The record types this Repository works on (one list, in Core\Post_Types).
	 *
	 * @return string[]
	 */
	public static function types() {
		return Post_Types::sellers();
	}

	/**
	 * The live seller record of a user (0 when none, trashed, or not pointing back at that user). A rejected record is still live.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function id_for_user( $user_id ) {

		$id = (int) get_user_meta( (int) $user_id, self::USER_META, true );

		if ( ! $id || ! in_array( get_post_type( $id ), self::types(), true ) || 'trash' === get_post_status( $id ) ) {
			return 0;
		}

		return (int) get_post_meta( $id, '_wp_user_id', true ) === (int) $user_id ? $id : 0;
	}

	/**
	 * The user a seller record belongs to (0 when unknown).
	 *
	 * @param int $vendor_id Seller record ID.
	 * @return int
	 */
	public static function user_for( $vendor_id ) {

		if ( ! in_array( get_post_type( $vendor_id ), self::types(), true ) ) {
			return 0;
		}

		return (int) get_post_meta( $vendor_id, '_wp_user_id', true );
	}

	/**
	 * The live seller record, of either type, that already uses a GSTIN (case-insensitive). Trashed records and
	 * rejected ones do not count: a rejected applicant gives the GSTIN back (and can re-apply on the same record).
	 *
	 * @param string $gstin   GSTIN.
	 * @param int    $exclude Seller record to ignore (the one being edited).
	 * @return int Seller record ID, or 0.
	 */
	public static function find_by_gstin( $gstin, $exclude = 0 ) {

		$ids = get_posts(
			array(
				'post_type'        => self::types(),
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => -1,
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

		foreach ( $ids as $id ) {

			$owner = self::user_for( $id );

			if ( $owner && Account_Types::REJECTED === Account_Types::vendor_status( $owner ) ) {
				continue;
			}

			return (int) $id;
		}

		return 0;
	}

	/**
	 * The seller records of every approved seller, both types (one query). Their published products are the ones the marketplace lists.
	 *
	 * @return int[]
	 */
	public static function approved_ids() {

		global $wpdb;

		$ids = array();

		// One join over the status meta (the single source of the status), once per record type: the type is bound, never interpolated.
		foreach ( self::types() as $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a join over post and user meta; WP_Query cannot filter on user meta.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_user_id'
					INNER JOIN {$wpdb->usermeta} u ON u.user_id = m.meta_value AND u.meta_key = %s AND u.meta_value = %s
					WHERE p.post_type = %s AND p.post_status = 'publish'",
					Account_Types::STATUS_META,
					Account_Types::APPROVED,
					$type
				)
			);
			$ids   = array_merge( $ids, $found );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * The stored business details of a seller record.
	 *
	 * @param int $vendor_id Seller record ID.
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
	 * Creates the seller record of a customer who becomes a seller (`nexora_cust_seller`) and links it.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Validated business details.
	 * @return int|\WP_Error New seller record ID.
	 */
	public static function create( $user_id, array $data ) {

		$id = self::insert( Post_Types::CUST_SELLER, $user_id, $data['business_name'] );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::save( $id, $data );
		Links::link( $id );

		return $id;
	}

	/**
	 * Creates the single post of a member who registers directly as a seller (`nexora_seller`): their personal
	 * and business details, and their profile. There is no customer post.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $personal Personal details (personal_keys()).
	 * @param array $data     Validated business details.
	 * @return int|\WP_Error New seller record ID.
	 */
	public static function create_direct( $user_id, array $personal, array $data ) {

		$id = self::insert( Post_Types::SELLER, $user_id, $data['business_name'], isset( $personal['user_name'] ) ? $personal['user_name'] : '' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		foreach ( self::personal_keys() as $key ) {
			if ( isset( $personal[ $key ] ) ) {
				update_post_meta( $id, $key, $personal[ $key ] );
			}
		}

		self::save( $id, $data );
		Links::link( $id );

		return $id;
	}

	/**
	 * Inserts a seller record post owned by a user.
	 *
	 * @param string $type        Post type (Core\Post_Types::sellers()).
	 * @param int    $user_id     Owner.
	 * @param string $title       Post title (the business name).
	 * @param string $slug_source Optional text the post slug is made from.
	 * @return int|\WP_Error
	 */
	private static function insert( $type, $user_id, $title, $slug_source = '' ) {

		$postarr = array(
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_author' => (int) $user_id,
		);

		if ( '' !== $slug_source ) {
			$postarr['post_name'] = sanitize_title( $slug_source );
		}

		$id = self::internally(
			function () use ( $postarr ) {
				return wp_insert_post( $postarr, true );
			}
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'nexora_save_failed', 'Could not save.' );
		}

		update_post_meta( $id, '_wp_user_id', (int) $user_id );

		return (int) $id;
	}

	/**
	 * Writes business details (only the keys given) to a seller record.
	 *
	 * @param int   $vendor_id Seller record ID.
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
	 * Removes a seller record for good: the links go, the owner stops being a seller (status none, back to a
	 * customer) and, for a direct seller whose record was their only profile, a plain customer post is made so
	 * they keep a profile. This is the one deletion path (a failed application being rolled back, and, from 7b, the admin's).
	 *
	 * @param int $vendor_id Seller record ID.
	 */
	public static function delete( $vendor_id ) {

		$vendor_id = (int) $vendor_id;
		$type      = get_post_type( $vendor_id );
		$user_id   = self::user_for( $vendor_id );
		$owned     = $user_id && (int) get_user_meta( $user_id, self::USER_META, true ) === $vendor_id;

		if ( ! in_array( $type, self::types(), true ) ) {
			return;
		}

		$personal = array();

		if ( Post_Types::SELLER === $type && $user_id ) {
			foreach ( \Nexora\Profile\Fields::admin_editable() as $key ) {
				$personal[ $key ] = get_post_meta( $vendor_id, $key, true );
			}
		}

		if ( $user_id ) {
			Links::unlink( $vendor_id, $user_id );
		}

		self::internally(
			function () use ( $vendor_id ) {
				wp_delete_post( $vendor_id, true );
			}
		);

		if ( ! $owned || ! get_userdata( $user_id ) ) {
			return;
		}

		Account_Types::clear( $user_id );

		if ( Post_Types::SELLER === $type ) {
			\Nexora\Profile\Repository::create_customer( $user_id, $personal );
		}
	}
}
