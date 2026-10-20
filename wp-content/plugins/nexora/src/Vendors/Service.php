<?php

namespace Nexora\Vendors;

use Nexora\Accounts\Account_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The seller application rules: register as a seller, become a seller (and re-apply after a rejection), edit details,
 * and the administrator's decisions. The only caller of Account_Types::set_status() outside Account_Types itself.
 * One code path for both seller record types (Core\Post_Types::sellers()).
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
			'reject'     => array( array( Account_Types::PENDING ), Account_Types::REJECTED ),
		);
	}

	/**
	 * Records a seller application from an existing member: "Become a seller" (a new `nexora_cust_seller` record linked
	 * to their customer profile) or a re-application after a rejection (the same record, new details). The result is
	 * ALWAYS "pending": nothing in $raw can choose a status or a role, and the role changes only on approval.
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

		$status = Account_Types::vendor_status( $user_id );

		if ( ! in_array( $status, array( Account_Types::NONE, Account_Types::REJECTED ), true ) ) {
			return new \WP_Error( 'nexora_already_applied', __( 'You have already applied to sell.', 'nexora' ) );
		}

		$checked = Validator::validate( $raw );

		if ( $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$existing  = Account_Types::REJECTED === $status ? Repository::id_for_user( $user_id ) : 0;
		$vendor_id = self::register( $user_id, $checked['data'], $existing, null );

		if ( is_wp_error( $vendor_id ) ) {
			return $vendor_id;
		}

		return array( 'vendor_id' => $vendor_id );
	}

	/**
	 * Registers a new member directly as a seller: ONE `nexora_seller` record holding their personal and business
	 * details (it is their profile; there is no customer post), the Seller role, status pending.
	 *
	 * @param int   $user_id  The new user (already created).
	 * @param array $personal Personal details (Repository::personal_keys()).
	 * @param array $raw      Unslashed business form values.
	 * @return array|\WP_Error array( 'vendor_id' => int )
	 */
	public static function register_seller( $user_id, array $personal, array $raw ) {

		$user_id = (int) $user_id;

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'nexora_no_user', __( 'Account not found.', 'nexora' ) );
		}

		if ( Account_Types::NONE !== Account_Types::vendor_status( $user_id ) || Repository::id_for_user( $user_id ) ) {
			return new \WP_Error( 'nexora_already_applied', __( 'You have already applied to sell.', 'nexora' ) );
		}

		$checked = Validator::validate( $raw );

		if ( $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$vendor_id = self::register( $user_id, $checked['data'], 0, $personal );

		if ( is_wp_error( $vendor_id ) ) {
			return $vendor_id;
		}

		return array( 'vendor_id' => $vendor_id );
	}

	/**
	 * The part both entry points share: the GSTIN lock and check, the record (new, or the rejected one being
	 * reused), the pending status, the mails.
	 *
	 * @param int        $user_id  Applicant.
	 * @param array      $data     Validated business details.
	 * @param int        $existing A rejected record to reuse (0 for a new one).
	 * @param array|null $personal Personal details for a NEW direct seller (null: a customer becoming a seller).
	 * @return int|\WP_Error Seller record ID.
	 */
	private static function register( $user_id, array $data, $existing, $personal ) {

		if ( ! self::lock( $data['gstin'] ) ) {
			return new \WP_Error( 'nexora_busy', __( 'This GSTIN is being registered right now. Please try again in a moment.', 'nexora' ) );
		}

		if ( Repository::find_by_gstin( $data['gstin'], $existing ) ) {
			self::unlock( $data['gstin'] );
			return self::invalid( array( 'gstin' => self::duplicate_message() ) );
		}

		if ( $existing ) {
			Repository::save( $existing, $data );
			$vendor_id = $existing;
		} elseif ( null !== $personal ) {
			$vendor_id = Repository::create_direct( $user_id, $personal, $data );
		} else {
			$vendor_id = Repository::create( $user_id, $data );
		}

		if ( is_wp_error( $vendor_id ) ) {
			self::unlock( $data['gstin'] );
			return new \WP_Error( 'nexora_save_failed', __( 'Could not save your application. Please try again.', 'nexora' ) );
		}

		if ( null !== $personal ) {
			Account_Types::assign_seller_role( $user_id );
		}

		$status = Account_Types::set_status( $user_id, Account_Types::PENDING );

		if ( is_wp_error( $status ) ) {
			if ( ! $existing ) {
				Repository::delete( $vendor_id );
			}
			self::unlock( $data['gstin'] );
			return new \WP_Error( 'nexora_save_failed', __( 'Could not save your application. Please try again.', 'nexora' ) );
		}

		self::unlock( $data['gstin'] );

		Notifier::application_received( $vendor_id );

		do_action( 'nexora_vendor_applied', $vendor_id, $user_id );

		return (int) $vendor_id;
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

		if ( Account_Types::REJECTED === $status ) {
			return new \WP_Error( 'nexora_rejected', __( 'Your application was not approved. Send a new application instead.', 'nexora' ) );
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

		if ( $current === $to ) {
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

		Notifier::status_changed( $user_id, $action, $business );

		return true;
	}

	/**
	 * An administrator's edit of a seller's business details (any subset, already validated by Validator). Takes the GSTIN lock
	 * and checks the GSTIN is not another live seller record's (either type) before writing through the Repository.
	 *
	 * @param int   $vendor_id Seller record ID.
	 * @param array $data      Validated business fields.
	 * @return true|\WP_Error
	 */
	public static function admin_save( $vendor_id, array $data ) {

		$vendor_id = (int) $vendor_id;

		if ( ! current_user_can( 'manage_options' ) || ! Repository::user_for( $vendor_id ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

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
	 * An administrator opens an empty seller record for an existing member (Users screen). Status pending; nothing is approved.
	 *
	 * @param int $user_id Member.
	 * @return int|\WP_Error New seller record ID.
	 */
	public static function open_blank( $user_id ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		$id = Repository::create_blank( $user_id );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$status = Account_Types::set_status( $user_id, Account_Types::PENDING );

		if ( is_wp_error( $status ) ) {
			Repository::delete( $id );
			return $status;
		}

		return $id;
	}

	/**
	 * The action (approve, suspend, reactivate, reject) that takes a seller from one status to another, or '' when there is none.
	 *
	 * @param string $from Current status.
	 * @param string $to   Wanted status.
	 * @return string
	 */
	public static function action_for( $from, $to ) {

		foreach ( self::actions() as $action => $rule ) {
			if ( $rule[1] === $to && in_array( $from, $rule[0], true ) ) {
				return $action;
			}
		}

		return '';
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
			'locked' => in_array( $status, array( Account_Types::APPROVED, Account_Types::SUSPENDED ), true ) ? array_diff( Fields::keys(), Fields::editable_after_review() ) : array(),
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
