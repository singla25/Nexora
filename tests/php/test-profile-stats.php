<?php
// The profile dashboard opens with a welcome line and a stat strip for the OWNER only: connections, unread notifications and, for an
// approved seller, products. Nobody else sees it (the numbers describe the member's own account).
require_once __DIR__ . '/../bootstrap.php';

$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
/** The strip's figures as label => number. */
$stats = function ( $html ) {
	$out = array();
	if ( preg_match( '#<div class="nx-stat-bar profile-stats"[^>]*>(.*?)</div>#s', $html, $m ) ) {
		preg_match_all( '#<span[^>]*class="nx-stat"[^>]*><strong>(\d+)</strong> ([^<]+)</span>#', $m[1], $f, PREG_SET_ORDER );
		foreach ( $f as $row ) {
			$out[ trim( $row[2] ) ] = (int) $row[1];
		}
	}
	return $out;
};

$cust   = nx_test_user( 'stcust' );
$friend = nx_test_user( 'stfriend' );
$other  = nx_test_user( 'stother' );
nx_test_connection( $cust, $friend, 'accepted' );

/* ---------- a customer sees welcome + connections + notifications, no products ---------- */
$html = $render( $cust['user_id'], $cust['login'] );
$header = preg_match( '#<div class="profile-header">(.*?)<!-- TABS -->#s', $html, $hm ) ? $hm[1] : '';
nx_assert( false !== strpos( $header, 'class="profile-welcome"' ) && false !== strpos( $header, 'Welcome back,' ) && false !== strpos( $header, 'nx-stat-bar profile-stats' ), 'owner: the welcome line and stat strip sit in the identity panel (profile-header)' );
nx_assert( false === strpos( $html, 'logout-btn' ), 'owner: no Logout button on the profile (the site header has one)' );
$s = $stats( $html );
nx_assert_same( 1, $s['Connections'] ?? null, 'owner: the strip shows the connection count (1)' );
nx_assert( isset( $s['Unread notifications'] ), 'owner: the strip shows unread notifications' );
nx_assert( ! isset( $s['Products'] ), 'customer: no products figure' );

/* ---------- an approved seller also sees products ---------- */
$vend = nx_test_vendor( 'stvend', 'approved' );
$s    = $stats( $render( $vend['user_id'], $vend['login'] ) );
nx_assert_same( 0, $s['Products'] ?? null, 'approved seller: products figure shown (0 so far)' );

/* ---------- other members and guests see none of it ---------- */
$html = $render( $other['user_id'], $cust['login'] );
nx_assert( false === strpos( $html, 'profile-stats' ) && false === strpos( $html, 'profile-welcome' ), "another member: no strip or welcome on someone else's profile" );
$html = $render( 0, $cust['login'] );
nx_assert( false === strpos( $html, 'profile-stats' ) && false === strpos( $html, 'profile-welcome' ), 'guest: no strip or welcome' );

nx_test_finish();
