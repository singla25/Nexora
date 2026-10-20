<?php

namespace Nexora\Admin_Edit;

use Nexora\Accounts\Account_Types;
use Nexora\Core\Post_Types;
use Nexora\Imports\Connection_Repository;
use Nexora\Orders\Earnings;
use Nexora\Vendors\Repository as Sellers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What happens on the WordPress Users screen.
 *
 *  - Deleting a user whose seller record has order lines is REFUSED, with a plain message (the account stays; their orders stay valid).
 *  - Deleting any other user takes their Nexora records with them: seller record (with products, API connections, import history),
 *    customer profile, connections, member posts and notifications.
 *  - A row action "Create seller record for this user" makes an empty, pending seller record for an existing account (the shared
 *    linking method, Accounts\Links, does the linking). Nothing is approved by it.
 */
class Users {

	const ACTION = 'nexora_create_seller';

	/**
	 * Hooks the screen.
	 */
	public function __construct() {
		add_filter( 'map_meta_cap', array( $this, 'map_cap' ), 10, 4 );
		add_action( 'load-users.php', array( $this, 'check_screen' ) );
		add_action( 'delete_user', array( $this, 'cascade' ), 5 );
		add_filter( 'user_row_actions', array( $this, 'row_actions_filter' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_create' ) );
	}

	/**
	 * Why a user cannot be deleted ('' when they can): their seller record has order lines.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function refusal( $user_id ) {

		$seller_id = (int) get_user_meta( (int) $user_id, Sellers::USER_META, true );

		if ( $seller_id && in_array( get_post_type( $seller_id ), Sellers::types(), true ) && Earnings::has_orders( $seller_id ) ) {
			return __( 'This account belongs to a seller whose products appear in orders, so it cannot be deleted. Suspend the seller or move their record to the trash instead; the orders stay valid.', 'nexora' );
		}

		return '';
	}

	/**
	 * The refusal for a Users-screen delete request ('' when there is none): one user or a bulk list.
	 *
	 * @param array $request The request ($_REQUEST).
	 * @return string
	 */
	public static function refusal_for_request( array $request ) {

		if ( ! isset( $request['action'] ) || 'dodelete' !== $request['action'] ) {
			return '';
		}

		$ids = array();

		if ( isset( $request['users'] ) ) {
			$ids = array_map( 'intval', (array) $request['users'] );
		} elseif ( isset( $request['user'] ) ) {
			$ids = array( (int) $request['user'] );
		}

		foreach ( $ids as $id ) {

			$message = self::refusal( $id );

			if ( '' !== $message ) {
				return $message;
			}
		}

		return '';
	}

	/**
	 * WordPress does not offer, or allow, deleting such a user.
	 *
	 * @param string[] $caps    Required capabilities.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id Acting user (unused).
	 * @param array    $args    Arguments; the first is the user to delete.
	 * @return string[]
	 */
	public function map_cap( $caps, $cap, $user_id, $args ) {

		unset( $user_id );

		if ( 'delete_user' === $cap && ! empty( $args[0] ) && '' !== self::refusal( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * On the Users screen: a delete request that includes such a user stops with the plain message.
	 */
	public function check_screen() {

		// A refusal only: nothing is changed here, and WordPress checks its own nonce when it deletes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$message = self::refusal_for_request( wp_unslash( $_REQUEST ) );

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

	/**
	 * The member is being deleted: their Nexora records go with them.
	 *
	 * @param int $user_id User ID.
	 */
	public function cascade( $user_id ) {

		$user_id = (int) $user_id;

		if ( '' !== self::refusal( $user_id ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'        => array_merge( Sellers::types(), array( Post_Types::CUSTOMER ) ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per deleted account.
				'meta_query'       => array(
					array(
						'key'   => '_wp_user_id',
						'value' => $user_id,
					),
				),
			)
		);

		// Seller records first (a customer profile with a seller record cannot go before it).
		usort(
			$ids,
			function ( $a, $b ) {
				return (int) ( Post_Types::CUSTOMER === get_post_type( $a ) ) - (int) ( Post_Types::CUSTOMER === get_post_type( $b ) );
			}
		);

		foreach ( $ids as $id ) {
			if ( Post_Types::CUSTOMER === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			} else {
				Deletion::remove_seller( $id, false );
			}
		}

		$related = get_posts(
			array(
				'post_type'        => array( 'user_connections', 'user_content' ),
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per deleted account.
				'meta_query'       => array(
					'relation' => 'OR',
					array(
						'key'   => 'sender_user_id',
						'value' => $user_id,
					),
					array(
						'key'   => 'receiver_user_id',
						'value' => $user_id,
					),
					array(
						'key'   => 'user_id',
						'value' => $user_id,
					),
				),
			)
		);

		foreach ( $related as $id ) {
			wp_delete_post( $id, true );
		}

		( new \Nexora\Notifications\Repository() )->delete_for_user( $user_id );
	}

	/**
	 * The Users-screen row actions of a user.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_User $user    The row's user.
	 * @return array
	 */
	public static function row_actions( array $actions, $user ) {

		if ( ! $user instanceof \WP_User || ! current_user_can( 'manage_options' ) || Account_Types::has_seller_record( $user->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&user_id=' . (int) $user->ID ), self::ACTION . '_' . (int) $user->ID );

		$actions[ self::ACTION ] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Create seller record for this user', 'nexora' ) . '</a>';

		return $actions;
	}

	/**
	 * Filter wrapper for the row actions.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_User $user    The row's user.
	 * @return array
	 */
	public function row_actions_filter( $actions, $user ) {
		return self::row_actions( (array) $actions, $user );
	}

	/**
	 * Whether a create request is allowed: an administrator, and the nonce made for that user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce   The nonce sent.
	 * @return bool
	 */
	public static function request_ok( $user_id, $nonce ) {
		return current_user_can( 'manage_options' ) && false !== wp_verify_nonce( (string) $nonce, self::ACTION . '_' . (int) $user_id );
	}

	/**
	 * Makes the empty, pending seller record for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int|\WP_Error The new seller record ID.
	 */
	public static function create_seller_record( $user_id ) {

		$user_id = (int) $user_id;

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'nexora_no_user', __( 'Account not found.', 'nexora' ) );
		}

		if ( Account_Types::has_seller_record( $user_id ) || Sellers::id_for_user( $user_id ) ) {
			return new \WP_Error( 'nexora_already_applied', __( 'This user already has a seller record.', 'nexora' ) );
		}

		$id = \Nexora\Vendors\Service::open_blank( $user_id );

		if ( ! is_wp_error( $id ) ) {
			Audit::record( get_post_type( $id ), $id, 'created', 'none', 'by administrator' );
		}

		return $id;
	}

	/**
	 * The row action's request: check it, create the record, go to its edit screen.
	 */
	public function handle_create() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- request_ok() verifies the nonce made for this user.
		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable

		if ( ! self::request_ok( $user_id, $nonce ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'nexora' ), '', array( 'response' => 403 ) );
		}

		$id = self::create_seller_record( $user_id );

		if ( is_wp_error( $id ) ) {
			Acf_Bridge::push_notice( get_current_user_id(), $id );
			wp_safe_redirect( admin_url( 'users.php' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) );
		exit;
	}
}
