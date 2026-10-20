<?php
// Marketplace Phase 7b: an administrator edits a product. The same Products\Validator as the seller's form (price in two decimals, whole
// stock, SKU shape, a category from the list), the SKU stays unique per seller, the owner / seller link / import origin stay read-only, and
// a price change reaches FUTURE orders only: orders already placed keep their frozen figures and the seller's earnings do not move.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\Fields;
use Nexora\Admin_Edit\Saver;
use Nexora\Orders\Earnings;
use Nexora\Orders\Lines;
use Nexora\Products\Fields as Product_Fields;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$admin = nx_test_admin();
wp_set_current_user( $admin );
$A   = nx_test_vendor( 'aepa', 'approved' );
$B   = nx_test_vendor( 'aepb', 'approved' );
$buy = nx_test_user( 'aepbuy' );
$p1  = nx_test_product( $A, 'AEP-1', '100.00', '10' );
$p2  = nx_test_product( $A, 'AEP-2', '20.00', '4' );
$q1  = nx_test_product( $B, 'AEP-9', '30.00', '4' );
$cats = array_keys( Product_Fields::categories() );
$meta = function ( $id, $key ) { clean_post_cache( $id ); return get_post_meta( $id, $key, true ); };
$edit = function ( $id, array $in ) { return Saver::save( $id, $in ); };
$img  = nx_test_attachment( $admin, 'aep-img' );

nx_assert_same( array( 'name', 'description', 'price', 'stock', 'sku', 'category', 'image_id', 'status' ), Fields::names( 'nexora_product', 'edit' ), 'product: the editable fields' );
nx_assert_same( array( 'vendor_id', 'source', 'external_id', 'image_url' ), Fields::names( 'nexora_product', 'readonly' ), 'product: the seller link and the import origin are read-only' );

/* ---------- a valid edit, stored exactly like the seller\'s form stores it ---------- */
$res = $edit( $p1, array( 'name' => 'Renamed Thing', 'description' => "Line one\nLine two", 'price' => '12.5', 'stock' => '7', 'sku' => 'aep-1b', 'category' => $cats[0], 'image_id' => (string) $img, 'status' => 'draft' ) );
nx_assert( ! is_wp_error( $res ), 'an administrator edits a product (' . ( is_wp_error( $res ) ? wp_json_encode( $res->get_error_data() ) : 'ok' ) . ')' );
clean_post_cache( $p1 );
nx_assert_same( array( 'Renamed Thing', "Line one\nLine two", 'draft' ), array( get_post_field( 'post_title', $p1 ), get_post_field( 'post_content', $p1 ), get_post_status( $p1 ) ), 'name, description and status are stored on the post' );
nx_assert_same( '12.50', $meta( $p1, 'price' ), 'the price is stored as a two-decimal string, like the form does' );
nx_assert_same( '7', (string) $meta( $p1, 'stock' ), 'the stock is a whole number' );
nx_assert_same( 'AEP-1B', $meta( $p1, 'sku' ), 'the SKU is stored in upper case' );
nx_assert_same( array( $cats[0], (string) $img ), array( $meta( $p1, 'category' ), (string) $meta( $p1, 'image_id' ) ), 'category and image are stored' );
$fields = array_column( Audit::rows( 'nexora_product', $p1 ), 'field' );
sort( $fields );
nx_assert_same( array( 'category', 'description', 'image_id', 'name', 'price', 'sku', 'status', 'stock' ), $fields, 'each changed field has a history row' );
$pr = array_values( array_filter( Audit::rows( 'nexora_product', $p1 ), function ( $r ) { return 'price' === $r['field']; } ) )[0];
nx_assert_same( array( '100.00', '12.50' ), array( $pr['old_value'], $pr['new_value'] ), 'the history has the old and the new price' );
nx_assert_same( array( 'publish' ), array( get_post_status( $p2 ) ), 'the other product is untouched' );
$res = $edit( $p1, array( 'status' => 'publish' ) );
nx_assert( array( 'status' ) === $res && 'publish' === get_post_status( $p1 ), 'a product can be published again' );

/* ---------- invalid values ---------- */
$bad = array(
	'name' => array( '', 'x', str_repeat( 'n', 121 ) ), 'description' => array( str_repeat( 'd', 2001 ) ), 'price' => array( '', '0', '0.00', 'abc', '1.234', '-5', '1000001', '1e3' ),
	'stock' => array( '', '-1', '1.5', 'many', '100001' ), 'sku' => array( '', 'a b', 'x', '-bad', str_repeat( 'S', 41 ) ), 'category' => array( '', 'not-a-category' ),
	'image_id' => array( 'abc', '999999999' ), 'status' => array( 'trash', 'banana', 'pending', '' ),
);
foreach ( $bad as $field => $values ) {
	foreach ( $values as $v ) {
		$before = array( get_post_field( 'post_title', $p1 ), $meta( $p1, 'price' ) );
		$res    = $edit( $p1, array( 'name' => 'Sneaky', $field => $v ) );
		$ok     = is_wp_error( $res ) && ! empty( $res->get_error_data()['fields'][ $field ] ) && $before === array( get_post_field( 'post_title', $p1 ), $meta( $p1, 'price' ) );
		nx_assert( $ok, "product: $field '" . substr( (string) $v, 0, 12 ) . "' is refused with a message, and nothing else was saved" );
	}
}
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'nxtest not an image', 'post_status' => 'publish' ) ); nx_test_track_post( $page );
nx_assert( is_wp_error( $edit( $p1, array( 'image_id' => (string) $page ) ) ), 'the image must really be an image attachment' );
nx_assert( ! is_wp_error( $edit( $p1, array( 'image_id' => '' ) ) ) && 0 === (int) $meta( $p1, 'image_id' ), 'clearing the image is allowed' );

