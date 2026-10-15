<?php

namespace Nexora\Accounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the account type of a member: customer or vendor.
 *
 * A "customer" is any member who is not a vendor; the WordPress role is never consulted
 * for that. A vendor is a customer plus the additive `nexora_vendor` role (added with
 * add_role(), so the original role stays). The vendor status lives in user meta and is
 * only ever changed here; nothing the browser sends can choose it.
 *
 * Not to be confused with Profile\Privacy::role(), which is a visitor's relationship to a
 * profile (guest / viewer / owner).
 */
class Account_Types {

	const ROLE        = 'nexora_vendor';
	const STATUS_META = '_nexora_vendor_status';
	const OPTION      = 'nexora_accounts_version';
	const VERSION     = '1.0.0';

	const NONE      = 'none';
	const PENDING   = 'pending';
	const APPROVED  = 'approved';
	const SUSPENDED = 'suspended';

	const CAP_PRODUCTS  = 'nexora_manage_own_products';
	const CAP_ANALYTICS = 'nexora_view_own_analytics';

	/**
	 * True only while set_status() is writing the status meta.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Registers the self-upgrade, like Database\Migrations: uploading new plugin files is enough.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_register' ) );

		// The status is a permission: nothing but set_status() may write it (not a form, a hook or another plugin).
		add_filter( 'add_user_metadata', array( __CLASS__, 'guard_status_meta' ), 10, 3 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'guard_status_meta' ), 10, 3 );
		add_filter( 'delete_user_metadata', array( __CLASS__, 'guard_status_meta_delete' ), 10, 5 );
	}

	/**
	 * Refuses any write of the vendor status that does not come from set_status().
	 *
	 * @param null|bool $check    Short-circuit value (null lets the write continue).
	 * @param int       $user_id  User ID (unused).
	 * @param string    $meta_key Meta key being written.
	 * @return null|bool
	 */
	public static function guard_status_meta( $check, $user_id, $meta_key ) {

		unset( $user_id );

		if ( self::STATUS_META === $meta_key && ! self::$writing ) {
			return false;
		}

		return $check;
	}

	/**
	 * Refuses a delete of the vendor status unless set_status() or the uninstaller (delete for all users) does it.
	 *
	 * @param null|bool $check      Short-circuit value.
	 * @param int       $user_id    User ID (unused).
	 * @param string    $meta_key   Meta key being deleted.
	 * @param mixed     $meta_value Meta value (unused).
	 * @param bool      $delete_all Whether the key is deleted for every user.
	 * @return null|bool
	 */
	public static function guard_status_meta_delete( $check, $user_id, $meta_key, $meta_value = '', $delete_all = false ) {

		unset( $user_id, $meta_value );

		if ( self::STATUS_META === $meta_key && ! self::$writing && ! $delete_all ) {
			return false;
		}

		return $check;
	}

