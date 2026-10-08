<?php

namespace Nexora\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member posts (user_content). Meta: user_id, user_profile_id, user_name.
 */
class Repository {

	/**
	 * @return int New post id, 0 on failure.
	 */
	public static function create( $user_id, $profile_id, $title, $description, $image_id = 0 ) {

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'user_content',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		if ( $image_id ) {
			set_post_thumbnail( $post_id, $image_id );
		}

		update_post_meta( $post_id, 'user_id', $user_id );
		update_post_meta( $post_id, 'user_profile_id', $profile_id );
		update_post_meta( $post_id, 'user_name', get_post_meta( $profile_id, 'user_name', true ) );

		return (int) $post_id;
	}

	/** A profile's own posts, newest first. */
	public static function for_profile( $profile_id, $limit = 100 ) {

		return get_posts(
			array(
				'post_type'      => 'user_content',
				'posts_per_page' => $limit,
				'meta_query'     => array(
					array(
						'key'   => 'user_profile_id',
						'value' => $profile_id,
					),
				),
			)
		);
	}

	/**
	 * The content feed for a profile: every post written by someone else, newest first.
	 *
	 * @return array{posts:\WP_Post[],any_exist:bool} any_exist is true when the site has any posts at all.
	 */
	public static function feed_for( $profile_id ) {

		$all    = get_posts(
			array(
				'post_type'      => 'user_content',
				'posts_per_page' => -1,
			)
		);
		$others = array();

		foreach ( $all as $post ) {

			if ( (int) get_post_meta( $post->ID, 'user_profile_id', true ) === (int) $profile_id ) {
				continue;
			}

			$others[] = $post;
		}

		return array(
			'posts'     => $others,
			'any_exist' => ! empty( $all ),
		);
	}
}
