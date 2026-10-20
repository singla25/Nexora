<?php

namespace Nexora\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries and writes for the `nexora_product` posts. The author is the vendor's user; `vendor_id` is the vendor's
 * application post. Everything goes through Core\Post_Guard: only these methods (called by Service) may create,
 * change or delete a product.
 */
class Repository {

	use \Nexora\Core\Internal_Writes;

	const POST_TYPE = 'nexora_product';

	/**
	 * Creates a product.
	 *
	 * @param int    $user_id        Vendor's user ID (the author).
	 * @param int    $vendor_post_id Vendor's application post ID.
	 * @param array  $data           Validated fields (Validator).
	 * @param string $source         manual | csv | api.
	 * @param string $external_id    The id in the source system (imports).
	 * @param string $status         Post status (publish for a listed product).
	 * @return int|\WP_Error Product ID.
	 */
	public static function create( $user_id, $vendor_post_id, array $data, $source = 'manual', $external_id = '', $status = 'publish' ) {

		$id = self::internally(
			function () use ( $user_id, $data, $status ) {
				return wp_insert_post(
					array(
						'post_type'    => self::POST_TYPE,
						'post_status'  => $status,
						'post_title'   => $data['name'],
						'post_content' => isset( $data['description'] ) ? $data['description'] : '',
						'post_author'  => (int) $user_id,
					),
					true
				);
			}
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return is_wp_error( $id ) ? $id : new \WP_Error( 'nexora_save_failed', 'Could not save.' );
		}

		update_post_meta( $id, 'vendor_id', (int) $vendor_post_id );
		update_post_meta( $id, 'source', in_array( $source, array( 'manual', 'csv', 'api' ), true ) ? $source : 'manual' );
		update_post_meta( $id, 'external_id', (string) $external_id );
		self::write_meta( $id, $data );

		return (int) $id;
	}

	/**
	 * Updates a product's fields (only the keys given).
	 *
	 * @param int   $id   Product ID.
	 * @param array $data Validated fields.
	 */
	public static function update( $id, array $data ) {

		$post = array( 'ID' => (int) $id );

		if ( isset( $data['name'] ) ) {
			$post['post_title'] = $data['name'];
		}

		if ( isset( $data['description'] ) ) {
			$post['post_content'] = $data['description'];
		}

		if ( count( $post ) > 1 ) {
			self::internally(
				function () use ( $post ) {
					wp_update_post( $post );
				}
			);
		}

		self::write_meta( $id, $data );
	}

	/**
	 * Sets a product's status (publish lists it in the marketplace, draft hides it).
	 *
	 * @param int    $id     Product ID.
	 * @param string $status publish | draft.
	 */
	public static function set_status( $id, $status ) {

		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			return;
		}

