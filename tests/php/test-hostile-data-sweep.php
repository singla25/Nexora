<?php
// Phase 10: the escaping and privacy sweep. Hostile data (script tags, attribute breakers, quotes, very long strings, unicode) is written
// STRAIGHT into the stored fields (as old data, an import or an administrator could), then every screen that shows it is rendered and the
// output must carry it only in escaped form. The same fixture is used to prove that private fields (e-mail, phone, address, ID documents,
// a seller's business contact details) never reach someone who is not the owner, and that nothing about the buyer reaches a seller.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\History_Box;
use Nexora\Orders\Overview_Page;
use Nexora\Orders\Report_View;
use Nexora\Orders\Reports;
use Nexora\Vendors\Products_Box;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
global $wpdb;

/* ---------- the hostile strings (each carries a marker so a leak names its source) ---------- */
$H = array(
	'script' => '<script>NXH1()</script>',
	'attr'   => '"><svg onload=NXH2()>',
	'img'    => "'><img src=x onerror=NXH3()>",
	'quote'  => 'Quote " and \' and & and <b>bold</b> NXH4',
	'uni'    => 'Ünïcödé 日本語 🚀 NXH5',
	'long'   => str_repeat( 'LONG', 800 ) . ' NXH6',
);
$all = implode( ' | ', $H );
$raw_patterns = array( '<script>NXH', '<svg onload=NXH', '<img src=x onerror=NXH', '<b>bold</b> NXH4' );
$check = function ( $label, $html, $expect_shown = true ) use ( $raw_patterns, $H ) {
	$html = is_string( $html ) ? $html : wp_json_encode( $html );
	$raw  = array_values( array_filter( $raw_patterns, function ( $p ) use ( $html ) { return false !== strpos( $html, $p ); } ) );
	nx_assert_same( array(), $raw, "$label: no hostile markup comes out raw" );
	if ( $expect_shown ) {
		nx_assert( false !== strpos( $html, esc_html( $H['script'] ) ) || false !== strpos( $html, esc_attr( $H['script'] ) ) || false !== strpos( $html, 'NXH5' ) || false !== strpos( $html, 'NXH4' ), "$label: the hostile data was really shown (escaped), so this check means something" );
	}
};
$admin = nx_test_admin();
wp_set_current_user( $admin );

/* ---------- the world ---------- */
$M = nx_test_user( 'swm' );        // the member whose profile is shown (owner)
$N = nx_test_user( 'swn' );        // another member (viewer), connected to M
$V = nx_test_vendor( 'swv', 'approved' ); // a seller
$mid = $M['profile_id'];
$private = array( 'email' => 'nxpriv.owner@example.test', 'phone' => '+91 90000 NXPRIVPH', 'perm_address' => '77 NXPRIV Private Street', 'corr_address' => '88 NXPRIV Corr Road', 'company_email' => 'nxpriv.work@example.test', 'company_phone' => '+91 91111 NXPRIVWK', 'linkedin_id' => 'nxpriv-linkedin-handle' );
foreach ( $private as $k => $v ) { update_post_meta( $mid, $k, $v ); }
update_post_meta( $mid, 'first_name', $H['script'] . ' ' . $H['uni'] );
update_post_meta( $mid, 'last_name', $H['attr'] );
update_post_meta( $mid, 'bio', $H['img'] . ' ' . $H['quote'] . ' ' . $H['long'] );
update_post_meta( $mid, 'company_name', $H['quote'] );
update_post_meta( $mid, 'designation', $H['script'] );
update_post_meta( $N['profile_id'], 'first_name', $H['img'] );
update_post_meta( $N['profile_id'], 'last_name', $H['quote'] );
nx_test_connection( $M, $N, 'accepted' );
$att = nx_test_attachment( $M['user_id'], 'nxpriv-doc' );
update_post_meta( $mid, 'aadhaar_card', $att );
$doc_url = (string) wp_get_attachment_url( $att );

// The seller: hostile business data, private business contact details.
$sid = $V['vendor_id'];
$biz_private = array( 'business_email' => 'nxpriv.biz@example.test', 'business_phone' => '98765 NXPRIVBZ', 'business_address' => '5 NXPRIV Business Lane', 'gstin' => $V['gstin'] );
foreach ( $biz_private as $k => $v ) { update_post_meta( $sid, $k, $v ); }
update_post_meta( $sid, 'business_name', $H['quote'] );
update_post_meta( $sid, 'business_city', $H['script'] );
$wpdb->update( $wpdb->posts, array( 'post_title' => $H['quote'] ), array( 'ID' => $sid ) ); clean_post_cache( $sid );
$p1 = nx_test_product( $V, 'SW-1', '25.00', '50' );
$wpdb->update( $wpdb->posts, array( 'post_title' => $H['script'] . ' ' . $H['uni'], 'post_content' => $H['img'] . ' ' . $H['long'] ), array( 'ID' => $p1 ) ); clean_post_cache( $p1 );
update_post_meta( $p1, 'sku', $H['attr'] );
update_post_meta( $p1, 'category', $H['quote'] );

