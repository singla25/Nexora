<?php

namespace Nexora\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for the personal and work details of a profile. ONE rule set for the member's own forms (Profile\Ajax) and for an
 * administrator's edit (Admin_Edit\Saver), so a value is judged the same way wherever it arrives. Pure: it writes nothing.
 */
class Validator {

	/**
	 * Cleans and checks one value.
	 *
	 * @param string $field Field name (a key of Fields::PERSONAL or Fields::WORK).
	 * @param string $value Unslashed text.
	 * @return string[]|string array( cleaned value ), or an error message.
	 */
	public static function check( $field, $value ) {

		$value = (string) $value;

		if ( 'bio' === $field ) {
			return array( sanitize_textarea_field( $value ) );
		}

		$value = sanitize_text_field( $value );

		if ( 'gender' === $field && ! in_array( $value, array( 'male', 'female', 'other', '' ), true ) ) {
			return __( 'Invalid gender', 'nexora' );
		}

		if ( 'birthdate' === $field && '' !== $value ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d', $value );

			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value || $dt > new \DateTime( 'today' ) ) {
				return __( 'Invalid date of birth', 'nexora' );
			}
		}

		if ( 'company_email' === $field && '' !== $value ) {
			$value = sanitize_email( $value );

			if ( ! is_email( $value ) ) {
				return __( 'Invalid company email', 'nexora' );
			}
		}

		return array( $value );
	}

	/**
	 * Checks several fields.
	 *
	 * @param array    $raw  Unslashed input (extra keys are ignored).
	 * @param string[] $keys Fields to check.
	 * @return array { data: field => cleaned value, errors: field => message }
	 */
	public static function validate( array $raw, array $keys ) {

		$data   = array();
		$errors = array();

		foreach ( $keys as $key ) {

			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$result = self::check( $key, is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '' );

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
}
