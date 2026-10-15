<?php

namespace Nexora\Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The business details a vendor gives. All of them are owner-only (and visible to administrators in
 * the admin screens): they are never part of the public profile data, `Privacy::script_data()` or any
 * list another member can load.
 */
class Fields {

	/**
	 * Every business field, in the order the forms show them.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array(
			'business_name',
			'business_type',
			'gstin',
			'business_email',
			'business_phone',
			'business_address',
			'business_city',
			'business_state',
			'business_pincode',
		);
	}

	/**
	 * Human labels for the fields (forms and error messages).
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'business_name'    => __( 'Business name', 'nexora' ),
			'business_type'    => __( 'Business type', 'nexora' ),
			'gstin'            => __( 'GSTIN', 'nexora' ),
			'business_email'   => __( 'Business email', 'nexora' ),
			'business_phone'   => __( 'Business phone', 'nexora' ),
			'business_address' => __( 'Address', 'nexora' ),
			'business_city'    => __( 'City', 'nexora' ),
			'business_state'   => __( 'State', 'nexora' ),
			'business_pincode' => __( 'Pincode', 'nexora' ),
		);
	}

	/**
	 * The fixed list of business types (slug => label).
	 *
	 * @return array
	 */
	public static function business_types() {
		return array(
			'sole_proprietorship' => __( 'Sole proprietorship', 'nexora' ),
			'partnership'         => __( 'Partnership', 'nexora' ),
			'llp'                 => __( 'LLP', 'nexora' ),
			'private_limited'     => __( 'Private limited', 'nexora' ),
			'other'               => __( 'Other', 'nexora' ),
		);
	}

	/**
	 * Fields a vendor may still edit once the application is decided (identity fields are locked).
	 *
	 * @return string[]
	 */
	public static function editable_after_review() {
		return array( 'business_email', 'business_phone', 'business_address', 'business_city', 'business_state', 'business_pincode' );
	}
}
