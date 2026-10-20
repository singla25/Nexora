<?php
// A read-only "Products (N)" box on the admin edit screen of a seller record (both seller types), directly below the details box:
// 5 products a page, newest first, with pagination through a GET parameter; drafts and pending count, trash does not; another
// seller's products never show; administrators only; everything escaped; no query per row.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Vendors\Products_Box;

nx_test_reset_limits();
global $wpdb, $wp_meta_boxes;
$admin = nx_test_admin();
wp_set_current_user( $admin );

$page = function ( $post_id, $n = null ) {
	if ( null === $n ) { unset( $_GET['nx_products_page'] ); } else { $_GET['nx_products_page'] = $n; }
	ob_start();
	Products_Box::render( get_post( $post_id ) );
	return ob_get_clean();
};
$ids_in = function ( $html ) { preg_match_all( '/post\.php\?post=(\d+)(?:&amp;|&#038;)action=edit"/', $html, $m ); return array_map( 'intval', $m[1] ); };
$title = function ( $type, $post_id ) use ( &$wp_meta_boxes ) {
	$wp_meta_boxes = array();
	do_action( 'add_meta_boxes', $type, get_post( $post_id ) );
	return $wp_meta_boxes[ $type ]['normal']['high']['nexora_seller_products']['title'] ?? null;
};

$S0 = nx_test_vendor( 'spb0', 'approved' );   // none
$S5 = nx_test_vendor( 'spb5', 'approved' );   // exactly 5
$S12 = nx_test_vendor( 'spb12', 'approved' ); // 12
$D  = nx_test_direct_seller( 'spbd', 'approved' ); // a direct seller (the other record type)
$O  = nx_test_vendor( 'spbo', 'approved' );   // somebody else
$mk = function ( $seller, $tag, $n ) { $out = array(); for ( $i = 1; $i <= $n; $i++ ) { $out[] = nx_test_product( $seller, "$tag-$i", '10.50', (string) ( $i * 3 ) ); } return $out; };
$p5  = $mk( $S5, 'F', 5 );
$p12 = $mk( $S12, 'T', 12 );
$pd  = $mk( $D, 'D', 2 );
$po  = $mk( $O, 'O', 3 );

/* ---------- nothing ---------- */
$html = $page( $S0['vendor_id'] );
nx_assert( false !== strpos( $html, 'No products yet' ) && array() === $ids_in( $html ), 'a seller with no products: "No products yet", no table rows' );
nx_assert( false === strpos( $html, 'tablenav-pages' ), '... and no pagination' );
nx_assert_same( 'Products (0)', $title( 'nexora_cust_seller', $S0['vendor_id'] ), 'the heading carries the count: Products (0)' );

/* ---------- exactly 5 ---------- */
$html = $page( $S5['vendor_id'] );
nx_assert_same( array_reverse( $p5 ), $ids_in( $html ), 'five products: all five, newest first' );
nx_assert( false === strpos( $html, 'tablenav-pages' ), '... a single page has no pagination' );
nx_assert_same( 'Products (5)', $title( 'nexora_cust_seller', $S5['vendor_id'] ), 'heading: Products (5)' );
foreach ( array( 'Product ID', 'Name', 'SKU', 'Price', 'Items in stock' ) as $col ) { nx_assert( false !== strpos( $html, '>' . $col . '<' ), "column: $col" ); }
nx_assert( false !== strpos( $html, '>F-5<' ) && false !== strpos( $html, '>10.50<' ) && false !== strpos( $html, '>15<' ), 'SKU, price and stock are shown exactly as stored' );

/* ---------- 12 products: 5, 5, 2 ---------- */
$rev = array_reverse( $p12 );
nx_assert_same( 'Products (12)', $title( 'nexora_cust_seller', $S12['vendor_id'] ), 'heading: Products (12)' );
nx_assert_same( array_slice( $rev, 0, 5 ), $ids_in( $page( $S12['vendor_id'] ) ), 'page 1 (the default): the 5 newest' );
nx_assert_same( array_slice( $rev, 5, 5 ), $ids_in( $page( $S12['vendor_id'], '2' ) ), 'page 2: the next 5' );
nx_assert_same( array_slice( $rev, 10, 2 ), $ids_in( $page( $S12['vendor_id'], '3' ) ), 'page 3: the last 2' );
// absint() turns -4 into 4 and '3.9x' into 3; both are then clamped to the last page (3). Anything that is not a number is page 1.
foreach ( array( '99', '0', '-4', 'abc', '3.9x', '' ) as $bad ) {
	$got = $ids_in( $page( $S12['vendor_id'], $bad ) );
	$last = in_array( $bad, array( '99', '-4', '3.9x' ), true );
	$want = array_slice( $rev, ( $last ? 10 : 0 ), ( $last ? 2 : 5 ) );
	nx_assert_same( $want, $got, "page '$bad' is clamped to a real page" );
}
$html = $page( $S12['vendor_id'], '2' );
nx_assert( false !== strpos( $html, 'tablenav-pages' ) && false !== strpos( $html, 'Previous' ) && false !== strpos( $html, 'Next' ), 'pagination has Previous and Next' );
nx_assert( false !== strpos( $html, 'nx_products_page=3' ) && false !== strpos( $html, 'nx_products_page=1' ), '... links to the other pages' );
nx_assert( false !== strpos( $html, 'post=' . $S12['vendor_id'] . '&#038;action=edit' ) || false !== strpos( $html, 'post=' . $S12['vendor_id'] . '&amp;action=edit' ), '... that stay on the same edit screen' );
nx_assert( false === strpos( $html, 'admin-ajax' ) && false === strpos( $html, '<script' ), 'no AJAX and no script' );

