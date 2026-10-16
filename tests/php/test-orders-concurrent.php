<?php
// Marketplace Phase 6b: two buyers, the last unit. Two real PHP processes place their orders at the same instant; exactly one gets the
// unit, the other is refused with a clear message, and the stock never goes below zero.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Lines;

if ( ! function_exists( 'WC' ) || ! function_exists( 'proc_open' ) ) { echo "WooCommerce or proc_open missing: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();

$A = nx_test_vendor( 'cca', 'approved' );
$stock = function ( $pid ) { clean_post_cache( $pid ); wp_cache_delete( $pid, 'post_meta' ); return (int) get_post_meta( $pid, 'stock', true ); };

/** Start one child per order id, all released at the same moment; returns their decoded RESULT lines. */
$race = function ( array $order_ids ) {
	$go     = microtime( true ) + 7.0; // WordPress needs a moment to load in each child
	$procs  = array();
	$cmd    = 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval-file tests/child/place-order.php --path=. 2>&1';
	foreach ( $order_ids as $i => $id ) {
		$env = array_merge( $_ENV, array( 'NX_ORDER_ID' => (string) $id, 'NX_GO_AT' => sprintf( '%.4f', $go ) ) );
		if ( getenv( 'WP_CLI_CONFIG_PATH' ) ) { $env['WP_CLI_CONFIG_PATH'] = getenv( 'WP_CLI_CONFIG_PATH' ); }
		$env['PATH'] = getenv( 'PATH' );
		$env['HOME'] = getenv( 'HOME' );
		$procs[ $i ] = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
		$procs[ $i ] = array( $procs[ $i ], $pipes );
	}
	$out = array();
	foreach ( $procs as $i => $p ) {
		$text = stream_get_contents( $p[1][1] );
		fclose( $p[1][1] ); fclose( $p[1][2] );
		proc_close( $p[0] );
		$out[ $i ] = preg_match( '/^RESULT (.*)$/m', $text, $m ) ? json_decode( $m[1], true ) : array( 'ok' => null, 'message' => 'no result: ' . substr( $text, 0, 300 ) );
	}
	wp_cache_flush(); // the children changed the database behind this process's object cache
	return $out;
};

/* ---------- the last unit, three rounds ---------- */
for ( $round = 1; $round <= 3; $round++ ) {
	$pid = nx_test_product( $A, "CC-$round", '10.00', '1' );
	$b1  = nx_test_user( "ccb1$round" );
	$b2  = nx_test_user( "ccb2$round" );
	$o1  = nx_test_order( $b1, array( array( $pid, 1 ) ), array( 'place' => false ) );
	$o2  = nx_test_order( $b2, array( array( $pid, 1 ) ), array( 'place' => false ) );
	nx_assert( $o1['id'] > 0 && $o2['id'] > 0, "round $round: two buyers each have an order waiting for the last unit" );

	$res = $race( array( $o1['id'], $o2['id'] ) );
	$ok  = array_filter( $res, function ( $r ) { return true === $r['ok']; } );
	$no  = array_filter( $res, function ( $r ) { return false === $r['ok']; } );
	nx_assert_same( 1, count( $ok ), "round $round: exactly one order got the last unit (" . wp_json_encode( $res ) . ')' );
	nx_assert_same( 1, count( $no ), "round $round: exactly one was refused" );
	nx_assert_same( 0, $stock( $pid ), "round $round: the stock is 0, never negative" );
	$refused = array_values( $no );
	nx_assert( $refused && false !== stripos( (string) $refused[0]['message'], 'stock' ), "round $round: the loser is told stock is gone: \"" . ( $refused ? $refused[0]['message'] : '' ) . '"' );
	$held = 0;
	foreach ( array( $o1['id'], $o2['id'] ) as $oid ) { $held += Lines::of( wc_get_order( $oid ) )[0]['stock_held']; }
	nx_assert_same( 1, $held, "round $round: orders hold exactly one unit between them" );
	$statuses = array_map( function ( $oid ) { return wc_get_order( $oid )->get_status(); }, array( $o1['id'], $o2['id'] ) );
	nx_assert( 1 === count( array_filter( $statuses, function ( $s ) { return in_array( $s, array( 'cancelled', 'failed' ), true ); } ) ), "round $round: the refused order is cancelled/failed, not left waiting (" . implode( ',', $statuses ) . ')' );
}

/* ---------- 5 in stock, three buyers want 2 each: two win, one is refused ---------- */
$pid = nx_test_product( $A, 'CC-MANY', '10.00', '5' );
$ids = array();
foreach ( array( 'x', 'y', 'z' ) as $k ) {
	$b     = nx_test_user( "ccm$k" );
	$o     = nx_test_order( $b, array( array( $pid, 2 ) ), array( 'place' => false ) );
	$ids[] = $o['id'];
}
$res = $race( $ids );
$won = count( array_filter( $res, function ( $r ) { return true === $r['ok']; } ) );
nx_assert_same( 2, $won, 'five units, three buyers wanting two each: two orders win' );
nx_assert_same( 1, $stock( $pid ), '... and one unit is left, never a negative number' );

nx_test_finish();
