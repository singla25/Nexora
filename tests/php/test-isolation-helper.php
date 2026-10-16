<?php
// Test isolation: the shared helpers in tests/bootstrap.php that let a test set its own known option values and put the site's
// originals back EXACTLY (value, autoload flag, or "absent"), even when the test fails, throws or dies; that keep the real
// Marketplace Item untouched; and that bring back ephemeral rows (rate-limit counters, API caches, import tokens, locks).
// Everything here uses scratch names (nx_iso_*) that this test creates itself.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Marketplace\Marketplace_Item;

global $wpdb;

$raw = function ( $name ) { return nx_test_raw_option( $name ); };
$put = function ( $name, $value, $autoload = 'yes' ) use ( $wpdb ) {
	$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => $autoload ) );
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
};
$gone = function ( $name ) use ( $wpdb ) {
	$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( $name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
};
foreach ( array( 'nx_iso_a', 'nx_iso_b', 'nx_iso_c', 'nx_iso_crash', 'nx_iso_crash_absent', 'nx_iso_crash_extra', 'nx_iso_san' ) as $n ) { $gone( $n ); }

/* ---------- set a known value, put the original back: absent, serialized, odd autoload, unicode ---------- */
$serialized = serialize( array( 'k' => "v\u{00e9}", 'n' => array( 1, 2 ), 'b' => true ) );
$put( 'nx_iso_a', $serialized, 'no' );
$put( 'nx_iso_b', "  spaces and\nnewlines and ünïcödé  ", 'yes' );
$gone( 'nx_iso_c' );
$before = array( $raw( 'nx_iso_a' ), $raw( 'nx_iso_b' ), $raw( 'nx_iso_c' ) );
nx_assert_same( array( 'value' => $serialized, 'autoload' => 'no' ), $before[0], 'setup: the raw row of a serialized option with autoload "no"' );
nx_assert_same( null, $before[2], 'setup: an absent option reads as null' );

nx_test_options( array( 'nx_iso_a' => 'known-a', 'nx_iso_b' => null, 'nx_iso_c' => array( 'scratch' => 1 ) ) );
nx_assert_same( 'known-a', get_option( 'nx_iso_a' ), 'inside the scope the option has the known value' );
nx_assert_same( false, get_option( 'nx_iso_b' ), 'null makes it absent inside the scope' );
nx_assert_same( array( 'scratch' => 1 ), get_option( 'nx_iso_c' ), 'an absent option can be given a known value (arrays are stored serialized)' );
update_option( 'nx_iso_a', 'changed by the test' );
delete_option( 'nx_iso_c' );
add_option( 'nx_iso_b', 'created by the test' );
nx_test_options_restore();
nx_assert_same( $before, array( $raw( 'nx_iso_a' ), $raw( 'nx_iso_b' ), $raw( 'nx_iso_c' ) ), 'restore puts back the exact raw rows: value, autoload and absence (whatever the test did in between)' );
nx_assert_same( array( 'k' => "v\u{00e9}", 'n' => array( 1, 2 ), 'b' => true ), get_option( 'nx_iso_a' ), 'and WordPress reads the original through its cache' );
nx_test_options_restore();
nx_assert_same( $before, array( $raw( 'nx_iso_a' ), $raw( 'nx_iso_b' ), $raw( 'nx_iso_c' ) ), 'restoring twice changes nothing' );

/* ---------- the first snapshot wins when a name is scoped twice ---------- */
nx_test_options( array( 'nx_iso_a' => 'first' ) );
nx_test_options( array( 'nx_iso_a' => 'second' ) );
nx_assert_same( 'second', get_option( 'nx_iso_a' ), 'a second call sets the new known value' );
nx_test_options_restore();
nx_assert_same( $before[0], $raw( 'nx_iso_a' ), '... but restore brings back the ORIGINAL, not the first scratch value' );

/* ---------- the scope is raw: no sanitize filter can change what is set or what comes back ---------- */
add_filter( 'sanitize_option_nx_iso_san', function () { return 'MANGLED'; } );
add_filter( 'pre_update_option_nx_iso_san', function () { return 'MANGLED'; } );
$put( 'nx_iso_san', 'original-san', 'no' );
nx_test_options( array( 'nx_iso_san' => 'known-san' ) );
nx_assert_same( 'known-san', $raw( 'nx_iso_san' )['value'], 'the known value is written as given (settings sanitisers are not applied)' );
nx_test_options_restore();
nx_assert_same( array( 'value' => 'original-san', 'autoload' => 'no' ), $raw( 'nx_iso_san' ), 'and the original comes back unsanitised, autoload intact' );
$gone( 'nx_iso_san' );

/* ---------- it comes back when the test fails, throws, dies or exits (each in its own process) ---------- */
$child = function ( $mode ) {
	// (a scratch-database run reaches the child through WP_CLI_CONFIG_PATH, which the child inherits)
	return (string) shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && NX_ISO_MODE=' . escapeshellarg( $mode ) . ' wp eval-file tests/child/isolation-crash.php --path=. 2>&1' );
};
foreach ( array( 'exception' => 'an uncaught exception', 'fatal' => 'a fatal error', 'exit' => 'exit()', 'fail' => 'a failed assertion', 'pass' => 'a normal finish' ) as $mode => $label ) {
	$put( 'nx_iso_crash', 'ORIGINAL-' . $mode, 'no' );
	$gone( 'nx_iso_crash_absent' );
	$gone( 'nx_iso_crash_extra' );
	$out = $child( $mode );
	nx_assert( false !== strpos( $out, 'nx_this_function_does_not_exist' ) || 'fatal' !== $mode, "(the child really did die with $label)" );
	nx_assert_same( array( 'value' => 'ORIGINAL-' . $mode, 'autoload' => 'no' ), $raw( 'nx_iso_crash' ), "after $label the scoped option is exactly back" );
	nx_assert_same( null, $raw( 'nx_iso_crash_absent' ), "after $label an option that did not exist is absent again" );
	nx_assert( null !== $raw( 'nx_iso_crash_extra' ), "after $label an option the test created but did not scope is left alone (the helper touches only what it was told)" );
}
foreach ( array( 'nx_iso_crash', 'nx_iso_crash_absent', 'nx_iso_crash_extra' ) as $n ) { $gone( $n ); }

/* ---------- ephemeral rows: put back what was there, remove what the run made ---------- */
nx_test_ephemera_restore(); // end the tracking the bootstrap started, so this test can stage its own "rows the site already has"
$gone( 'nexora_rl_nxiso_pre_1' ); $gone( '_transient_nexora_api_nxiso_pre' ); $gone( '_transient_timeout_nexora_api_nxiso_pre' ); $gone( '_transient_nexora_import_nxiso_pre' ); $gone( 'nexora_lock_nxiso_pre' ); $gone( 'nexora_rl_nxiso_new_1' ); $gone( '_transient_nexora_api_nxiso_new' );
$put( 'nexora_rl_nxiso_pre_1', '7', 'no' );
$put( '_transient_nexora_api_nxiso_pre', 'cached page', 'no' );
$put( '_transient_timeout_nexora_api_nxiso_pre', (string) ( time() + 600 ), 'no' );
$put( '_transient_nexora_import_nxiso_pre', 'a real vendor\'s preview', 'no' );
$put( 'nexora_lock_nxiso_pre', '1', 'no' );
$pre = array( $raw( 'nexora_rl_nxiso_pre_1' ), $raw( '_transient_nexora_api_nxiso_pre' ), $raw( '_transient_timeout_nexora_api_nxiso_pre' ), $raw( '_transient_nexora_import_nxiso_pre' ), $raw( 'nexora_lock_nxiso_pre' ) );
nx_test_ephemera_begin( true );
$put( 'nexora_rl_nxiso_new_1', '3', 'no' );
$put( '_transient_nexora_api_nxiso_new', 'x', 'no' );
nx_test_reset_limits();
nx_assert_same( null, $raw( 'nexora_rl_nxiso_new_1' ), 'a rate-limit counter made by the test is cleared by nx_test_reset_limits()' );
nx_assert_same( null, $raw( 'nexora_rl_nxiso_pre_1' ), '... and the test sees a clean slate (a counter that was already there is set aside, not counted)' );
nx_test_ephemera_restore();
nx_assert_same( $pre, array( $raw( 'nexora_rl_nxiso_pre_1' ), $raw( '_transient_nexora_api_nxiso_pre' ), $raw( '_transient_timeout_nexora_api_nxiso_pre' ), $raw( '_transient_nexora_import_nxiso_pre' ), $raw( 'nexora_lock_nxiso_pre' ) ), 'rows that were there before (counter, API cache, import token, lock) are exactly back, values and autoload' );
nx_assert_same( array( null, null ), array( $raw( 'nexora_rl_nxiso_new_1' ), $raw( '_transient_nexora_api_nxiso_new' ) ), 'rows the run created are gone' );
foreach ( array( 'nexora_rl_nxiso_pre_1', '_transient_nexora_api_nxiso_pre', '_transient_timeout_nexora_api_nxiso_pre', '_transient_nexora_import_nxiso_pre', 'nexora_lock_nxiso_pre' ) as $n ) { $gone( $n ); }
nx_test_ephemera_begin( false ); // (tracking for the rest of this test, as the bootstrap does)

/* ---------- the Marketplace Item scope: the real item is never edited or deleted ---------- */
$made_real = false;
if ( ! (int) get_option( Marketplace_Item::OPTION ) && ! get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => Marketplace_Item::META, 'meta_value' => '1', 'fields' => 'ids', 'numberposts' => 1 ) ) ) {
	$made_real = true; // a database without one: this test makes a stand-in "real" item and removes it at the end
	$stand_in = Marketplace_Item::id();
}
$flagged = function () {
	return array_map( 'intval', get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => Marketplace_Item::META, 'meta_value' => '1', 'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true ) ) );
};
$real_id   = (int) get_option( Marketplace_Item::OPTION );
$real_rows = function () use ( $wpdb, $real_id ) {
	return array(
		$wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $real_id ), ARRAY_A ),
		$wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $real_id ), ARRAY_A ),
		$wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", Marketplace_Item::OPTION ), ARRAY_A ),
	);
};
nx_assert( $real_id > 0 && array( $real_id ) === $flagged(), 'setup: there is one real Marketplace Item' );
$real_before = $real_rows();
$flagged_before = $flagged();

