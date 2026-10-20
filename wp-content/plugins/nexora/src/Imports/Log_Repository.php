<?php

namespace Nexora\Imports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The import history: one private post per real import (dry runs are never recorded). Core\Post_Guard lets only this
 * class write them. A vendor reads only their own.
 */
class Log_Repository {

	use \Nexora\Core\Internal_Writes;

	const POST_TYPE  = 'nexora_import_log';
	const KEEP       = 50;
	const MAX_ERRORS = 50;

	/**
	 * Records an import.
	 *
	 * @param int   $user_id   The vendor's user ID.
	 * @param int   $vendor_id The vendor's application post ID.
	 * @param array $entry     source, label, created, updated, unchanged, skipped, errors [ line, ref, message ].
	 * @return int Log post ID (0 on failure).
	 */
	public static function record( $user_id, $vendor_id, array $entry ) {

		$source = 'api' === ( $entry['source'] ?? '' ) ? 'api' : 'csv';
		$label  = mb_substr( preg_replace( '/[\x00-\x1f]/', '', (string) ( $entry['label'] ?? '' ) ), 0, 120 );
		$errors = array();

		foreach ( array_slice( (array) ( $entry['errors'] ?? array() ), 0, self::MAX_ERRORS ) as $error ) {
			$errors[] = array(
				'line'    => (int) ( $error['line'] ?? 0 ),
				'ref'     => mb_substr( (string) ( $error['ref'] ?? '' ), 0, 40 ),
				'message' => mb_substr( (string) ( $error['message'] ?? '' ), 0, 200 ),
			);
		}

		$id = self::internally(
			function () use ( $user_id, $source, $label ) {
				return wp_insert_post(
					array(
						'post_type'   => self::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => ( 'api' === $source ? 'API import' : 'CSV import' ) . ( '' !== $label ? ' - ' . $label : '' ),
						'post_author' => (int) $user_id,
					)
				);
			}
		);

		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}

		update_post_meta( $id, 'vendor_id', (int) $vendor_id );
		update_post_meta( $id, 'source', $source );
		update_post_meta( $id, 'label', $label );

		foreach ( array( 'created', 'updated', 'unchanged', 'skipped' ) as $count ) {
			update_post_meta( $id, $count, (int) ( $entry[ $count ] ?? 0 ) );
		}

		update_post_meta( $id, 'errors', wp_slash( wp_json_encode( $errors ) ) );

		self::trim( $vendor_id );

		return (int) $id;
	}

	/**
	 * Deletes the whole import history of a vendor (the seller record is being deleted for good).
	 *
	 * @param int $vendor_id Vendor application post ID.
	 */
	public static function delete_all_for_vendor( $vendor_id ) {

		for ( $round = 0; $round < 20; $round++ ) {

			$ids = self::ids( (int) $vendor_id, 500 );

			if ( ! $ids ) {
				return;
			}

			foreach ( $ids as $id ) {
				self::internally(
					function () use ( $id ) {
						wp_delete_post( $id, true );
					}
				);
			}
		}
	}

	/**
	 * A vendor's history, newest first.
	 *
	 * @param int $vendor_id Vendor application post ID.
	 * @param int $limit     Most entries to return.
	 * @return array[]
	 */
	public static function for_vendor( $vendor_id, $limit = 10 ) {

		$out = array();

		foreach ( self::ids( $vendor_id, (int) $limit ) as $id ) {

			$errors = json_decode( (string) get_post_meta( $id, 'errors', true ), true );

			$out[] = array(
				'id'        => $id,
				'source'    => (string) get_post_meta( $id, 'source', true ),
				'label'     => (string) get_post_meta( $id, 'label', true ),
				'created'   => (int) get_post_meta( $id, 'created', true ),
				'updated'   => (int) get_post_meta( $id, 'updated', true ),
				'unchanged' => (int) get_post_meta( $id, 'unchanged', true ),
				'skipped'   => (int) get_post_meta( $id, 'skipped', true ),
				'errors'    => is_array( $errors ) ? $errors : array(),
				'date'      => (string) get_post_field( 'post_date_gmt', $id ),
			);
		}

		return $out;
	}

	/**
	 * Keeps only the newest entries of a vendor.
	 *
	 * @param int $vendor_id Vendor application post ID.
	 */
	private static function trim( $vendor_id ) {

		foreach ( array_slice( self::ids( $vendor_id, 500 ), self::KEEP ) as $old ) {
			self::internally(
				function () use ( $old ) {
					wp_delete_post( $old, true );
				}
			);
		}
	}

	/**
	 * Entry IDs of a vendor, newest first.
	 *
	 * @param int $vendor_id Vendor application post ID.
	 * @param int $limit     Most to return.
	 * @return int[]
	 */
	private static function ids( $vendor_id, $limit ) {

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'posts_per_page'   => $limit,
					'orderby'          => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the history of one vendor.
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
