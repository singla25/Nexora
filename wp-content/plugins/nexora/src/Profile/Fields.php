<?php

namespace Nexora\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The profile meta keys, grouped the way the UI edits them. One list shared by the
 * member AJAX handlers, the profile page and the admin meta boxes.
 */
class Fields {

	/** Personal details a member edits (user_name and email are set at registration). */
	const PERSONAL = array( 'first_name', 'last_name', 'phone', 'gender', 'birthdate', 'linkedin_id', 'bio' );

	const ADDRESS = array( 'perm_address', 'perm_city', 'perm_state', 'perm_pincode', 'corr_address', 'corr_city', 'corr_state', 'corr_pincode' );

	const WORK = array( 'company_name', 'designation', 'company_email', 'company_phone', 'company_address' );

	/** Attachment-id fields. */
	const DOCUMENTS = array( 'profile_image', 'cover_image', 'aadhaar_card', 'driving_license', 'company_id_card' );

	/** Details only the owner may see (sent to the browser for the owner and nobody else). */
	public static function owner_only() {
		return array_merge( array( 'email', 'phone', 'gender', 'birthdate', 'linkedin_id' ), array( 'perm_address', 'perm_city', 'perm_state', 'perm_pincode', 'corr_address', 'corr_city', 'corr_state', 'corr_pincode' ), self::WORK );
	}

	/** Everything an administrator can edit on a user_profile post. */
	public static function admin_editable() {
		return array_merge( array( 'user_name', 'first_name', 'last_name', 'email', 'phone', 'linkedin_id', 'bio', 'gender', 'birthdate' ), self::ADDRESS, self::WORK, self::DOCUMENTS );
	}
}