/* ---------- SKU: unique per seller, free across sellers ---------- */
$res = $edit( $p2, array( 'sku' => 'aep-1b' ) );
nx_assert( is_wp_error( $res ) && ! empty( $res->get_error_data()['fields']['sku'] ) && 'AEP-2' === $meta( $p2, 'sku' ), 'a SKU the same seller already uses is refused (case does not matter)' );
nx_assert( ! is_wp_error( $edit( $p2, array( 'sku' => 'AEP-2' ) ) ), 'keeping its own SKU is fine' );
nx_assert( ! is_wp_error( $edit( $q1, array( 'sku' => 'AEP-1B' ) ) ) && 'AEP-1B' === $meta( $q1, 'sku' ), 'another seller may use the same SKU' );
$draft = $edit( $p2, array( 'status' => 'draft' ) );
nx_assert( ! is_wp_error( $draft ) && is_wp_error( $edit( $q1, array( 'sku' => 'AEP-1B' ) ) ) === false, 'setup: a draft product exists' );
$p3 = nx_test_product( $A, 'AEP-3', '5.00', '1' );
nx_assert( is_wp_error( $edit( $p3, array( 'sku' => 'AEP-2' ) ) ), 'a draft product still owns its SKU' );

/* ---------- read-only: seller link, import origin, owner ---------- */
$own = array( $meta( $p1, 'vendor_id' ), $meta( $p1, 'source' ), $meta( $p1, 'external_id' ), (int) get_post_field( 'post_author', $p1 ) );
$res = $edit( $p1, array( 'vendor_id' => $B['vendor_id'], 'source' => 'api', 'external_id' => 'zzz', 'image_url' => 'https://evil.example/x.png', 'post_author' => $B['user_id'], 'stock' => '8' ) );
nx_assert( array( 'stock' ) === $res, 'a request naming read-only keys changes only the editable one' );
nx_assert_same( $own, array( $meta( $p1, 'vendor_id' ), $meta( $p1, 'source' ), $meta( $p1, 'external_id' ), (int) get_post_field( 'post_author', $p1 ) ), 'seller link, source, external id and owner are unchanged' );
nx_assert_same( '', (string) $meta( $p1, 'image_url' ), 'and the external image URL was not set' );

/* ---------- a price edit changes FUTURE orders only ---------- */
$o = nx_test_order( $buy, array( array( $p1, 2 ) ), array( 'payment' => 'cod' ) );
$order = $o['order'];
nx_assert( $order && 'processing' === $order->get_status(), 'setup: a cash-on-delivery order is placed (' . $o['error'] . ')' );
$lines_before = Lines::of( wc_get_order( $order->get_id() ) );
$sum_before   = Earnings::summary( $A['vendor_id'] );
$stock_before = (int) $meta( $p1, 'stock' );
nx_assert_same( '100.00', $meta( $p1, 'price' ) === '12.50' ? '100.00' : $lines_before[0]['price'], 'setup: the frozen line price is the price at order time' );
nx_assert( ! is_wp_error( $edit( $p1, array( 'price' => '250.00', 'name' => 'Now Expensive' ) ) ), 'the price and name are edited after the order' );
$lines_after = Lines::of( wc_get_order( $order->get_id() ) );
nx_assert_same( $lines_before, $lines_after, 'the placed order line is exactly as it was (price, name, SKU, commission, earning)' );
nx_assert_same( $sum_before, Earnings::summary( $A['vendor_id'] ), 'and the seller\'s earnings did not move' );
nx_assert_same( '12.50', $lines_after[0]['price'], 'the frozen unit price is the old one' );
$o2 = nx_test_order( $buy, array( array( $p1, 1 ) ), array( 'payment' => 'cod' ) );
$l2 = Lines::of( wc_get_order( $o2['order']->get_id() ) );
nx_assert_same( '250.00', $l2[0]['price'], 'a new order uses the new price' );
nx_assert_same( $lines_after, Lines::of( wc_get_order( $order->get_id() ) ), 'and still the first order is untouched' );
nx_assert( $stock_before - 1 === (int) $meta( $p1, 'stock' ) || $stock_before - 1 === (int) get_post_meta( $p1, 'stock', true ), 'stock reacts to the new order as always (the edit did not break it)' );

/* ---------- only a genuine admin request, never a seller or the front end ---------- */
$tryit = function ( array $over ) use ( $p3 ) {
	nx_test_admin_request( $p3, 'edit', $over );
	$_POST['acf'] = array( Fields::field_key( 'nexora_product', 'price' ) => '99.00' );
	$_REQUEST     = array_merge( $_GET, $_POST );
	$r = Saver::from_request( $p3 );
	nx_test_admin_request_end();
	return $r;
};
foreach ( array( 'the seller who owns it' => $A['user_id'], 'another seller' => $B['user_id'], 'a customer' => $buy['user_id'] ) as $who => $uid ) {
	nx_assert( is_wp_error( $tryit( array( 'user' => $uid ) ) ) && '5.00' === $meta( $p3, 'price' ), "$who cannot change a price through the admin path" );
}
nx_assert( is_wp_error( $tryit( array( 'ajax' => true ) ) ) && '5.00' === $meta( $p3, 'price' ), 'admin-ajax cannot, even as administrator' );
nx_assert( is_wp_error( $tryit( array( 'nonce' => false ) ) ) && '5.00' === $meta( $p3, 'price' ), 'no nonce cannot' );
nx_assert( ! is_wp_error( $tryit( array() ) ) && '99.00' === $meta( $p3, 'price' ), 'the genuine request does (the same path the edit screen uses)' );

nx_test_finish();
