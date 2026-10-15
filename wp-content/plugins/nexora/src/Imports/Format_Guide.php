<?php

namespace Nexora\Imports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The published "Nexora format": what a vendor's API must answer. The popup's field table and the downloadable sample come
 * from here, and a test feeds the sample through the real importer, so the guide cannot drift from the code.
 */
class Format_Guide {

	/**
	 * The fields of one product: name, required, type, note.
	 *
	 * @return array[]
	 */
	public static function fields() {

		return array(
			array( 'id', true, __( 'text or whole number', 'nexora' ), __( 'Your own identifier for the product (1-64 letters, numbers, . _ : -). Importing again updates the product with the same id.', 'nexora' ) ),
			array( 'name', true, __( 'text', 'nexora' ), __( '2-120 characters.', 'nexora' ) ),
			array( 'price', true, __( 'number', 'nexora' ), __( '0.01 to 1,000,000, at most two decimals, no currency symbol.', 'nexora' ) ),
			array( 'stock', true, __( 'whole number', 'nexora' ), __( '0 to 100,000.', 'nexora' ) ),
			array( 'category', true, __( 'text', 'nexora' ), __( 'One of the marketplace categories (its name or its slug).', 'nexora' ) ),
			array( 'sku', false, __( 'text', 'nexora' ), __( 'Your stock code, 2-40 letters, numbers, . _ -. Defaults to the id.', 'nexora' ) ),
			array( 'description', false, __( 'text', 'nexora' ), __( 'Plain text, up to 2,000 characters.', 'nexora' ) ),
			array( 'image', false, __( 'https address', 'nexora' ), __( 'A picture on your API\'s own domain (or a subdomain). It stays on your server. Anything else is ignored.', 'nexora' ) ),
		);
	}

	/**
	 * The rules around the fields, in plain words.
	 *
	 * @return string[]
	 */
	public static function rules() {

		return array(
			__( 'Answer a JSON object with a "products" list. Add "next" (the full https address of the next page, on the same host) when there are more pages.', 'nexora' ),
			__( 'https only, on a public address. Redirects are not followed.', 'nexora' ),
			__( 'At most 500 products, 10 pages, 2 MB per answer, and 10 seconds per request.', 'nexora' ),
			__( 'If your API needs a key, it is sent in a header you choose (for example X-API-Key) or as a Bearer token.', 'nexora' ),
		);
	}

	/**
	 * A small valid example.
	 *
	 * @return array
	 */
	public static function sample() {

		return array(
			'products' => array(
				array(
					'id'          => 'KURTA-001',
					'name'        => 'Cotton Kurta',
					'price'       => 1299.5,
					'stock'       => 25,
					'category'    => 'fashion',
					'sku'         => 'KURTA-001',
					'description' => 'Soft cotton, hand block printed.',
					'image'       => 'https://shop.example.com/images/kurta-001.jpg',
				),
				array(
					'id'       => 'MUG-7',
					'name'     => 'Ceramic Mug',
					'price'    => 249,
					'stock'    => 120,
					'category' => 'Home & Kitchen',
				),
			),
			'next'     => 'https://shop.example.com/api/products?page=2',
		);
	}

	/**
	 * The sample as pretty JSON text.
	 *
	 * @return string
	 */
	public static function sample_json() {
		return wp_json_encode( self::sample(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
}
