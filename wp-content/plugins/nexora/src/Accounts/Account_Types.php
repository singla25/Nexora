<?php

namespace Nexora\Accounts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the account type of a member: customer or seller.
 *
 * Three roles: the core `subscriber` (shown as "Customer"), `nexora_seller` (registered as a seller) and
 * `nexora_customer_seller` (a customer who became a seller). No code asks for a role slug: it asks
 * has_seller_record() (a seller record in any status) or can_sell() (approved). The role changes only when a
 * seller is approved and when a seller record is deleted; suspension keeps it, the status decides what the
 * seller may do. The status lives in user meta and is only ever changed here; nothing the browser sends can
 * choose it.
 *
 * Not to be confused with Profile\Privacy::role(), which is a visitor's relationship to a
 * profile (guest / viewer / owner).
 */
class Account_Types {

	const ROLE_SELLER          = 'nexora_seller';
	const ROLE_CUSTOMER_SELLER = 'nexora_customer_seller';
	const LEGACY_ROLE          = 'nexora_vendor'; // The pre-7a role: only Migration removes it.
	const STATUS_META          = '_nexora_vendor_status';
	const SELLER_META          = '_seller_post_id'; // User meta: the seller record (also set for a direct seller).
	const OPTION               = 'nexora_accounts_version';
	const VERSION              = '2.0.0';

	const NONE      = 'none';
	const PENDING   = 'pending';
	const APPROVED  = 'approved';
	const SUSPENDED = 'suspended';
	const REJECTED  = 'rejected';

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
		add_filter( 'gettext_with_context', array( __CLASS__, 'role_label' ), 10, 4 );

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
	 * How a role name is shown (role dropdowns, the Users list): the core subscriber role reads "Customer" and the two
	 * seller roles are translated. Display only: nothing is written to the roles table, and nothing here runs before init.
	 *
	 * @param string $translated Text as translated so far.
	 * @param string $name       Stored role name (the original text).
	 * @param string $context    Gettext context; role names use "User role".
	 * @param string $domain     Text domain of the name.
	 * @return string
	 */
	public static function role_label( $translated, $name, $context = '', $domain = '' ) {

		unset( $domain );

		if ( 'User role' !== $context ) {
			return $translated;
		}

		if ( 'Subscriber' === $name ) {
			return __( 'Customer', 'nexora' );
		}

		if ( 'Seller' === $name ) {
			return __( 'Seller', 'nexora' );
		}

		if ( 'Customer & Seller' === $name ) {
			return __( 'Customer & Seller', 'nexora' );
		}

		return $translated;
	}

