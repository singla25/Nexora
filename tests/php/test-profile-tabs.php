<?php
// Marketplace Phase 3: the profile page's tab row is driven by Account_Types::tabs_for().
// Tabs that are not built yet (Marketplace, My Orders, My Products, Sales) never render, so a customer's page is unchanged.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Profile\Page;

$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
$tabs = function ( $html ) {
	if ( ! preg_match( '#<div class="profile-tabs">(.*?)</div></div>#s', preg_replace( '/>\s+</', '><', $html ), $m ) && ! preg_match( '#<div class="profile-tabs">(.*?)<!-- MAIN CONTENT -->#s', $html, $m ) ) { return null; }
	preg_match_all( '/data-tab="([a-z-]+)"/', $m[1], $t );
	return $t[1];
};

$base = array( 'user-info', 'connections', 'content', 'notifications' );
nx_assert_same( $base, Page::BUILT_TABS, 'the tabs that exist today' );

$customer = nx_test_user( 'ptcust' );
$pending  = nx_test_user( 'ptpend' );
$approved = nx_test_user( 'ptappr' );
$suspend  = nx_test_user( 'ptsusp' );
$viewer   = nx_test_user( 'ptview' );
Account_Types::set_status( $pending['user_id'], 'pending' );
Account_Types::set_status( $approved['user_id'], 'pending' );
Account_Types::set_status( $approved['user_id'], 'approved' );
Account_Types::set_status( $suspend['user_id'], 'pending' );
Account_Types::set_status( $suspend['user_id'], 'approved' );
Account_Types::set_status( $suspend['user_id'], 'suspended' );

foreach ( array( 'customer' => $customer, 'pending vendor' => $pending, 'approved vendor' => $approved, 'suspended vendor' => $suspend ) as $label => $u ) {
	$html = $render( $u['user_id'], $u['login'] );
	$got  = $tabs( $html );
	nx_assert_same( array_values( array_intersect( Account_Types::tabs_for( $u['user_id'] ), Page::BUILT_TABS ) ), $got, "$label: the tab row is tabs_for() limited to the tabs that exist" );
	nx_assert_same( $base, $got, "$label: today that is User Information, Connections, Content, Notifications" );
	foreach ( array( 'marketplace', 'my-orders', 'my-products', 'sales', 'vendor-status' ) as $later ) {
		nx_assert( false === strpos( $html, 'data-tab="' . $later . '"' ) && false === strpos( $html, 'id="' . $later . '"' ), "$label: the unbuilt tab \"$later\" is not rendered" );
	}
	foreach ( $base as $tab ) {
		nx_assert( false !== strpos( $html, 'id="' . $tab . '"' ), "$label: the content area of \"$tab\" is there" );
	}
	nx_assert( 1 === substr_count( $html, 'tab-btn active' ), "$label: exactly one tab starts active" );
}

// Other members' profiles: only the two public tabs, as before.
$html = $render( $viewer['user_id'], $customer['login'] );
nx_assert_same( array( 'user-info', 'connections' ), $tabs( $html ), 'a viewer sees User Information and Connections only' );
$html = $render( $viewer['user_id'], $approved['login'] );
nx_assert_same( array( 'user-info', 'connections' ), $tabs( $html ), 'a viewer of a vendor still sees only the two public tabs' );
$html = $render( 0, $customer['login'] );
nx_assert( null === $tabs( $html ) || array() === $tabs( $html ), 'a guest sees no tab row (login wall)' );

// The tab row really comes from the tab list: removing a tab through the filter removes its button.
add_filter( 'nexora_profile_tabs', function ( $list ) { return array_values( array_diff( $list, array( 'content' ) ) ); } );
$html = $render( $customer['user_id'], $customer['login'] );
nx_assert_same( array( 'user-info', 'connections', 'notifications' ), $tabs( $html ), 'the nexora_profile_tabs filter changes the rendered tab row' );
remove_all_filters( 'nexora_profile_tabs' );
$html = $render( $customer['user_id'], $customer['login'] );
nx_assert_same( $base, $tabs( $html ), 'and removing the filter restores it' );

// Hiding a tab is not the security: a viewer cannot reach owner-only content by asking for it.
$html = $render( $viewer['user_id'], $customer['login'] );
nx_assert( false === strpos( $html, 'data-tab="notifications"' ) && false === strpos( $html, 'data-tab="content"' ), 'a viewer gets no owner-only tab buttons' );

nx_test_finish();
