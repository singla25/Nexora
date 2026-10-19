<?php

namespace Nexora\Accounts;

use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts the pre-7a model in place, so no post ID changes:
 *
 * - every `user_profile` post becomes a `nexora_customer` post;
 * - every `nexora_vendor` post becomes a `nexora_cust_seller` post and is linked to its owner's customer post
 *   (`customer_post_id` / `seller_post_id`, `_seller_post_id`; `_vendor_id` stays as it was);
 * - approved and suspended sellers end as `nexora_customer_seller` (suspension keeps the role), everyone else as
 *   `subscriber`; the old `nexora_vendor` role leaves every user and, once nobody holds it, the site.
 *
 * Idempotent (a second run finds nothing to do), reports counts first (plan(): reads, writes nothing) and touches
 * nothing outside Nexora's own records and the roles of the users involved. `wp nexora migrate-accounts [--dry-run]`.
 * An optional scope (post IDs and user IDs) limits a run to those rows; only the tests use it.
 */
class Migration {

	const OPTION = 'nexora_accounts_migration';
	const MARKER = '7a-1';

	const OLD_CUSTOMER = 'user_profile';
	const OLD_SELLER   = 'nexora_vendor';

	/**
	 * Registers the CLI command.
	 */
	public function __construct() {

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nexora migrate-accounts', array( $this, 'cli' ) );
		}
	}

	/**
	 * What a run would change. Reads only.
	 *
	 * @param array|null $scope Optional array( 'post_ids' => int[], 'user_ids' => int[] ).
	 * @return array customers, sellers, roles_to_customer_seller, legacy_role_removed, anomalies, applied (false).
	 */
	public static function plan( $scope = null ) {

		$posts = self::posts( $scope );
		$users = self::users( $scope );

		$to_seller_role = 0;
		$legacy         = 0;
		$anomalies      = array();

		foreach ( $users as $user_id ) {

			$account = get_userdata( $user_id );

			if ( ! $account ) {
				continue;
			}

			if ( in_array( Account_Types::LEGACY_ROLE, (array) $account->roles, true ) ) {
				++$legacy;
			}

			if ( self::wants_seller_role( $user_id ) && ! in_array( Account_Types::ROLE_CUSTOMER_SELLER, (array) $account->roles, true ) ) {
				++$to_seller_role;
			}
		}

		foreach ( $posts['sellers'] as $record_id ) {

			$user_id = (int) get_post_meta( $record_id, '_wp_user_id', true );

			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				$anomalies[] = "seller record $record_id has no existing owner";
			} elseif ( 'trash' !== get_post_status( $record_id ) && ! self::customer_post_of( $user_id, $posts['customers'] ) ) {
				$anomalies[] = "seller record $record_id: its owner has no customer post";
			}
		}

		return array(
			'customers'                => count( $posts['customers'] ),
			'sellers'                  => count( $posts['sellers'] ),
			'roles_to_customer_seller' => $to_seller_role,
			'legacy_role_removed'      => $legacy,
			'anomalies'                => $anomalies,
			'applied'                  => false,
		);
	}

	/**
	 * Applies the conversion. Safe to repeat.
	 *
	 * @param array|null $scope Optional array( 'post_ids' => int[], 'user_ids' => int[] ).
	 * @return array Same shape as plan(), with the counts of what was changed and applied = true.
	 */
	public static function run( $scope = null ) {

		global $wpdb;

		$plan  = self::plan( $scope );
		$posts = self::posts( $scope );
		$users = self::users( $scope );

		Account_Types::register_roles();

		// 1. The post types, in place (same IDs). Customers first, so the seller links below find them.
		foreach ( array(
			Post_Types::CUSTOMER    => $posts['customers'],
			Post_Types::CUST_SELLER => $posts['sellers'],
		) as $new_type => $ids ) {
			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- changing the type in place keeps every post ID.
				$wpdb->update( $wpdb->posts, array( 'post_type' => $new_type ), array( 'ID' => $id ) );
				clean_post_cache( $id );
			}
		}

		// 2. The links between each seller record, its customer post and its user.
		foreach ( $posts['sellers'] as $record_id ) {
			self::link_record( $record_id );
		}

		// 3. The roles.
		foreach ( $users as $user_id ) {
			self::fix_roles( $user_id );
		}

		if ( ! $scope ) {
			self::remove_legacy_role();
			update_option( self::OPTION, self::MARKER, false );
			delete_transient( \Nexora\Shortcodes\Home::STATS_TRANSIENT );
			flush_rewrite_rules();
		}

		$plan['applied'] = true;

		return $plan;
	}

	/**
	 * Removes the old nexora_vendor role from the site, but only when no user holds it any more.
	 *
	 * @return bool True when the role is gone, false when someone still holds it.
	 */
	public static function remove_legacy_role() {

		$holder = get_users(
			array(
				'role'   => Account_Types::LEGACY_ROLE,
				'fields' => 'ID',
				'number' => 1,
			)
		);

		if ( $holder ) {
			return false;
		}

		if ( null !== get_role( Account_Types::LEGACY_ROLE ) ) {
			remove_role( Account_Types::LEGACY_ROLE );
		}

		return true;
	}

	/**
	 * `wp nexora migrate-accounts [--dry-run]`: prints what exists before, what changes, and what exists after.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change and write nothing.
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Flags.
	 */
	public function cli( $args, $assoc_args ) {

		unset( $args );

		$dry = ! empty( $assoc_args['dry-run'] );

		\WP_CLI::log( 'Before:' );
		self::print_counts();

		$result = $dry ? self::plan() : self::run();

		\WP_CLI::log( ( $dry ? 'Would change (dry-run, nothing written):' : 'Changed:' ) );
		\WP_CLI::log( '  customer posts renamed to nexora_customer: ' . $result['customers'] );
		\WP_CLI::log( '  seller records renamed to nexora_cust_seller: ' . $result['sellers'] );
		\WP_CLI::log( '  users who end as Customer & Seller: ' . $result['roles_to_customer_seller'] );
		\WP_CLI::log( '  users losing the old nexora_vendor role: ' . $result['legacy_role_removed'] );

		foreach ( $result['anomalies'] as $note ) {
			\WP_CLI::warning( $note );
		}

		if ( ! $dry ) {
			\WP_CLI::log( 'After:' );
			self::print_counts();
			\WP_CLI::success( 'Accounts migrated.' );
		} else {
			\WP_CLI::success( 'Dry-run finished. Nothing was changed.' );
		}
	}

	/**
	 * Prints the counts that must not move (and the ones that must).
	 */
	private static function print_counts() {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$types = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type IN ('user_profile','nexora_vendor','nexora_customer','nexora_seller','nexora_cust_seller','nexora_product') GROUP BY post_type", OBJECT_K );
		$stat  = $wpdb->get_results( "SELECT meta_value, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key = '_nexora_vendor_status' GROUP BY meta_value", OBJECT_K );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		foreach ( array( 'user_profile', 'nexora_customer', 'nexora_vendor', 'nexora_seller', 'nexora_cust_seller', 'nexora_product' ) as $type ) {
			\WP_CLI::log( sprintf( '  posts %-20s %d', $type, isset( $types[ $type ] ) ? $types[ $type ]->n : 0 ) );
		}

		foreach ( array( 'pending', 'approved', 'suspended', 'rejected' ) as $status ) {
			\WP_CLI::log( sprintf( '  sellers %-18s %d', $status, isset( $stat[ $status ] ) ? $stat[ $status ]->n : 0 ) );
		}

		$counts = count_users();

		foreach ( array( 'subscriber', Account_Types::LEGACY_ROLE, Account_Types::ROLE_SELLER, Account_Types::ROLE_CUSTOMER_SELLER ) as $role ) {
			\WP_CLI::log( sprintf( '  users with role %-24s %d', $role, isset( $counts['avail_roles'][ $role ] ) ? $counts['avail_roles'][ $role ] : 0 ) );
		}

		$earning = 0.0;
		$orders  = 0;

		foreach ( get_posts(
			array(
				'post_type'      => array( Post_Types::SELLER, Post_Types::CUST_SELLER, self::OLD_SELLER ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $record_id ) {
			$sum      = \Nexora\Orders\Earnings::summary( $record_id );
			$earning += (float) $sum['earning'];
			$orders  += (int) $sum['orders'];
		}

		\WP_CLI::log( sprintf( '  counted marketplace orders %d, sellers\' earnings total %.2f', $orders, $earning ) );
	}

	/**
	 * The post IDs the run converts: old customer posts and old seller records (any status), limited to the scope.
	 *
	 * @param array|null $scope Scope or null.
	 * @return array customers => int[], sellers => int[].
	 */
	private static function posts( $scope ) {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$customers = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", self::OLD_CUSTOMER ) ) );
		$sellers   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", self::OLD_SELLER ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( $scope ) {
			$only      = array_map( 'intval', (array) ( $scope['post_ids'] ?? array() ) );
			$customers = array_values( array_intersect( $customers, $only ) );
			$sellers   = array_values( array_intersect( $sellers, $only ) );
		}

		return array(
			'customers' => $customers,
			'sellers'   => $sellers,
		);
	}

	/**
	 * The users whose roles may need fixing: anyone holding the old role, or approved or suspended.
	 *
	 * @param array|null $scope Scope or null.
	 * @return int[]
	 */
	private static function users( $scope ) {

		global $wpdb;

		if ( $scope ) {
			return array_values( array_unique( array_map( 'intval', (array) ( $scope['user_ids'] ?? array() ) ) ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_status = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN ('approved','suspended')", Account_Types::STATUS_META ) );
		$by_role   = get_users(
			array(
				'role'   => Account_Types::LEGACY_ROLE,
				'fields' => 'ID',
			)
		);

		return array_values( array_unique( array_map( 'intval', array_merge( $by_status, $by_role ) ) ) );
	}

	/**
	 * Whether the user's status means they end as Customer & Seller.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function wants_seller_role( $user_id ) {
		return in_array( Account_Types::vendor_status( $user_id ), array( Account_Types::APPROVED, Account_Types::SUSPENDED ), true );
	}

	/**
	 * The user's customer post among those being converted (or already converted).
	 *
	 * @param int   $user_id   User ID.
	 * @param int[] $customers Customer post IDs in this run.
	 * @return int
	 */
	private static function customer_post_of( $user_id, array $customers ) {

		$profile = (int) get_user_meta( $user_id, '_profile_id', true );

		return ( $profile && ( in_array( $profile, $customers, true ) || Post_Types::CUSTOMER === get_post_type( $profile ) ) ) ? $profile : 0;
	}

	/**
	 * Links one converted seller record. A live one is linked both ways and to the user; an old rejected one (in the
	 * trash, unlinked from its user) only remembers its customer post.
	 *
	 * @param int $record_id Seller record ID (already nexora_cust_seller).
	 */
	private static function link_record( $record_id ) {

		$user_id = (int) get_post_meta( $record_id, '_wp_user_id', true );

		if ( ! $user_id ) {
			return;
		}

		if ( 'trash' !== get_post_status( $record_id ) && (int) get_user_meta( $user_id, Links::VENDOR_META, true ) === (int) $record_id ) {
			Links::link( $record_id );
			return;
		}

		$customer_id = Links::customer_for( $record_id, $user_id );

		if ( $customer_id ) {
			update_post_meta( $record_id, Links::CUSTOMER_LINK, $customer_id );
		}
	}

	/**
	 * Roles of one user: approved and suspended end as Customer & Seller, everyone else as a customer; the old role goes.
	 *
	 * @param int $user_id User ID.
	 */
	private static function fix_roles( $user_id ) {

		$account = get_userdata( $user_id );

		if ( ! $account ) {
			return;
		}

		$roles = (array) $account->roles;

		if ( self::wants_seller_role( $user_id ) && ! in_array( Account_Types::ROLE_SELLER, $roles, true ) ) {
			$account->remove_role( 'subscriber' );
			$account->add_role( Account_Types::ROLE_CUSTOMER_SELLER );
		}

		if ( in_array( Account_Types::LEGACY_ROLE, $roles, true ) ) {
			$account->remove_role( Account_Types::LEGACY_ROLE );
		}

		clean_user_cache( $user_id );

		if ( ! get_userdata( $user_id )->roles ) {
			$account->add_role( 'subscriber' );
		}
	}
}
