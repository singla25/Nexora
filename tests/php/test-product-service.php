<?php
// Marketplace Phase 4: adding, editing and deleting products. Server-side rules, ownership, gating, rate limit.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Products\Repository;
use Nexora\Products\Service;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();

$A = nx_test_vendor( 'psa', 'approved' );
$B = nx_test_vendor( 'psb', 'approved' );
$P = nx_test_vendor( 'psp', 'pending' );
$S = nx_test_vendor( 'pss', 'suspended' );
$C = nx_test_user( 'psc' );
$admin = nx_test_admin();

$form = function ( $sku, array $over = array() ) {
	return array_merge( array( 'name' => 'Product ' . $sku, 'description' => 'About ' . $sku, 'price' => '499.00', 'stock' => '10', 'sku' => $sku, 'category' => 'fashion', 'image_id' => '0' ), $over );
};
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$track = function ( $r ) { if ( is_array( $r ) ) { $GLOBALS['nx_test']['posts'][] = $r['product_id']; } return $r; };

$pt = get_post_type_object( 'nexora_product' );
nx_assert( (bool) $pt && false === $pt->public && true === $pt->exclude_from_search && false === $pt->publicly_queryable && 'nexora-system' === $pt->show_in_menu, 'nexora_product is a private post type under Nexora System' );

/* ---------- add ---------- */
$r  = $track( Service::save( $A['user_id'], $form( 'AAA-1' ) ) );
$id = is_array( $r ) ? $r['product_id'] : 0;
nx_assert( $id > 0, 'an approved vendor can add a product' );
$d = Repository::data( $id );
nx_assert_same( 'nexora_product', get_post_type( $id ), 'it is a nexora_product' );
nx_assert_same( 'publish', get_post_status( $id ), 'approved vendors publish directly' );
nx_assert_same( $A['user_id'], (int) get_post_field( 'post_author', $id ), 'the author is the vendor user' );
nx_assert_same( $A['vendor_id'], $d['vendor_id'], 'vendor_id is the vendor\'s own application post' );
nx_assert_same( 'manual', $d['source'], 'source is manual' );
nx_assert_same( 'Product AAA-1', get_the_title( $id ), 'the title is the product name' );
nx_assert_same( 'About AAA-1', get_post_field( 'post_content', $id ), 'the description is the post content' );
nx_assert( '499.00' === $d['price'] && 10 === $d['stock'] && 'AAA-1' === $d['sku'] && 'fashion' === $d['category'], 'price, stock, sku and category are stored' );
nx_assert_same( $A['user_id'], Repository::owner_of( $id ), 'the owner resolves' );

/* ---------- who may add ---------- */
$before = $count();
foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor' => $S['user_id'], 'an administrator' => $admin, 'a guest' => 0 ) as $label => $uid ) {
	$r = Service::save( $uid, $form( 'NOPE-1' ) );
	nx_assert( is_wp_error( $r ) && 'nexora_forbidden' === $r->get_error_code(), "$label cannot add a product" );
}
nx_assert_same( $before, $count(), 'nothing was created by the refused attempts' );

/* ---------- validation ---------- */
$r = Service::save( $A['user_id'], $form( 'x', array( 'price' => '-5', 'stock' => 'lots', 'category' => 'cars', 'name' => 'A' ) ) );
nx_assert( is_wp_error( $r ) && 'nexora_invalid' === $r->get_error_code(), 'invalid fields are refused' );
nx_assert_same( array( 'name', 'price', 'stock', 'sku', 'category' ), array_keys( $r->get_error_data()['fields'] ), 'with a message per bad field' );
nx_assert_same( $before, $count(), 'and nothing is created' );
$r = $track( Service::save( $A['user_id'], $form( 'XSS-1', array( 'name' => '<script>alert(1)</script>Tee <b>Shirt</b>', 'description' => '<img src=x onerror=alert(1)>Nice' ) ) ) );
nx_assert( is_array( $r ) && false === strpos( get_the_title( $r['product_id'] ), '<' ) && false === strpos( get_post_field( 'post_content', $r['product_id'] ), '<' ), 'markup never reaches the stored name or description' );

