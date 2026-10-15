<?php

namespace Nexora\Imports;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only admin list of every vendor's saved API connections: the vendor, the address, the kind of authentication and
 * the last import. The key is never shown.
 */
class Connection_Admin {

	/**
	 * Hooks the list columns and the details box.
	 */
	public function __construct() {
		add_filter( 'manage_nexora_api_conn_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_nexora_api_conn_posts_custom_column', array( $this, 'column' ), 10, 2 );
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

		unset( $columns ); // The default columns (checkbox, date) are replaced on purpose: this list is read-only.

		return array(
			'title'       => __( 'Connection', 'nexora' ),
			'vendor'      => __( 'Vendor', 'nexora' ),
			'address'     => __( 'Address', 'nexora' ),
			'auth'        => __( 'Authentication', 'nexora' ),
			'last_import' => __( 'Last import', 'nexora' ),
		);
	}

	/**
	 * Prints one custom column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Connection ID.
	 */
	public function column( $column, $post_id ) {

		$vendor_id = (int) get_post_meta( $post_id, 'vendor_id', true );

		switch ( $column ) {
			case 'vendor':
				$user = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
				echo esc_html( (string) get_post_meta( $vendor_id, 'business_name', true ) . ( $user ? ' (' . $user->user_login . ')' : '' ) );
				break;
			case 'address':
				echo esc_html( (string) get_post_meta( $post_id, 'url', true ) );
				break;
			case 'auth':
				echo esc_html( self::auth_label( (string) get_post_meta( $post_id, 'auth_type', true ), (string) get_post_meta( $post_id, 'auth_header', true ) ) );
				break;
			case 'last_import':
				echo esc_html( self::last_import( $post_id ) );
				break;
		}
	}

	/**
	 * Keeps only "Edit" (to read the connection) in the row links.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The row's post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( Connection_Repository::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		return isset( $actions['edit'] ) ? array( 'edit' => $actions['edit'] ) : array();
	}

	/**
	 * Registers the details box.
	 */
	public function add_meta_boxes() {
		add_meta_box( 'nexora_conn_details', __( 'Connection details', 'nexora' ), array( $this, 'render_box' ), Connection_Repository::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Prints the read-only details (never the key).
	 *
	 * @param \WP_Post $post Connection post.
	 */
	public function render_box( $post ) {

		$vendor_id = (int) get_post_meta( $post->ID, 'vendor_id', true );

		View::output(
			'imports/connection-box',
			array(
				'name'    => (string) $post->post_title,
				'vendor'  => (string) get_post_meta( $vendor_id, 'business_name', true ),
				'address' => (string) get_post_meta( $post->ID, 'url', true ),
				'auth'    => self::auth_label( (string) get_post_meta( $post->ID, 'auth_type', true ), (string) get_post_meta( $post->ID, 'auth_header', true ) ),
				'last'    => self::last_import( $post->ID ),
			)
		);
	}

	/**
	 * The kind of authentication in words.
	 *
	 * @param string $type   none | header | bearer.
	 * @param string $header Header name.
	 * @return string
	 */
	public static function auth_label( $type, $header ) {

		if ( 'bearer' === $type ) {
			return __( 'Bearer token (saved)', 'nexora' );
		}

		if ( 'header' === $type ) {
			/* translators: %s: header name. */
			return sprintf( __( 'Header key: %s (saved)', 'nexora' ), $header );
		}

		return __( 'None (no key)', 'nexora' );
	}

	/**
	 * When the connection was last used for an import, and how it went.
	 *
	 * @param int $post_id Connection ID.
	 * @return string
	 */
	private static function last_import( $post_id ) {

		$when = (string) get_post_meta( $post_id, 'last_import', true );

		if ( '' === $when ) {
			return __( 'Never', 'nexora' );
		}

		return get_date_from_gmt( $when, 'j M Y H:i' ) . ' - ' . ( 'ok' === get_post_meta( $post_id, 'last_status', true ) ? __( 'ok', 'nexora' ) : __( 'failed', 'nexora' ) );
	}
}
