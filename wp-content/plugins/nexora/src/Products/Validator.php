<?php

namespace Nexora\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation and sanitising of a product. ONE class for every way a product can arrive (the manual form, the
 * CSV import and the API import), so all three enforce identical rules. Pure: it writes nothing and does not
 * know who is asking (ownership of an image, SKU uniqueness and the like belong to the Service).
 */
class Validator {

	const NAME_MAX        = 120;
	const DESCRIPTION_MAX = 2000;
	const PRICE_MIN       = 0.01;
	const PRICE_MAX       = 1000000;
	const STOCK_MAX       = 100000;

	/**
	 * Validates a submission.
	 *
	 * @param array         $raw  Unslashed input (extra keys are ignored).
	 * @param string[]|null $keys Fields to check (null = all of them).
	 * @return array { data: valid cleaned fields, errors: field => message }
	 */
	public static function validate( array $raw, $keys = null ) {

		$keys   = null === $keys ? Fields::keys() : array_values( array_intersect( Fields::keys(), (array) $keys ) );
		$data   = array();
		$errors = array();

		foreach ( $keys as $key ) {

			$value  = self::scalar( isset( $raw[ $key ] ) ? $raw[ $key ] : '' );
			$result = self::check( $key, $value );

			if ( is_string( $result ) ) {
				$errors[ $key ] = $result;
			} else {
				$data[ $key ] = $result[0];
			}
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Strings and plain numbers only (CSV gives strings, a JSON API gives numbers); anything else is empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function scalar( $value ) {

		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? (string) $value : '';
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Cleans one value and checks it.
	 *
	 * @param string $key   Field name.
	 * @param string $value Raw string.
	 * @return string|array An error message, or array( cleaned value ).
	 */
	private static function check( $key, $value ) {

		$required = __( 'This field is required.', 'nexora' );

		switch ( $key ) {

			case 'name':
				$value = sanitize_text_field( $value );
				$len   = mb_strlen( $value );

				if ( '' === $value ) {
					return $required;
				}

				return ( $len < 2 || $len > self::NAME_MAX ) ? __( 'Product name must be 2-120 characters.', 'nexora' ) : array( $value );

			case 'description':
				$value = trim( sanitize_textarea_field( $value ) );

				return mb_strlen( $value ) > self::DESCRIPTION_MAX ? __( 'Description can be at most 2,000 characters.', 'nexora' ) : array( $value );

			case 'price':
				$value = trim( $value );

				if ( '' === $value ) {
					return $required;
				}

				if ( ! preg_match( '/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/', $value ) || (float) $value < self::PRICE_MIN || (float) $value > self::PRICE_MAX ) {
					return __( 'Price must be between 0.01 and 1,000,000 (at most two decimals).', 'nexora' );
				}

				return array( number_format( (float) $value, 2, '.', '' ) );

			case 'stock':
				$value = trim( $value );

				if ( '' === $value ) {
					return $required;
				}

				if ( ! preg_match( '/^[0-9]{1,6}$/', $value ) || (int) $value > self::STOCK_MAX ) {
					return __( 'Stock must be a whole number from 0 to 100,000.', 'nexora' );
				}

				return array( (int) $value );

			case 'sku':
				$value = strtoupper( trim( sanitize_text_field( $value ) ) );

				if ( '' === $value ) {
					return $required;
				}

				return preg_match( '/^[A-Z0-9][A-Z0-9._-]{1,39}$/', $value ) ? array( $value ) : __( 'SKU must be 2-40 characters: letters, numbers, dot, dash or underscore.', 'nexora' );

			case 'category':
				$value = trim( $value );

				if ( '' === $value ) {
					return $required;
				}

				return isset( Fields::categories()[ $value ] ) ? array( $value ) : __( 'Choose a category from the list.', 'nexora' );

			case 'image_id':
				$value = trim( $value );

				if ( '' === $value ) {
					return array( 0 );
				}

				return preg_match( '/^[0-9]{1,9}$/', $value ) ? array( (int) $value ) : __( 'Invalid image.', 'nexora' );
		}

		return __( 'Invalid value.', 'nexora' );
	}
}