/* ---------- SKU is unique per vendor, not globally ---------- */
$r = Service::save( $A['user_id'], $form( 'aaa-1', array( 'name' => 'Duplicate' ) ) );
nx_assert( is_wp_error( $r ) && isset( $r->get_error_data()['fields']['sku'] ), 'the same SKU (any case) twice for one vendor is refused' );
$rb = $track( Service::save( $B['user_id'], $form( 'AAA-1', array( 'name' => 'B version', 'price' => '10.00' ) ) ) );
nx_assert( is_array( $rb ) && $rb['product_id'] !== $id, 'another vendor may use the same SKU (their own product)' );
nx_assert( 'Product AAA-1' === get_the_title( $id ) && '499.00' === Repository::data( $id )['price'], 'and the first vendor\'s product is untouched' );
nx_assert_same( $id, Repository::find_by_sku( $A['vendor_id'], 'aaa-1' ), 'find_by_sku is scoped to the vendor' );
nx_assert_same( $rb['product_id'], Repository::find_by_sku( $B['vendor_id'], 'AAA-1' ), 'and to the other vendor' );

/* ---------- text is stored exactly as validated (no HTML-entity rewriting by WordPress's post filters) ---------- */
$tricky = array( 'name' => 'Tom & "Jerry" Tee <3 it\'s', 'description' => "Salt & pepper, 5 > 3 & \"quotes\"\nSecond line", 'price' => '12', 'stock' => '2', 'sku' => 'AMP-1', 'category' => 'other' );
wp_set_current_user( $A['user_id'] ); // a vendor: no unfiltered_html, so WordPress's kses filters are active
$r = $track( Service::save( $A['user_id'], $tricky ) );
$want = Nexora\Products\Validator::validate( $tricky )['data'];
$have = Repository::data( $r['product_id'] );
nx_assert( $want['name'] === $have['name'] && $want['description'] === $have['description'], 'name and description are stored exactly as validated (no &amp; rewriting): ' . wp_json_encode( array( $have['name'], $have['description'] ) ) );
$again = Service::import_row( $A['user_id'], $tricky, 'csv', '', false );
nx_assert( is_array( $again ) && 'unchanged' === $again['action'], 'importing the same text again is "unchanged", not an update' );
$card = Nexora\Products\Presenter::mine( $A['user_id'], $A['vendor_id'] );
$edit = array(); foreach ( $card as $c ) { if ( $c['id'] === $r['product_id'] ) { $edit = $c['edit']; } }
nx_assert( $want['name'] === ( $edit['name'] ?? '' ), 'the edit popup is filled with the real text, not an entity-encoded copy' );
wp_set_current_user( 0 );

/* ---------- edit own ---------- */
$r = Service::save( $A['user_id'], $form( 'AAA-1', array( 'name' => 'Renamed', 'price' => '600' ) ), $id );
nx_assert( is_array( $r ) && $id === $r['product_id'], 'a vendor can edit their product' );
nx_assert( 'Renamed' === get_the_title( $id ) && '600.00' === Repository::data( $id )['price'], 'the changes are stored (saving its own SKU is fine)' );
nx_assert_same( 'publish', get_post_status( $id ), 'editing keeps it published' );
$second = $track( Service::save( $A['user_id'], $form( 'AAA-2' ) ) );
$r = Service::save( $A['user_id'], $form( 'AAA-1' ), $second['product_id'] );
nx_assert( is_wp_error( $r ) && isset( $r->get_error_data()['fields']['sku'] ), 'renaming a SKU onto another of your own is refused' );