/* ---------- drafts and pending count, trash does not ---------- */
\Nexora\Products\Repository::set_status( $p5[0], 'draft' );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'pending' ), array( 'ID' => $p5[1] ) );
$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $p5[2] ) );
clean_post_cache( $p5[1] ); clean_post_cache( $p5[2] );
$got = $ids_in( $page( $S5['vendor_id'] ) );
nx_assert( in_array( $p5[0], $got, true ) && in_array( $p5[1], $got, true ) && ! in_array( $p5[2], $got, true ) && 4 === count( $got ), 'a draft and a pending product are listed, a trashed one is not' );
nx_assert_same( 'Products (4)', $title( 'nexora_cust_seller', $S5['vendor_id'] ), '... and the heading counts the same four' );

/* ---------- other sellers, both record types ---------- */
$all = array_merge( $ids_in( $page( $S12['vendor_id'] ) ), $ids_in( $page( $S12['vendor_id'], '2' ) ), $ids_in( $page( $S12['vendor_id'], '3' ) ) );
nx_assert( ! array_intersect( $all, array_merge( $po, $p5, $pd ) ) && 12 === count( array_unique( $all ) ), 'only this seller\'s 12 products appear, over all pages' );
$html = $page( $D['seller_id'] );
nx_assert_same( array_reverse( $pd ), $ids_in( $html ), 'a direct seller (nexora_seller) gets the box with their own products' );
nx_assert_same( 'Products (2)', $title( 'nexora_seller', $D['seller_id'] ), '... with its heading' );
nx_assert_same( array_reverse( $po ), $ids_in( $page( $O['vendor_id'] ) ), 'and the other seller sees theirs' );

/* ---------- placed right below the details box; the box is for administrators ---------- */
$wp_meta_boxes = array();
do_action( 'add_meta_boxes', 'nexora_cust_seller', get_post( $S12['vendor_id'] ) );
$keys = array_keys( $wp_meta_boxes['nexora_cust_seller']['normal']['high'] );
$at   = array_search( 'nexora_vendor_details', $keys, true );
nx_assert( false !== $at && ( $keys[ $at + 1 ] ?? '' ) === 'nexora_seller_products', 'the Products box comes directly after the seller details box' );
$cust = nx_test_user( 'spbc' );
foreach ( array( 'a customer' => $cust['user_id'], 'the seller themself' => $S12['user_id'], 'a guest' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	$wp_meta_boxes = array();
	do_action( 'add_meta_boxes', 'nexora_cust_seller', get_post( $S12['vendor_id'] ) );
	nx_assert( ! isset( $wp_meta_boxes['nexora_cust_seller']['normal']['high']['nexora_seller_products'] ), "$who does not get the box" );
	ob_start(); Products_Box::render( get_post( $S12['vendor_id'] ) ); nx_assert_same( '', ob_get_clean(), "$who gets nothing even if the renderer is called" );
}
wp_set_current_user( $admin );

/* ---------- escaping ---------- */
$evil = nx_test_product( $S0, 'EVIL-1', '1.00', '1', array( 'name' => '<script>alert(1)</script>Evil "name" & co' ) );
$html = $page( $S0['vendor_id'] );
nx_assert( false === strpos( $html, '<script' ), 'no script tag in the box' );
nx_assert_same( 'Products (1)', $title( 'nexora_cust_seller', $S0['vendor_id'] ), '(the heading counts it)' );
// A name that reaches the page with markup in it (written straight to the row) is escaped on output.
$wpdb->update( $wpdb->posts, array( 'post_title' => '<img src=x onerror=alert(1)> Name' ), array( 'ID' => $evil ) );
clean_post_cache( $evil );
$html = $page( $S0['vendor_id'] );
nx_assert( false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img src=x' ), 'a stored name containing markup is escaped on output' );

/* ---------- no query per row ---------- */
$count = function ( $post_id ) use ( $page ) {
	wp_cache_flush();
	$n = 0;
	$f = function ( $q ) use ( &$n ) { if ( 0 === stripos( ltrim( $q ), 'SELECT' ) ) { $n++; } return $q; };
	add_filter( 'query', $f );
	$page( $post_id );
	remove_filter( 'query', $f );
	return $n;
};
$one = nx_test_vendor( 'spbone', 'approved' ); $mk( $one, 'R1', 1 );
$five = nx_test_vendor( 'spbfive', 'approved' ); $mk( $five, 'R5', 5 );
$many = nx_test_vendor( 'spbmany', 'approved' ); $mk( $many, 'RM', 12 );
$q1 = $count( $one['vendor_id'] ); $q5 = $count( $five['vendor_id'] ); $qm = $count( $many['vendor_id'] );
nx_assert( $q5 === $q1 && $qm === $q1, "the same number of SELECTs for 1, 5 and 12 products ($q1 / $q5 / $qm): none per row" );
nx_assert( $q1 <= 8, "and few in total ($q1)" );

nx_test_finish();
