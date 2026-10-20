<?php

namespace Nexora\Vendors;

use Nexora\Accounts\Account_Types;
use Nexora\Core\Post_Types;
use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The administrator's screens for seller records (both types): list columns, a details box with the decision
 * buttons, and the admin-post action behind them (nonce + manage_options, then Service::change_status()). The
 * customer's own edit screen gets a read-only "Seller account" box.
 */
class Admin {

	const ACTION = 'nexora_vendor_status';

	/**
	 * Hooks the list columns, the details box, the decision action and its notice.
	 */
	public function __construct() {
		foreach ( Repository::types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		}

		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_status_request' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The list-table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {

		return array(
			'cb'            => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'         => __( 'Business', 'nexora' ),
			'gstin'         => __( 'GSTIN', 'nexora' ),
			'owner'         => __( 'Account', 'nexora' ),
			'vendor_status' => __( 'Status', 'nexora' ),
			'date'          => __( 'Applied', 'nexora' ),
		);
	}

	/**
	 * Prints one custom column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Vendor post ID.
	 */
	public function column( $column, $post_id ) {

		if ( 'gstin' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, 'gstin', true ) );
			return;
		}

		$user = get_userdata( Repository::user_for( $post_id ) );

		if ( 'owner' === $column ) {
			echo $user ? esc_html( $user->user_login ) : '&mdash;';
			return;
		}

		if ( 'vendor_status' === $column ) {
			echo $user ? esc_html( self::status_label( Account_Types::vendor_status( $user->ID ) ) ) : '&mdash;';
		}
	}

	/**
	 * Replaces the row links with the decision links that apply.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The row's post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( ! in_array( $post->post_type, Repository::types(), true ) ) {
			return $actions;
		}

		$edit = isset( $actions['edit'] ) ? array( 'edit' => $actions['edit'] ) : array();

		return $edit + $this->decision_links( $post->ID );
	}

	/**
	 * Registers the details box on both seller edit screens (and, for administrators, the read-only Products box directly below it),
	 * and the read-only "Seller account" box on the customer's.
	 *
	 * @param string        $post_type Post type being edited.
	 * @param \WP_Post|null $post      The post being edited.
	 */
	public function add_meta_boxes( $post_type = '', $post = null ) {

		foreach ( Repository::types() as $type ) {
			add_meta_box( 'nexora_vendor_details', __( 'Seller application', 'nexora' ), array( $this, 'render_details_box' ), $type, 'normal', 'high' );

			if ( $type === $post_type && $post instanceof \WP_Post && current_user_can( 'manage_options' ) ) {

				$total = Products_Box::total( $post->ID );

				add_meta_box(
					'nexora_seller_products',
					Products_Box::title( $total ),
					function ( $record ) use ( $total ) {
						Products_Box::render( $record, $total );
					},
					$type,
					'normal',
					'high'
				);
			}

			// The decision buttons: administrators only, top of the side column where the Publish box sits.
			if ( current_user_can( 'manage_options' ) ) {
				add_meta_box( 'nexora_vendor_decision', __( 'Application decision', 'nexora' ), array( $this, 'render_decision_box' ), $type, 'side', 'high' );
			}
		}

		add_meta_box( 'nexora_seller_account', __( 'Seller account', 'nexora' ), array( $this, 'render_seller_account_box' ), Post_Types::CUSTOMER, 'side', 'default' );
	}

	/**
	 * Prints the read-only "Seller account" box on a customer's edit screen: is this customer also a seller, the
	 * status, and a link to the seller record.
	 *
	 * @param \WP_Post $post Customer post.
	 */
	public function render_seller_account_box( $post ) {

		$user_id   = (int) get_post_meta( $post->ID, '_wp_user_id', true );
		$vendor_id = $user_id ? Repository::id_for_user( $user_id ) : 0;

		View::output(
			'admin/metabox-seller-account',
			array(
				'is_seller' => (bool) $vendor_id,
				'business'  => $vendor_id ? (string) get_post_meta( $vendor_id, 'business_name', true ) : '',
				'status'    => $vendor_id ? self::status_label( Account_Types::vendor_status( $user_id ) ) : '',
				'edit_url'  => $vendor_id ? admin_url( 'post.php?post=' . $vendor_id . '&action=edit' ) : '',
			)
		);
	}

	/**
	 * Prints the decision box: who applied, the current status and the buttons that are valid now.
	 * Prints nothing for anyone who may not decide.
	 *
	 * @param \WP_Post $post Vendor post.
	 */
	public function render_decision_box( $post ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = Repository::user_for( $post->ID );
		$user    = get_userdata( $user_id );
		$trashed = 'trash' === $post->post_status;

		View::output(
			'admin/metabox-vendor-decision',
			array(
				'status'   => $trashed ? self::status_label( Account_Types::REJECTED ) : self::status_label( $user ? Account_Types::vendor_status( $user_id ) : Account_Types::NONE ),
				'name'     => $user ? $user->display_name : '',
				'account'  => $user ? $user->user_login : '',
				'business' => (string) get_post_meta( $post->ID, 'business_name', true ),
				'links'    => $this->decision_links( $post->ID, true ),
			)
		);
	}

	/**
	 * Prints the details box (read-only; the decision is made with the buttons).
	 *
	 * @param \WP_Post $post Vendor post.
	 */
	public function render_details_box( $post ) {

		$user_id = Repository::user_for( $post->ID );
		$user    = get_userdata( $user_id );
		$status  = $user ? Account_Types::vendor_status( $user_id ) : Account_Types::NONE;

		$customer_id = (int) get_post_meta( $post->ID, \Nexora\Accounts\Links::CUSTOMER_LINK, true );

		View::output(
			'admin/metabox-vendor',
			array(
				'customer_url' => $customer_id && Post_Types::CUSTOMER === get_post_type( $customer_id ) ? admin_url( 'post.php?post=' . $customer_id . '&action=edit' ) : '',
				'data'         => Repository::data( $post->ID ),
				'labels'       => Fields::labels(),
				'types'        => Fields::business_types(),
				'account'      => $user ? $user->user_login : '',
				'email'        => $user ? $user->user_email : '',
				'status'       => self::status_label( $status ),
				'trashed'      => 'trash' === $post->post_status,
			)
		);
	}

	/**
	 * The decision links that are valid for the vendor's current status (each carries its own nonce).
	 *
	 * @param int  $vendor_id Vendor post ID.
	 * @param bool $buttons   Style the links as buttons (the decision box) instead of plain row links.
	 * @return array Label-bearing anchors keyed by action.
	 */
	private function decision_links( $vendor_id, $buttons = false ) {

		$user_id = Repository::user_for( $vendor_id );

		if ( ! $user_id || 'trash' === get_post_status( $vendor_id ) ) {
			return array();
		}

		$status = Account_Types::vendor_status( $user_id );
		$labels = array(
			'approve'    => __( 'Approve', 'nexora' ),
			'reject'     => __( 'Reject', 'nexora' ),
			'suspend'    => __( 'Suspend', 'nexora' ),
			'reactivate' => __( 'Reactivate', 'nexora' ),
		);
		$out    = array();

		foreach ( Service::actions() as $action => $path ) {

			if ( ! in_array( $status, $path[0], true ) ) {
				continue;
			}

			$class   = $buttons ? ' class="button' . ( in_array( $action, array( 'approve', 'reactivate' ), true ) ? ' button-primary' : '' ) . '"' : '';
			$confirm = 'reject' === $action
				? ' onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Are you sure? The application is marked rejected and kept. The applicant stays a regular member and can apply again.', 'nexora' ) ) ) . ');"'
				: '';

			$out[ $action ] = '<a' . $class . ' href="' . esc_url( self::action_url( $vendor_id, $action ) ) . '"' . $confirm . '>' . esc_html( $labels[ $action ] ) . '</a>';
		}

		return $out;
	}

	/**
	 * A signed link that asks for one decision.
	 *
	 * @param int    $vendor_id Vendor post ID.
	 * @param string $action    approve | suspend | reactivate | reject.
	 * @return string
	 */
	public static function action_url( $vendor_id, $action ) {

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::ACTION,
					'vendor_id' => (int) $vendor_id,
					'do'        => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $vendor_id . '_' . $action
		);
	}

	/**
	 * Handles a decision link: administrators only, signed, then back to the list with a notice.
	 */
	public function handle_status_request() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'nexora' ), '', array( 'response' => 403 ) );
		}

		// Reading a routing value; the nonce below is bound to this exact vendor and action.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0;
		$action    = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $vendor_id || ! in_array( get_post_type( $vendor_id ), Repository::types(), true ) || ! isset( Service::actions()[ $action ] ) ) {
			wp_die( esc_html__( 'Invalid request.', 'nexora' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::ACTION . '_' . $vendor_id . '_' . $action );

		$result = Service::change_status( $vendor_id, $action );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'            => get_post_type( $vendor_id ),
					'nexora_vendor_notice' => is_wp_error( $result ) ? 'error' : 'ok',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Shows the outcome of a decision on the list screen.
	 */
	public function notice() {

		// Display only; nothing is changed by this parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['nexora_vendor_notice'] ) ? sanitize_key( wp_unslash( $_GET['nexora_vendor_notice'] ) ) : '';

		if ( 'ok' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Vendor status updated.', 'nexora' ) . '</p></div>';
		} elseif ( 'error' === $state ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That change was not possible for this vendor\'s current status.', 'nexora' ) . '</p></div>';
		}
	}

	/**
	 * Display label of a vendor status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( $status ) {

		$labels = array(
			Account_Types::PENDING   => __( 'Pending', 'nexora' ),
			Account_Types::APPROVED  => __( 'Approved', 'nexora' ),
			Account_Types::SUSPENDED => __( 'Suspended', 'nexora' ),
			Account_Types::REJECTED  => __( 'Rejected', 'nexora' ),
			Account_Types::NONE      => __( 'Not a vendor', 'nexora' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ Account_Types::NONE ];
	}
}
