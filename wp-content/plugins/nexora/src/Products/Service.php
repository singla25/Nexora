<?php

namespace Nexora\Products;

use Nexora\Accounts\Account_Types;
use Nexora\Core\Lock;
use Nexora\Vendors\Repository as Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The product rules: who may add, edit and delete what. Every way in (the manual form today, CSV and API
 * imports later) ends here, so the same checks always run: approved vendor, own product, validated fields,
 * own image, unique SKU per vendor, a per-vendor limit.
 */
class Service {

	/**
	 * Adds a product (product_id 0) or edits one of the caller's own.
	 *
	 * @param int   $user_id    The vendor's user ID (never taken from the request).
	 * @param array $raw        Unslashed form values (only the product fields are read).
	 * @param int   $product_id 0 to add, or the product to edit.
	 * @return array|\WP_Error array( 'product_id' => int )
	 */
	public static function save( $user_id, array $raw, $product_id = 0 ) {

		$user_id    = (int) $user_id;
		$product_id = (int) $product_id;
		$vendor_id  = self::vendor_for( $user_id );

		if ( ! $vendor_id || $product_id < 0 || ( $product_id > 0 && ! self::owns( $user_id, $vendor_id, $product_id ) ) ) {
			return self::forbidden();
		}

		$checked = Validator::validate( $raw );
		$errors  = $checked['errors'];
		$data    = $checked['data'];

		if ( ! isset( $errors['image_id'] ) && $data['image_id'] && ! self::owns_image( $user_id, $data['image_id'] ) ) {
			$errors['image_id'] = __( 'Choose one of your own uploaded images.', 'nexora' );
		}

		if ( $errors ) {
			return self::invalid( $errors );
		}

		$lock = $vendor_id . '|' . $data['sku'];

		if ( ! Lock::take( $lock ) ) {
			return new \WP_Error( 'nexora_busy', __( 'This product is being saved right now. Please try again in a moment.', 'nexora' ) );
		}

		$result = self::store( $user_id, $vendor_id, $product_id, $data );

		Lock::release( $lock );

		return $result;
	}

	/**
	 * Deletes one of the caller's own products.
	 *
	 * @param int $user_id    The vendor's user ID.
	 * @param int $product_id Product to delete.
	 * @return true|\WP_Error
	 */
	public static function delete( $user_id, $product_id ) {

		$user_id    = (int) $user_id;
		$product_id = (int) $product_id;
		$vendor_id  = self::vendor_for( $user_id );

		if ( ! $vendor_id || $product_id <= 0 || ! self::owns( $user_id, $vendor_id, $product_id ) ) {
			return self::forbidden();
		}

		Repository::delete( $product_id );

		return true;
	}

	/**
	 * The most products one vendor may have.
	 *
	 * @return int
	 */
	public static function max_products() {
		return max( 1, (int) apply_filters( 'nexora_max_products', 500 ) );
	}

	/**
	 * Does the checks that need the database, then writes.
	 *
	 * @param int   $user_id    Vendor's user ID.
	 * @param int   $vendor_id  Vendor's application post.
	 * @param int   $product_id 0 to add.
	 * @param array $data       Validated fields.
	 * @return array|\WP_Error
	 */
	private static function store( $user_id, $vendor_id, $product_id, array $data ) {

		if ( Repository::find_by_sku( $vendor_id, $data['sku'], $product_id ) ) {
			return self::invalid( array( 'sku' => __( 'You already have a product with this SKU.', 'nexora' ) ) );
		}

		if ( 0 === $product_id ) {

			if ( Repository::count_for_vendor( $vendor_id ) >= self::max_products() ) {
				return new \WP_Error( 'nexora_limit', __( 'You have reached the maximum number of products.', 'nexora' ) );
			}

			$id = Repository::create( $user_id, $vendor_id, $data );

			if ( is_wp_error( $id ) ) {
				return new \WP_Error( 'nexora_save_failed', __( 'Could not save the product. Please try again.', 'nexora' ) );
			}

			return array( 'product_id' => $id );
		}

		Repository::update( $product_id, $data );

		return array( 'product_id' => $product_id );
	}

	/**
	 * The caller's vendor application post, only if they may sell (approved status AND role).
	 *
	 * @param int $user_id User ID.
	 * @return int 0 when they may not sell.
	 */
	private static function vendor_for( $user_id ) {
		return $user_id && Account_Types::can_sell( $user_id ) ? Vendors::id_for_user( $user_id ) : 0;
	}

	/**
	 * Whether a product belongs to this vendor (both the author and the vendor post must match).
	 *
	 * @param int $user_id    User ID.
	 * @param int $vendor_id  Vendor application post.
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function owns( $user_id, $vendor_id, $product_id ) {
		return Repository::owner_of( $product_id ) === $user_id && Repository::vendor_of( $product_id ) === $vendor_id;
	}

	/**
	 * An image may only be one of the vendor's own uploads.
	 *
	 * @param int $user_id  User ID.
	 * @param int $image_id Attachment ID.
	 * @return bool
	 */
	private static function owns_image( $user_id, $image_id ) {
		return get_post_type( $image_id ) === 'attachment' && wp_attachment_is_image( $image_id ) && (int) get_post_field( 'post_author', $image_id ) === (int) $user_id;
	}

	/**
	 * The same refusal for every "not yours / not allowed" case (it says nothing about other vendors).
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
	}

	/**
	 * A WP_Error carrying per-field messages.
	 *
	 * @param array $fields Field => message.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return new \WP_Error( 'nexora_invalid', __( 'Please correct the highlighted fields.', 'nexora' ), array( 'fields' => $fields ) );
	}
}
