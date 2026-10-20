<?php

namespace Nexora\Vendors;

use Nexora\Core\View;
use Nexora\Products\Repository as Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-only "Products" box on the admin edit screen of a seller record (either seller type): the products linked to the record,
 * 5 a page, newest first, with the page chosen by a GET parameter. One count query and one page query; nothing is changed here.
 */
class Products_Box {

	const PER_PAGE = 5;
	const PARAM    = 'nx_products_page';

	/**
	 * The box heading, with the number of products (every status except trash).
	 *
	 * @param int $total Number of products.
	 * @return string
	 */
	public static function title( $total ) {

		return sprintf(
			/* translators: %d: number of products the seller has */
			__( 'Products (%d)', 'nexora' ),
			(int) $total
		);
	}

	/**
	 * How many products a seller record has.
	 *
	 * @param int $seller_id Seller record ID.
	 * @return int
	 */
	public static function total( $seller_id ) {
		return Products::count_for_vendor( (int) $seller_id );
	}

	/**
	 * Prints the box. Prints nothing for anyone who may not manage the site.
	 *
	 * @param \WP_Post $post  The seller record.
	 * @param int|null $total Product count when the caller already has it (null: count here).
	 */
	public static function render( $post, $total = null ) {

		if ( ! current_user_can( 'manage_options' ) || ! $post instanceof \WP_Post || ! in_array( $post->post_type, Repository::types(), true ) ) {
			return;
		}

		$total = null === $total ? self::total( $post->ID ) : (int) $total;
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

		// The page number is only a position in this read-only list: whole number, never below 1, never past the last page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET[ self::PARAM ] ) ? absint( wp_unslash( $_GET[ self::PARAM ] ) ) : 1;
		$current = min( $pages, max( 1, $current ) );

		$rows = array();

		foreach ( $total ? Products::page_for_vendor( $post->ID, $current, self::PER_PAGE ) : array() as $product ) {
			$rows[] = array(
				'id'       => $product->ID,
				'edit_url' => admin_url( 'post.php?post=' . $product->ID . '&action=edit' ),
				'name'     => (string) $product->post_title,
				'sku'      => (string) get_post_meta( $product->ID, 'sku', true ),
				'price'    => (string) get_post_meta( $product->ID, 'price', true ),
				'stock'    => (string) get_post_meta( $product->ID, 'stock', true ),
			);
		}

		$links = '';

		if ( $pages > 1 ) {
			$links = (string) paginate_links(
				array(
					'base'         => admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit&' . self::PARAM . '=%#%' ),
					'format'       => '',
					'current'      => $current,
					'total'        => $pages,
					'prev_text'    => __( 'Previous', 'nexora' ),
					'next_text'    => __( 'Next', 'nexora' ),
					'add_fragment' => '#nexora_seller_products',
				)
			);
		}

		View::output(
			'admin/metabox-products',
			array(
				'rows'  => $rows,
				'links' => $links,
				'total' => $total,
			)
		);
	}
}
