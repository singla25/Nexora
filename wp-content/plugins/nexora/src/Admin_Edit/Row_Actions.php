<?php

namespace Nexora\Admin_Edit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The list-screen links of every record type with an administrator form: Edit, and for administrators Move to Trash / Restore /
 * Delete Permanently. The links carry the nonce of that exact post and action, which Core\Admin_Gate checks; Quick Edit is removed
 * (it would write titles and statuses around the validators).
 */
class Row_Actions {

	/**
	 * Hooks the filter (after the seller screens have set theirs).
	 */
	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'actions' ), 20, 2 );
	}

	/**
	 * Adjusts a row's links.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The row's post.
	 * @return array
	 */
	public function actions( $actions, $post ) {

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, Fields::types(), true ) ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'], $actions['view'] );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		if ( 'trash' === $post->post_status ) {
			$actions['untrash'] = $this->link( $post->ID, 'untrash', __( 'Restore', 'nexora' ), '' );
			$actions['delete']  = $this->link( $post->ID, 'delete', __( 'Delete Permanently', 'nexora' ), 'submitdelete' );
		} else {
			$actions['trash'] = $this->link( $post->ID, 'trash', __( 'Move to Trash', 'nexora' ), 'submitdelete' );
		}

		return $actions;
	}

	/**
	 * One link with the right nonce.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  trash | untrash | delete.
	 * @param string $label   Link text.
	 * @param string $css_class   CSS class.
	 * @return string
	 */
	private function link( $post_id, $action, $label, $css_class ) {

		$url = wp_nonce_url( admin_url( 'post.php?post=' . (int) $post_id . '&action=' . $action ), $action . '-post_' . (int) $post_id );

		return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</a>';
	}
}
