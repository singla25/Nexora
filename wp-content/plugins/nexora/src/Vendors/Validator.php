<?php

namespace Nexora\Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side validation and sanitising of the business details. Pure: it writes nothing.
 * GSTIN is checked for format only (no live lookup); uniqueness is the Service's job.
 */
class Validator {

	const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';

	/**
	 * The business fields of the current request, unslashed (Validator::validate() sanitises them).
	 * Callers have already verified their nonce.
	 *
	 * @return array
	 */
	public static function read_post() {

		$raw = array();

		foreach ( Fields::keys() as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the caller; every value is sanitised in validate().
			$raw[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		}

		return $raw;
	}

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

			$value = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? $raw[ $key ] : '';
			$value = self::clean( $key, $value );
			$error = self::check( $key, $value );

			if ( '' !== $error ) {
				$errors[ $key ] = $error;
			} else {
				$data[ $key ] = $value;
			}
		}

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Strips markup and normalises one value.
	 *
	 * @param string $key   Field name.
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean( $key, $value ) {

		// Also flattens line breaks, so a value can never inject a mail header.
		$value = sanitize_text_field( $value );

		if ( 'gstin' === $key ) {
			return strtoupper( $value );
		}

		if ( 'business_email' === $key ) {
			return sanitize_email( $value );
		}

		if ( 'business_phone' === $key ) {
			return preg_replace( '/[\s\-()]/', '', $value );
		}

		return $value;
	}

	/**
	 * Error message for one cleaned value, or '' when it is fine.
	 *
	 * @param string $key   Field name.
	 * @param string $value Cleaned value.
	 * @return string
	 */
	private static function check( $key, $value ) {

		if ( '' === $value ) {
			return __( 'This field is required.', 'nexora' );
		}

		$length = mb_strlen( $value );

		switch ( $key ) {
			case 'business_name':
				return ( $length < 2 || $length > 100 ) ? __( 'Business name must be 2-100 characters.', 'nexora' ) : '';

			case 'business_type':
				return isset( Fields::business_types()[ $value ] ) ? '' : __( 'Choose a business type from the list.', 'nexora' );

			case 'gstin':
				return preg_match( self::GSTIN_PATTERN, $value ) ? '' : __( 'Enter a valid 15-character GSTIN.', 'nexora' );

			case 'business_email':
				return ( is_email( $value ) && $length <= 100 ) ? '' : __( 'Enter a valid business email address.', 'nexora' );

			case 'business_phone':
				return preg_match( '/^\+?[0-9]{10,13}$/', $value ) ? '' : __( 'Enter a valid business phone number.', 'nexora' );

			case 'business_address':
				return ( $length < 5 || $length > 200 ) ? __( 'Address must be 5-200 characters.', 'nexora' ) : '';

			case 'business_city':
			case 'business_state':
				return ( $length < 2 || $length > 60 ) ? __( 'Enter 2-60 characters.', 'nexora' ) : '';

			case 'business_pincode':
				return preg_match( '/^[1-9][0-9]{5}$/', $value ) ? '' : __( 'Enter a valid 6-digit pincode.', 'nexora' );
		}

		return __( 'Invalid value.', 'nexora' );
	}
}