/* ---------- edit / delete another vendor's product ---------- */
$snapshot = Repository::data( $id ) + array( 'title' => get_the_title( $id ) );
$r = Service::save( $B['user_id'], $form( 'AAA-1', array( 'name' => 'HIJACKED', 'price' => '1' ) ), $id );
nx_assert( is_wp_error( $r ) && 'nexora_forbidden' === $r->get_error_code(), "a vendor cannot edit another vendor's product" );
nx_assert_same( $snapshot, Repository::data( $id ) + array( 'title' => get_the_title( $id ) ), "the other vendor's product is byte-for-byte untouched" );
$r = Service::delete( $B['user_id'], $id );
nx_assert( is_wp_error( $r ) && 'nexora_forbidden' === $r->get_error_code() && null !== get_post( $id ), "a vendor cannot delete another vendor's product" );
foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor (even their own)' => $S['user_id'] ) as $label => $uid ) {
	nx_assert( is_wp_error( Service::delete( $uid, $id ) ) && null !== get_post( $id ), "$label cannot delete a product" );
}
$mine_susp = Repository::create( $S['user_id'], $S['vendor_id'], Nexora\Products\Validator::validate( $form( 'SUSP-1' ) )['data'] );
$GLOBALS['nx_test']['posts'][] = $mine_susp;
nx_assert( is_wp_error( Service::save( $S['user_id'], $form( 'SUSP-1', array( 'name' => 'Edited' ) ), $mine_susp ) ) && 'Product SUSP-1' === get_the_title( $mine_susp ), 'a suspended vendor cannot edit their own existing product' );
nx_assert( is_wp_error( Service::delete( $S['user_id'], $mine_susp ) ) && null !== get_post( $mine_susp ), 'nor delete it' );
foreach ( array( 'a vendor application' => $A['vendor_id'], 'a profile' => $C['profile_id'], 'a missing id' => 99999999, 'zero' => 0, 'negative' => -4 ) as $label => $bad ) {
	nx_assert( is_wp_error( Service::delete( $A['user_id'], $bad ) ), "deleting $label is refused" );
	nx_assert( is_wp_error( Service::save( $A['user_id'], $form( 'ZZ-9' ), $bad ) ) || 0 === $bad, "editing $label is refused" );
}
nx_assert_same( 'nexora_vendor', get_post_type( $A['vendor_id'] ), 'a vendor application was not touched by those attempts' );

/* ---------- delete own ---------- */
$tmp = $track( Service::save( $A['user_id'], $form( 'TMP-1' ) ) );
nx_assert( true === Service::delete( $A['user_id'], $tmp['product_id'] ) && null === get_post( $tmp['product_id'] ), 'a vendor can delete their own product (permanently)' );
nx_assert( is_wp_error( Service::delete( $A['user_id'], $tmp['product_id'] ) ), 'deleting twice is an error, not a crash' );