	/**
	 * Registers the two seller roles when the stored version is older than the code.
	 */
	public static function maybe_register() {

		if ( version_compare( (string) get_option( self::OPTION, '0' ), self::VERSION, '>=' ) ) {
			return;
		}

		self::register_roles();
		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * The two seller roles and the name each is stored with (English, like any role name; role_label() translates it for display).
	 *
	 * @return string[] Role slug => stored name.
	 */
	public static function seller_roles() {
		return array(
			self::ROLE_SELLER          => 'Seller',
			self::ROLE_CUSTOMER_SELLER => 'Customer & Seller',
		);
	}

	/**
	 * Creates the seller roles, or brings an existing one back to exactly `read`. Safe to repeat.
	 * Selling is never a capability: it is the status (can_sell()).
	 */
	public static function register_roles() {

		foreach ( self::seller_roles() as $slug => $name ) {

			$role = get_role( $slug );

			if ( null === $role ) {
				add_role( $slug, $name, array( 'read' => true ) );
				continue;
			}

			if ( ! $role->has_cap( 'read' ) ) {
				$role->add_cap( 'read' );
			}

			foreach ( array_keys( $role->capabilities ) as $cap ) {
				if ( 'read' !== $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Every status a seller can have.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::NONE, self::PENDING, self::APPROVED, self::SUSPENDED, self::REJECTED );
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
	 * True when the user has a seller record, in ANY status (pending, approved, rejected, suspended).
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return bool
	 */
	public static function has_seller_record( $user ) {

		$id = self::user_id( $user );

		return $id && \Nexora\Vendors\Repository::id_for_user( $id ) > 0;
	}

	/**
	 * True only for an approved seller who holds a seller role (both are required). Pending, rejected and
	 * suspended sellers can buy but cannot sell.
	 *
	 * @param int|\WP_User $user User or ID.
	 * @return bool
	 */
	public static function can_sell( $user ) {

		$id = self::user_id( $user );

		if ( ! $id || self::APPROVED !== self::vendor_status( $id ) ) {
			return false;
		}

		$account = get_userdata( $id );

		return $account && (bool) array_intersect( array( self::ROLE_SELLER, self::ROLE_CUSTOMER_SELLER ), (array) $account->roles );
	}

	/**
	 * Approved and suspended sellers may read their earnings (suspended ones read-only).
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
			self::PENDING   => array( self::APPROVED, self::REJECTED ),
			self::APPROVED  => array( self::SUSPENDED ),
			self::SUSPENDED => array( self::APPROVED ),
			self::REJECTED  => array( self::PENDING ),
		);

		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Changes a seller status. The role changes only on approval (a customer is promoted to Customer & Seller; a
	 * direct seller already has the Seller role); suspension and rejection keep it. Callers are responsible for the
	 * permission check (admin decision, or the applicant's own form).
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

		self::write_status( $id, $to );

		if ( self::APPROVED === $to ) {
			self::promote( $id );
		}

		do_action( 'nexora_vendor_status_changed', $id, $from, $to );

		return true;
	}

	/**
	 * Takes a user's seller standing away because their seller record was deleted: status none, back to the
	 * plain customer role. The one other place a role changes.
	 *
	 * @param int|\WP_User $user User or ID.
	 */
	public static function clear( $user ) {

		$id = self::user_id( $user );

		if ( ! $id || ! get_userdata( $id ) ) {
			return;
		}

		$from = self::vendor_status( $id );

		self::write_status( $id, self::NONE );
		self::demote( $id );

		if ( self::NONE !== $from ) {
			do_action( 'nexora_vendor_status_changed', $id, $from, self::NONE );
		}
	}

	/**
	 * Stores a status (the only code allowed to write the meta).
	 *
	 * @param int    $id     User ID.
	 * @param string $status New status (none removes the meta).
	 */
	private static function write_status( $id, $status ) {

		self::$writing = true;

		if ( self::NONE === $status ) {
			delete_user_meta( $id, self::STATUS_META );
		} else {
			update_user_meta( $id, self::STATUS_META, $status );
		}

		self::$writing = false;
	}

	/**
	 * A member who registers directly as a seller gets the Seller role at once (the customer role is replaced).
	 * The role is not permission to sell: that is the approved status.
	 *
	 * @param int|\WP_User $user User or ID.
	 */
	public static function assign_seller_role( $user ) {

		$id      = self::user_id( $user );
		$account = $id ? get_userdata( $id ) : false;

		if ( ! $account ) {
			return;
		}

		$account->remove_role( 'subscriber' );
		$account->add_role( self::ROLE_SELLER );
	}

	/**
	 * Approval: a customer becomes a Customer & Seller (the subscriber role is replaced; any other role stays).
	 *
	 * @param int $id User ID.
	 */
	private static function promote( $id ) {

		$account = get_userdata( $id );

		if ( in_array( self::ROLE_SELLER, (array) $account->roles, true ) || in_array( self::ROLE_CUSTOMER_SELLER, (array) $account->roles, true ) ) {
			return;
		}

		$account->remove_role( 'subscriber' );
		$account->add_role( self::ROLE_CUSTOMER_SELLER );
	}

	/**
	 * Record deleted: every seller role (and the old vendor role) goes, and the user is a plain customer again.
	 *
	 * @param int $id User ID.
	 */
	private static function demote( $id ) {

		$account = get_userdata( $id );

		foreach ( array( self::ROLE_SELLER, self::ROLE_CUSTOMER_SELLER, self::LEGACY_ROLE ) as $role ) {
			$account->remove_role( $role );
		}

		clean_user_cache( $id );

		// A user left with no role at all is a customer; one who also holds another role (an administrator, say) keeps just that.
		if ( ! get_userdata( $id )->roles ) {
			$account->add_role( 'subscriber' );
		}
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
			case self::REJECTED:
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
