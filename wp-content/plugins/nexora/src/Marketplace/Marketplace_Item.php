<?php

namespace Nexora\Marketplace;

use Nexora\Core\Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one hidden WooCommerce product every marketplace purchase goes through. A cart line is this product plus the id of
 * the real product post; the real price, name and vendor are read from that post, never from this item. It is physical (so
 * WooCommerce asks for a shipping address and charges shipping), costs 0, is tax-free and is hidden from the shop, search,
 * the Store API product lists, the sitemaps and its own address. It is created once, kept in an option, repaired if edited,
 * restored if trashed and recreated if deleted. Only administrators can touch it.
 */
class Marketplace_Item {

	const OPTION = 'nexora_marketplace_item_id';
	const META   = '_nexora_marketplace_item';
	const LOCK   = 'nexora-marketplace-item';

	/**
	 * Hooks the hiding, the protection and the self-healing.
	 */
	public function __construct() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect' ), 10, 4 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'hide_from_sitemap' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_rest_lists' ) );
		add_action( 'deleted_post', array( __CLASS__, 'after_delete' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'heal' ) );
	}

	/**
	 * The Marketplace Item's product id: found, repaired, restored or created as needed.
	 *
	 * @return int 0 when it cannot be had right now (WooCommerce missing, or another request is creating it).
	 */
	public static function id() {

		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$id = (int) get_option( self::OPTION );

		if ( $id && self::flagged( $id ) ) {
			return self::repair( $id );
		}

		$found = self::find();

		if ( $found ) {
			update_option( self::OPTION, $found );
			return self::repair( $found );
		}

		if ( ! Lock::take( self::LOCK ) ) {
			return 0;
		}

		try {
			// Another request may have finished while we waited for the lock.
			$found = self::find();
			$id    = $found ? $found : self::create();
		} finally {
			Lock::release( self::LOCK );
		}

		if ( $id ) {
			update_option( self::OPTION, $id );
		}

		return $id;
	}

	/**
	 * Whether an id is the Marketplace Item.
	 *
	 * @param mixed $id Product id.
	 * @return bool
	 */
	public static function is_item( $id ) {

		if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
			return false;
		}

		$id = (int) $id;

		return $id > 0 && (int) get_option( self::OPTION ) === $id;
	}

	/**
	 * Nobody but an administrator may edit, delete or publish it (vendors have no business with it).
	 *
	 * @param string[] $caps    Required capabilities.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User ID.
	 * @param array    $args    Arguments; the first is the post ID for post capabilities.
	 * @return string[]
	 */
	public static function protect( $caps, $cap, $user_id, $args ) {

		if ( in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) && ! empty( $args[0] ) && self::is_item( (int) $args[0] ) && ! user_can( $user_id, 'manage_options' ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Keeps it out of the core sitemap of products.
	 *
	 * @param array  $args      Query arguments.
	 * @param string $post_type Post type of the sitemap.
	 * @return array
	 */
	public static function hide_from_sitemap( $args, $post_type ) {

		$id = (int) get_option( self::OPTION );

		if ( 'product' === $post_type && $id ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $id ) );
		}

		return $args;
	}

	/**
	 * Keeps it out of product lists a visitor can read through the REST API (the Store API lists hidden products unless asked not to).
	 *
	 * @param \WP_Query $query The query about to run.
	 */
	public static function exclude_from_rest_lists( $query ) {

		$id = (int) get_option( self::OPTION );

		if ( ! $id || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || self::META === $query->get( 'meta_key' ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		$types = (array) $query->get( 'post_type' );

		if ( in_array( 'product', $types, true ) ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), array( $id ) ) );
		}
	}

	/**
	 * Its own page answers 404.
	 */
	public static function maybe_404() {

		global $wp_query;

		$id = (int) get_option( self::OPTION );

		if ( $id && $wp_query instanceof \WP_Query && $wp_query->is_singular( 'product' ) && (int) $wp_query->get_queried_object_id() === $id ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Deleted for good: make a new one at once so the option never points at nothing.
	 *
	 * @param int $post_id Deleted post.
	 */
	public static function after_delete( $post_id ) {

		if ( (int) get_option( self::OPTION ) === (int) $post_id ) {
			delete_option( self::OPTION );
			self::id();
		}
	}

	/**
	 * Every request makes sure it is published (a trashed or drafted item is brought back).
	 */
	public static function heal() {

		if ( function_exists( 'wc_get_product' ) && 'publish' !== get_post_status( (int) get_option( self::OPTION ) ) ) {
			self::id();
		}
	}

	/**
	 * Whether a post is a Marketplace Item (carries the flag), trashed or not.
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	private static function flagged( $id ) {
		return get_post_type( $id ) === 'product' && '1' === (string) get_post_meta( $id, self::META, true );
	}

	/**
	 * An existing, not trashed item (another request may have created it).
	 *
	 * @return int
	 */
	private static function find() {

		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one lookup, only when the option is missing or stale.
				'meta_key'         => self::META,
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Creates the product.
	 *
	 * @return int
	 */
	private static function create() {

		$product = new \WC_Product_Simple();
		$product->set_name( 'Marketplace Item' );
		$product->set_slug( 'marketplace-item' );
		$product->set_status( 'publish' );
		$product->set_description( 'Internal product. Every marketplace purchase is booked against it; its price, name and seller come from the product post.' );
		self::shape( $product );
		$product->update_meta_data( self::META, '1' );

		return (int) $product->save();
	}

	/**
	 * Puts a found item right again (published, price 0, physical, tax-free, hidden) and saves only if something differed.
	 *
	 * @param int $id Product id.
	 * @return int The id.
	 */
	private static function repair( $id ) {

		if ( 'trash' === get_post_status( $id ) ) {
			wp_untrash_post( $id );
		}

		$product = wc_get_product( $id );

		if ( ! $product ) {
			return $id;
		}

		$before = wp_json_encode( self::fingerprint( $product ) );

		if ( 'publish' !== $product->get_status() ) {
			$product->set_status( 'publish' );
		}

		self::shape( $product );

		if ( wp_json_encode( self::fingerprint( $product ) ) !== $before || 'publish' !== get_post_status( $id ) ) {
			$product->save();
		}

		return $id;
	}

	/**
	 * The settings that make it what it is.
	 *
	 * @param \WC_Product $product The product.
	 */
	private static function shape( $product ) {

		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '0' );
		$product->set_sale_price( '' );
		$product->set_virtual( false );
		$product->set_downloadable( false );
		$product->set_tax_status( 'none' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_sold_individually( false );
		$product->set_reviews_allowed( false );
	}

	/**
	 * What shape() controls, to see whether anything changed.
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private static function fingerprint( $product ) {

		return array(
			$product->get_status(),
			$product->get_catalog_visibility(),
			(string) $product->get_regular_price(),
			(string) $product->get_sale_price(),
			$product->is_virtual(),
			$product->is_downloadable(),
			$product->get_tax_status(),
			$product->get_manage_stock(),
			$product->get_stock_status(),
			$product->is_sold_individually(),
			$product->get_reviews_allowed(),
		);
	}
}
