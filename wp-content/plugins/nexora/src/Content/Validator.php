<?php

namespace Nexora\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for a member's post (title and text): the member's form and an administrator's edit both use it.
 */
class Validator {

	/**
	 * Cleans and checks a title and a text.
	 *
	 * @param string $title       Unslashed title.
	 * @param string $description Unslashed text.
	 * @return array { data: title, description; errors: field => message }
	 */
	public static function validate( $title, $description ) {

		$title       = sanitize_text_field( (string) $title );
		$description = sanitize_textarea_field( (string) $description );
		$errors      = array();

		if ( '' === $title ) {
			$errors['title'] = __( 'Title is required', 'nexora' );
		}

		return array(
			'data'   => array(
				'title'       => $title,
				'description' => $description,
			),
			'errors' => $errors,
		);
	}
}
