<?php

namespace Nexora\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a visitor may learn about a profile. The single place that decides who gets private data.
 *
 *   guest  - not logged in
 *   viewer - logged in, looking at somebody else's profile
 *   owner  - logged in, looking at their own profile
 */
class Privacy {

	const GUEST  = 'guest';
	const VIEWER = 'viewer';
	const OWNER  = 'owner';

	public static function role( $current_user_id, $owner_user_id ) {

		if ( ! is_user_logged_in() ) {
			return self::GUEST;
		}

		if ( (int) $current_user_id === (int) $owner_user_id ) {
			return self::OWNER;
		}

		return self::VIEWER;
	}

	/**
	 * Profile data handed to the browser (profilePageData.userData).
	 * Public fields for everybody; contact details, address and ID documents only for the owner.
	 */
	public static function script_data( $profile_id, $role ) {

		$data = array(
			'profile_id'    => $profile_id,
			'user_name'     => get_post_meta( $profile_id, 'user_name', true ),
			'first_name'    => get_post_meta( $profile_id, 'first_name', true ),
			'last_name'     => get_post_meta( $profile_id, 'last_name', true ),
			'bio'           => get_post_meta( $profile_id, 'bio', true ),
			'profile_image' => wp_get_attachment_url( (int) get_post_meta( $profile_id, 'profile_image', true ) ),
			'cover_image'   => wp_get_attachment_url( (int) get_post_meta( $profile_id, 'cover_image', true ) ),
		);

		if ( self::OWNER === $role && $profile_id ) {

			foreach ( Fields::owner_only() as $field ) {
				$data[ $field ] = get_post_meta( $profile_id, $field, true );
			}

			foreach ( Fields::DOCUMENTS as $doc ) {
				$doc_id = (int) get_post_meta( $profile_id, $doc, true );

				$data[ $doc . '_id' ] = $doc_id ? $doc_id : '';
				$data[ $doc ]         = $doc_id ? wp_get_attachment_url( $doc_id ) : '';
			}
		}

		return $data;
	}
}
