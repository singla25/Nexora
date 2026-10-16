<?php
// Marketplace Phase 6b: the My Orders and Sales tabs. A customer sees their own orders as read-only cards; a vendor (approved, or
// suspended for reading) sees only the order lines of their own products with the earning per line and totals; a vendor can never open
// another vendor's order; the buyer's billing details and e-mail never reach a vendor, only what shipping needs.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Core\View;
use Nexora\Orders\Earnings;
use Nexora\Profile\Page;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$A = nx_test_vendor( 'oaa', 'approved' );
$B = nx_test_vendor( 'oab', 'approved' );
$S = nx_test_vendor( 'oas', 'approved' );
$P = nx_test_vendor( 'oap', 'pending' );
$C = nx_test_user( 'oac' );
$D = nx_test_user( 'oad' );
$N = nx_test_user( 'oan' );
$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$has = function ( $html, $id ) { return 1 === preg_match( '/#' . (int) $id . '(?!\d)/', $html ); };
$forbidden = function ( $r ) { return nx_rejected( $r ) && is_array( $r['data'] ?? null ) && 'forbidden' === ( $r['data']['code'] ?? '' ); };

/* ---------- the tabs exist ---------- */
nx_assert( in_array( 'my-orders', Page::BUILT_TABS, true ) && in_array( 'sales', Page::BUILT_TABS, true ), 'My Orders and Sales are built tabs now' );
nx_assert( in_array( 'my-orders', Account_Types::tabs_for( $C['user_id'] ), true ) && ! in_array( 'sales', Account_Types::tabs_for( $C['user_id'] ), true ), 'a customer has My Orders and no Sales' );
nx_assert( in_array( 'sales', Account_Types::tabs_for( $A['user_id'] ), true ) && in_array( 'my-orders', Account_Types::tabs_for( $A['user_id'] ), true ), 'a vendor has both' );
$shell_orders = View::render( 'profile/tab-my-orders', array() );
$shell_sales  = View::render( 'profile/tab-sales', array() );
nx_assert( 1 === preg_match( '/id="my-orders"[^>]*class="[^"]*tab-content|class="[^"]*tab-content[^"]*"[^>]*id="my-orders"/', $shell_orders ) && false !== strpos( $shell_orders, 'nx-orders-results' ), 'the My Orders shell is a tab with a results area' );
nx_assert( 1 === preg_match( '/id="sales"[^>]*class="[^"]*tab-content|class="[^"]*tab-content[^"]*"[^>]*id="sales"/', $shell_sales ) && false !== strpos( $shell_sales, 'nx-sales-results' ) && false !== strpos( $shell_sales, 'nx-sales-totals' ), 'the Sales shell is a tab with totals and a results area' );
nx_assert( false === strpos( $shell_orders . $shell_sales, '<script' ), 'neither shell prints a script' );

/* ---------- orders to look at ---------- */
$pa = nx_test_product( $A, 'OA-A', '100.00', '50', array( 'name' => 'Fish & Chips Set' ) );
$pb = nx_test_product( $B, 'OA-B', '30.00', '50' );
$bill = array( 'billing_email' => 'private.buyer@example.test', 'billing_phone' => '9876500001', 'billing_address_1' => '77 Secret Billing Lane' );
$r1 = nx_test_order( $C, array( array( $pa, 2 ), array( $pb, 1 ) ), array( 'payment' => 'cod', 'billing' => $bill ) );   // shared by A and B, Processing
$r2 = nx_test_order( $D, array( array( $pb, 3 ) ), array( 'payment' => 'bacs' ) );                                     // only B, On hold
$r3 = nx_test_order( $C, array( array( $pa, 1 ) ), array( 'payment' => 'cod', 'status' => 'cancelled' ) );              // only A, Cancelled
$o1 = $r1['order']->get_id(); $o2 = $r2['order']->get_id(); $o3 = $r3['order']->get_id();

/* ---------- My Orders: guards ---------- */
nx_assert( nx_rejected( $call( 0, 'nexora_my_orders' ) ), 'my orders: a guest is rejected' );
nx_assert( nx_rejected( $call( $C['user_id'], 'nexora_my_orders', array(), false ) ), 'my orders: no nonce is rejected' );
nx_assert( nx_rejected( $call( $C['user_id'], 'nexora_my_orders', array() + array( 'nonce' => 'bad' ), false ) ), 'my orders: a wrong nonce is rejected' );

