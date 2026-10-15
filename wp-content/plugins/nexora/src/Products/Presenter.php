<?php

namespace Nexora\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns products into what the templates print. Prepares data only (templates escape it). A card carries what a
 * buyer may see: name, short description, price, stock label, category, image and the seller's business NAME.
 * It never carries the seller's GSTIN, email, phone or address.
 */
class Presenter {

	/**
	 * Cards for several products with the caches primed, so the number of queries does not grow with the list.
	 *
	 * @param int[] $ids       Product IDs.
	 * @param int   $viewer_id Who is looking.
	 * @return array[]
	 */
	public static function cards( array $ids, $viewer_id ) {

		$ids = array_values( array_map( 'intval', $ids ) );

		if ( ! $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$vendors = array();
		$images  = array();

		foreach ( $ids as $id ) {
			$vendors[] = (int) get_post_meta( $id, 'vendor_id', true );
			$images[]  = (int) get_post_meta( $id, 'image_id', true );
		}

		$vendors = array_values( array_unique( array_filter( $vendors ) ) );
		$images  = array_values( array_unique( array_filter( $images ) ) );

		if ( $vendors ) {
			update_meta_cache( 'post', $vendors );
		}

		if ( $images ) {
			_prime_post_caches( $images, false, true );
		}

		return array_map(
			function ( $id ) use ( $viewer_id ) {
				return self::card( $id, $viewer_id );
			},
			$ids
		);
	}

	/**
	 * One product card.
	 *
	 * @param int $id        Product ID.
	 * @param int $viewer_id Who is looking.
	 * @return array
	 */
	public static function card( $id, $viewer_id ) {

		$id         = (int) $id;
		$stock      = (int) get_post_meta( $id, 'stock', true );
		$image_id   = (int) get_post_meta( $id, 'image_id', true );
		$category   = (string) get_post_meta( $id, 'category', true );
		$categories = Fields::categories();
		$vendor_id  = (int) get_post_meta( $id, 'vendor_id', true );

		return array(
			'id'          => $id,
			'name'        => (string) get_post_field( 'post_title', $id ),
			'description' => wp_html_excerpt( (string) get_post_field( 'post_content', $id ), 160, '…' ),
			'price'       => self::money( (float) get_post_meta( $id, 'price', true ) ),
			'stock'       => $stock,
			'stock_label' => self::stock_label( $stock ),
			'category'    => isset( $categories[ $category ] ) ? $categories[ $category ] : '',
			'image'       => self::image( $id, $image_id, 'medium' ),
			'seller'      => $vendor_id ? (string) get_post_meta( $vendor_id, 'business_name', true ) : '',
			'own'         => (int) $viewer_id > 0 && Repository::owner_of( $id ) === (int) $viewer_id,
		);
	}

	/**
	 * The vendor's own products as cards, each with the fields the edit popup needs.
	 *
	 * @param int $user_id  The vendor's user ID.
	 * @param int $vendor_id The vendor's application post ID.
	 * @return array[]
	 */
	public static function mine( $user_id, $vendor_id ) {

		$cards = self::cards( Repository::ids_for_vendor( $vendor_id ), $user_id );

		foreach ( $cards as &$card ) {

			$id   = $card['id'];
			$data = Repository::data( $id );

			$card['edit'] = array(
				'id'          => $id,
				'name'        => $data['name'],
				'description' => $data['description'],
				'price'       => $data['price'],
				'stock'       => $data['stock'],
				'sku'         => $data['sku'],
				'category'    => $data['category'],
				'image_id'    => $data['image_id'],
				'image_url'   => self::image( $id, $data['image_id'], 'thumbnail' ),
			);
			$card['sku']  = $data['sku'];
		}

		return $cards;
	}

	/**
	 * The image to show: the uploaded one, else an imported one from an allowed host.
	 *
	 * @param int    $id       Product ID.
	 * @param int    $image_id Attachment ID (0 when none).
	 * @param string $size     Image size.
	 * @return string URL, or ''.
	 */
	private static function image( $id, $image_id, $size ) {

		if ( $image_id ) {
			$url = (string) wp_get_attachment_image_url( $image_id, $size );

			if ( '' !== $url ) {
				return $url;
			}
		}

		$external = (string) get_post_meta( $id, 'image_url', true );

		return Repository::external_image_ok( $external ) ? $external : '';
	}

	/**
	 * A price as text: currency symbol, thousands separator and two decimals.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {

		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '₹';

		return $symbol . number_format( (float) $amount, 2, '.', ',' );
	}

	/**
	 * A short stock message.
	 *
	 * @param int $stock Units in stock.
	 * @return string
	 */
	private static function stock_label( $stock ) {

		if ( $stock <= 0 ) {
			return __( 'Out of stock', 'nexora' );
		}

		if ( $stock <= 5 ) {
			/* translators: %d: units left. */
			return sprintf( __( 'Only %d left', 'nexora' ), $stock );
		}

		return __( 'In stock', 'nexora' );
	}
}
