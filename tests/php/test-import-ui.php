<?php
// Marketplace Phase 5: the import buttons, popups and history on the My Products tab.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Importer;

$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
$visible  = function ( $html ) { return preg_replace( '#<template\b.*?</template>#s', '', $html ); };
$template = function ( $html, $id ) { return preg_match( '#<template id="' . preg_quote( $id, '#' ) . '">(.*?)</template>#s', $html, $m ) ? $m[1] : ''; };

$A = nx_test_vendor( 'iua', 'approved' );
$C = nx_test_user( 'iuc' );
$V = nx_test_user( 'iuv' );
$html = $render( $A['user_id'], $A['login'] );
$vis  = $visible( $html );

nx_assert( 1 === preg_match( '/<button[^>]*class="[^"]*nx-import-csv[^"]*"[^>]*>\s*Import CSV/', $vis ), 'My Products has an Import CSV button' );
nx_assert( 1 === preg_match( '/<button[^>]*class="[^"]*nx-import-api[^"]*"[^>]*>\s*Import from API/', $vis ), 'My Products has an Import from API button' );
nx_assert( 0 === preg_match( '/<input[^>]*type="file"/', $vis ), 'no file input sits on the page itself (it is in the popup)' );
$csv = $template( $html, 'nx-import-tpl-csv' );
nx_assert( 1 === preg_match( '/<input[^>]*type="file"[^>]*accept="[^"]*\.csv[^"]*"/', $csv ), 'the CSV popup has a file input that accepts .csv' );
foreach ( array( 'sku', 'name', 'description', 'price', 'stock', 'category', 'image_url' ) as $col ) {
	nx_assert( false !== strpos( $csv, $col ), "the CSV popup explains the $col column" );
}
nx_assert( false !== strpos( $csv, '2 MB' ) && false !== strpos( $csv, '500' ), 'the CSV popup states the 2 MB and 500 row limits' );
nx_assert( false !== strpos( $csv, 'Preview' ), 'the CSV popup starts with a preview (dry run)' );
$api = $template( $html, 'nx-import-tpl-api' );
nx_assert( false !== strpos( $api, 'DummyJSON' ) && false === strpos( $api, 'type="file"' ), 'the API popup offers the platform source (DummyJSON from the settings) and has no file input' );
nx_assert( 0 === preg_match( '/<input[^>]*type="(text|url|password)"/', $api ) || 0 === (int) get_option( 'nexora_vendor_apis_enabled', 0 ), 'with vendor APIs switched off the popup has no address or key field at all' );
nx_assert( false !== strpos( $html, 'id="nx-import-report"' ) || false !== strpos( $csv, 'nx-import-report' ), 'there is a place for the preview report' );

/* ---------- history ---------- */
nx_assert( false !== strpos( $vis, 'Import history' ) && false !== strpos( $vis, 'No imports yet' ), 'an empty history says so' );
$plan = Importer::plan_csv( $A['user_id'], array( array( 'line' => 2, 'cells' => array( 'sku' => 'UI-1', 'name' => 'Shown in history', 'description' => '', 'price' => '10', 'stock' => '1', 'category' => 'other' ) ), array( 'line' => 3, 'cells' => array( 'sku' => 'UI-2', 'name' => 'x', 'description' => '', 'price' => '0', 'stock' => '1', 'category' => 'other' ) ) ), '<b>evil</b>.csv' );
Importer::apply( $A['user_id'], $plan );
foreach ( \Nexora\Products\Repository::ids_for_vendor( $A['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; }
$vis = $visible( $render( $A['user_id'], $A['login'] ) );
nx_assert( false !== strpos( $vis, 'CSV' ) && false !== strpos( $vis, '&lt;b&gt;evil&lt;/b&gt;.csv' ) && false === strpos( $vis, '<b>evil</b>' ), 'the history lists the import and escapes the file name' );
nx_assert( 1 === preg_match( '/Created[^<]*<[^>]*>\s*1|1\s*created/i', $vis ) || false !== strpos( $vis, '1 created' ), 'the history shows how many were created' );
nx_assert( false !== strpos( $vis, 'line 3' ) || false !== strpos( $vis, 'Line 3' ), 'and the row error with its line number' );

/* ---------- others get none of it ---------- */
foreach ( array( 'a customer' => array( $C['user_id'], $C['login'] ), 'a visitor on the vendor\'s profile' => array( $V['user_id'], $A['login'] ) ) as $label => $who ) {
	$h = $render( $who[0], $who[1] );
	nx_assert( false === strpos( $h, 'nx-import' ) && false === strpos( $h, 'Import history' ) && false === strpos( $h, 'Shown in history' ), "$label: no import controls and no history" );
}

/* ---------- scripts ---------- */
$load = function ( $uid ) {
	$GLOBALS['wp_scripts'] = null; $GLOBALS['wp_styles'] = null; $GLOBALS['post'] = null;
	wp_set_current_user( $uid );
	$q = new WP_Query( array( 'pagename' => 'profile-page' ) );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
	if ( $q->have_posts() ) { $q->the_post(); $GLOBALS['post'] = $q->post; }
	set_query_var( 'username', '' );
	do_action( 'wp_enqueue_scripts' );
	$r = wp_script_is( 'nexora-imports', 'enqueued' );
	wp_set_current_user( 0 );
	return $r;
};
nx_assert( $load( $A['user_id'] ), 'the import script loads for an approved vendor' );
nx_assert( ! $load( $C['user_id'] ), 'and not for a customer' );
$js = file_get_contents( NEXORA_PATH . 'assets/js/imports.js' );
nx_assert( false !== strpos( $js, 'Swal.fire' ) && false !== strpos( $js, 'FormData' ) && false !== strpos( $js, 'nexora_csv_preview' ) && false !== strpos( $js, 'nexora_csv_import' ) && false !== strpos( $js, 'nexora_api_preview' ) && false !== strpos( $js, 'nexora_api_import' ), 'imports.js uses SweetAlert2 and FormData and calls the four actions' );
nx_assert( false === strpos( $js, 'innerHTML' ) && false === strpos( $js, 'eval(' ) && false === strpos( $js, 'document.write' ) && false === strpos( $js, '.html(' ), 'imports.js builds the report with text nodes only (no raw HTML)' );
nx_assert( 1 !== preg_match( '/[\'"](vendor_id|user_id|source|post_status)[\'"]\s*:/', $js ), 'imports.js never sends a vendor_id, user_id, source or status' );

nx_test_finish();