/* ---------- My Orders: only your own, read-only cards ---------- */
$r = $call( $C['user_id'], 'nexora_my_orders' );
nx_assert( ! empty( $r['success'] ) && 2 === $r['data']['total'], 'customer C sees their 2 orders (' . ( $r['data']['total'] ?? '?' ) . ')' );
$html = $r['data']['html'];
nx_assert( $has( $html, $o1 ) && $has( $html, $o3 ), 'the cards carry the order numbers' );
nx_assert( ! $has( $html, $o2 ), 'another customer\'s order (D) is not there' );
nx_assert( false !== strpos( $html, 'Fish &amp; Chips Set' ) && false === strpos( $html, 'Fish & Chips Set' ), 'names come out HTML-escaped' );
nx_assert( false !== strpos( $html, 'Test Shop oaa' ) && false !== strpos( $html, 'Test Shop oab' ), 'each line says who sold it (the seller name frozen at order time)' );
nx_assert( false !== strpos( $html, 'OA-A' ) && false !== strpos( $html, '100.00' ), 'with SKU and the frozen price' );
nx_assert( 1 === preg_match( '/Processing/i', $html ) && 1 === preg_match( '/Cancelled/i', $html ), 'and each order\'s status in words' );
nx_assert( 0 === preg_match( '/<(input|textarea|button|select|form)\b/i', $html ), 'the cards are read-only: no form controls' );
nx_assert( false === strpos( $html, '<script' ) && false === strpos( $html, 'onerror' ), 'no script in the cards' );
$r = $call( $D['user_id'], 'nexora_my_orders' );
nx_assert( 1 === $r['data']['total'] && $has( $r['data']['html'], $o2 ) && false !== strpos( $r['data']['html'], 'On hold' ), 'customer D sees only their own on-hold order' );
$r = $call( $N['user_id'], 'nexora_my_orders' );
nx_assert( ! empty( $r['success'] ) && 0 === $r['data']['total'] && false !== stripos( $r['data']['html'], 'no orders' ), 'a member with no orders gets a friendly empty message' );
$r = $call( $A['user_id'], 'nexora_my_orders' );
nx_assert( ! empty( $r['success'] ) && 0 === $r['data']['total'], 'a vendor\'s "My Orders" is what they BOUGHT (nothing): their sales are not in it' );
foreach ( array( 'page' => array( '-1', '0', 'abc', '99999999999999999999', array( '1' ), '1e3' ) ) as $k => $vals ) {
	foreach ( $vals as $v ) {
		$r = $call( $C['user_id'], 'nexora_my_orders', array( $k => $v ) );
		nx_assert( ! empty( $r['success'] ) && $r['data']['page'] >= 1, 'my orders: a hostile page value (' . wp_json_encode( $v ) . ') is handled' );
	}
}
nx_assert( ! $has( $call( $C['user_id'], 'nexora_my_orders', array( 'user_id' => $D['user_id'], 'customer' => $D['user_id'] ) )['data']['html'], $o2 ), 'a posted user_id / customer never shows another member\'s orders' );

/* ---------- Sales: who may open it ---------- */
foreach ( array( 'nexora_sales', 'nexora_sale_order' ) as $action ) {
	$post = 'nexora_sale_order' === $action ? array( 'order_id' => $o1 ) : array();
	nx_assert( nx_rejected( $call( 0, $action, $post ) ), "$action: a guest is rejected" );
	nx_assert( nx_rejected( $call( $A['user_id'], $action, $post, false ) ), "$action: no nonce is rejected" );
	foreach ( array( 'a plain customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a member who never applied' => $N['user_id'] ) as $label => $uid ) {
		nx_assert( $forbidden( $call( $uid, $action, $post ) ), "$action: $label is forbidden" );
	}
}

