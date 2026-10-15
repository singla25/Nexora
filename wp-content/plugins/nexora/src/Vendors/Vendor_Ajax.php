<?php

namespace Nexora\Vendors;

use Nexora\Accounts\Account_Types;
use Nexora\Http\Member_Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for every endpoint that sells or reads sales (products, imports, analytics...).
 *
 * On top of the member guard (nonce, login, profile) it re-checks, on the server, who the member is: hiding a tab
 * never protects anything. A refusal is a JSON error with code "forbidden" and a message that says nothing about
 * other vendors. Use seller() for approved vendors only (status approved AND the vendor role), earnings_viewer()
 * for approved or suspended vendors (suspended ones read their history, nothing else) and require_owner() when the
 * object being touched must belong to the caller. Handlers take the vendor from the return value, never from the
 * request.
 */
abstract class Vendor_Ajax extends Member_Ajax {

	/**
	 * Member guard + approved vendor. Ends the request with a forbidden error otherwise.
	 *
	 * @return array user_id, profile_id and vendor_id (the caller's own application).
	 */
	protected function seller() {

		$member = $this->member();

		if ( ! Account_Types::can_sell( $member['user_id'] ) ) {
			$this->forbid();
		}

		$member['vendor_id'] = Repository::id_for_user( $member['user_id'] );

		return $member;
	}

	/**
	 * Member guard + may read earnings (approved, or suspended for history). Ends the request otherwise.
	 *
	 * @return array user_id, profile_id and vendor_id.
	 */
	protected function earnings_viewer() {

		$member = $this->member();

		if ( ! Account_Types::can_view_earnings( $member['user_id'] ) ) {
			$this->forbid();
		}

		$member['vendor_id'] = Repository::id_for_user( $member['user_id'] );

		return $member;
	}

	/**
	 * The object belongs to the calling vendor, or the request ends with the same forbidden error as any other refusal.
	 *
	 * @param int   $owner_user_id User who owns the object being touched.
	 * @param array $member        What seller() / earnings_viewer() returned.
	 */
	protected function require_owner( $owner_user_id, array $member ) {

		if ( ! $owner_user_id || (int) $owner_user_id !== (int) $member['user_id'] ) {
			$this->forbid();
		}
	}

	/**
	 * Ends the request with the forbidden error.
	 */
	protected function forbid() {

		wp_send_json_error(
			array(
				'code'    => 'forbidden',
				'message' => __( 'You are not allowed to do that.', 'nexora' ),
			)
		);
	}
}
