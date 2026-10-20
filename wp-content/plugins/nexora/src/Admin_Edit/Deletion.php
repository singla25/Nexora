<?php

namespace Nexora\Admin_Edit;

use Nexora\Core\Admin_Gate;
use Nexora\Core\Post_Types;
use Nexora\Imports\Connection_Repository;
use Nexora\Imports\Log_Repository;
use Nexora\Orders\Earnings;
use Nexora\Products\Repository as Products;
use Nexora\Vendors\Repository as Sellers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The delete rules for the administrator's trash / restore / delete.
 *
 *  - TRASHING a seller record is allowed even with orders: it hides the seller (no live record, cannot sell) and sets their products
 *    to draft. Orders are never touched. Restoring brings the seller back; the products stay drafts until the administrator publishes them.
 *  - DELETING a seller record for good is refused while ANY order line refers to it (every order status counts). Without orders it
 *    removes the products, the API connections and the import history, then the record through Vendors\Repository::delete(): the
 *    member keeps their account and drops back to a plain customer (a direct seller gets a customer profile).
 *  - A customer profile that still has a seller record cannot be trashed or deleted until the seller record is dealt with.
 *
 * The order rule holds on every route (the gate does not matter); everything else here still needs the Core\Admin_Gate (or the
 * Repository's own writes) because Core\Post_Guard refuses it otherwise.
 */
class Deletion {

	/**
	 * Hooks the rules.
	 */
	public function __construct() {
		add_filter( 'pre_trash_post', array( $this, 'before_trash' ), 5, 2 );
		add_filter( 'pre_delete_post', array( $this, 'before_delete' ), 5, 2 );
		add_action( 'trashed_post', array( $this, 'after_trash' ) );
		add_filter( 'wp_untrash_post_status', array( $this, 'restore_status' ), 10, 3 );
		add_action( 'untrashed_post', array( $this, 'after_untrash' ) );
		add_action( 'deleted_post', array( $this, 'after_delete' ), 10, 2 );
		add_action( 'load-post.php', array( $this, 'explain_refusal' ) );
	}

	/**
	 * Why a customer profile cannot be trashed or deleted ('' when it can).
	 *
	 * @param int $customer_id Customer post ID.
	 * @return string
	 */
	public static function customer_blocked( $customer_id ) {

		$customer_id = (int) $customer_id;

		if ( Post_Types::CUSTOMER !== get_post_type( $customer_id ) ) {
			return '';
		}

		$seller_id = (int) get_post_meta( $customer_id, 'seller_post_id', true );

		if ( $seller_id && in_array( get_post_type( $seller_id ), Sellers::types(), true ) ) {
			return __( 'This customer profile still has a seller record. Deal with the seller record first (trash or delete it), then this profile can go.', 'nexora' );
		}

		return '';
	}

	/**
	 * Why a seller record cannot be deleted for good ('' when it can).
	 *
	 * @param int $seller_id Seller record ID.
	 * @return string
	 */
	public static function seller_blocked( $seller_id ) {

		if ( ! in_array( get_post_type( (int) $seller_id ), Sellers::types(), true ) ) {
			return '';
		}

		if ( Earnings::has_orders( (int) $seller_id ) ) {
			return __( 'This seller cannot be deleted for good because orders refer to them. Move the record to the trash instead: that hides the seller and unpublishes their products, and the orders stay exactly as they are.', 'nexora' );
		}

		return '';
	}

	/**
	 * The refusal that applies to an operation on a post ('' when none).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $operation trash | delete.
	 * @return string
	 */
	public static function refusal( $post_id, $operation ) {

		$type = get_post_type( (int) $post_id );

		if ( Post_Types::CUSTOMER === $type && in_array( $operation, array( 'trash', 'delete' ), true ) ) {
			return self::customer_blocked( $post_id );
		}

		if ( 'delete' === $operation && in_array( $type, Sellers::types(), true ) ) {
			return self::seller_blocked( $post_id );
		}

		return '';
	}

	/**
	 * Refuses to trash a customer profile that still has a seller record.
	 *
	 * @param bool|null $trash Short-circuit value.
	 * @param \WP_Post  $post  The post.
	 * @return bool|null
	 */
	public function before_trash( $trash, $post ) {
		return $post instanceof \WP_Post && '' !== self::refusal( $post->ID, 'trash' ) ? false : $trash;
	}

	/**
	 * The delete rules. A seller record that is allowed to go takes its products, connections and import history with it.
	 *
	 * @param bool|null $delete Short-circuit value.
	 * @param \WP_Post  $post   The post.
	 * @return bool|\WP_Post|null
	 */
	public function before_delete( $delete, $post ) {

		if ( ! $post instanceof \WP_Post ) {
			return $delete;
		}

		if ( '' !== self::refusal( $post->ID, 'delete' ) ) {
			return false;
		}

		if ( ! in_array( $post->post_type, Sellers::types(), true ) || Sellers::is_internal() ) {
			return $delete;
		}

		// An administrator's delete, or the scheduled emptying of the trash: do the whole job. Anything else stays with Post_Guard.
		if ( 'trash' === $post->post_status || Admin_Gate::open( $post->ID, 'delete' ) ) {
			return self::remove_seller( $post->ID, true ) ? $post : false;
		}

		return $delete;
	}

	/**
	 * Deletes a seller record that has no orders, with everything that hangs off it.
	 *
	 * @param int  $seller_id        Seller record ID.
	 * @param bool $keep_profile_for A direct seller gets a customer profile (false when the account goes too).
	 * @return bool Whether it was deleted.
	 */
	public static function remove_seller( $seller_id, $keep_profile_for ) {

		$seller_id = (int) $seller_id;

		if ( '' !== self::seller_blocked( $seller_id ) ) {
			return false;
		}

		foreach ( Products::ids_for_vendor( $seller_id, 100000 ) as $product_id ) {
			Products::delete( $product_id );
		}

		Connection_Repository::delete_all_for_vendor( $seller_id );
		Log_Repository::delete_all_for_vendor( $seller_id );

		return Sellers::delete( $seller_id, $keep_profile_for );
	}

	/**
	 * A seller record went to the trash: their products are hidden (set to draft); the change is written down.
	 *
	 * @param int $post_id Post ID.
	 */
	public function after_trash( $post_id ) {

		$type = get_post_type( $post_id );

		if ( in_array( $type, Sellers::types(), true ) ) {
			foreach ( Products::ids_for_vendor( (int) $post_id, 100000 ) as $product_id ) {
				if ( 'publish' === get_post_status( $product_id ) ) {
					Products::set_status( $product_id, 'draft' );
				}
			}
		}

		if ( in_array( $type, Fields::types(), true ) && self::by_administrator() ) {
			Audit::record( $type, (int) $post_id, 'post_status', (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ), 'trash' );
		}
	}

	/**
	 * A restored record gets back the status it had (WordPress would make it a draft, and a draft seller is not a live one).
	 *
	 * @param string $new_status      The status WordPress would use.
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status The status before the trash.
	 * @return string
	 */
	public function restore_status( $new_status, $post_id, $previous_status ) {

		if ( in_array( get_post_type( $post_id ), Fields::types(), true ) && in_array( $previous_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			return $previous_status;
		}

		return $new_status;
	}

	/**
	 * A record was restored from the trash: the change is written down.
	 *
	 * @param int $post_id Post ID.
	 */
	public function after_untrash( $post_id ) {

		$type = get_post_type( $post_id );

		if ( in_array( $type, Fields::types(), true ) && self::by_administrator() ) {
			Audit::record( $type, (int) $post_id, 'post_status', 'trash', (string) get_post_status( $post_id ) );
		}
	}

	/**
	 * A record was deleted for good: that is written down too (the history stays).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 */
	public function after_delete( $post_id, $post = null ) {

		if ( $post instanceof \WP_Post && in_array( $post->post_type, Fields::types(), true ) && self::by_administrator() ) {
			Audit::record( $post->post_type, (int) $post_id, 'deleted', 'no', 'yes' );
		}
	}

	/**
	 * Whether an administrator is acting from wp-admin right now (only their changes are written to the history).
	 *
	 * @return bool
	 */
	private static function by_administrator() {
		return is_admin() && current_user_can( 'manage_options' );
	}

	/**
	 * On a genuine administrator trash / delete request that a rule refuses, say WHY (WordPress would only say "Error in deleting").
	 */
	public function explain_refusal() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Admin_Gate::open() verifies the nonce for this post and action.
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		// phpcs:enable

		if ( ! in_array( $action, array( 'trash', 'delete' ), true ) || ! Admin_Gate::open( $post_id, $action ) ) {
			return;
		}

		$message = self::refusal( $post_id, $action );

		if ( '' !== $message ) {
			wp_die(
				esc_html( $message ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}
}
