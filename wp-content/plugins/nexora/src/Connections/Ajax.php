<?php

namespace Nexora\Connections;

use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Http\Ajax as Http;
use Nexora\Http\Member_Ajax;
use Nexora\Http\Rate_Limiter;
use Nexora\Profile\Repository as Profiles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connections tab: find people, send / answer requests, history, connection lists.
 */
class Ajax extends Member_Ajax {

	/** @var Service */
	private $service;

	public function __construct( Service $service = null ) {

		$this->service = null !== $service ? $service : new Service();

		Http::register( 'get_add_new_users', array( $this, 'get_add_new_users' ) );
		Http::register( 'send_connection_request', array( $this, 'send_connection_request' ) );
		Http::register( 'get_requests', array( $this, 'get_requests' ) );
		Http::register( 'update_connection_status', array( $this, 'update_connection_status' ) );
		Http::register( 'get_history', array( $this, 'get_history' ) );
		Http::register( 'view_all_connection', array( $this, 'view_all_connection' ) );
		Http::register( 'view_mutual_connection', array( $this, 'view_mutual_connection' ) );
	}

	// GET NEW USER
	public function get_add_new_users() {

		$auth = $this->member();

		$users = get_posts(
			array(
				'post_type'      => 'user_profile',
				// Capped list of members to offer (a search box is the longer-term fix).
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'posts_per_page' => 200,
				'post__not_in'   => Repository::unavailable_profile_ids( $auth['profile_id'] ),
			)
		);

		$data = array();

		foreach ( $users as $user ) {

			$data[] = array(
				'profile_id' => $user->ID,
				'username'   => get_post_meta( $user->ID, 'user_name', true ),
				'name'       => Profiles::full_name( $user->ID ),
				'image'      => Profiles::get_profile_image( $user->ID ),
			);
		}

		wp_send_json_success( $data );
	}

	// SEND CONNECTION REQUEST
	public function send_connection_request() {

		$auth = $this->member();

		$receiver_profile_id = absint( $_POST['receiver_profile_id'] ?? 0 );

		if ( ! $receiver_profile_id || get_post_type( $receiver_profile_id ) !== 'user_profile' ) {
			wp_send_json_error( 'User not found' );
		}

		if ( Rate_Limiter::hit( 'connection_request', 'u' . $auth['user_id'] ) ) {
			wp_send_json_error( Rate_Limiter::message() );
		}

		$result = $this->service->send_request( $auth['user_id'], $auth['profile_id'], $receiver_profile_id );

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['message'] );
		}

		wp_send_json_success( 'Request sent' );
	}

	// GET REQUESTS
	public function get_requests() {

		$auth = $this->member();

		$data = array();

		foreach ( Repository::pending_for( $auth['profile_id'] ) as $conn ) {

			$sender = get_post_meta( $conn->ID, 'sender_profile_id', true );

			$data[] = array(
				'connection_id' => $conn->ID,
				'profile_id'    => $sender,
				'username'      => get_post_meta( $sender, 'user_name', true ),
				'name'          => Profiles::full_name( $sender ),
				'image'         => Profiles::get_profile_image( $sender ),
			);
		}

		wp_send_json_success( $data );
	}

	// REQUEST ACCEPTED / REJECT / REMOVED
	public function update_connection_status() {

		$auth = $this->member();

		$connection_id = absint( $_POST['connection_id'] ?? 0 );
		$status        = $this->post_value( 'status' );

		if ( ! $connection_id || get_post_type( $connection_id ) !== 'user_connections' ) {
			wp_send_json_error( 'Connection not found' );
		}

		if ( ! in_array( $status, array( 'accepted', 'rejected', 'removed' ), true ) ) {
			wp_send_json_error( 'Invalid status' );
		}

		$result = $this->service->change_status( $auth['user_id'], $connection_id, $status );

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['message'] );
		}

		wp_send_json_success();
	}

	// HISTORY
	public function get_history() {

		$auth = $this->member();

		$html = View::render(
			'profile/history',
			array(
				'received' => $this->history_rows( Repository::received_by( $auth['profile_id'] ), 'sender_profile_id' ),
				'sent'     => $this->history_rows( Repository::sent_by( $auth['profile_id'] ), 'receiver_profile_id' ),
			)
		);

		wp_send_json_success( $html );
	}

	/**
	 * Rows for the history cards: the other person on each connection post.
	 */
	private function history_rows( array $connections, $other_side_meta_key ) {

		$rows = array();

		foreach ( $connections as $conn ) {

			$other_id = get_post_meta( $conn->ID, $other_side_meta_key, true );
			$username = get_post_meta( $other_id, 'user_name', true );

			$rows[] = array(
				'status'   => get_post_meta( $conn->ID, 'status', true ),
				'username' => $username,
				'name'     => Profiles::full_name( $other_id ),
				'image'    => Profiles::get_profile_image( $other_id ),
				'date'     => get_the_date( 'd M Y', $conn->ID ),
				'time'     => get_the_time( 'h:i A', $conn->ID ),
				'link'     => Urls::profile( $username ),
			);
		}

		return $rows;
	}

	// VIEW ALL CONNECTIONS
	public function view_all_connection() {

		$this->member();

		$profile_id = absint( $_POST['profile_id'] ?? 0 );

		if ( ! $profile_id || get_post_type( $profile_id ) !== 'user_profile' ) {
			wp_send_json_error( 'Profile not found' );
		}

		wp_send_json_success(
			View::render(
				'profile/connection-cards',
				array(
					'users'         => $this->card_rows( Repository::accepted_profile_ids( $profile_id ) ),
					'empty_message' => 'No connections found',
					'mutual'        => false,
				)
			)
		);
	}

	// VIEW MUTUAL CONNECTIONS
	public function view_mutual_connection() {

		$auth             = $this->member();
		$other_profile_id = absint( $_POST['profile_id'] ?? 0 );

		if ( ! $other_profile_id || get_post_type( $other_profile_id ) !== 'user_profile' ) {
			wp_send_json_error( 'Profile not found' );
		}

		$mutual_ids = array_intersect(
			Repository::accepted_profile_ids( $auth['profile_id'] ),
			Repository::accepted_profile_ids( $other_profile_id )
		);

		wp_send_json_success(
			View::render(
				'profile/connection-cards',
				array(
					'users'         => $this->card_rows( $mutual_ids ),
					'empty_message' => 'No mutual connections found',
					'mutual'        => true,
				)
			)
		);
	}

	/**
	 * Cards for a list of profile ids.
	 */
	private function card_rows( array $profile_ids ) {

		$rows = array();

		foreach ( $profile_ids as $id ) {

			$username = get_post_meta( $id, 'user_name', true );

			$rows[] = array(
				'username'     => $username,
				'name'         => Profiles::full_name( $id ),
				'image'        => Profiles::get_profile_image( $id ),
				'profile_link' => Urls::profile( $username ),
			);
		}

		return $rows;
	}
}