nx_test_marketplace_item_scope();
$scratch = Marketplace_Item::id();
nx_assert( $scratch > 0 && $scratch !== $real_id, 'inside the scope Marketplace_Item::id() makes its own scratch item, not the real one' );
nx_assert_same( $real_before[0], $real_rows()[0], 'the real item\'s post row is not touched while the scope is open' );
nx_assert( ! Marketplace_Item::is_item( $real_id ) && Marketplace_Item::is_item( $scratch ), 'the real item is not "the" item inside the scope' );
// What the Marketplace Item test does: edit it and delete it for good (it heals by making a new one).
$edit = wc_get_product( $scratch ); $edit->set_regular_price( '99' ); $edit->save();
wp_delete_post( $scratch, true );
$healed = (int) get_option( Marketplace_Item::OPTION );
nx_assert( $healed > 0 && $healed !== $real_id && $healed !== $scratch, 'deleting the scratch item heals to another scratch item' );
nx_assert_same( $real_before[0], $real_rows()[0], 'the real item is still untouched' );
nx_test_marketplace_item_scope_restore();
nx_assert_same( $real_before, $real_rows(), 'after the scope the real item\'s post row, all its meta rows and the option are byte-identical' );
nx_assert_same( $flagged_before, $flagged(), 'and it is again the only flagged item (every scratch item the test made is gone)' );
nx_assert( null === get_post( $healed ) || 'trash' === get_post_status( $healed ) || ! in_array( $healed, $flagged(), true ), 'the healed scratch item is removed too' );
nx_assert_same( $real_id, Marketplace_Item::id(), 'Marketplace_Item::id() answers the real one again' );
nx_test_marketplace_item_scope_restore();
nx_assert_same( $real_before, $real_rows(), 'restoring twice changes nothing' );
if ( $made_real ) { wp_delete_post( $real_id, true ); nx_test_write_raw_option( Marketplace_Item::OPTION, null ); }

// remove the scratch rows this test made itself
foreach ( array( 'nx_iso_a', 'nx_iso_b', 'nx_iso_c', 'nx_iso_san', 'nx_iso_crash', 'nx_iso_crash_absent', 'nx_iso_crash_extra' ) as $n ) { $gone( $n ); }

nx_test_finish();
