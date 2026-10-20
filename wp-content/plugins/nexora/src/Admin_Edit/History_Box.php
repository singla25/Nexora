<?php

namespace Nexora\Admin_Edit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-only "Change history" box on the edit screen of every record type an administrator can edit.
 */
class History_Box {

	/**
	 * Hooks the box.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 1 );
	}

	/**
	 * Adds the box to a record type's edit screen.
	 *
	 * @param string $post_type Post type being edited.
	 */
	public function add( $post_type ) {

		if ( in_array( $post_type, Fields::types(), true ) ) {
			add_meta_box( 'nexora_change_history', __( 'Change history', 'nexora' ), array( __CLASS__, 'render' ), $post_type, 'normal', 'low' );
		}
	}

	/**
	 * Prints the history of a post: when, who, which field, from what to what. Nothing here can be changed.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function render( $post ) {

		$rows = $post instanceof \WP_Post ? Audit::rows( $post->post_type, $post->ID ) : array();

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No changes by an administrator have been recorded.', 'nexora' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When (UTC)', 'nexora' ) . '</th><th>' . esc_html__( 'Who', 'nexora' ) . '</th><th>' . esc_html__( 'Field', 'nexora' ) . '</th><th>' . esc_html__( 'Before', 'nexora' ) . '</th><th>' . esc_html__( 'After', 'nexora' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {

			$user = get_userdata( (int) $row['user_id'] );
			$who  = $user ? $user->display_name : sprintf(
				/* translators: %d: user ID */
				__( 'user #%d', 'nexora' ),
				(int) $row['user_id']
			);
			$secret = Audit::is_secret_field( $row['field'] );

			echo '<tr><td>' . esc_html( $row['created_at'] ) . '</td><td>' . esc_html( $who ) . '</td><td>' . esc_html( $row['field'] ) . '</td>';
			echo '<td>' . ( $secret ? esc_html__( '[value not recorded]', 'nexora' ) : esc_html( $row['old_value'] ) ) . '</td>';
			echo '<td>' . ( $secret ? esc_html__( '[value not recorded]', 'nexora' ) : esc_html( $row['new_value'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
