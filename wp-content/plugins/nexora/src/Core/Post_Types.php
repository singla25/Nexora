<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The names of the three account record types, and the two lists the rest of the plugin asks for. Nothing else
 * spells these slugs: a place that needs "a member's profile" or "a seller's record" calls profiles() or sellers().
 *
 * - CUSTOMER: a customer's profile.
 * - SELLER: a member who registered directly as a seller (their personal and business details; it is their profile).
 * - CUST_SELLER: the business details of a customer who became a seller (linked to the customer's profile).
 *
 * WordPress limits a post type to 20 characters.
 */
class Post_Types {

	const CUSTOMER    = 'nexora_customer';
	const SELLER      = 'nexora_seller';
	const CUST_SELLER = 'nexora_cust_seller';

	/**
	 * The record types that can be a member's primary profile (what `_profile_id` points to).
	 *
	 * @return string[]
	 */
	public static function profiles() {
		return array( self::CUSTOMER, self::SELLER );
	}

	/**
	 * The record types that hold a seller's business details and status.
	 *
	 * @return string[]
	 */
	public static function sellers() {
		return array( self::SELLER, self::CUST_SELLER );
	}

	/**
	 * Every account record type.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::CUSTOMER, self::SELLER, self::CUST_SELLER );
	}

	/**
	 * Whether a post is a member profile (customer or direct seller).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_profile( $post_id ) {
		return $post_id && in_array( get_post_type( $post_id ), self::profiles(), true );
	}

	/**
	 * Whether a post is a seller record (direct seller or customer-seller).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_seller_record( $post_id ) {
		return $post_id && in_array( get_post_type( $post_id ), self::sellers(), true );
	}
}
