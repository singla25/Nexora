<?php

namespace Nexora\Admin_Edit;

use Nexora\Core\Admin_Gate;
use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the core fields (title, text, status) of the record types Post_Guard does not guard (customers, connections, member posts)
 * as they are during any wp-admin save, so WordPress's own boxes, Quick Edit and bulk edit cannot change them around the
 * validators. The Saver (the ACF fields) is the way to change them. A genuine administrator trash / restore is let through.
 */
class Core_Lock {

	/**
	 * Hooks the filter.
	 */
	public function __construct() {
		add_filter( 'wp_insert_post_data', array( $this, 'keep' ), 10, 2 );
	}

	/**
	 * The record types this lock covers.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array( Post_Types::CUSTOMER, 'user_connections', 'user_content' );
	}

	/**
	 * Puts the stored core fields back into a save of an existing record.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr The submitted post array.
	 * @return array
	 */
	public function keep( $data, $postarr ) {

		if ( empty( $postarr['ID'] ) || ! is_admin() || Saver::writing() || ! in_array( $data['post_type'], self::types(), true ) ) {
			return $data;
		}

		$id = (int) $postarr['ID'];

		if ( Admin_Gate::open( $id, 'trash' ) || Admin_Gate::open( $id, 'untrash' ) ) {
			return $data;
		}

		$current = get_post( $id );

		if ( $current && $current->post_type === $data['post_type'] ) {

			foreach ( array( 'post_status', 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
				$data[ $field ] = wp_slash( $current->$field );
			}
		}

		return $data;
	}
}
