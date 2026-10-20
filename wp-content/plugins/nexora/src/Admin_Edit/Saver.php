<?php

namespace Nexora\Admin_Edit;

use Nexora\Accounts\Account_Types;
use Nexora\Core\Admin_Gate;
use Nexora\Core\Post_Types;
use Nexora\Vendors\Repository as Sellers;
use Nexora\Vendors\Service as Seller_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An administrator's edit of a record, for every record type: check EVERY value with the same validators the front end uses, and only
 * if all of them pass write them, through the same Services and Repositories (so the same side effects run: a status change is the
 * approve / suspend / reactivate / reject logic, a price goes through the product Service, a GSTIN is checked for uniqueness under
 * its lock). Each field that really changed is written down in the change history. Nothing outside Fields::names( $type, 'edit' )
 * is ever written, whatever the request names.
 */
class Saver {

	/**
	 * True while this class itself is writing (Core_Lock lets those writes through).
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Whether the Saver is writing right now.
	 *
	 * @return bool
	 */
	public static function writing() {
		return self::$writing;
	}

	/**
	 * Saves what an edit-screen request carries (values keyed by ACF field key), but only if the Admin_Gate is open for this post.
	 *
	 * @param int $post_id The post being edited.
	 * @return string[]|\WP_Error The names of the fields that changed, or the refusal.
	 */
	public static function from_request( $post_id ) {

		$post_id = (int) $post_id;

		if ( ! Admin_Gate::open( $post_id, 'edit' ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		$type = get_post_type( $post_id );

		return self::save( $post_id, self::input_from_post( $type ) );
	}

	/**
	 * The submitted values of an edit screen, turned from ACF field keys into field names (unknown keys are dropped).
	 *
	 * @param string $type Record type.
	 * @return array
	 */
	public static function input_from_post( $type ) {

		// The nonce and the capability were checked by the caller (Admin_Gate); every value is cleaned by the validators.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw   = isset( $_POST['acf'] ) && is_array( $_POST['acf'] ) ? wp_unslash( $_POST['acf'] ) : array();
		$input = array();

		foreach ( $raw as $key => $value ) {

			$name = Fields::name_for_key( (string) $type, (string) $key );

			if ( '' !== $name && is_scalar( $value ) ) {
				$input[ $name ] = (string) $value;
			}
		}

		return $input;
	}

	/**
	 * Checks and saves.
	 *
	 * @param int   $post_id The record.
	 * @param array $input   Field name => unslashed value (names outside the editable ones are ignored).
	 * @return string[]|\WP_Error The names of the fields that changed (empty when nothing did), or the errors per field.
	 */
	public static function save( $post_id, array $input ) {

		$post_id = (int) $post_id;

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, Fields::types(), true ) ) {
			return new \WP_Error( 'nexora_not_found', __( 'Record not found.', 'nexora' ) );
		}

		$checked = self::validate( $post_id, $input );

		if ( $checked['errors'] ) {
			return new \WP_Error( 'nexora_invalid', __( 'Please correct the highlighted fields.', 'nexora' ), array( 'fields' => $checked['errors'] ) );
		}

		$current = self::current( $post_id );
		$changes = array();

		foreach ( $checked['values'] as $name => $new ) {

			$old = isset( $current[ $name ] ) ? (string) $current[ $name ] : '';

			if ( $old !== (string) $new ) {
				$changes[ $name ] = array( $old, (string) $new );
			}
		}

		if ( ! $changes ) {
			return array();
		}

		self::$writing = true;

		try {
			$done = self::write( $post, $changes, $checked['values'] );
		} finally {
			self::$writing = false;
		}

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		foreach ( $changes as $name => $pair ) {
			Audit::record( $post->post_type, $post_id, $name, $pair[0], $pair[1] );
		}

		return array_keys( $changes );
	}