/* ---------- Sales: only my own lines, earnings per line, totals ---------- */
$r = $call( $A['user_id'], 'nexora_sales' );
nx_assert( ! empty( $r['success'] ), 'vendor A opens Sales' );
$html = $r['data']['html'];
nx_assert( false !== strpos( $html, 'OA-A' ) && false === strpos( $html, 'OA-B' ) && false === strpos( $html, 'Test Shop oab' ), 'A sees their line and nothing of vendor B (SKU, name)' );
nx_assert( $has( $html, $o1 ) && $has( $html, $o3 ) && ! $has( $html, $o2 ), 'A sees the 2 orders that include their products, not the one that does not' );
nx_assert( false !== strpos( $html, '180.00' ) && false !== strpos( $html, '20.00' ), 'the line shows gross 200.00 split into commission 20.00 and earning 180.00' );
$t = $r['data']['summary'];
nx_assert_same( array( '200.00', '20.00', '180.00', 1 ), array( $t['gross'], $t['commission'], $t['earning'], $t['orders'] ), 'totals count only the Processing order (the cancelled one adds nothing)' );
nx_assert( false !== stripos( $html, 'Cancelled' ) && false !== stripos( $html, 'Processing' ), 'every order shows its status, so the vendor can see why a line does not count' );
$rb = $call( $B['user_id'], 'nexora_sales' );
nx_assert( false === strpos( $rb['data']['html'], 'OA-A' ) && false !== strpos( $rb['data']['html'], 'OA-B' ), 'B sees only their own lines' );
nx_assert_same( array( '30.00', '3.00', '27.00', 1, '81.00' ), array( $rb['data']['summary']['gross'], $rb['data']['summary']['commission'], $rb['data']['summary']['earning'], $rb['data']['summary']['orders'], $rb['data']['summary']['awaiting'] ), 'B: 27.00 counted from the paid order, 81.00 awaiting on the bank-transfer order' );
$rn = $call( $S['user_id'], 'nexora_sales' );
nx_assert( ! empty( $rn['success'] ) && 0 === $rn['data']['total'], 'a vendor with no sales gets an empty list' );
nx_assert( false !== strpos( wp_json_encode( $call( $A['user_id'], 'nexora_sales', array( 'vendor_id' => $B['vendor_id'], 'user_id' => $B['user_id'] ) ) ), 'OA-A' ) && false === strpos( wp_json_encode( $call( $A['user_id'], 'nexora_sales', array( 'vendor_id' => $B['vendor_id'], 'user_id' => $B['user_id'] ) ) ), 'OA-B' ), 'a posted vendor_id / user_id never switches to another vendor' );

/* ---------- a suspended vendor reads their history, read-only ---------- */
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $A['vendor_id'], 'suspend' );
wp_set_current_user( 0 );
$r = $call( $A['user_id'], 'nexora_sales' );
nx_assert( ! empty( $r['success'] ) && '180.00' === $r['data']['summary']['earning'], 'suspended: A still reads their sales and earnings' );
$r = $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $o1 ) );
nx_assert( ! empty( $r['success'] ), 'suspended: A can still open their own order' );
nx_assert( 0 === preg_match( '/<(input|textarea|button|select|form)\b/i', $r['data']['html'] ), 'the order view has no controls (nothing to change)' );
wp_set_current_user( nx_test_admin() );
\Nexora\Vendors\Service::change_status( $A['vendor_id'], 'reactivate' );
wp_set_current_user( 0 );

/* ---------- object level: one vendor can never open another vendor's order ---------- */
$r = $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $o2 ) );
nx_assert( $forbidden( $r ), 'vendor A cannot open an order that has only B\'s products' );
nx_assert( false === strpos( wp_json_encode( $r ), 'OA-B' ) && ! $has( wp_json_encode( $r ), $o2 ) && false === strpos( wp_json_encode( $r ), 'Shipping' ), 'and the refusal reveals nothing about it' );
$r2x = $call( $B['user_id'], 'nexora_sale_order', array( 'order_id' => $o3 ) );
nx_assert( $forbidden( $r2x ), 'vendor B cannot open the order that has only A\'s product either' );
foreach ( array( '0', '-1', '99999999', 'abc', '1.5', '1e3', '', array( '1' ), str_repeat( '9', 30 ), (string) $C['user_id'], (string) $A['vendor_id'] ) as $bad ) {
	nx_assert( $forbidden( $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $bad ) ) ), 'sale_order: a bad or foreign id (' . wp_json_encode( $bad ) . ') gives the same forbidden answer' );
}
$plain = wc_create_order(); $plain->save(); $GLOBALS['nx_test']['orders'][] = $plain->get_id();
nx_assert( $forbidden( $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $plain->get_id() ) ) ), 'an ordinary Woo order (no marketplace lines) cannot be opened either' );

