<?php

namespace Nexora\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The fields of a product and the fixed category list.
 */
class Fields {

	const OPTION = 'nexora_product_categories';

	/**
	 * Every field a vendor (or an import) may set, in the order the forms show them.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( 'name', 'description', 'price', 'stock', 'sku', 'category', 'image_id' );
	}

	/**
	 * Human labels for the fields.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'name'        => __( 'Product name', 'nexora' ),
			'description' => __( 'Description', 'nexora' ),
			'price'       => __( 'Price', 'nexora' ),
			'stock'       => __( 'Stock', 'nexora' ),
			'sku'         => __( 'SKU', 'nexora' ),
			'category'    => __( 'Category', 'nexora' ),
			'image_id'    => __( 'Image', 'nexora' ),
		);
	}

	/**
	 * The default categories (slug => label).
	 *
	 * @return array
	 */
	public static function default_categories() {
		return array(
			'electronics'  => __( 'Electronics', 'nexora' ),
			'fashion'      => __( 'Fashion', 'nexora' ),
			'home-kitchen' => __( 'Home & Kitchen', 'nexora' ),
			'beauty'       => __( 'Beauty', 'nexora' ),
			'groceries'    => __( 'Groceries', 'nexora' ),
			'books'        => __( 'Books', 'nexora' ),
			'toys'         => __( 'Toys', 'nexora' ),
			'sports'       => __( 'Sports', 'nexora' ),
			'other'        => __( 'Other', 'nexora' ),
		);
	}

	/**
	 * The category list: one label per line in Nexora System > Settings (the slug is made from the label),
	 * or the defaults when the setting is empty. "Other" is always kept as the catch-all.
	 *
	 * @return array slug => label.
	 */
	public static function categories() {

		$list = array();

		foreach ( preg_split( '/\R/', (string) get_option( self::OPTION, '' ) ) as $line ) {

			$label = sanitize_text_field( $line );
			$slug  = sanitize_title( $label );

			if ( '' !== $label && '' !== $slug ) {
				$list[ $slug ] = $label;
			}
		}

		if ( ! $list ) {
			$list = self::default_categories();
		}

		if ( ! isset( $list['other'] ) ) {
			$list['other'] = __( 'Other', 'nexora' );
		}

		/**
		 * The product categories (slug => label).
		 *
		 * @param array $list Categories.
		 */
		return (array) apply_filters( 'nexora_product_categories', $list );
	}

	/**
	 * Adds categories to the list in the setting (an import asked for them). Existing ones are never duplicated or changed;
	 * an empty setting starts from the defaults so they are kept; the list never grows past 50.
	 *
	 * @param array $additions slug => label, as made by the importer (the slug is what the label turns into).
	 * @return int How many were added.
	 */
	public static function add_categories( array $additions ) {

		$lines = array();

		foreach ( preg_split( '/\R/', (string) get_option( self::OPTION, '' ) ) as $line ) {

			$label = sanitize_text_field( $line );

			if ( '' !== $label && '' !== sanitize_title( $label ) ) {
				$lines[ sanitize_title( $label ) ] = $label;
			}
		}

		if ( ! $lines ) {
			$lines = self::default_categories();
		}

		$added = 0;

		foreach ( $additions as $label ) {

			$label = sanitize_text_field( (string) $label );
			$slug  = sanitize_title( $label );

			if ( '' === $slug || isset( $lines[ $slug ] ) || count( $lines ) >= 50 ) {
				continue;
			}

			$lines[ $slug ] = $label;
			++$added;
		}

		if ( $added ) {
			update_option( self::OPTION, implode( "\n", $lines ) );
		}

		return $added;
	}
}