// An order by M for the seller's product (the frozen names carry the hostile data), with a distinctive buyer.
$o = nx_test_order( $M, array( array( $p1, 2 ) ), array( 'payment' => 'cod', 'billing' => array( 'billing_first_name' => 'Zed', 'billing_last_name' => 'NXBUYERNAME', 'billing_email' => 'nxbuyer.private@example.test', 'billing_phone' => '9811 NXBUYERPH', 'billing_address_1' => '9 NXBUYER Billing Road' ) ) );
nx_assert( (bool) $o['order'], 'setup: an order exists (' . $o['error'] . ')' );
$order = wc_get_order( $o['order']->get_id() );
foreach ( $order->get_items() as $item ) { $item->update_meta_data( '_nx_name', $H['script'] . ' ' . $H['uni'] ); $item->update_meta_data( '_nx_sku', $H['attr'] ); $item->update_meta_data( '_nx_vendor_name', $H['quote'] ); $item->save(); }
Reports::flush();

// Notifications, member posts and an audit row with hostile text.
$wpdb->insert( $wpdb->prefix . 'nexora_notifications', array( 'actor_user_id' => 0, 'actor_user_name' => $H['script'], 'receiver_user_id' => $M['user_id'], 'receiver_user_name' => $H['quote'], 'type' => 'order_new', 'message' => $H['img'] . ' ' . $H['uni'], 'is_read' => 0 ) );
$wpdb->insert( $wpdb->prefix . 'nexora_notifications', array( 'actor_user_id' => 0, 'actor_user_name' => 'Nexora', 'receiver_user_id' => $V['user_id'], 'receiver_user_name' => 'x', 'type' => 'low_stock', 'message' => $H['attr'], 'is_read' => 0 ) );
$content = \Nexora\Content\Repository::create( $M['user_id'], $mid, 'tmp', 'tmp' ); nx_test_track_post( $content );
$wpdb->update( $wpdb->posts, array( 'post_title' => $H['script'], 'post_content' => $H['img'] . ' ' . $H['uni'] ), array( 'ID' => $content ) ); clean_post_cache( $content );
Audit::record( 'nexora_product', $p1, 'name', $H['script'], $H['attr'] );
Audit::record( 'nexora_product', $p1, 'description', $H['img'], $H['quote'] );

/* ---------- screens: profile pages ---------- */
$page = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	return do_shortcode( '[profile_dashboard]' );
};
$m_name = get_post_meta( $mid, 'user_name', true );
$owner_page  = $page( $M['user_id'], $m_name );
$viewer_page = $page( $N['user_id'], $m_name );
$guest_page  = $page( 0, $m_name );
$check( 'the owner\'s own profile page', $owner_page );
$check( 'another member\'s view of the profile page', $viewer_page );
$check( 'a guest\'s view of the profile page', $guest_page, false );
$v_name = get_post_meta( $V['profile_id'], 'user_name', true );
$v_owner = $page( $V['user_id'], $v_name );
$check( 'the seller\'s own profile page (cards, products, report)', $v_owner );
$check( 'the seller\'s profile page seen by another member', $page( $N['user_id'], $v_name ), false );

/* ---------- screens: AJAX fragments ---------- */
$ajax = function ( $uid, $action, array $post = array() ) {
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + nx_profile_nonce() );
	return $r;
};
$market = $ajax( $N['user_id'], 'nexora_marketplace_list' );
$check( 'the marketplace list', $market );
$my_orders = $ajax( $M['user_id'], 'nexora_my_orders' );
$check( 'My Orders', $my_orders );
$sales = $ajax( $V['user_id'], 'nexora_sales' );
$sale_order = $ajax( $V['user_id'], 'nexora_sale_order', array( 'order_id' => $o['order']->get_id() ) );
$check( 'Sales (the order list)', $sales );
$check( 'the order popup', $sale_order );
$check( 'content history', $ajax( $M['user_id'], 'nexora_get_user_content_history' ) );
$check( 'connection history', $ajax( $M['user_id'], 'nexora_get_history' ) );
$check( 'mutual connections', $ajax( $N['user_id'], 'nexora_view_mutual_connection', array( 'profile_id' => $mid ) ), false );
$check( 'all connections of a member', $ajax( $N['user_id'], 'nexora_view_all_connection', array( 'profile_id' => $mid ) ), false );
$directory = $ajax( $N['user_id'], 'nexora_get_add_new_users' );
$check( 'the member directory', $directory, false );
$notes_page = $page( $M['user_id'], $m_name );
nx_assert( false !== strpos( $notes_page, 'NXH5' ), 'the notifications tab shows the hostile message text (escaped)' );

