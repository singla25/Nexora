<?php

namespace Nexora\Imports;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only admin view of the import history: list columns and a details box.
 */
class Admin {

	/**
	 * Hooks the list columns and the details box.
	 */
	public function __construct() {
		add_filter( 'manage_nexora_import_log_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_nexora_import_log_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
	}

	/**
	 * The list-table columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {

		return array(
			'title'     => __( 'Import', 'nexora' ),
			'seller'    => __( 'Seller', 'nexora' ),
			'created'   => __( 'Created', 'nexora' ),
			'updated'   => __( 'Updated', 'nexora' ),
			'unchanged' => __( 'Unchanged', 'nexora' ),
			'skipped'   => __( 'Skipped', 'nexora' ),
			'date'      => isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'nexora' ),
		);
	}

	/**
	 * Prints one custom column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Log entry ID.
	 */
	public function column( $column, $post_id ) {

		if ( 'seller' === $column ) {
			echo esc_html( (string) get_post_meta( (int) get_post_meta( $post_id, 'vendor_id', true ), 'business_name', true ) );
			return;
		}

		if ( in_array( $column, array( 'created', 'updated', 'unchanged', 'skipped' ), true ) ) {
			echo esc_html( (string) (int) get_post_meta( $post_id, $column, true ) );
		}
	}

	/**
	 * Keeps only "Edit" (to read the entry) in the row links.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The row's post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( Log_Repository::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		return isset( $actions['edit'] ) ? array( 'edit' => $actions['edit'] ) : array();
	}

	/**
	 * Registers the details box.
	 */
	public function add_meta_boxes() {
		add_meta_box( 'nexora_import_details', __( 'Import details', 'nexora' ), array( $this, 'render_box' ), Log_Repository::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Prints the read-only details.
	 *
	 * @param \WP_Post $post Log entry.
	 */
	public function render_box( $post ) {

		$vendor_id = (int) get_post_meta( $post->ID, 'vendor_id', true );
		$entries   = Log_Repository::for_vendor( $vendor_id, 50 );
		$entry     = array();

		foreach ( $entries as $candidate ) {
			if ( (int) $candidate['id'] === (int) $post->ID ) {
				$entry = $candidate;
			}
		}

		View::output( 'imports/admin-box', array( 'entry' => $entry ) );
	}
}
