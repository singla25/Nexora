<?php
// Products can only change through Products\Service: same guard as the vendor applications.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Products\Repository;
use Nexora\Products\Service;

global $wp_meta_boxes;
$A     = nx_test_vendor( 'pga', 'approved' );
$B     = nx_test_vendor( 'pgb', 'approved' );
$admin = nx_test_admin();
$r     = Service::save( $A['user_id'], array( 'name' => 'Guarded', 'description' => 'Original text', 'price' => '10', 'stock' => '5', 'sku' => 'G-1', 'category' => 'books' ) );
$id    = (int) ( $r['product_id'] ?? 0 );
$GLOBALS['nx_test']['posts'][] = $id;
$pt = get_post_type_object( 'nexora_product' );
nx_assert( $id > 0, 'setup: a product' );

foreach ( array( 'an administrator' => $admin, 'the owner' => $A['user_id'], 'another vendor' => $B['user_id'] ) as $who => $uid ) {
	wp_set_current_user( $uid );
	wp_trash_post( $id );
	nx_assert_same( 'publish', get_post_status( $id ), "$who: wp_trash_post is refused" );
	wp_delete_post( $id, true );
	nx_assert( null !== get_post( $id ), "$who: wp_delete_post is refused" );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'draft', 'post_title' => 'Changed', 'post_content' => 'Changed text' ) );
	nx_assert( 'publish' === get_post_status( $id ) && 'Guarded' === get_the_title( $id ) && 'Original text' === get_post_field( 'post_content', $id ), "$who: wp_update_post cannot change the status, name or description" );
	nx_assert( ! current_user_can( 'delete_post', $id ), "$who: the delete capability is withdrawn (no Trash link)" );
	nx_assert( ! current_user_can( $pt->cap->create_posts ), "$who: Add New is withdrawn" );
	wp_set_current_user( 0 );
}
$d = Repository::data( $id );
nx_assert( '10.00' === $d['price'] && 5 === $d['stock'] && 'G-1' === $d['sku'], 'the product data is intact' );

// A product cannot be created behind the validator, not even by code that bypasses the capability checks.
global $wpdb;
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" );
wp_set_current_user( $admin );
$forged = wp_insert_post( array( 'post_type' => 'nexora_product', 'post_title' => 'Forged', 'post_status' => 'publish' ) );
wp_set_current_user( 0 );
nx_assert( ! $forged || is_wp_error( $forged ), 'wp_insert_post of a product from outside the Repository is refused' );
nx_assert_same( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ), 'and no product appeared' );
nx_assert( ! current_user_can( $pt->cap->create_posts ), 'nobody has the capability to create a product by hand' );

/* ---------- the screen ---------- */
$wp_meta_boxes = array();
wp_set_current_user( $admin );
add_meta_box( 'submitdiv', 'Publish', '__return_null', 'nexora_product', 'side', 'core' );
do_action( 'add_meta_boxes', 'nexora_product', get_post( $id ) );
wp_set_current_user( 0 );
nx_assert( empty( $wp_meta_boxes['nexora_product']['side']['core']['submitdiv'] ), 'the Publish/Update box is removed from the product screen' );
nx_assert_same( array(), apply_filters( 'bulk_actions-edit-nexora_product', array( 'trash' => 'Trash', 'edit' => 'Edit' ) ), 'no bulk actions on the product list' );
$rows = apply_filters( 'post_row_actions', array( 'edit' => 'Edit', 'trash' => 'Trash', 'inline hide-if-no-js' => 'Quick Edit', 'view' => 'View' ), get_post( $id ) );
nx_assert( ! array_intersect( array_keys( $rows ), array( 'trash', 'untrash', 'delete', 'inline hide-if-no-js', 'view' ) ) && isset( $rows['edit'] ), 'the product rows keep only Edit (to read it)' );

/* ---------- the sanctioned paths still work ---------- */
nx_assert( is_array( Service::save( $A['user_id'], array( 'name' => 'Renamed OK', 'description' => 'New text', 'price' => '11', 'stock' => '6', 'sku' => 'G-1', 'category' => 'books' ), $id ) ) && 'Renamed OK' === get_the_title( $id ) && 'New text' === get_post_field( 'post_content', $id ), 'Service can still edit (name and description included)' );
nx_assert( true === Service::delete( $A['user_id'], $id ) && null === get_post( $id ), 'Service can still delete' );
nx_assert( false !== has_filter( 'pre_trash_post' ) && false !== has_filter( 'pre_delete_post' ) && false !== has_filter( 'wp_insert_post_data' ), 'the guard filters are registered' );

nx_test_finish();
