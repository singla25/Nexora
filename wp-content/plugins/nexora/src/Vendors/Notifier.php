<?php

namespace Nexora\Vendors;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The emails around a vendor application: to the site admin and to the applicant.
 */
class Notifier {

	/**
	 * Tells the admin about a new application and thanks the applicant.
	 *
	 * @param int $vendor_id Vendor post ID.
	 */
	public static function application_received( $vendor_id ) {

		$user = get_userdata( Repository::user_for( $vendor_id ) );

		if ( ! $user ) {
			return;
		}

		$data = Repository::data( $vendor_id );

		self::send(
			self::admin_email(),
			__( 'New seller application on Nexora', 'nexora' ),
			View::render(
				'mail/vendor-application-admin',
				array(
					'account'  => $user->user_login,
					'data'     => $data,
					'types'    => Fields::business_types(),
					'edit_url' => admin_url( 'post.php?post=' . (int) $vendor_id . '&action=edit' ),
				)
			)
		);

		self::send(
			$user->user_email,
			__( 'We received your seller application', 'nexora' ),
			View::render(
				'mail/vendor-application-received',
				array(
					'name'     => $data['business_name'],
					'greeting' => $user->display_name,
				)
			)
		);
	}

	/**
	 * Tells the applicant what the admin decided.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action  approve | suspend | reactivate | reject.
	 * @param string $business Business name.
	 */
	public static function status_changed( $user_id, $action, $business ) {

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$subjects = array(
			'approve'    => __( 'Your seller application was approved', 'nexora' ),
			'reactivate' => __( 'Your seller account is active again', 'nexora' ),
			'suspend'    => __( 'Your seller account was suspended', 'nexora' ),
			'reject'     => __( 'Your seller application was closed', 'nexora' ),
		);

		if ( ! isset( $subjects[ $action ] ) ) {
			return;
		}

		self::send(
			$user->user_email,
			$subjects[ $action ],
			View::render(
				'mail/vendor-status',
				array(
					'action'   => $action,
					'greeting' => $user->display_name,
					'business' => $business,
				)
			)
		);
	}

	/**
	 * The address the admin notices go to (same rule as the new-member mail).
	 *
	 * @return string
	 */
	private static function admin_email() {

		$email = get_option( 'default_admin_mail' );

		return $email ? $email : get_option( 'admin_email' );
	}

	/**
	 * Sends one HTML mail; the subject is flattened to one line.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 */
	private static function send( $to, $subject, $body ) {
		wp_mail( $to, sanitize_text_field( $subject ), $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
