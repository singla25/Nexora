<?php
// Marketplace Phase 6a: the one hidden WooCommerce product that every marketplace purchase goes through.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Lock;
use Nexora\Marketplace\Marketplace_Item;

if ( ! function_exists( 'wc_get_product' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }

global $wpdb;
nx_test_marketplace_item_scope(); // a scratch item: the site's real one (real orders point at it) is never edited or deleted
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_nexora_marketplace_item' AND p.post_status <> 'trash'" ); };

/* ---------- created once, with the right shape ---------- */
$id = Marketplace_Item::id();
nx_assert( $id > 0 && 'product' === get_post_type( $id ), 'there is a Marketplace Item and it is a WooCommerce product' );
nx_assert_same( $id, (int) get_option( Marketplace_Item::OPTION ), 'its id is kept in an option' );
nx_assert_same( $id, Marketplace_Item::id(), 'asking again gives the same product (created once)' );
nx_assert_same( 1, $count(), 'exactly one Marketplace Item exists' );
$p = wc_get_product( $id );
nx_assert( $p && 'publish' === $p->get_status() && 'simple' === $p->get_type(), 'it is a published simple product' );
nx_assert( false === $p->is_virtual() && false === $p->is_downloadable(), 'it is PHYSICAL (not virtual), so Woo asks for a shipping address and charges shipping' );
nx_assert( 0.0 === (float) $p->get_price() && '0' === (string) $p->get_regular_price() && '' === (string) $p->get_sale_price(), 'its price is 0 (every cart line gets its real price from the product post)' );
nx_assert_same( 'none', $p->get_tax_status(), 'it is tax-free' );
nx_assert_same( 'hidden', $p->get_catalog_visibility(), 'it is hidden from the catalog' );
nx_assert( false === $p->get_manage_stock() && 'instock' === $p->get_stock_status() && false === $p->is_sold_individually(), 'Woo does not manage its stock (Nexora does) and it can be bought in any quantity' );
nx_assert( $p->is_purchasable(), 'it can be put in a cart' );
nx_assert_same( '1', (string) get_post_meta( $id, '_nexora_marketplace_item', true ), 'it is marked as the Marketplace Item' );
nx_assert( '' === $p->get_weight() && '' === $p->get_sku(), 'no weight and no SKU of its own' );

/* ---------- hidden from the shop, search, the Store API, the sitemap and its own URL ---------- */
nx_assert( ! in_array( $id, wc_get_products( array( 'visibility' => 'catalog', 'limit' => -1, 'return' => 'ids' ) ), true ), 'not in the shop catalog' );
nx_assert( ! in_array( $id, wc_get_products( array( 'visibility' => 'search', 'limit' => -1, 'return' => 'ids' ) ), true ), 'not in search results' );
rest_get_server();
define( 'REST_REQUEST', true );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/products' ) );
nx_assert( 200 === $res->get_status() && ! in_array( $id, array_column( (array) $res->get_data(), 'id' ), true ), 'not in the Store API product list' );
$args = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'product' ), 'product' );
nx_assert( in_array( $id, (array) ( $args['post__not_in'] ?? array() ), true ), 'excluded from the core sitemap of products' );
$other = apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'page' ), 'page' );
nx_assert( empty( $other['post__not_in'] ), 'other post types are not touched' );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'p' => $id, 'post_type' => 'product' ) );
Marketplace_Item::maybe_404();
nx_assert( is_404(), 'its own page answers 404' );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'sample-page' ) );
Marketplace_Item::maybe_404();
nx_assert( ! is_404(), 'other pages are not turned into a 404' );

