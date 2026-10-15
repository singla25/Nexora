<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes a Repository the only way to change the posts of a private post type (vendor applications, products).
 * A live post cannot be created, trashed, deleted, edited (status, title, content) or restored from wp-admin,
 * a bulk action, a crafted request or other code, because that would skip the Service's validation and
 * ownership rules. The Repository's own writes (flagged with Internal_Writes) and deleting the owning member
 * (their posts go with them) are allowed.
 */
class Post_Guard {

	/**
	 * The guarded post type.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Tells whether the current write comes from the Repository.
	 *
	 * @var callable
	 */
	private $is_internal;

	/**
	 * Post fields that cannot change from outside the Repository.
	 *
	 * @var string[]
	 */
	private $keep;

	/**
	 * True while WordPress deletes a user.
	 *
	 * @var bool
	 */
	private $deleting_user = false;

	/**
	 * Hooks every way a post of this type could be changed around its Service.
	 *
	 * @param string   $post_type   Post type to guard.
	 * @param callable $is_internal Returns true while the Repository is writing.
	 * @param string[] $keep        Post fields that must not change from outside.
	 */
	public function __construct( $post_type, callable $is_internal, array $keep = array( 'post_status', 'post_title' ) ) {

		$this->post_type   = $post_type;
		$this->is_internal = $is_internal;
		$this->keep        = $keep;

		add_action( 'add_meta_boxes', array( $this, 'remove_publish_box' ), 99, 1 );
		add_filter( 'bulk_actions-edit-' . $post_type, '__return_empty_array' );
		add_filter( 'map_meta_cap', array( $this, 'withdraw_delete_cap' ), 10, 4 );
		add_filter( 'pre_trash_post', array( $this, 'refuse_trash' ), 10, 2 );
		add_filter( 'pre_untrash_post', array( $this, 'refuse_untrash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'refuse_delete' ), 10, 2 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'refuse_create' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'keep_fields' ), 10, 2 );
		add_action( 'delete_user', array( $this, 'user_deleting' ) );
		add_action( 'deleted_user', array( $this, 'user_deleted' ) );
	}

	/**
	 * Removes the Publish / Update box (and with it Move to Trash, status and date) from the edit screen.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public function remove_publish_box( $post_type ) {

		if ( $this->post_type === $post_type ) {
			remove_meta_box( 'submitdiv', $this->post_type, 'side' );
		}
	}

	/**
	 * Nobody may delete a live post through the UI.
	 *
	 * @param string[] $caps    Required capabilities.
	 * @param string   $cap     Requested capability.
	 * @param int      $user_id User ID (unused).
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
	 * Refuses to trash a post unless the Repository does it.
	 *
	 * @param bool|null $trash Short-circuit value.
	 * @param \WP_Post  $post  The post.
	 * @return bool|null
	 */
	public function refuse_trash( $trash, $post ) {
		return $this->guarded( $post ) && ! $this->internal() ? false : $trash;
	}

	/**
	 * Refuses to restore a trashed post.
	 *
	 * @param bool|null $untrash Short-circuit value.
	 * @param \WP_Post  $post    The post.
	 * @return bool|null
	 */
	public function refuse_untrash( $untrash, $post ) {
		return $this->guarded( $post ) ? false : $untrash;
	}

	/**
	 * Refuses to delete a live post, except through the Repository or while deleting its member.
	 *
	 * @param bool|null $delete Short-circuit value.
	 * @param \WP_Post  $post   The post.
	 * @return bool|null
	 */
	public function refuse_delete( $delete, $post ) {

		if ( $this->guarded( $post ) && 'trash' !== $post->post_status && ! $this->internal() && ! $this->deleting_user ) {
			return false;
		}

		return $delete;
	}

	/**
	 * Refuses to create a post of this type from outside the Repository (wp_insert_post returns 0).
	 *
	 * @param bool  $is_empty Whether the post counts as empty.
	 * @param array $postarr  The submitted post array.
	 * @return bool
	 */
	public function refuse_create( $is_empty, $postarr ) {

		if ( isset( $postarr['post_type'] ) && $this->post_type === $postarr['post_type'] && empty( $postarr['ID'] ) && ! $this->internal() ) {
			return true;
		}

		return $is_empty;
	}

	/**
	 * Keeps an existing post's status, title and the like as they are unless the Repository writes them.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr The submitted post array.
	 * @return array
	 */
	public function keep_fields( $data, $postarr ) {

		if ( empty( $postarr['ID'] ) || $this->post_type !== $data['post_type'] || $this->internal() ) {
			return $data;
		}

		$current = get_post( (int) $postarr['ID'] );

		if ( $current && $this->post_type === $current->post_type ) {

			foreach ( $this->keep as $field ) {
				$data[ $field ] = wp_slash( $current->$field );
			}
		}

		return $data;
	}

	/**
	 * A member is being deleted: their posts may go with them.
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
	 * Whether the Repository is writing right now.
	 *
	 * @return bool
	 */
	private function internal() {
		return (bool) call_user_func( $this->is_internal );
	}

	/**
	 * Whether a post belongs to the guarded type.
	 *
	 * @param \WP_Post|mixed $post The post.
	 * @return bool
	 */
	private function guarded( $post ) {
		return $post instanceof \WP_Post && $this->post_type === $post->post_type;
	}

	/**
	 * Whether the ID is a guarded post that is not in the trash.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_live( $post_id ) {
		return get_post_type( $post_id ) === $this->post_type && 'trash' !== get_post_status( $post_id );
	}
}
