<?php

namespace Nexora\Notifications;

use Nexora\Http\Ajax as Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification actions for logged-in members.
 */
class Ajax {

	/**
	 * Registers the notification AJAX action.
	 */
	public function __construct() {
		Http::register( 'mark_notification_read', array( $this, 'mark_notification_read' ) );
	}

	/**
	 * Marks one of the member's own notifications as read.
	 */
	public function mark_notification_read() {

		$user_id = Http::member( 'profile_nonce', false, __( 'Not logged in', 'nexora' ) )['user_id'];

		$id = absint( $_POST['id'] ?? 0 );

		$notification = new Repository();

		$row = $notification->get_row( $id );

		// A member can only touch their own notifications
		if ( ! $row || (int) $row->receiver_user_id !== (int) $user_id ) {
			wp_send_json_error( __( 'Unauthorized', 'nexora' ) );
		}

		$notification->mark_as_read( $id );

		wp_send_json_success(
			array(
				'message' => $row->message,
			)
		);
	}
}