/* ---------- vendors (and everyone but an administrator) cannot edit or delete it ---------- */
$vendor   = nx_test_vendor( 'miv', 'approved' );
$customer = nx_test_user( 'mic' );
$admin    = nx_test_admin();
foreach ( array( 'an approved vendor' => $vendor['user_id'], 'a customer' => $customer['user_id'] ) as $label => $uid ) {
	nx_assert( ! user_can( $uid, 'edit_post', $id ) && ! user_can( $uid, 'delete_post', $id ) && ! user_can( $uid, 'publish_post', $id ), "$label cannot edit, delete or publish it" );
}
nx_assert( user_can( $admin, 'edit_post', $id ), 'an administrator still can open it' );

/* ---------- trashed: restored (same product); deleted for good: recreated ---------- */
wp_trash_post( $id );
nx_assert_same( 'trash', get_post_status( $id ), 'setup: it was trashed' );
nx_assert_same( $id, Marketplace_Item::id(), 'a trashed item is restored (same id)' );
nx_assert_same( 'publish', get_post_status( $id ), 'and is published again' );
wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
nx_assert_same( $id, Marketplace_Item::id(), 'a draft item is published again (same id)' );
nx_assert_same( 'publish', get_post_status( $id ), '...and really is published' );
wc_get_product( $id )->set_regular_price( '99' );
$q = wc_get_product( $id ); $q->set_regular_price( '99' ); $q->set_price( '99' ); $q->set_virtual( true ); $q->set_tax_status( 'taxable' ); $q->save();
Marketplace_Item::id();
$p = wc_get_product( $id );
nx_assert( '0' === (string) $p->get_regular_price() && false === $p->is_virtual() && 'none' === $p->get_tax_status(), 'if someone edits it to a price, virtual or taxable, it is put back (price 0, physical, tax-free)' );

wp_delete_post( $id, true );
$new = (int) get_option( Marketplace_Item::OPTION );
nx_assert( $new > 0 && $new !== $id && null !== get_post( $new ) && 'publish' === get_post_status( $new ), 'deleted for good: a new one is created at once and the option follows' );
nx_assert_same( 1, $count(), 'still exactly one' );
nx_assert_same( $new, Marketplace_Item::id(), 'and id() returns it' );
$id = $new;

// A stale option (the product is gone) with another request having already made a new item: adopt it, do not make a second.
update_option( Marketplace_Item::OPTION, 99999999 );
nx_assert_same( $id, Marketplace_Item::id(), 'a stale option is repaired by finding the existing item (no duplicate)' );
nx_assert_same( 1, $count(), 'still exactly one' );
nx_assert_same( $id, (int) get_option( Marketplace_Item::OPTION ), 'and the option is corrected' );

// Concurrent creation: only one request creates it; the other gets "not now".
nx_test_write_raw_option( Marketplace_Item::OPTION, null ); // so deleting the scratch items does not make a new one
foreach ( nx_test_marketplace_item_scratch_ids() as $scratch_id ) { wp_delete_post( $scratch_id, true ); }
nx_assert_same( 0, $count(), 'setup: no item at all' );
nx_assert( Lock::take( Marketplace_Item::LOCK ), 'setup: another request holds the creation lock' );
nx_assert_same( 0, Marketplace_Item::id(), 'while the lock is held and there is no item, id() says 0 (try again) and creates nothing' );
nx_assert_same( 0, $count(), '...nothing was created' );
Lock::release( Marketplace_Item::LOCK );
$id = Marketplace_Item::id();
nx_assert( $id > 0 && 1 === $count(), 'once the lock is free it is created (once)' );

/* ---------- it is recognised, and cleaned up on uninstall ---------- */
nx_assert( Marketplace_Item::is_item( $id ) && ! Marketplace_Item::is_item( $id + 12345 ) && ! Marketplace_Item::is_item( 0 ) && ! Marketplace_Item::is_item( 'x' ), 'is_item() recognises only the Marketplace Item' );
nx_assert( in_array( Marketplace_Item::OPTION, \Nexora\Database\Uninstaller::defaults()['options'], true ), 'the option is on the uninstall list' );

Marketplace_Item::id(); // (the scratch items are removed and the real item put back when the test finishes)
nx_test_finish();