	/**
	 * Registers the vendor role when the stored version is older than the code.
	 */
	public static function maybe_register() {

		if ( version_compare( (string) get_option( self::OPTION, '0' ), self::VERSION, '>=' ) ) {
			return;
		}

		self::register_role();
		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Creates the vendor role, or brings an existing one back to exactly the two narrow caps. Safe to repeat.
	 */
	public static function register_role() {

		$caps = array(
			self::CAP_PRODUCTS  => true,
			self::CAP_ANALYTICS => true,
		);

		$role = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, 'Nexora Vendor', $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap, $grant );
			}
		}

		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! isset( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Every status a vendor can have.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::NONE, self::PENDING, self::APPROVED, self::SUSPENDED );
	}

	/**
	 * Reduces whatever the browser sent for "what do you want to be" to customer or seller.
	 *
	 * @param mixed $raw Raw request value.
	 * @return string customer|seller
	 */
	public static function sanitize_intent( $raw ) {
		return ( 'seller' === $raw ) ? 'seller' : 'customer';
	}

	/**
	 * The vendor status of a user (none for guests and for corrupt values).
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return string
	 */
	public static function vendor_status( $user ) {

		$id = self::user_id( $user );

		if ( ! $id ) {
			return self::NONE;
		}

		$status = get_user_meta( $id, self::STATUS_META, true );

		return in_array( $status, self::statuses(), true ) ? $status : self::NONE;
	}

	/**
	 * True for anyone who applied: pending, approved or suspended.
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return bool
	 */
	public static function is_vendor( $user ) {
		return self::NONE !== self::vendor_status( $user );
	}

	/**
	 * True only for an approved vendor who really holds the vendor role (both are required).
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return bool
	 */
	public static function can_sell( $user ) {

		$id = self::user_id( $user );

		return $id && self::APPROVED === self::vendor_status( $id ) && user_can( $id, self::CAP_PRODUCTS );
	}

	/**
	 * Approved and suspended vendors may read their earnings (suspended ones read-only).
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return bool
	 */
	public static function can_view_earnings( $user ) {
		return in_array( self::vendor_status( $user ), array( self::APPROVED, self::SUSPENDED ), true );
	}

	/**
	 * Whether a status may change to another.
	 *
	 * @param string $from Current status.
	 * @param string $to   Wanted status.
	 * @return bool
	 */
	public static function transition_allowed( $from, $to ) {

		$map = array(
			self::NONE      => array( self::PENDING ),
			self::PENDING   => array( self::APPROVED, self::NONE ),
			self::APPROVED  => array( self::SUSPENDED ),
			self::SUSPENDED => array( self::APPROVED ),
		);

		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Changes a vendor status and keeps the vendor role in step (only approved vendors hold it).
	 * Callers are responsible for the permission check (admin approval, or the applicant's own form).
	 *
	 * @param int|\WP_User $user User or ID.
	 * @param string       $to   New status.
	 * @return true|\WP_Error
	 */
	public static function set_status( $user, $to ) {

		$id = self::user_id( $user );

		if ( ! $id || ! get_userdata( $id ) ) {
			return new \WP_Error( 'nexora_no_user', 'Unknown user.' );
		}

		$from = self::vendor_status( $id );

		if ( ! self::transition_allowed( $from, $to ) ) {
			return new \WP_Error( 'nexora_bad_transition', 'That status change is not allowed.' );
		}

		self::$writing = true;

		if ( self::NONE === $to ) {
			delete_user_meta( $id, self::STATUS_META );
		} else {
			update_user_meta( $id, self::STATUS_META, $to );
		}

		self::$writing = false;

		$account = get_userdata( $id );

		if ( self::APPROVED === $to ) {
			$account->add_role( self::ROLE );
		} else {
			$account->remove_role( self::ROLE );
		}

		do_action( 'nexora_vendor_status_changed', $id, $from, $to );

		return true;
	}

	/**
	 * Which tabs a member sees on their own profile, in order. The one place that decides it;
	 * a hidden tab is never the security, every endpoint re-checks on the server.
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return string[] Tab slugs (empty for guests).
	 */
	public static function tabs_for( $user ) {

		if ( ! self::user_id( $user ) ) {
			return array();
		}

		$tabs = array( 'user-info', 'connections', 'content', 'notifications', 'marketplace', 'my-orders' );

		switch ( self::vendor_status( $user ) ) {
			case self::PENDING:
				$tabs[] = 'vendor-status';
				break;
			case self::APPROVED:
				$tabs[] = 'my-products';
				$tabs[] = 'sales';
				break;
			case self::SUSPENDED:
				$tabs[] = 'sales';
				$tabs[] = 'vendor-status';
				break;
		}

		return $tabs;
	}

	/**
	 * Normalises a user or ID to an ID.
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return int
	 */
	private static function user_id( $user ) {
		return $user instanceof \WP_User ? (int) $user->ID : (int) $user;
	}
}
