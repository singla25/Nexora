<?php

namespace Nexora\Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes Vendors\Service the only way to change a vendor application. A live application cannot be trashed,
 * deleted, edited (status or title), restored or created by hand from wp-admin, a bulk action, a crafted
 * request or other code: those would leave the member's status and the application out of step. Deleting the
 * member (their application goes with them) and the Repository's own writes are allowed.
 */
class Guard {

	/**
	 * True while WordPress deletes a user (their vendor post is deleted with them).
	 *
	 * @var bool
	 */
	private $deleting_user = false;

	/**
	 * Hooks every way a vendor post could be changed around the Service.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'remove_publish_box' ), 99, 1 );
		add_filter( 'bulk_actions-edit-' . Repository::POST_TYPE, '__return_empty_array' );
		add_filter( 'map_meta_cap', array( $this, 'withdraw_delete_cap' ), 10, 4 );
		add_filter( 'pre_trash_post', array( $this, 'refuse_trash' ), 10, 2 );
		add_filter( 'pre_untrash_post', array( $this, 'refuse_untrash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'refuse_delete' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'keep_status_and_title' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'user_deleting' ) );
		add_action( 'deleted_user', array( $this, 'user_deleted' ) );
	}

	/**
	 * Removes the Publish / Update box (and with it Move to Trash, status and date) from the vendor screen.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public function remove_publish_box( $post_type ) {

		if ( Repository::POST_TYPE === $post_type ) {
			remove_meta_box( 'submitdiv', Repository::POST_TYPE, 'side' );
		}
	}

	/**
	 * Nobody may delete a live application through the UI (rejecting is the decision that trashes it).
	 *
	 * @param string[] $caps    Required capabilities.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User ID.
	 * @param array    $args    Arguments; the first is the post ID for post capabilities.
	 * @return string[]
	 */
	public function withdraw_delete_cap( $caps, $cap, $user_id, $args ) {

		unset( $user_id );

		if ( 'delete_post' === $cap && ! empty( $args[0] ) && $this->is_live( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Refuses to trash a vendor post unless the Repository does it (reject).
	 *
	 * @param bool|null $trash Short-circuit value.
	 * @param \WP_Post  $post  The post.
	 * @return bool|null
	 */
	public function refuse_trash( $trash, $post ) {
		return $this->guarded( $post ) && ! Repository::is_internal() ? false : $trash;
	}

	/**
	 * Refuses to restore a rejected application (it would reserve its GSTIN again).
	 *
	 * @param bool|null $untrash Short-circuit value.
	 * @param \WP_Post  $post    The post.
	 * @return bool|null
	 */
	public function refuse_untrash( $untrash, $post ) {
		return $this->guarded( $post ) ? false : $untrash;
	}

	/**
	 * Refuses to delete a live application, except through the Repository or while deleting its member.
	 *
	 * @param bool|null $delete Short-circuit value.
	 * @param \WP_Post  $post   The post.
	 * @return bool|null
	 */
	public function refuse_delete( $delete, $post ) {

		if ( $this->guarded( $post ) && 'trash' !== $post->post_status && ! Repository::is_internal() && ! $this->deleting_user ) {
			return false;
		}

		return $delete;
	}

	/**
	 * Keeps the status and title of an existing application as they are unless the Repository writes them.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr The submitted post array.
	 * @return array
	 */
	public function keep_status_and_title( $data, $postarr ) {

		if ( empty( $postarr['ID'] ) || Repository::POST_TYPE !== $data['post_type'] || Repository::is_internal() ) {
			return $data;
		}

		$current = get_post( (int) $postarr['ID'] );

		if ( $current && Repository::POST_TYPE === $current->post_type ) {
			$data['post_status'] = $current->post_status;
			$data['post_title']  = wp_slash( $current->post_title );
		}

		return $data;
	}

	/**
	 * A member is being deleted: their application may go with them.
	 */
	public function user_deleting() {
		$this->deleting_user = true;
	}

	/**
	 * The member deletion is over.
	 */
	public function user_deleted() {
		$this->deleting_user = false;
	}

	/**
	 * Whether a post is a vendor application.
	 *
	 * @param \WP_Post|mixed $post The post.
	 * @return bool
	 */
	private function guarded( $post ) {
		return $post instanceof \WP_Post && Repository::POST_TYPE === $post->post_type;
	}

	/**
	 * Whether the ID is an application that is not (yet) rejected.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_live( $post_id ) {
		return Repository::POST_TYPE === get_post_type( $post_id ) && 'trash' !== get_post_status( $post_id );
	}
}
