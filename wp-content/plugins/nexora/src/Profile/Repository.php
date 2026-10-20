<?php

namespace Nexora\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read access to member profile posts (a customer's, or a direct seller's), and the one place a plain customer post is made.
 */
class Repository {

	/**
	 * Profile post id of a WP user (0 when none).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function id_for_user( $user_id ) {

		$id = (int) get_user_meta( $user_id, '_profile_id', true );

		return \Nexora\Core\Post_Types::is_profile( $id ) ? $id : 0;
	}

	/**
	 * Creates a customer profile post for a user, linked both ways like a registration does it, and makes it their
	 * primary profile. Used by sign-up and when a direct seller's record is deleted.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $personal Profile meta (user_name, first_name, ...) to store; user_name is the post title.
	 * @return int|\WP_Error Post ID.
	 */
	public static function create_customer( $user_id, array $personal ) {

		$user_id = (int) $user_id;
		$title   = isset( $personal['user_name'] ) && '' !== $personal['user_name'] ? (string) $personal['user_name'] : (string) get_userdata( $user_id )->user_login;

		$post_id = wp_insert_post(
			array(
				'post_type'   => \Nexora\Core\Post_Types::CUSTOMER,
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
				'post_status' => 'publish',
				'post_author' => $user_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return is_wp_error( $post_id ) ? $post_id : new \WP_Error( 'nexora_save_failed', 'Could not save.' );
		}

		update_post_meta( $post_id, '_wp_user_id', $user_id );

		foreach ( $personal as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( '' === (string) get_post_meta( $post_id, 'user_name', true ) ) {
			update_post_meta( $post_id, 'user_name', $title );
		}

		update_user_meta( $user_id, '_profile_id', $post_id );

		return (int) $post_id;
	}

	/**
	 * Profile post id for a username (the user_name meta), or 0.
	 *
	 * @param string $username Username (the user_name meta).
	 * @return int
	 */
	public static function id_by_username( $username ) {

		$username = sanitize_user( $username, true );

		if ( '' === $username ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => \Nexora\Core\Post_Types::profiles(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'user_name',
						'value'   => $username,
						'compare' => '=',
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Name shown for a profile (first + last).
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return string
	 */
	public static function full_name( $profile_id ) {
		return trim( get_post_meta( $profile_id, 'first_name', true ) . ' ' . get_post_meta( $profile_id, 'last_name', true ) );
	}

	/**
	 * URL of a profile's image, or the default image.
	 *
	 * @param int $profile_id Profile post ID.
	 * @return string
	 */
	public static function get_profile_image( $profile_id ) {

		$image_id = get_post_meta( $profile_id, 'profile_image', true );

		$default_id  = get_option( 'default_profile_image' );
		$default_url = $default_id ? wp_get_attachment_url( $default_id ) : '';

		return $image_id
			? wp_get_attachment_url( $image_id )
			: $default_url;
	}
}
