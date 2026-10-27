<?php
// Marketplace Phase 2 (UI fix): business details are read-only text on the profile, edited through a popup;
// "Become a seller" is a popup too. Only the owner ever gets any of this markup.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Vendors\Service;

$biz = array(
	'business_name' => 'Popup Traders', 'business_type' => 'partnership', 'gstin' => '27AAPFU0939F1ZV', 'business_email' => 'popup@example.test',
	'business_phone' => '9876500001', 'business_address' => '7 Lake Road', 'business_city' => 'Mohali', 'business_state' => 'Punjab', 'business_pincode' => '160074',
);
$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
/** The page without the contents of <template> elements: what is actually visible on load. */
$visible = function ( $html ) { return preg_replace( '#<template\b.*?</template>#s', '', $html ); };
/** The contents of one <template> element. */
$template = function ( $html, $id ) { return preg_match( '#<template id="' . preg_quote( $id, '#' ) . '">(.*?)</template>#s', $html, $m ) ? $m[1] : ''; };
$subtabs  = function ( $html ) { preg_match_all( '#<button[^>]*class="[^"]*user-edit-info[^"]*"[^>]*data-type="([a-z-]+)"#', $html, $m ); return $m[1]; };

$customer = nx_test_user( 'uicust' );
$vendor   = nx_test_user( 'uivend' );
$viewer   = nx_test_user( 'uiview' );
Service::apply( $vendor['user_id'], $biz );
$vid = \Nexora\Vendors\Repository::id_for_user( $vendor['user_id'] );
$GLOBALS['nx_test']['posts'][] = $vid;

/* ---------- a customer: "Become a seller" is a popup ---------- */
$html = $render( $customer['user_id'], $customer['login'] );
$vis  = $visible( $html );
nx_assert( false !== strpos( $vis, 'nx-vendor-card' ) && false !== strpos( $vis, 'Become a seller' ), 'customer: the Become a seller card is shown' );
nx_assert( 1 === preg_match( '/<button[^>]*class="[^"]*nx-vendor-open[^"]*"[^>]*data-template="nx-vendor-tpl-apply"/', $vis ), 'customer: its button opens the popup template' );
nx_assert( false === strpos( $vis, '<form' ) || 1 !== preg_match( '/<form[^>]*nx-vendor-form/', $vis ), 'customer: no application form sits open on the page' );
nx_assert( false === strpos( $vis, 'name="gstin"' ), 'customer: no GSTIN input on the page itself' );
$tpl = $template( $html, 'nx-vendor-tpl-apply' );
nx_assert( false !== strpos( $tpl, 'name="gstin"' ) && false !== strpos( $tpl, 'data-action="nexora_vendor_apply"' ), 'customer: the popup template holds the application form' );
nx_assert( ! in_array( 'business-info', $subtabs( $html ), true ), 'customer: no Business sub-tab yet' );
nx_assert( false === strpos( $vis, 'Seller account' ), 'customer: the old always-editable "Seller account" form is gone' );

/* ---------- a pending applicant ---------- */
$html = $render( $vendor['user_id'], $vendor['login'] );
$vis  = $visible( $html );
$tabs = $subtabs( $html );
nx_assert_same( array( 'personal-info', 'address-info', 'work-info', 'docs-info', 'business-info', 'security-info' ), $tabs, 'pending: the Business sub-tab sits between Documents and Security' );
nx_assert( 1 === preg_match( '/<button[^>]*nx-vendor-open[^>]*data-type="business-info"[^>]*data-template="nx-vendor-tpl-edit"|<button[^>]*data-type="business-info"[^>]*nx-vendor-open/', $vis ), 'pending: the Business sub-tab opens the edit popup' );
nx_assert( false !== strpos( $vis, 'Business Details' ), 'pending: a Business Details card is shown' );
foreach ( array( 'Popup Traders', '27AAPFU0939F1ZV', 'popup@example.test', '9876500001', '7 Lake Road', 'Mohali', 'Punjab', '160074', 'Partnership' ) as $v ) {
	nx_assert( 1 === preg_match( '#<span class="info-value">\s*' . preg_quote( $v, '#' ) . '\s*</span>#', $vis ), "pending: $v is read-only text on the card" );
}
nx_assert( false !== strpos( $vis, 'under review' ), 'pending: the status notice says the application is under review' );
nx_assert( false === strpos( $vis, '<input' ) || 0 === preg_match( '/<input[^>]*name="(business_|gstin)/', $vis ), 'pending: no business input is visible on the page' );
nx_assert( 1 !== preg_match( '/<button[^>]*nx-vendor-open[^>]*data-template="nx-vendor-tpl-edit"[^>]*>\s*Edit/', $vis ), 'pending: no Edit button on the card (the Business sub-tab opens the popup)' );
nx_assert( false !== strpos( $template( $html, 'nx-vendor-tpl-edit' ), 'nexora_vendor_update' ), 'pending: the edit popup template is still on the page for the Business sub-tab' );
$tpl = $template( $html, 'nx-vendor-tpl-edit' );
nx_assert( false !== strpos( $tpl, 'data-action="nexora_vendor_update"' ), 'pending: the popup template posts to vendor_update' );
nx_assert( 0 === preg_match( '/<(input|select)[^>]*\b(disabled|readonly)\b/', $tpl ), 'pending: every field is editable in the popup' );
nx_assert( false === strpos( $tpl, 'nx-vendor-locked-note' ), 'pending: no "locked" explanation' );
nx_assert( false !== strpos( $tpl, 'value="Popup Traders"' ) && false !== strpos( $tpl, 'value="27AAPFU0939F1ZV"' ), 'pending: the popup is pre-filled' );