	/**
	 * Checks the values that are present; writes nothing.
	 *
	 * @param int   $post_id The record.
	 * @param array $input   Field name => unslashed value.
	 * @return array { values: name => cleaned value (as text), errors: name => message }
	 */
	public static function validate( $post_id, array $input ) {

		$post  = get_post( (int) $post_id );
		$empty = array(
			'values' => array(),
			'errors' => array(),
		);

		if ( ! $post || ! in_array( $post->post_type, Fields::types(), true ) ) {
			return $empty;
		}

		$type   = $post->post_type;
		$input  = array_intersect_key( $input, array_flip( Fields::names( $type, 'edit' ) ) );
		$values = array();
		$errors = array();

		switch ( $type ) {

			case Post_Types::CUSTOMER:
				self::profile_fields( $input, array_diff( array_keys( $input ), array( 'profile_image', 'cover_image' ) ), $values, $errors );
				self::image_fields( $input, array( 'profile_image', 'cover_image' ), $values, $errors, true );
				break;

			case Post_Types::SELLER:
			case Post_Types::CUST_SELLER:
				self::profile_fields( $input, array_intersect( array( 'first_name', 'last_name', 'phone', 'gender', 'birthdate' ), array_keys( $input ) ), $values, $errors );
				self::business_fields( $post, $input, $values, $errors );
				self::seller_status( $post, $input, $values, $errors );
				break;

			case 'nexora_product':
				self::product_fields( $post, $input, $values, $errors );
				break;

			case 'user_connections':
				if ( isset( $input['status'] ) ) {
					if ( in_array( $input['status'], array( 'pending', 'accepted', 'rejected', 'removed' ), true ) ) {
						$values['status'] = $input['status'];
					} else {
						$errors['status'] = __( 'Choose pending, accepted, rejected or removed.', 'nexora' );
					}
				}
				break;

			case 'user_content':
				if ( isset( $input['title'] ) || isset( $input['description'] ) ) {

					$check = \Nexora\Content\Validator::validate(
						isset( $input['title'] ) ? $input['title'] : $post->post_title,
						isset( $input['description'] ) ? $input['description'] : $post->post_content
					);

					foreach ( array( 'title', 'description' ) as $name ) {
						if ( isset( $input[ $name ] ) ) {
							$values[ $name ] = $check['data'][ $name ];
						}
					}

					if ( isset( $input['title'] ) && isset( $check['errors']['title'] ) ) {
						$errors['title'] = $check['errors']['title'];
					}
				}
				break;
		}

		return array(
			'values' => array_diff_key( $values, $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * What is stored now, as text, for every field of the record (editable and read-only).
	 *
	 * @param int $post_id The record.
	 * @return array Name => value.
	 */
	public static function current( $post_id ) {

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		$out     = array();

		if ( ! $post || ! in_array( $post->post_type, Fields::types(), true ) ) {
			return $out;
		}

		foreach ( Fields::names( $post->post_type ) as $name ) {
			$value        = get_post_meta( $post_id, $name, true );
			$out[ $name ] = is_scalar( $value ) ? (string) $value : '';
		}

		if ( in_array( $post->post_type, array( Post_Types::CUSTOMER ), true ) ) {
			foreach ( array( 'profile_image', 'cover_image' ) as $name ) {
				$out[ $name ] = '' === $out[ $name ] ? '' : (string) (int) $out[ $name ];
			}
		}

		if ( in_array( $post->post_type, Post_Types::sellers(), true ) ) {
			$user_id       = Sellers::user_for( $post_id );
			$out['status'] = $user_id ? Account_Types::vendor_status( $user_id ) : '';
		}

		if ( 'nexora_product' === $post->post_type ) {
			$out['name']        = (string) $post->post_title;
			$out['description'] = (string) $post->post_content;
			$out['status']      = (string) $post->post_status;
			$out['stock']       = (string) (int) $out['stock'];
			$out['image_id']    = (string) (int) $out['image_id'];
		}

		if ( 'user_content' === $post->post_type ) {
			$out['title']       = (string) $post->post_title;
			$out['description'] = (string) $post->post_content;
		}

		return $out;
	}

	/**
	 * Personal, address and work details of a profile, through the member forms' own rules.
	 *
	 * @param array    $input  Submitted values.
	 * @param string[] $keys   Fields to check.
	 * @param array    $values Cleaned values (added to).
	 * @param array    $errors Messages (added to).
	 */
	private static function profile_fields( array $input, array $keys, array &$values, array &$errors ) {

		$result = \Nexora\Profile\Validator::validate( $input, array_values( $keys ) );

		$values = array_merge( $values, array_map( 'strval', $result['data'] ) );
		$errors = array_merge( $errors, $result['errors'] );
	}

	/**
	 * Image fields: empty (remove) or an image in the Media Library that is not a private document.
	 *
	 * @param array    $input  Submitted values.
	 * @param string[] $keys   Fields to check.
	 * @param array    $values Cleaned values (added to).
	 * @param array    $errors Messages (added to).
	 * @param bool     $is_public Whether the image is shown to other members (so a private document is refused).
	 */
	private static function image_fields( array $input, array $keys, array &$values, array &$errors, $is_public ) {

		foreach ( $keys as $name ) {

			if ( ! isset( $input[ $name ] ) ) {
				continue;
			}

			$value = trim( $input[ $name ] );

			if ( '' === $value || '0' === $value ) {
				$values[ $name ] = '';
				continue;
			}

			$id = preg_match( '/^[0-9]{1,9}$/', $value ) ? (int) $value : 0;

			if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
				$errors[ $name ] = __( 'Choose an image from the Media Library.', 'nexora' );
			} elseif ( $is_public && \Nexora\Profile\Private_Documents::is_private( $id ) ) {
				$errors[ $name ] = __( 'This file is a private document and cannot be used as a public image', 'nexora' );
			} else {
				$values[ $name ] = (string) $id;
			}
		}
	}

	/**
	 * A seller's business details: the Vendors validator, and a GSTIN no other live seller record (either type) has.
	 *
	 * @param \WP_Post $post   The seller record.
	 * @param array    $input  Submitted values.
	 * @param array    $values Cleaned values (added to).
	 * @param array    $errors Messages (added to).
	 */
	private static function business_fields( $post, array $input, array &$values, array &$errors ) {

		$keys = array_values( array_intersect( \Nexora\Vendors\Fields::keys(), array_keys( $input ) ) );

		if ( ! $keys ) {
			return;
		}

		$result = \Nexora\Vendors\Validator::validate( $input, $keys );
		$errors = array_merge( $errors, $result['errors'] );
		$values = array_merge( $values, array_map( 'strval', $result['data'] ) );

		if ( isset( $result['data']['gstin'] ) && (string) get_post_meta( $post->ID, 'gstin', true ) !== $result['data']['gstin'] && Sellers::find_by_gstin( $result['data']['gstin'], $post->ID ) ) {
			$errors['gstin'] = __( 'This GSTIN is already registered.', 'nexora' );
			unset( $values['gstin'] );
		}
	}

	/**
	 * A seller's status: the change must be one the decision buttons offer from the current status.
	 *
	 * @param \WP_Post $post   The seller record.
	 * @param array    $input  Submitted values.
	 * @param array    $values Cleaned values (added to).
	 * @param array    $errors Messages (added to).
	 */
	private static function seller_status( $post, array $input, array &$values, array &$errors ) {

		if ( ! isset( $input['status'] ) ) {
			return;
		}

		$user_id = Sellers::user_for( $post->ID );
		$now     = $user_id ? Account_Types::vendor_status( $user_id ) : '';
		$wanted  = $input['status'];

		if ( ! $user_id || 'trash' === $post->post_status || ! in_array( $wanted, array( 'pending', 'approved', 'rejected', 'suspended' ), true ) ) {
			$errors['status'] = __( 'Choose a status from the list.', 'nexora' );
			return;
		}

		if ( $wanted !== $now && '' === Seller_Service::action_for( $now, $wanted ) ) {
			$errors['status'] = __( 'That status change is not allowed.', 'nexora' );
			return;
		}

		$values['status'] = $wanted;
	}

	/**
	 * A product's fields through the Products validator, the status, and an image that really is an image.
	 *
	 * @param \WP_Post $post   The product.
	 * @param array    $input  Submitted values.
	 * @param array    $values Cleaned values (added to).
	 * @param array    $errors Messages (added to).
	 */
	private static function product_fields( $post, array $input, array &$values, array &$errors ) {

		$keys = array_values( array_intersect( \Nexora\Products\Fields::keys(), array_keys( $input ) ) );

		if ( $keys ) {

			$result = \Nexora\Products\Validator::validate( $input, $keys );
			$errors = array_merge( $errors, $result['errors'] );

			foreach ( $result['data'] as $name => $value ) {
				$values[ $name ] = (string) $value;
			}
		}

		if ( isset( $values['image_id'] ) && ! isset( $errors['image_id'] ) && (int) $values['image_id'] > 0 ) {

			$image = (int) $values['image_id'];

			if ( 'attachment' !== get_post_type( $image ) || ! wp_attachment_is_image( $image ) ) {
				$errors['image_id'] = __( 'Choose an image from the Media Library.', 'nexora' );
			}
		}

		if ( isset( $values['sku'] ) && \Nexora\Products\Repository::find_by_sku( \Nexora\Products\Repository::vendor_of( $post->ID ), $values['sku'], $post->ID ) ) {
			$errors['sku'] = __( 'This seller already has a product with this SKU.', 'nexora' );
		}

		if ( isset( $input['status'] ) ) {
			if ( in_array( $input['status'], array( 'publish', 'draft' ), true ) ) {
				$values['status'] = $input['status'];
			} else {
				$errors['status'] = __( 'Choose Published or Draft.', 'nexora' );
			}
		}
	}

	/**
	 * Writes the changes that passed validation, through the owners of the data.
	 *
	 * @param \WP_Post $post    The record.
	 * @param array    $changes Name => array( old, new ).
	 * @param array    $values  All cleaned values.
	 * @return true|\WP_Error
	 */
	private static function write( $post, array $changes, array $values ) {

		$id    = $post->ID;
		$types = $post->post_type;
		$new   = array_map(
			function ( $pair ) {
				return $pair[1];
			},
			$changes
		);

		if ( Post_Types::CUSTOMER === $types ) {
			self::meta_fields( $id, $new, array( 'profile_image', 'cover_image' ) );
			return true;
		}

		if ( in_array( $types, Post_Types::sellers(), true ) ) {

			$business = array_intersect_key( $new, array_flip( \Nexora\Vendors\Fields::keys() ) );
			$personal = array_diff_key( $new, $business, array( 'status' => 1 ) );

			if ( $personal ) {
				self::meta_fields( $id, $personal, array() );
			}

			if ( $business ) {

				$saved = Seller_Service::admin_save( $id, $business );

				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
			}

			if ( isset( $new['status'] ) ) {

				$action = Seller_Service::action_for( $changes['status'][0], $new['status'] );

				return Seller_Service::change_status( $id, $action );
			}

			return true;
		}

		if ( 'nexora_product' === $types ) {

			$data = array_intersect_key( $values, array_flip( array( 'name', 'description', 'price', 'stock', 'sku', 'category', 'image_id' ) ) );
			$data = array_intersect_key( $data, $new );

			foreach ( array( 'stock', 'image_id' ) as $int ) {
				if ( isset( $data[ $int ] ) ) {
					$data[ $int ] = (int) $data[ $int ];
				}
			}

			return \Nexora\Products\Service::admin_save( $id, $data, isset( $new['status'] ) ? $new['status'] : null );
		}

		if ( 'user_connections' === $types ) {
			\Nexora\Connections\Repository::set_status( $id, $new['status'] );
			return true;
		}

		if ( 'user_content' === $types ) {

			$post_data = array( 'ID' => $id );

			if ( isset( $new['title'] ) ) {
				$post_data['post_title'] = $new['title'];
			}

			if ( isset( $new['description'] ) ) {
				$post_data['post_content'] = $new['description'];
			}

			$result = wp_update_post( wp_slash( $post_data ), true );

			return is_wp_error( $result ) ? $result : true;
		}

		return true;
	}

	/**
	 * Writes meta fields of a profile-like record (an empty image removes the link).
	 *
	 * @param int      $id     Post ID.
	 * @param array    $values Name => value.
	 * @param string[] $images Names that hold an attachment ID.
	 */
	private static function meta_fields( $id, array $values, array $images ) {

		foreach ( $values as $name => $value ) {

			if ( in_array( $name, $images, true ) && '' === $value ) {
				delete_post_meta( $id, $name );
				continue;
			}

			update_post_meta( $id, $name, wp_slash( in_array( $name, $images, true ) ? (int) $value : $value ) );
		}
	}
}