/* ---------- screens: seller reports and the administrator's screens ---------- */
wp_set_current_user( $V['user_id'] );
$report = Report_View::seller_html( $V['user_id'], array( 'nx_range' => 'all' ) );
$check( 'the seller report', $report );
wp_set_current_user( $admin );
set_current_screen( 'dashboard' );
$overview = Overview_Page::html( array( 'nx_range' => 'all' ) );
$check( 'the Marketplace overview', $overview );
ob_start(); Products_Box::render( get_post( $sid ) ); $pbox = ob_get_clean();
$check( 'the Products box on a seller record', $pbox );
ob_start(); History_Box::render( get_post( $p1 ) ); $hbox = ob_get_clean();
$check( 'the Change history box', $hbox );
ob_start(); ( new \Nexora\Admin\Pages() )->notifications_page(); $np = ob_get_clean();
$check( 'the Notifications admin screen', $np );
$boxes = new \Nexora\Admin\Meta_Boxes();
$mb = '';
foreach ( array( 'user_personal_details', 'user_address_details', 'user_work_details', 'user_document_details', 'user_connection_details', 'user_content_details' ) as $m ) { ob_start(); $boxes->$m( get_post( $mid ) ); $mb .= ob_get_clean(); }
$check( 'the customer record boxes', $mb );
$vadmin = new \Nexora\Vendors\Admin();
ob_start(); $vadmin->render_details_box( get_post( $sid ) ); $vd = ob_get_clean();
ob_start(); $vadmin->render_decision_box( get_post( $sid ) ); $vd .= ob_get_clean();
ob_start(); $vadmin->column( 'gstin', $sid ); $vadmin->column( 'owner', $sid ); $vd .= ob_get_clean();
$check( 'the seller record boxes and list columns', $vd );
$padm = new \Nexora\Products\Admin();
ob_start(); $padm->render_details_box( get_post( $p1 ) ); foreach ( array( 'vendor', 'sku', 'price', 'stock', 'category', 'name' ) as $col ) { $padm->column( $col, $p1 ); } $pr = ob_get_clean();
$check( 'the product record box and list columns', $pr );
nx_test_options( array( 'nexora_home_title' => $H['script'], 'nexora_home_subtitle' => $H['attr'], 'nexora_home_features' => $H['img'], 'default_admin_mail' => null ) );
ob_start(); ( new \Nexora\Admin\Settings() )->settings_page(); $st = ob_get_clean();
$check( 'the Settings screen', $st );

/* ---------- e-mails ---------- */
Reports::flush();
$m0 = count( $GLOBALS['nx_test']['mail'] );
\Nexora\Orders\Mailer::run( 'new_order', $o['order']->get_id(), $sid );
\Nexora\Orders\Mailer::run( 'low_stock', $p1, 0 );
wp_set_current_user( $V['user_id'] );
\Nexora\Orders\Fulfilment::ship( $V['user_id'], $o['order']->get_id() );
\Nexora\Orders\Mailer::run( 'shipped', $o['order']->get_id(), $sid );
wp_set_current_user( $admin );
$mails = array_slice( $GLOBALS['nx_test']['mail'], $m0 );
nx_assert( count( $mails ) >= 3, 'the three kinds of order e-mail were produced (' . count( $mails ) . ')' );
foreach ( $mails as $i => $mail ) { $check( 'e-mail ' . ( $i + 1 ) . ' (' . $mail['subject'] . ')', $mail['subject'] . $mail['message'], false ); }
$seller_mails = array_filter( $mails, function ( $m ) use ( $V ) { return in_array( get_userdata( $V['user_id'] )->user_email, (array) $m['to'], true ); } );

