<?php

namespace Nexora\Accounts;

use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that links a user, their profile and their seller record. Everything that creates or repairs a
 * link calls link(); nothing else writes these keys.
 *
 * - Customer who became a seller (`nexora_cust_seller`): `customer_post_id` on the seller record, `seller_post_id`
 *   on the customer post, `_seller_post_id` (and the kept `_vendor_id`) on the user. `_profile_id` stays the customer post.
 * - Direct seller (`nexora_seller`): the seller post is the member's profile, so `_profile_id` and `_seller_post_id`
 *   (and `_vendor_id`) all point at it. There is no customer post.
 */
class Links {

	const CUSTOMER_LINK = 'customer_post_id'; // Post meta on a customer-seller record.
	const SELLER_LINK   = 'seller_post_id';   // Post meta on a customer post.
	const PROFILE_META  = '_profile_id';      // User meta: the primary profile.
	const VENDOR_META   = '_vendor_id';       // User meta kept from before the rename: the seller record.

	/**
	 * Writes every link for a seller record, from the record outward. Repeating it changes nothing.
	 *
	 * @param int $seller_id Seller record post ID (either seller type).
	 * @return bool False when the post is not a seller record or has no owner.
	 */
	public static function link( $seller_id ) {

		$seller_id = (int) $seller_id;
		$type      = get_post_type( $seller_id );
		$user_id   = (int) get_post_meta( $seller_id, '_wp_user_id', true );

		if ( ! in_array( $type, Post_Types::sellers(), true ) || ! $user_id ) {
			return false;
		}

		update_user_meta( $user_id, Account_Types::SELLER_META, $seller_id );
		update_user_meta( $user_id, self::VENDOR_META, $seller_id );

		if ( Post_Types::SELLER === $type ) {
			update_user_meta( $user_id, self::PROFILE_META, $seller_id );
			return true;
		}

		$customer_id = self::customer_for( $seller_id, $user_id );

		if ( $customer_id ) {
			update_post_meta( $seller_id, self::CUSTOMER_LINK, $customer_id );
			update_post_meta( $customer_id, self::SELLER_LINK, $seller_id );

			if ( ! (int) get_user_meta( $user_id, self::PROFILE_META, true ) ) {
				update_user_meta( $user_id, self::PROFILE_META, $customer_id );
			}
		}

		return true;
	}

	/**
	 * Removes the links to a seller record (it is being deleted, or the member stops being a seller).
	 *
	 * @param int $seller_id Seller record post ID.
	 * @param int $user_id   The owner.
	 */
	public static function unlink( $seller_id, $user_id ) {

		$seller_id = (int) $seller_id;
		$user_id   = (int) $user_id;

		$customer_id = (int) get_post_meta( $seller_id, self::CUSTOMER_LINK, true );

		if ( $customer_id && (int) get_post_meta( $customer_id, self::SELLER_LINK, true ) === $seller_id ) {
			delete_post_meta( $customer_id, self::SELLER_LINK );
		}

		if ( (int) get_user_meta( $user_id, Account_Types::SELLER_META, true ) === $seller_id ) {
			delete_user_meta( $user_id, Account_Types::SELLER_META );
		}

		if ( (int) get_user_meta( $user_id, self::VENDOR_META, true ) === $seller_id ) {
			delete_user_meta( $user_id, self::VENDOR_META );
		}
	}

	/**
	 * The customer post a customer-seller record belongs with: its own link if that is a customer post of the same
	 * user, otherwise the user's primary profile.
	 *
	 * @param int $seller_id Seller record ID.
	 * @param int $user_id   Owner.
	 * @return int Customer post ID, or 0.
	 */
	public static function customer_for( $seller_id, $user_id ) {

		$candidates = array(
			(int) get_post_meta( $seller_id, self::CUSTOMER_LINK, true ),
			(int) get_user_meta( $user_id, self::PROFILE_META, true ),
		);

		foreach ( $candidates as $id ) {
			if ( $id && Post_Types::CUSTOMER === get_post_type( $id ) && (int) get_post_meta( $id, '_wp_user_id', true ) === (int) $user_id ) {
				return $id;
			}
		}

		return 0;
	}
}
