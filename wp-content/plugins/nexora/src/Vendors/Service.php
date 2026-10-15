<?php

namespace Nexora\Vendors;

use Nexora\Accounts\Account_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The vendor application rules: apply, edit details, and the administrator's decisions. The only caller of
 * Account_Types::set_status() outside Account_Types itself.
 */
class Service {

	const LOCK_PREFIX = 'nexora_vendor_lock_';

	/**
	 * Admin actions: action => [ statuses it may start from, new status ].
	 *
	 * @return array
	 */
	public static function actions() {
		return array(
			'approve'    => array( array( Account_Types::PENDING ), Account_Types::APPROVED ),
			'suspend'    => array( array( Account_Types::APPROVED ), Account_Types::SUSPENDED ),
			'reactivate' => array( array( Account_Types::SUSPENDED ), Account_Types::APPROVED ),
			'reject'     => array( array( Account_Types::PENDING ), Account_Types::NONE ),
		);
	}

	/**
	 * Records a seller application. The result is ALWAYS "pending": nothing in $raw can choose a status or a role.
	 *
	 * @param int   $user_id Applicant.
	 * @param array $raw     Unslashed form values (only the business fields are read).
	 * @return array|\WP_Error array( 'vendor_id' => int )
	 */
	public static function apply( $user_id, array $raw ) {

		$user_id = (int) $user_id;

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'nexora_no_user', __( 'Account not found.', 'nexora' ) );
		}

		if ( Account_Types::NONE !== Account_Types::vendor_status( $user_id ) ) {
			return new \WP_Error( 'nexora_already_applied', __( 'You have already applied to sell.', 'nexora' ) );
		}

		$checked = Validator::validate( $raw );

		if ( $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$data = $checked['data'];

		if ( ! self::lock( $data['gstin'] ) ) {
			return new \WP_Error( 'nexora_busy', __( 'This GSTIN is being registered right now. Please try again in a moment.', 'nexora' ) );
		}

		$vendor_id = 0;

		if ( Repository::find_by_gstin( $data['gstin'] ) ) {
			self::unlock( $data['gstin'] );
			return self::invalid( array( 'gstin' => self::duplicate_message() ) );
		}

		$vendor_id = Repository::create( $user_id, $data );

		if ( is_wp_error( $vendor_id ) ) {
			self::unlock( $data['gstin'] );
			return new \WP_Error( 'nexora_save_failed', __( 'Could not save your application. Please try again.', 'nexora' ) );
		}

		$status = Account_Types::set_status( $user_id, Account_Types::PENDING );

		if ( is_wp_error( $status ) ) {
			Repository::delete( $vendor_id );
			self::unlock( $data['gstin'] );
			return new \WP_Error( 'nexora_save_failed', __( 'Could not save your application. Please try again.', 'nexora' ) );
		}

		self::unlock( $data['gstin'] );

		Notifier::application_received( $vendor_id );

		do_action( 'nexora_vendor_applied', $vendor_id, $user_id );

		return array( 'vendor_id' => $vendor_id );
	}

	/**
	 * Lets an applicant or vendor correct their own details. Pending applicants may change everything;
	 * after a decision the business name, type and GSTIN are locked and only contact/address fields change.
	 *
	 * @param int   $user_id Owner.
	 * @param array $raw     Unslashed form values.
	 * @return true|\WP_Error
	 */
	public static function update_details( $user_id, array $raw ) {

		$user_id   = (int) $user_id;
		$status    = Account_Types::vendor_status( $user_id );
		$vendor_id = Repository::id_for_user( $user_id );

		if ( Account_Types::NONE === $status || ! $vendor_id ) {
			return new \WP_Error( 'nexora_not_vendor', __( 'You have no seller application.', 'nexora' ) );
		}

		$allowed = Account_Types::PENDING === $status ? Fields::keys() : Fields::editable_after_review();
		$keys    = array_values( array_intersect( $allowed, array_keys( $raw ) ) );

		if ( ! $keys ) {
			return new \WP_Error( 'nexora_nothing', __( 'Nothing to update.', 'nexora' ) );
		}

		$checked = Validator::validate( $raw, $keys );

		if ( $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$data    = $checked['data'];
		$locked  = false;
		$current = Repository::data( $vendor_id );

		if ( isset( $data['gstin'] ) && $data['gstin'] !== $current['gstin'] ) {

			if ( ! self::lock( $data['gstin'] ) ) {
				return new \WP_Error( 'nexora_busy', __( 'This GSTIN is being registered right now. Please try again in a moment.', 'nexora' ) );
			}

			$locked = true;

			if ( Repository::find_by_gstin( $data['gstin'], $vendor_id ) ) {
				self::unlock( $data['gstin'] );
				return self::invalid( array( 'gstin' => self::duplicate_message() ) );
			}
		}

		Repository::save( $vendor_id, $data );

		if ( $locked ) {
			self::unlock( $data['gstin'] );
		}

		return true;
	}

	/**
	 * An administrator's decision on an application. Idempotent: repeating it changes nothing and sends nothing.
	 *
	 * @param int    $vendor_id Vendor post ID.
	 * @param string $action    approve | suspend | reactivate | reject.
	 * @return true|\WP_Error
	 */
	public static function change_status( $vendor_id, $action ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		$actions = self::actions();

		if ( ! isset( $actions[ $action ] ) ) {
			return new \WP_Error( 'nexora_bad_action', __( 'Unknown action.', 'nexora' ) );
		}

		$vendor_id = (int) $vendor_id;
		$user_id   = Repository::user_for( $vendor_id );

		if ( ! $user_id || 'trash' === get_post_status( $vendor_id ) || Repository::id_for_user( $user_id ) !== $vendor_id ) {
			return new \WP_Error( 'nexora_not_found', __( 'Application not found.', 'nexora' ) );
		}

		list( $from, $to ) = $actions[ $action ];
		$current           = Account_Types::vendor_status( $user_id );

		if ( $current === $to && Account_Types::NONE !== $to ) {
			return true;
		}

		if ( ! in_array( $current, $from, true ) ) {
			return new \WP_Error( 'nexora_bad_transition', __( 'That status change is not allowed.', 'nexora' ) );
		}

		$business = (string) get_post_meta( $vendor_id, 'business_name', true );
		$result   = Account_Types::set_status( $user_id, $to );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'reject' === $action ) {
			Repository::trash( $vendor_id );
		}

		Notifier::status_changed( $user_id, $action, $business );

		return true;
	}

	/**
	 * What the owner's profile card needs (never called for anyone else).
	 *
	 * @param int $user_id Owner.
	 * @return array status, data (business details), types.
	 */
	public static function card_data( $user_id ) {

		$status    = Account_Types::vendor_status( $user_id );
		$vendor_id = Repository::id_for_user( $user_id );

		return array(
			'status' => $status,
			'data'   => $vendor_id ? Repository::data( $vendor_id ) : array_fill_keys( Fields::keys(), '' ),
			'types'  => Fields::business_types(),
			'locked' => Account_Types::PENDING !== $status ? array_diff( Fields::keys(), Fields::editable_after_review() ) : array(),
		);
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

	/**
	 * The message for a GSTIN that is taken (says nothing about who has it).
	 *
	 * @return string
	 */
	private static function duplicate_message() {
		return __( 'This GSTIN is already registered.', 'nexora' );
	}

	/**
	 * Takes a short-lived lock on a GSTIN (add_option is atomic) so two requests cannot register it at once.
	 *
	 * @param string $gstin GSTIN.
	 * @return bool Whether the lock was obtained.
	 */
	private static function lock( $gstin ) {

		$key = self::LOCK_PREFIX . md5( $gstin );

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		// A lock left by a crashed request expires after a minute.
		if ( time() - (int) get_option( $key ) > MINUTE_IN_SECONDS ) {
			update_option( $key, time(), false );
			return true;
		}

		return false;
	}

	/**
	 * Releases a GSTIN lock.
	 *
	 * @param string $gstin GSTIN.
	 */
	private static function unlock( $gstin ) {
		delete_option( self::LOCK_PREFIX . md5( $gstin ) );
	}
}