/* ---------- images use the existing uploader: only your own uploads ---------- */
$own   = nx_test_attachment( $A['user_id'], 'mine' );
$theirs = nx_test_attachment( $B['user_id'], 'theirs' );
$pdf   = wp_insert_attachment( array( 'post_title' => 'doc', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_author' => $A['user_id'] ), false );
$GLOBALS['nx_test']['posts'][] = $pdf;
$r = $track( Service::save( $A['user_id'], $form( 'IMG-1', array( 'image_id' => (string) $own ) ) ) );
nx_assert( is_array( $r ) && (int) Repository::data( $r['product_id'] )['image_id'] === $own, 'an image the vendor uploaded can be attached' );
foreach ( array( "another member's upload" => $theirs, 'a PDF' => $pdf, 'a profile post' => $C['profile_id'], 'a missing id' => 987654321 ) as $label => $img ) {
	$r = Service::save( $A['user_id'], $form( 'IMG-X', array( 'image_id' => (string) $img ) ) );
	nx_assert( is_wp_error( $r ) && isset( $r->get_error_data()['fields']['image_id'] ), "$label is refused as a product image" );
}
nx_assert_same( 0, Repository::find_by_sku( $A['vendor_id'], 'IMG-X' ), 'and no product was created by those' );

/* ---------- limit per vendor ---------- */
add_filter( 'nexora_max_products', function () { return 3; } );
$C2 = nx_test_vendor( 'psd', 'approved' );
foreach ( array( 'L-1', 'L-2', 'L-3' ) as $sku ) { nx_assert( is_array( $track( Service::save( $C2['user_id'], $form( $sku ) ) ) ), "limit: $sku is within the limit" ); }
$r = Service::save( $C2['user_id'], $form( 'L-4' ) );
nx_assert( is_wp_error( $r ) && 'nexora_limit' === $r->get_error_code(), 'the 4th product is refused by the per-vendor limit' );
nx_assert( is_array( $track( Service::save( $C2['user_id'], $form( 'L-1', array( 'name' => 'Edit at limit' ) ), Repository::find_by_sku( $C2['vendor_id'], 'L-1' ) ) ) ), 'editing is still possible at the limit' );
remove_all_filters( 'nexora_max_products' );

/* ---------- deleting the vendor removes their products ---------- */
require_once ABSPATH . 'wp-admin/includes/user.php';
$gone = nx_test_vendor( 'psgone', 'approved' );
$g    = Service::save( $gone['user_id'], $form( 'GONE-1' ) );
wp_delete_user( $gone['user_id'] );
nx_assert_same( null, get_post( $g['product_id'] ), 'deleting a vendor deletes their products' );

/* ---------- AJAX ---------- */
$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$forbidden = function ( $r ) { return nx_rejected( $r ) && is_array( $r['data'] ?? null ) && 'forbidden' === ( $r['data']['code'] ?? '' ); };
$before = $count();
nx_assert( nx_rejected( $call( 0, 'nexora_product_save', $form( 'AJ-0' ) ) ), 'ajax save: a guest is rejected' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_product_save', $form( 'AJ-0' ), false ) ), 'ajax save: no nonce is rejected' );
foreach ( array( 'customer' => $C['user_id'], 'pending' => $P['user_id'], 'suspended' => $S['user_id'] ) as $label => $uid ) {
	nx_assert( $forbidden( $call( $uid, 'nexora_product_save', $form( 'AJ-0' ) ) ), "ajax save: $label is forbidden" );
	nx_assert( $forbidden( $call( $uid, 'nexora_product_delete', array( 'product_id' => $id ) ) ), "ajax delete: $label is forbidden" );
}
nx_assert( nx_rejected( $call( $admin, 'nexora_product_save', $form( 'AJ-0' ) ) ) && nx_rejected( $call( $admin, 'nexora_product_delete', array( 'product_id' => $id ) ) ), 'ajax: an administrator (no member profile, not a seller) is rejected' );
nx_assert_same( $before, $count(), 'no product was created or removed by the refused calls' );

$r = $call( $A['user_id'], 'nexora_product_save', $form( 'AJ-1' ) + array( 'vendor_id' => $B['vendor_id'], 'user_id' => $B['user_id'], 'source' => 'api', 'external_id' => 'evil-1', 'post_status' => 'draft', 'post_author' => $B['user_id'], 'author' => $B['user_id'] ) );
nx_assert( ! empty( $r['success'] ), 'ajax save: an approved vendor can add a product' );
$aj = Repository::find_by_sku( $A['vendor_id'], 'AJ-1' );
$GLOBALS['nx_test']['posts'][] = $aj;
$d = Repository::data( $aj );
nx_assert_same( $A['vendor_id'], $d['vendor_id'], 'ajax save: a posted vendor_id (another vendor) is ignored: the product belongs to the caller' );
nx_assert_same( $A['user_id'], (int) get_post_field( 'post_author', $aj ), 'ajax save: a posted user_id/author is ignored' );
nx_assert( 'manual' === $d['source'] && '' === $d['external_id'] && 'publish' === get_post_status( $aj ), 'ajax save: a posted source, external_id or status is ignored' );
nx_assert_same( 0, Repository::find_by_sku( $B['vendor_id'], 'AJ-1' ), 'ajax save: nothing was created under the other vendor' );

$r = $call( $A['user_id'], 'nexora_product_save', $form( 'AJ-1', array( 'name' => 'Edited by ajax' ) ) + array( 'product_id' => $aj, 'vendor_id' => $B['vendor_id'] ) );
nx_assert( ! empty( $r['success'] ) && 'Edited by ajax' === get_the_title( $aj ) && $A['vendor_id'] === Repository::data( $aj )['vendor_id'], 'ajax save: editing your own product works and cannot move it to another vendor' );
$r = $call( $B['user_id'], 'nexora_product_save', $form( 'AJ-1', array( 'name' => 'HIJACK' ) ) + array( 'product_id' => $aj, 'vendor_id' => $A['vendor_id'] ) );
nx_assert( $forbidden( $r ) && 'Edited by ajax' === get_the_title( $aj ), "ajax save: editing another vendor's product is forbidden, even naming their vendor_id" );
nx_assert( false === strpos( wp_json_encode( $r ), 'Test Shop' ) && false === strpos( wp_json_encode( $r ), $A['login'] ), 'ajax save: the refusal does not reveal who owns it' );
$r = $call( $B['user_id'], 'nexora_product_delete', array( 'product_id' => $aj, 'vendor_id' => $A['vendor_id'] ) );
nx_assert( $forbidden( $r ) && null !== get_post( $aj ), "ajax delete: another vendor's product cannot be deleted" );
$r = $call( $A['user_id'], 'nexora_product_save', $form( 'AJ-2', array( 'price' => '0' ) ) );
nx_assert( nx_rejected( $r ) && isset( $r['data']['fields']['price'] ), 'ajax save: validation errors come back per field' );
$r = $call( $A['user_id'], 'nexora_product_delete', array( 'product_id' => $aj ) );
nx_assert( ! empty( $r['success'] ) && null === get_post( $aj ), 'ajax delete: a vendor can delete their own product' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_product_delete', array( 'product_id' => 'abc' ) ) ) && nx_rejected( $call( $A['user_id'], 'nexora_product_delete', array() ) ) && nx_rejected( $call( $A['user_id'], 'nexora_product_delete', array( 'product_id' => array( 1 ) ) ) ), 'ajax delete: a bad product_id is rejected cleanly' );

/* ---------- rate limit ---------- */
add_filter( 'nexora_rate_limits', function ( $l ) { $l['product_save'] = array( 3, HOUR_IN_SECONDS ); return $l; } );
nx_test_reset_limits();
$R = nx_test_vendor( 'psr', 'approved' );
wp_set_current_user( $R['user_id'] );
$res = array();
for ( $i = 1; $i <= 4; $i++ ) { $res[] = nx_call_ajax( 'nexora_product_save', $form( "RL-$i" ) + nx_profile_nonce() ); }
wp_set_current_user( 0 );
$ids = array_filter( array( Repository::find_by_sku( $R['vendor_id'], 'RL-1' ), Repository::find_by_sku( $R['vendor_id'], 'RL-2' ), Repository::find_by_sku( $R['vendor_id'], 'RL-3' ), Repository::find_by_sku( $R['vendor_id'], 'RL-4' ) ) );
foreach ( $ids as $x ) { $GLOBALS['nx_test']['posts'][] = $x; }
nx_assert( ! empty( $res[0]['success'] ) && ! empty( $res[2]['success'] ) && nx_rejected( $res[3] ) && false !== stripos( (string) ( $res[3]['data']['message'] ?? $res[3]['data'] ?? '' ), 'too many' ), 'rate limit: the 4th save in the window is refused' );
nx_assert_same( 0, Repository::find_by_sku( $R['vendor_id'], 'RL-4' ), 'rate limit: and nothing was saved' );
remove_all_filters( 'nexora_rate_limits' );

nx_test_finish();