/* ---------- approved / suspended: identity fields locked ---------- */
wp_set_current_user( nx_test_admin() );
Service::change_status( $vid, 'approve' );
wp_set_current_user( 0 );
foreach ( array( 'approved' => 'approved seller', 'suspended' => 'suspended' ) as $state => $notice ) {
	if ( 'suspended' === $state ) {
		wp_set_current_user( nx_test_admin() );
		Service::change_status( $vid, 'suspend' );
		wp_set_current_user( 0 );
	}
	$html = $render( $vendor['user_id'], $vendor['login'] );
	$vis  = $visible( $html );
	$tpl  = $template( $html, 'nx-vendor-tpl-edit' );
	nx_assert( false !== strpos( $vis, $notice ), "$state: the status notice is shown ($notice)" );
	nx_assert( in_array( 'business-info', $subtabs( $html ), true ), "$state: the Business sub-tab is still there" );
	foreach ( array( 'business_name', 'business_type', 'gstin' ) as $locked ) {
		nx_assert( 1 === preg_match( '/<(input|select)[^>]*name="' . $locked . '"[^>]*\bdisabled\b|<(input|select)[^>]*\bdisabled\b[^>]*name="' . $locked . '"/', $tpl ), "$state: $locked is disabled in the popup" );
	}
	foreach ( array( 'business_email', 'business_phone', 'business_address', 'business_city', 'business_state', 'business_pincode' ) as $open ) {
		nx_assert( 0 === preg_match( '/<input[^>]*name="' . $open . '"[^>]*\b(disabled|readonly)\b/', $tpl ), "$state: $open stays editable" );
	}
	nx_assert( false !== strpos( $tpl, 'nx-vendor-locked-note' ), "$state: the popup explains why the fields are locked" );
}

/* ---------- nobody else gets any of it ---------- */
foreach ( array( 'viewer' => $viewer['user_id'], 'guest' => 0 ) as $who => $uid ) {
	$html = $render( $uid, $vendor['login'] );
	foreach ( array( 'nx-vendor', 'business-info', '<template', 'Popup Traders', '27AAPFU0939F1ZV', 'Become a seller', 'Business Details' ) as $needle ) {
		nx_assert( false === strpos( $html, $needle ), "$who: another member's profile has no \"$needle\"" );
	}
}
$customer_html = $render( $viewer['user_id'], $viewer['login'] );
nx_assert( false === strpos( $customer_html, 'Popup Traders' ), "a member's own page never shows someone else's business" );

/* ---------- scripts follow the existing popup pattern ---------- */
$pj = file_get_contents( NEXORA_PATH . 'assets/js/profile-page.js' );
$vj = file_get_contents( NEXORA_PATH . 'assets/js/vendor.js' );
nx_assert( false !== strpos( $pj, 'nx-vendor-open' ), 'profile-page.js ignores the vendor buttons (no empty popup)' );
nx_assert( false !== strpos( $vj, 'Swal.fire' ) && false !== strpos( $vj, 'location.reload' ), 'vendor.js uses SweetAlert2 and refreshes the page like the other sections' );
nx_assert( false === strpos( $vj, 'innerHTML' ) && false === strpos( $vj, 'modal' ), 'vendor.js adds no modal system and writes no raw HTML' );
nx_assert( false !== strpos( $vj, 'data-template' ) || false !== strpos( $vj, "data('template')" ), 'vendor.js reads the popup content from the server-rendered template' );
$data = \Nexora\Profile\Privacy::script_data( $vendor['profile_id'], 'owner' );
nx_assert( false === strpos( wp_json_encode( $data ), 'Popup Traders' ) && false === strpos( wp_json_encode( $data ), '27AAPFU0939F1ZV' ), 'the business details are not in the script data (templates are rendered for the owner only)' );

nx_test_finish();
