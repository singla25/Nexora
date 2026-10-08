<?php

namespace Nexora\Content;

use Nexora\Core\View;
use Nexora\Http\Ajax as Http;
use Nexora\Http\Member_Ajax;
use Nexora\Http\Rate_Limiter;
use Nexora\Profile\Private_Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts a member publishes on their profile.
 */
class Ajax extends Member_Ajax {

	public function __construct() {
		Http::register( 'save_user_content', array( $this, 'save_user_content' ) );
		Http::register( 'get_user_content_history', array( $this, 'get_user_content_history' ) );
	}

	// ADD NEW CONTENT
	public function save_user_content() {

		$auth       = $this->member();
		$user_id    = $auth['user_id'];
		$profile_id = $auth['profile_id'];

		$title       = $this->post_value( 'title' );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$image_id    = absint( $_POST['image'] ?? 0 );

		if ( '' === $title ) {
			wp_send_json_error( 'Title is required' );
		}

		if ( Rate_Limiter::hit( 'content_save', 'u' . $user_id ) ) {
			wp_send_json_error( Rate_Limiter::message() );
		}

		// The image must be the member's own, and never a private ID document
		if ( $image_id && ( ! $this->owns_attachment( $image_id, $user_id ) || Private_Documents::is_private( $image_id ) ) ) {
			wp_send_json_error( 'Invalid image selected' );
		}

		if ( ! Repository::create( $user_id, $profile_id, $title, $description, $image_id ) ) {
			wp_send_json_error( 'Failed to create post' );
		}

		wp_send_json_success( 'Post created' );
	}

	// HISTORY
	public function get_user_content_history() {

		$auth = $this->member();

		$rows = array();

		// Only the current member's content
		foreach ( Repository::for_profile( $auth['profile_id'] ) as $post ) {
			$rows[] = array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'image'   => get_the_post_thumbnail_url( $post->ID, 'medium' ),
				'date'    => get_the_date( 'Y-m-d H:i', $post->ID ),
			);
		}

		wp_send_json_success( View::render( 'profile/content-history', array( 'posts' => $rows ) ) );
	}
}
