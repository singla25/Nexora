<?php

namespace Nexora\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes user_connections posts. Meta keys: sender_/receiver_ + user_id, profile_id,
 * user_name, and status (pending, accepted, rejected, removed).
 */
class Repository {

	/**
	 * Profile ids connected (accepted) with this profile.
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array Profile IDs.
	 */
	public static function accepted_profile_ids( $profile_id ) {

		return Cache::remember(
			'ids',
			$profile_id,
			function () use ( $profile_id ) {
				return self::build_accepted_profile_ids( $profile_id );
			}
		);
	}

	/**
	 * Uncached version of accepted_profile_ids().
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array
	 */
	private static function build_accepted_profile_ids( $profile_id ) {

		$connections = get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'status',
						'value' => 'accepted',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => 'sender_profile_id',
							'value' => $profile_id,
						),
						array(
							'key'   => 'receiver_profile_id',
							'value' => $profile_id,
						),
					),
				),
			)
		);

		$ids = array();

		foreach ( $connections as $conn ) {

			$sender   = get_post_meta( $conn->ID, 'sender_profile_id', true );
			$receiver = get_post_meta( $conn->ID, 'receiver_profile_id', true );

			$ids[] = ( (int) $sender === (int) $profile_id ) ? $receiver : $sender;
		}

		return $ids;
	}

	/**
	 * Accepted connections of a profile as [connection post id, the other profile id] pairs, newest first.
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array<int,array{connection_id:int,profile_id:string}>
	 */
	public static function accepted_pairs( $profile_id ) {

		return Cache::remember(
			'pairs',
			$profile_id,
			function () use ( $profile_id ) {
				return self::build_accepted_pairs( $profile_id );
			}
		);
	}

	/**
	 * Uncached version of accepted_pairs().
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array
	 */
	private static function build_accepted_pairs( $profile_id ) {

		$connections = get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'status',
						'value' => 'accepted',
					),
					array(
						'relation' => 'OR',
						array(
							'key'   => 'sender_profile_id',
							'value' => $profile_id,
						),
						array(
							'key'   => 'receiver_profile_id',
							'value' => $profile_id,
						),
					),
				),
			)
		);

		$pairs = array();

		foreach ( $connections as $conn ) {

			$sender   = get_post_meta( $conn->ID, 'sender_profile_id', true );
			$receiver = get_post_meta( $conn->ID, 'receiver_profile_id', true );

			$pairs[] = array(
				'connection_id' => $conn->ID,
				'profile_id'    => ( (int) $sender === (int) $profile_id ) ? $receiver : $sender,
			);
		}

		return $pairs;
	}

	/**
	 * Profiles that must not be offered as "add new": the profile itself and everyone it already
	 * has a pending or accepted connection with.
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return int[] Profile IDs.
	 */
	public static function unavailable_profile_ids( $profile_id ) {

		return Cache::remember(
			'blocked',
			$profile_id,
			function () use ( $profile_id ) {
				return self::build_unavailable_profile_ids( $profile_id );
			}
		);
	}

	/**
	 * Uncached version of unavailable_profile_ids().
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array
	 */
	private static function build_unavailable_profile_ids( $profile_id ) {

		$connections = get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => 'sender_profile_id',
						'value' => $profile_id,
					),
					array(
						'key'   => 'receiver_profile_id',
						'value' => $profile_id,
					),
				),
			)
		);

		$blocked = array( $profile_id );

		foreach ( $connections as $conn_id ) {

			$status = get_post_meta( $conn_id, 'status', true );

			if ( in_array( $status, array( 'pending', 'accepted' ), true ) ) {
				$blocked[] = (int) get_post_meta( $conn_id, 'sender_profile_id', true );
				$blocked[] = (int) get_post_meta( $conn_id, 'receiver_profile_id', true );
			}
		}

		return array_values( array_unique( $blocked ) );
	}

	/**
	 * True when a pending or accepted connection exists between the two profiles, in either direction.
	 *
	 * @param int $profile_a First profile ID.
	 * @param int $profile_b Second profile ID.
	 * @return bool
	 */
	public static function active_between( $profile_a, $profile_b ) {

		$existing = get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'status',
						'value'   => array( 'pending', 'accepted' ),
						'compare' => 'IN',
					),
					array(
						'relation' => 'OR',
						array(
							'relation' => 'AND',
							array(
								'key'   => 'sender_profile_id',
								'value' => $profile_a,
							),
							array(
								'key'   => 'receiver_profile_id',
								'value' => $profile_b,
							),
						),
						array(
							'relation' => 'AND',
							array(
								'key'   => 'sender_profile_id',
								'value' => $profile_b,
							),
							array(
								'key'   => 'receiver_profile_id',
								'value' => $profile_a,
							),
						),
					),
				),
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Pending requests addressed to this profile.
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return \WP_Post[] Connection posts.
	 */
	public static function pending_for( $profile_id ) {

		return get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'receiver_profile_id',
						'value' => $profile_id,
					),
					array(
						'key'   => 'status',
						'value' => 'pending',
					),
				),
			)
		);
	}

	/**
	 * Every connection post received by this profile (any status).
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return \WP_Post[] Connection posts.
	 */
	public static function received_by( $profile_id ) {
		return self::by_side( 'receiver_profile_id', $profile_id );
	}

	/**
	 * Every connection post sent by this profile (any status).
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return \WP_Post[] Connection posts.
	 */
	public static function sent_by( $profile_id ) {
		return self::by_side( 'sender_profile_id', $profile_id );
	}

	/**
	 * Connection posts whose given profile meta equals the profile id.
	 *
	 * @param string $meta_key Connection meta key to match (sender_profile_id or receiver_profile_id).
	 * @param int    $profile_id Profile (user_profile) post ID.
	 * @return \WP_Post[]
	 */
	private static function by_side( $meta_key, $profile_id ) {

		return get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $profile_id,
					),
				),
			)
		);
	}

	/**
	 * Connection posts where the WP user is sender or receiver.
	 *
	 * @param int $user_id User ID.
	 * @return \WP_Post[] Connection posts.
	 */
	public static function for_user( $user_id ) {

		return get_posts(
			array(
				'post_type'      => 'user_connections',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => 'sender_user_id',
						'value' => $user_id,
					),
					array(
						'key'   => 'receiver_user_id',
						'value' => $user_id,
					),
				),
			)
		);
	}

	/**
	 * Create a pending request. $sender / $receiver: user_id, profile_id, user_name.
	 *
	 * @param array $sender Sending member: user_id, profile_id, user_name.
	 * @param array $receiver Receiving member: user_id, profile_id, user_name.
	 * @return int Post id, 0 on failure.
	 */
	public static function create_pending( array $sender, array $receiver ) {

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'user_connections',
				'post_status' => 'publish',
				'post_title'  => $sender['user_name'] . '->' . $receiver['user_name'],
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, 'sender_user_id', $sender['user_id'] );
		update_post_meta( $post_id, 'sender_profile_id', $sender['profile_id'] );
		update_post_meta( $post_id, 'sender_user_name', $sender['user_name'] );

		update_post_meta( $post_id, 'receiver_user_id', $receiver['user_id'] );
		update_post_meta( $post_id, 'receiver_profile_id', $receiver['profile_id'] );
		update_post_meta( $post_id, 'receiver_user_name', $receiver['user_name'] );

		update_post_meta( $post_id, 'status', 'pending' );

		return (int) $post_id;
	}

	/**
	 * Current status of a connection post.
	 *
	 * @param int $connection_id Connection (user_connections) post ID.
	 * @return string
	 */
	public static function status( $connection_id ) {
		return get_post_meta( $connection_id, 'status', true );
	}

	/**
	 * Stores a new status on a connection post.
	 *
	 * @param int    $connection_id Connection (user_connections) post ID.
	 * @param string $status Connection status: pending, accepted, rejected or removed.
	 */
	public static function set_status( $connection_id, $status ) {
		update_post_meta( $connection_id, 'status', $status );
	}
}