/* ---------- privacy: the owner's private fields never reach anyone else ---------- */
$private_vals = array_merge( array_values( $private ), array( $doc_url ) );
$private_vals = array_filter( $private_vals );
$others = array(
	'the profile page seen by another member' => $viewer_page, 'the profile page seen by a guest' => $guest_page,
	'the marketplace list' => wp_json_encode( $market ), 'the member directory' => wp_json_encode( $directory ),
	'mutual connections' => wp_json_encode( $ajax( $N['user_id'], 'nexora_view_mutual_connection', array( 'profile_id' => $mid ) ) ),
	'all connections of a member' => wp_json_encode( $ajax( $N['user_id'], 'nexora_view_all_connection', array( 'profile_id' => $mid ) ) ),
);
foreach ( $others as $label => $out ) {
	$leak = array_values( array_filter( $private_vals, function ( $v ) use ( $out ) { return false !== strpos( $out, (string) $v ) || false !== strpos( $out, rawurlencode( (string) $v ) ); } ) );
	nx_assert_same( array(), $leak, "$label: none of the owner's private fields (e-mail, phone, address, work details, document)" );
}
nx_assert( false !== strpos( $owner_page, 'nxpriv.owner@example.test' ) || false !== strpos( $owner_page, 'NXPRIVPH' ), '(the owner does see their own private fields)' );
$doc_leak = (bool) preg_grep( '#nexora-private#', array( $viewer_page, $guest_page, wp_json_encode( $market ) ) );
nx_assert( ! $doc_leak, 'no link into the private documents folder reaches another member or a guest' );

// A seller's business contact details are for the seller and the administrator only.
$public_seller = array( 'the marketplace list' => wp_json_encode( $market ), 'the seller\'s profile seen by a member' => $page( $N['user_id'], $v_name ), 'a buyer\'s My Orders' => wp_json_encode( $my_orders ), 'the member directory' => wp_json_encode( $directory ) );
foreach ( $public_seller as $label => $out ) {
	$leak = array_values( array_filter( array_values( $biz_private ), function ( $v ) use ( $out ) { return false !== strpos( $out, (string) $v ); } ) );
	nx_assert_same( array(), $leak, "$label: no seller e-mail, phone, address or GSTIN" );
}

/* ---------- privacy: nothing about the buyer reaches a seller ---------- */
$buyer = array( 'NXBUYERNAME', 'nxbuyer.private@example.test', 'NXBUYERPH', 'NXBUYER Billing Road' );
$buyer_account = array_merge( $buyer, array( $M['login'] ) ); // the account name must not come out of ORDER data (profile pages list members publicly)
$seller_facing = array( 'Sales' => wp_json_encode( $sales ), 'the seller report' => $report );
foreach ( $seller_mails as $i => $mail ) { $seller_facing[ 'seller e-mail ' . $i ] = $mail['subject'] . $mail['message']; }
$leak0 = array_values( array_filter( $buyer, function ( $v ) use ( $v_owner ) { return false !== stripos( $v_owner, $v ); } ) );
nx_assert_same( array(), $leak0, 'the seller\'s profile page: no buyer name, e-mail, phone or billing address from any order' );
foreach ( $seller_facing as $label => $out ) {
	$leak = array_values( array_filter( $buyer_account, function ( $v ) use ( $out ) { return false !== stripos( $out, $v ); } ) );
	nx_assert_same( array(), $leak, "$label: no buyer name, e-mail, phone, billing address or account name" );
}
$popup = wp_json_encode( $sale_order );
nx_assert( false === strpos( $popup, 'NXBUYERNAME' ) && false === strpos( $popup, 'nxbuyer.private' ) && false === strpos( $popup, 'NXBUYERPH' ) && false === strpos( $popup, 'NXBUYER Billing' ), 'the order popup shows no billing detail (only what shipping needs, and only while there is something to ship)' );

/* ---------- debug output never reaches a visitor ---------- */
$bad = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NEXORA_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( 'php' !== $f->getExtension() ) { continue; }
	$src = (string) file_get_contents( $f->getPathname() );
	if ( preg_match( '/\b(print_r|var_dump|var_export|debug_zval_dump|phpinfo)\s*\(|ini_set\(\s*[\'"]display_errors|error_reporting\s*\(|echo\s+\$e->getMessage|wp_send_json[a-z_]*\(\s*\$e->getMessage/', $src, $m ) && ! preg_match( '/print_r\([^)]*,\s*true\s*\)/', $src ) ) { $bad[] = basename( $f->getPathname() ) . ' (' . $m[0] . ')'; }
}
nx_assert_same( array(), $bad, 'no plugin code prints debug output, changes the error display or sends an exception message to the browser' );
nx_test_options( array( 'nexora_home_title' => null, 'nexora_home_subtitle' => null, 'nexora_home_features' => null, 'default_admin_mail' => null ) );

nx_test_finish();