		self::internally(
			function () use ( $id, $status ) {
				wp_update_post(
					array(
						'ID'          => (int) $id,
						'post_status' => $status,
					)
				);
			}
		);
	}

	/**
	 * Deletes a product for good (past orders keep their own snapshot).
	 *
	 * @param int $id Product ID.
	 */
	public static function delete( $id ) {

		self::internally(
			function () use ( $id ) {
				wp_delete_post( (int) $id, true );
			}
		);
	}

	/**
	 * Everything stored about a product.
	 *
	 * @param int $id Product ID.
	 * @return array
	 */
	public static function data( $id ) {

		return array(
			// The stored title, not get_the_title(): that one runs display filters (curly quotes) and would never equal what was saved.
			'name'        => (string) get_post_field( 'post_title', $id ),
			'description' => (string) get_post_field( 'post_content', $id ),
			'price'       => (string) get_post_meta( $id, 'price', true ),
			'stock'       => (int) get_post_meta( $id, 'stock', true ),
			'sku'         => (string) get_post_meta( $id, 'sku', true ),
			'category'    => (string) get_post_meta( $id, 'category', true ),
			'image_id'    => (int) get_post_meta( $id, 'image_id', true ),
			'image_url'   => (string) get_post_meta( $id, 'image_url', true ),
			'vendor_id'   => (int) get_post_meta( $id, 'vendor_id', true ),
			'source'      => (string) get_post_meta( $id, 'source', true ),
			'external_id' => (string) get_post_meta( $id, 'external_id', true ),
		);
	}

	/**
	 * The user who owns a product (0 when the ID is not a product).
	 *
	 * @param int $id Product ID.
	 * @return int
	 */
	public static function owner_of( $id ) {
		return get_post_type( $id ) === self::POST_TYPE ? (int) get_post_field( 'post_author', $id ) : 0;
	}

	/**
	 * The vendor application post a product belongs to (0 when the ID is not a product).
	 *
	 * @param int $id Product ID.
	 * @return int
	 */
	public static function vendor_of( $id ) {
		return get_post_type( $id ) === self::POST_TYPE ? (int) get_post_meta( $id, 'vendor_id', true ) : 0;
	}

	/**
	 * The product a vendor already has under a SKU (any status).
	 *
	 * @param int    $vendor_post_id Vendor application post ID.
	 * @param string $sku            SKU.
	 * @param int    $exclude        Product to ignore (the one being edited).
	 * @return int Product ID, or 0.
	 */
	public static function find_by_sku( $vendor_post_id, $sku, $exclude = 0 ) {

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'post__not_in'     => $exclude ? array( (int) $exclude ) : array(),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a lookup by vendor and SKU.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => 'vendor_id',
						'value' => (int) $vendor_post_id,
					),
					array(
						'key'   => 'sku',
						'value' => strtoupper( trim( (string) $sku ) ),
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The product a vendor already imported from a source under an external id.
	 *
	 * @param int    $vendor_post_id Vendor application post ID.
	 * @param string $external_id    The id in the source system.
	 * @param string $source         Source (api).
	 * @return int Product ID, or 0.
	 */
	public static function find_by_external( $vendor_post_id, $external_id, $source = 'api' ) {

		if ( '' === (string) $external_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a lookup by vendor, source and external id.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => 'vendor_id',
						'value' => (int) $vendor_post_id,
					),
					array(
						'key'   => 'source',
						'value' => $source,
					),
					array(
						'key'   => 'external_id',
						'value' => (string) $external_id,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Whether a URL may be used as a product image without being uploaded: https, no credentials or port, plain
	 * characters; and, when the import knows where the data came from, only on that source's own domain (or a subdomain,
	 * such as its CDN). Nothing is ever downloaded.
	 *
	 * @param string      $url         Image URL.
	 * @param string|null $source_host The host of the API the product came from (null when only the shape is checked,
	 *                                 e.g. when an already imported product is shown).
	 * @return bool
	 */
	public static function external_image_ok( $url, $source_host = null ) {

		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 300 || preg_match( '/[\x00-\x20\\\\]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		if ( is_wp_error( \Nexora\Imports\Url_Guard::syntax( 'https://' . $host . '/' ) ) ) {
			return false;
		}

		return null === $source_host || \Nexora\Imports\Url_Guard::on_list( $host, array( $source_host ) );
	}

	/**
	 * How many products a vendor has (any status).
	 *
	 * @param int $vendor_post_id Vendor application post ID.
	 * @return int
	 */
	public static function count_for_vendor( $vendor_post_id ) {

		$query = new \WP_Query(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one count per save.
				'meta_query'       => array(
					array(
						'key'   => 'vendor_id',
						'value' => (int) $vendor_post_id,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * A vendor's products, newest first.
	 *
	 * @param int $vendor_post_id Vendor application post ID.
	 * @param int $limit          Most to return.
	 * @return int[]
	 */
	public static function ids_for_vendor( $vendor_post_id, $limit = 200 ) {

		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
					'fields'           => 'ids',
					'posts_per_page'   => (int) $limit,
					'orderby'          => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'    => true,
					'suppress_filters' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one list per profile view.
					'meta_query'       => array(
						array(
							'key'   => 'vendor_id',
							'value' => (int) $vendor_post_id,
						),
					),
				)
			)
		);
	}

	/**
	 * One page of a vendor's products (every status except trash), newest first. Returns the posts themselves with their meta
	 * already loaded in one go, so showing them costs no query per row.
	 *
	 * @param int $vendor_post_id Vendor application post ID.
	 * @param int $page           Page number from 1.
	 * @param int $per_page       Products per page.
	 * @return \WP_Post[]
	 */
	public static function page_for_vendor( $vendor_post_id, $page, $per_page ) {

		$query = new \WP_Query(
			array(
				'post_type'           => self::POST_TYPE,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'      => max( 1, (int) $per_page ),
				'paged'               => max( 1, (int) $page ),
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one page of one vendor's products.
				'meta_query'          => array(
					array(
						'key'   => 'vendor_id',
						'value' => (int) $vendor_post_id,
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Writes the meta fields.
	 *
	 * @param int   $id   Product ID.
	 * @param array $data Validated fields.
	 */
	private static function write_meta( $id, array $data ) {

		foreach ( array( 'price', 'stock', 'sku', 'category', 'image_id' ) as $key ) {

			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, $key, $data[ $key ] );
			}
		}

		if ( isset( $data['image_url'] ) && self::external_image_ok( $data['image_url'] ) ) {
			update_post_meta( $id, 'image_url', esc_url_raw( $data['image_url'] ) );
		}
	}
}
