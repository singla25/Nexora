<?php
// The test runner must never run against the site's real database. tests/live-guard.php decides (a pure function, tested here with every
// combination), tests/run.sh and tests/guard.sh refuse before touching anything (tested by running them), and bootstrap.php, purge.php and
// the guard tool load it first. The one way in is an explicit override for the small list of read-only tests in tests/live-readonly.txt.
require_once __DIR__ . '/../bootstrap.php';

$root = dirname( __DIR__ );
require_once $root . '/live-guard.php';

$live = 'siteDB';
$ro   = 'test-static-namespaces.php';
$why  = function ( $db, $scratch, $override = '', $file = 'test-x.php', $allow = array( 'test-static-namespaces.php' ) ) use ( $live ) {
	return nx_live_guard_check( $live, $db, $scratch, $override, $file, $allow );
};

/* ---------- the decision ---------- */
nx_assert_same( '', $why( 'scratch1', 'scratch1' ), 'a scratch database named in NX_TEST_DB that is the one in use: allowed' );
nx_assert( '' !== $why( $live, '' ), 'no NX_TEST_DB, the live database in use: refused' );
nx_assert( '' !== $why( $live, $live ), 'NX_TEST_DB set to the live database name: refused' );
nx_assert( '' !== $why( 'scratch1', '' ), 'NX_TEST_DB empty while another database is in use: refused (the runner did not set it up)' );
nx_assert( '' !== $why( $live, 'scratch1' ), 'NX_TEST_DB names a scratch database but the live one is in use: refused' );
nx_assert( '' !== $why( 'scratch2', 'scratch1' ), 'the database in use is not the one NX_TEST_DB names: refused' );
nx_assert( '' !== $why( $live, 'SITEDB' ) && '' !== $why( 'SITEDB', 'SITEDB' ), 'the live name is compared without regard to case (MySQL table-name rules differ by system)' );
nx_assert( '' !== $why( '', '' ) && '' !== nx_live_guard_check( '', 'x', 'x', '', 'test-x.php', array() ), 'an unknown live name or database refuses' );
foreach ( array( 'yes', 'true', '0', '2', ' 1' ) as $bad ) {
	nx_assert( '' !== $why( $live, '', $bad, $ro ), "override '$bad' is not the explicit value 1: refused" );
}
nx_assert_same( '', $why( $live, '', '1', $ro ), 'the explicit override allows a test on the read-only list against the live database' );
nx_assert_same( '', $why( $live, $live, '1', $ro ), '... also with NX_TEST_DB set to the live name' );
nx_assert( '' !== $why( $live, '', '1', 'test-orders-placement.php' ), 'the override does NOT allow a test that is not on the read-only list' );
nx_assert( '' !== $why( $live, '', '1', 'purge.php', array() ), 'nor anything when the list is empty (purge.php and the guard tool pass no list)' );
nx_assert( '' !== $why( $live, '', '1', '../php/test-static-namespaces.php/../../x.php' ) && '' !== $why( $live, '', '1', '' ), 'the file name is matched exactly, not by a path trick or when empty' );
nx_assert_same( '', $why( 'scratch1', 'scratch1', '1', 'test-x.php' ), '(a scratch database never needs the override and is not affected by it)' );

/* ---------- the read-only list is small and really read-only ---------- */
$list = array_values( array_filter( array_map( 'trim', file( $root . '/live-readonly.txt' ) ), function ( $l ) { return '' !== $l && 0 !== strpos( $l, '#' ); } ) );
nx_assert( $list && count( $list ) <= 4, 'tests/live-readonly.txt lists a few files (' . count( $list ) . ')' );
foreach ( $list as $name ) {
	$code = file_get_contents( $root . '/php/' . $name );
	nx_assert( is_file( $root . '/php/' . $name ) && 0 === preg_match( '/nx_test_(user|vendor|product|order|admin|direct_seller|connection|attachment|options|marketplace|thread)|wp_insert_|wp_update_|wp_delete_|(update|add|delete)_(option|post_meta|user_meta)|wpdb->(insert|update|delete|query|replace)|curl_' . 'init/', $code ), "$name exists and writes nothing" );
}

/* ---------- the scripts refuse before touching anything ---------- */
$live_name = nx_live_guard_live_name();
nx_assert( '' !== $live_name, 'the live database name is read from wp-config.php (' . ( '' !== $live_name ? 'found' : 'NOT found' ) . ')' );
$run = function ( $env, $script, $args = '' ) use ( $root ) {
	$out = array();
	exec( 'cd ' . escapeshellarg( $root . '/..' ) . ' && env -u NX_TEST_DB -u NX_ALLOW_LIVE_READONLY ' . $env . ' bash ' . escapeshellarg( $root . '/' . $script ) . ' ' . $args . ' 2>&1', $out, $rc );
	return array( $rc, implode( "\n", $out ) );
};
list( $rc, $out ) = $run( '', 'run.sh', 'tests/php/test-smoke.php' );
nx_assert( 0 !== $rc && false !== stripos( $out, 'refus' ) && false === strpos( $out, 'purged' ) && false === strpos( $out, '== tests/php' ), 'run.sh with no NX_TEST_DB refuses before purging or running anything' );
list( $rc, $out ) = $run( 'NX_TEST_DB=' . escapeshellarg( $live_name ), 'run.sh', 'tests/php/test-smoke.php' );
nx_assert( 0 !== $rc && false !== stripos( $out, 'refus' ) && false === strpos( $out, '== tests/php' ), 'run.sh with NX_TEST_DB = the live database refuses' );
list( $rc, $out ) = $run( 'NX_ALLOW_LIVE_READONLY=1', 'run.sh', 'tests/php/test-smoke.php' );
nx_assert( 0 !== $rc && false !== stripos( $out, 'refus' ) && false === strpos( $out, '== tests/php' ), 'run.sh with the override refuses a test that is not on the read-only list' );
list( $rc, $out ) = $run( '', 'guard.sh' );
nx_assert( 0 !== $rc && false !== stripos( $out, 'refus' ), 'guard.sh with no NX_TEST_DB refuses' );
list( $rc, $out ) = $run( 'NX_TEST_DB=' . escapeshellarg( $live_name ), 'guard.sh' );
nx_assert( 0 !== $rc && false !== stripos( $out, 'refus' ), 'guard.sh with NX_TEST_DB = the live database refuses' );

/* ---------- everything that touches the database loads the guard first ---------- */
foreach ( array( 'bootstrap.php', 'purge.php', 'tools/guard-state.php' ) as $f ) {
	$code = file_get_contents( $root . '/' . $f );
	$pos  = strpos( $code, 'live-guard.php' );
	$first_work = preg_match( '/\n(global \$wpdb|require_once ABSPATH|add_filter|\$cmd)/', $code, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : PHP_INT_MAX;
	nx_assert( false !== $pos && $pos < $first_work, "$f loads tests/live-guard.php before it does any work" );
}
nx_assert( false !== strpos( file_get_contents( $root . '/run.sh' ), 'live-readonly.txt' ) && false !== strpos( file_get_contents( $root . '/run.sh' ), 'NX_ALLOW_LIVE_READONLY' ), 'run.sh knows the override and the read-only list' );

nx_test_finish();
