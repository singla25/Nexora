<?php

namespace Nexora\Products;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only admin screens for products: list columns and a details box. Nothing here changes a product.
 */
class Admin {

	/**
	 * Hooks the list columns and the details box.
	 */
	public function __construct() {
		add_filter( 'manage_nexora_product_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_nexora_product_posts_custom_column', array( $this, 'column' ), 10, 2 );
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
			'cb'       => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'    => __( 'Product', 'nexora' ),
			'seller'   => __( 'Seller', 'nexora' ),
			'sku'      => __( 'SKU', 'nexora' ),
			'price'    => __( 'Price', 'nexora' ),
			'stock'    => __( 'Stock', 'nexora' ),
			'category' => __( 'Category', 'nexora' ),
			'source'   => __( 'Source', 'nexora' ),
		);
	}

	/**
	 * Prints one custom column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public function column( $column, $post_id ) {

		$data = Repository::data( $post_id );

		switch ( $column ) {
			case 'seller':
				echo esc_html( (string) get_post_meta( $data['vendor_id'], 'business_name', true ) );
				break;
			case 'sku':
				echo esc_html( $data['sku'] );
				break;
			case 'price':
				echo esc_html( Presenter::money( (float) $data['price'] ) );
				break;
			case 'stock':
				echo esc_html( (string) $data['stock'] );
				break;
			case 'category':
				$cats = Fields::categories();
				echo esc_html( isset( $cats[ $data['category'] ] ) ? $cats[ $data['category'] ] : '' );
				break;
			case 'source':
				echo esc_html( $data['source'] );
				break;
		}
	}

	/**
	 * Keeps only "Edit" (to read the product) in the row links.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post    The row's post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {

		if ( Repository::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		return isset( $actions['edit'] ) ? array( 'edit' => $actions['edit'] ) : array();
	}

	/**
	 * Registers the details box.
	 */
	public function add_meta_boxes() {
		add_meta_box( 'nexora_product_details', __( 'Product details', 'nexora' ), array( $this, 'render_details_box' ), Repository::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Prints the read-only details.
	 *
	 * @param \WP_Post $post Product post.
	 */
	public function render_details_box( $post ) {

		$data = Repository::data( $post->ID );
		$cats = Fields::categories();
		$user = get_userdata( Repository::owner_of( $post->ID ) );

		View::output(
			'admin/metabox-product',
			array(
				'data'     => $data,
				'category' => isset( $cats[ $data['category'] ] ) ? $cats[ $data['category'] ] : '',
				'seller'   => (string) get_post_meta( $data['vendor_id'], 'business_name', true ),
				'account'  => $user ? $user->user_login : '',
				'price'    => Presenter::money( (float) $data['price'] ),
				'status'   => $post->post_status,
			)
		);
	}
}
