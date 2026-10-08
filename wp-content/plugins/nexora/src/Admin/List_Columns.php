<?php

namespace Nexora\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extra columns on the three post-type list screens.
 */
class List_Columns {

	/**
	 * Adds the extra columns to the three post-type list screens.
	 */
	public function __construct() {

		add_filter( 'manage_user_profile_posts_columns', array( $this, 'add_name_column' ) );
		add_action( 'manage_user_profile_posts_custom_column', array( $this, 'manage_name_column' ), 10, 2 );

		add_filter( 'manage_user_connections_posts_columns', array( $this, 'add_status_column' ) );
		add_action( 'manage_user_connections_posts_custom_column', array( $this, 'manage_status_column' ), 10, 2 );

		add_filter( 'manage_user_content_posts_columns', array( $this, 'add_user_name_column' ) );
		add_action( 'manage_user_content_posts_custom_column', array( $this, 'manage_user_name_column' ), 10, 2 );
	}

	/**
	 * Adds a "Name" column after the title on the profile list.
	 *
	 * @param array $columns Existing list-table columns, keyed by column key.
	 * @return array Columns including the new one.
	 */
	public function add_name_column( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $value ) {

			$new_columns[ $key ] = $value;

			// Add after Title column
			if ( 'title' === $key ) {
				$new_columns['user_full_name'] = 'Name';
			}
		}

		return $new_columns;
	}

	/**
	 * Prints the member's first and last name in the "Name" column.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function manage_name_column( $column, $post_id ) {

		if ( 'user_full_name' === $column ) {

			$first_name = get_post_meta( $post_id, 'first_name', true );
			$last_name  = get_post_meta( $post_id, 'last_name', true );
			$full_name  = $first_name . ' ' . $last_name;

			echo esc_html( $full_name );
		}
	}

	/**
	 * Adds a "Status" column after the title on the connections list.
	 *
	 * @param array $columns Existing list-table columns, keyed by column key.
	 * @return array Columns including the new one.
	 */
	public function add_status_column( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $value ) {

			$new_columns[ $key ] = $value;

			// Add after Title column
			if ( 'title' === $key ) {
				$new_columns['connection_status'] = 'Status';
			}
		}

		return $new_columns;
	}

	/**
	 * Prints the connection status (Accepted / Rejected / Removed / Pending) with its colour.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function manage_status_column( $column, $post_id ) {

		if ( 'connection_status' === $column ) {

			$status = get_post_meta( $post_id, 'status', true );

			if ( ! $status ) {
				$status = 'pending';
			}

			if ( 'accepted' === $status ) {
				echo '<span style="color: green; font-weight: 600;">Accepted</span>';
			} elseif ( 'rejected' === $status ) {
				echo '<span style="color: red; font-weight: 600;">Rejected</span>';
			} elseif ( 'removed' === $status ) {
				echo '<span style="color: #374151; font-weight: 600;">Removed</span>';
			} else {
				echo '<span style="color: orange; font-weight: 600;">Pending</span>';
			}
		}
	}

	/**
	 * Adds a "Name" column after the title on the content list.
	 *
	 * @param array $columns Existing list-table columns, keyed by column key.
	 * @return array Columns including the new one.
	 */
	public function add_user_name_column( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $value ) {

			$new_columns[ $key ] = $value;

			// Add after Title column
			if ( 'title' === $key ) {
				$new_columns['user_name'] = 'Name';
			}
		}

		return $new_columns;
	}

	/**
	 * Prints the author's full name in the content list.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public function manage_user_name_column( $column, $post_id ) {

		if ( 'user_name' === $column ) {

			$user_profile_id = get_post_meta( $post_id, 'user_profile_id', true );
			$first_name      = get_post_meta( $user_profile_id, 'first_name', true );
			$last_name       = get_post_meta( $user_profile_id, 'last_name', true );
			$full_name       = $first_name . ' ' . $last_name;

			echo esc_html( $full_name );
		}
	}
}
