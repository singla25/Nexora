<?php

namespace Nexora\Products;

use Nexora\Vendors\Repository as Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The marketplace listing: published products of approved vendors, with search, category filter and pagination.
 * A product is listed only while its vendor is approved, so suspending a vendor hides their products at once.
 */
class Listing {

	const PER_PAGE = 12;
	const MAX_PER  = 48;

	/**
	 * The vendors whose products may be listed.
	 *
	 * @return int[] Vendor application post IDs.
	 */
	public static function approved_vendor_ids() {

		/**
		 * The vendor posts whose published products are listed.
		 *
		 * @param int[] $ids Approved vendors (the built-in Platform vendor joins in a later phase).
		 */
		return array_map( 'intval', (array) apply_filters( 'nexora_listed_vendor_ids', Vendors::approved_ids() ) );
	}

	/**
	 * Runs a listing query.
	 *
	 * @param array $args search (text), category (a slug from the list), page, per_page. Anything else is ignored.
	 * @return array ids, total, pages, page.
	 */
	public static function query( array $args ) {

		$search   = isset( $args['search'] ) && is_string( $args['search'] ) ? mb_substr( trim( sanitize_text_field( $args['search'] ) ), 0, 80 ) : '';
		$category = isset( $args['category'] ) && is_string( $args['category'] ) && isset( Fields::categories()[ $args['category'] ] ) ? $args['category'] : '';
		$page     = self::positive( isset( $args['page'] ) ? $args['page'] : 1, 1 );
		$per_page = min( self::MAX_PER, self::positive( isset( $args['per_page'] ) ? $args['per_page'] : self::PER_PAGE, self::PER_PAGE ) );
		$vendors  = self::approved_vendor_ids();

		if ( ! $vendors ) {
			return self::result( array(), 0, 0, 1 );
		}

		$meta = array(
			'relation' => 'AND',
			array(
				'key'     => 'vendor_id',
				'value'   => $vendors,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			),
		);

		if ( '' !== $category ) {
			$meta[] = array(
				'key'   => 'category',
				'value' => $category,
			);
		}

		$query_args = array(
			'post_type'              => Repository::POST_TYPE,
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			's'                      => $search,
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the listing filters on vendor and category.
			'meta_query'             => $meta,
		);

		$query = new \WP_Query( $query_args );
		$pages = (int) $query->max_num_pages;

		// A page past the end shows the last one (WordPress reports no total for an empty page, so ask page 1 first).
		if ( ! $query->posts && $page > 1 ) {
			$query_args['paged'] = 1;
			$first               = new \WP_Query( $query_args );
			$pages               = (int) $first->max_num_pages;
			$page                = max( 1, $pages );
			$query_args['paged'] = $page;
			$query               = $page > 1 ? new \WP_Query( $query_args ) : $first;
		}

		return self::result( array_map( 'intval', $query->posts ), (int) $query->found_posts, $pages, $page );
	}

	/**
	 * Shapes the return value.
	 *
	 * @param int[] $ids   Product IDs.
	 * @param int   $total Matching products.
	 * @param int   $pages Number of pages.
	 * @param int   $page  Current page.
	 * @return array
	 */
	private static function result( array $ids, $total, $pages, $page ) {

		return array(
			'ids'   => $ids,
			'total' => $total,
			'pages' => $pages,
			'page'  => max( 1, $page ),
		);
	}

	/**
	 * A positive integer from request input; anything else (arrays, text, negatives) gives the default.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $fallback Value to use when the input is not a positive whole number.
	 * @return int
	 */
	private static function positive( $value, $fallback ) {

		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return min( (int) $value, 100000 );
		}

		if ( is_int( $value ) && $value > 0 ) {
			return min( $value, 100000 );
		}

		return $fallback;
	}
}
