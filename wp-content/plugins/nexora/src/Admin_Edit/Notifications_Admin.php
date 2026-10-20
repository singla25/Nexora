<?php

namespace Nexora\Admin_Edit;

use Nexora\Notifications\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edit and delete on the Notifications admin screen (the notifications live in a table of their own, not in posts). Administrators
 * only, a nonce per row and action, validated input. Who sent it, who got it, its type and its time are shown and never change.
 */
class Notifications_Admin {

	const OBJECT = 'nexora_notification';
	const MAX    = 1000;

	/**
	 * Hooks the two admin-post actions.
	 */
	public function __construct() {
		add_action( 'admin_post_nexora_notification_update', array( $this, 'handle_update' ) );
		add_action( 'admin_post_nexora_notification_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * Does one operation.
	 *
	 * @param string $operation update | delete.
	 * @param array  $request   The request: id, _wpnonce and, for update, message and is_read.
	 * @return true|\WP_Error
	 */
	public static function process( $operation, array $request ) {

		if ( ! in_array( $operation, array( 'update', 'delete' ), true ) ) {
			return new \WP_Error( 'nexora_bad_action', __( 'Unknown action.', 'nexora' ) );
		}

		$id    = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$nonce = isset( $request['_wpnonce'] ) && is_string( $request['_wpnonce'] ) ? $request['_wpnonce'] : '';

		if ( ! current_user_can( 'manage_options' ) || ! $id || false === wp_verify_nonce( $nonce, 'nexora_notification_' . $operation . '_' . $id ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		$repo = new Repository();
		$row  = $repo->get_row( $id );

		if ( ! $row ) {
			return new \WP_Error( 'nexora_not_found', __( 'Notification not found.', 'nexora' ) );
		}

		if ( 'delete' === $operation ) {

			$repo->delete_row( $id );
			Audit::record( self::OBJECT, $id, 'deleted', 'no', 'yes' );

			return true;
		}

		$message = isset( $request['message'] ) && is_string( $request['message'] ) ? trim( sanitize_textarea_field( $request['message'] ) ) : (string) $row->message;
		$is_read = isset( $request['is_read'] ) && is_scalar( $request['is_read'] ) ? (string) $request['is_read'] : (string) (int) $row->is_read;
		$errors  = array();

		if ( '' === $message || mb_strlen( $message ) > self::MAX ) {
			$errors['message'] = __( 'The message must be 1 to 1,000 characters.', 'nexora' );
		}

		if ( ! in_array( $is_read, array( '0', '1' ), true ) ) {
			$errors['is_read'] = __( 'Choose read or unread.', 'nexora' );
		}

		if ( $errors ) {
			return new \WP_Error( 'nexora_invalid', __( 'Please correct the highlighted fields.', 'nexora' ), array( 'fields' => $errors ) );
		}

		$repo->update_row( $id, $message, (int) $is_read );
		Audit::record( self::OBJECT, $id, 'message', (string) $row->message, $message );
		Audit::record( self::OBJECT, $id, 'is_read', (string) (int) $row->is_read, $is_read );

		return true;
	}

	/**
	 * The edit form's request.
	 */
	public function handle_update() {
		$this->finish( 'update', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- process() verifies the nonce and cleans every value.
	}

	/**
	 * The delete link's request.
	 */
	public function handle_delete() {
		$this->finish( 'delete', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- process() verifies the nonce.
	}

	/**
	 * Runs the operation and goes back to the screen with the result.
	 *
	 * @param string $operation update | delete.
	 * @param array  $request   The request.
	 */
	private function finish( $operation, array $request ) {

		$result = self::process( $operation, $request );

		if ( is_wp_error( $result ) ) {
			Acf_Bridge::push_notice( get_current_user_id(), $result );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=nexora-notifications' ) );
		exit;
	}
}