/* ---------- an order shared by two vendors: each sees only their part ---------- */
$ra = $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $o1 ) );
$rbb = $call( $B['user_id'], 'nexora_sale_order', array( 'order_id' => $o1 ) );
nx_assert( ! empty( $ra['success'] ) && false !== strpos( $ra['data']['html'], 'OA-A' ) && false === strpos( $ra['data']['html'], 'OA-B' ) && false === strpos( $ra['data']['html'], 'Test Shop oab' ), 'A opens the shared order and sees only A\'s line' );
nx_assert( ! empty( $rbb['success'] ) && false !== strpos( $rbb['data']['html'], 'OA-B' ) && false === strpos( $rbb['data']['html'], 'OA-A' ), 'B opens the same order and sees only B\'s line' );

/* ---------- what a vendor may know about the buyer ---------- */
$h = $ra['data']['html'];
nx_assert( false !== strpos( $h, '1 Ship Street' ) && false !== strpos( $h, 'Delhi' ) && false !== strpos( $h, '110001' ) && false !== strpos( $h, 'Sia Shipping' ), 'the shipping name and address are shown (shipping needs them)' );
nx_assert( false === strpos( $h, '77 Secret Billing Lane' ) && false === strpos( $h, '9 Billing Road' ) && false === strpos( $h, 'Mumbai' ) && false === strpos( $h, '400001' ), 'the billing address is never shown' );
nx_assert( false === strpos( $h, 'private.buyer@example.test' ) && false === strpos( $h, '9876500001' ) && false === strpos( $h, '@example.test' ), 'the buyer\'s e-mail and phone are never shown' );
nx_assert( false === stripos( $h, 'Bob Billing' ) && false === strpos( wp_json_encode( $call( $A['user_id'], 'nexora_sales' ) ), 'private.buyer' ), 'nor the billing name; and the Sales list carries no address at all' );
nx_assert( false === strpos( $call( $A['user_id'], 'nexora_sales' )['data']['html'], '1 Ship Street' ), 'the list shows no address (only the order view does)' );
$c3 = $call( $A['user_id'], 'nexora_sale_order', array( 'order_id' => $o3 ) );
nx_assert( ! empty( $c3['success'] ) && false === strpos( $c3['data']['html'], '1 Ship Street' ), 'a cancelled order shows no address (nothing to ship)' );
nx_assert( false === strpos( wp_json_encode( $ra ), $C['login'] ) && false === strpos( wp_json_encode( $ra ), 'nxtest_' ), 'the buyer\'s account name is not shown' );

/* ---------- limits and the script ---------- */
add_filter( 'nexora_rate_limits', function ( $d ) { $d['orders_view'] = array( 3, MINUTE_IN_SECONDS ); return $d; } );
nx_test_reset_limits();
$last = null; for ( $i = 0; $i < 5; $i++ ) { $last = $call( $C['user_id'], 'nexora_my_orders' ); }
nx_assert( nx_rejected( $last ), 'the orders endpoints are rate limited' );
remove_all_filters( 'nexora_rate_limits' );
nx_test_reset_limits();

$js = file_exists( NEXORA_PATH . 'assets/js/orders.js' ) ? file_get_contents( NEXORA_PATH . 'assets/js/orders.js' ) : '';
nx_assert( false !== strpos( $js, 'nexora_my_orders' ) && false !== strpos( $js, 'nexora_sales' ) && false !== strpos( $js, 'nexora_sale_order' ), 'orders.js calls the three endpoints' );
nx_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'eval(' ), 'and writes no raw HTML itself' );
preg_match_all( '/\$\.post\(cfg\.ajaxUrl,\s*\{(.*?)\}/s', $js, $posts );
$sent = implode( ' ', $posts[1] );
nx_assert( false === stripos( $sent, 'vendor' ) && false === stripos( $sent, 'user' ) && false === stripos( $sent, 'price' ) && false === stripos( $sent, 'earning' ), 'it sends only an action, the nonce, a page or an order id: never a vendor, user, price or earning' );

nx_test_finish();
