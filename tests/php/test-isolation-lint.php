<?php
// Test isolation rules, enforced on the test files themselves. A test may only change or delete what it created (or what it
// scoped with nx_test_options(), which puts the original back exactly). This reads every tests/php/test-*.php and fails on:
//  - update_option / delete_option / add_option on an option the file does not scope with nx_test_options() and did not name itself
//    (nx_test_*, nxtest_*, nx_iso_*, or a GSTIN lock it made),
//  - TRUNCATE, and DROP TABLE except on the nxtest_ scratch prefix,
//  - deleting products/posts by a meta or type sweep (the way a real Marketplace Item could be caught),
//  - removing or re-creating a real role, and raw "DELETE FROM options" sweeps outside the shared helpers.
require_once __DIR__ . '/../bootstrap.php';

$dir   = __DIR__;
$self  = basename( __FILE__ );
$files = glob( $dir . '/test-*.php' );
sort( $files );
nx_assert( count( $files ) > 40, 'setup: found the test files (' . count( $files ) . ')' );

// Class constants that name site options.
$constants = array(
	'\\Nexora\\Database\\Uninstaller::SETTING' => "'nexora_delete_data_on_uninstall'",
	'Uninstaller::SETTING'                       => "'nexora_delete_data_on_uninstall'",
	'Marketplace_Item::OPTION'                   => "'nexora_marketplace_item_id'",
	'Fields::OPTION'                             => "'nexora_product_categories'",
	'Account_Types::OPTION'                      => "'nexora_accounts_version'",
);

$own_prefixes = array( 'nx_test_', 'nxtest_', 'nx_iso_', 'nexora_vendor_lock_' );

$violations = array();
foreach ( $files as $file ) {

	$name = basename( $file );

	if ( $name === $self ) {
		continue;
	}

	$code    = file_get_contents( $file );
	$lines   = explode( "\n", $code );
	$scoped  = '';

	// Everything inside nx_test_options( ... ) / nx_test_options_keep( ... ) calls of this file is "scoped" text, and so are the
	// settings nx_test_default_settings() scopes.
	if ( preg_match_all( '/nx_test_options(?:_keep)?\s*\((.*?)\)\s*;/s', $code, $calls ) ) {
		$scoped = implode( "\n", $calls[1] );
	}

	// tests/bootstrap.php scopes these five for every test file (nx_test_default_settings()).
	$scoped .= "\n'nexora_api_sources' 'nexora_vendor_apis_enabled' 'nexora_api_host_allowlist' 'nexora_product_categories' 'nexora_delete_data_on_uninstall'";

	if ( false !== strpos( $code, 'nx_test_marketplace_item_scope()' ) ) {
		$scoped .= "\n'nexora_marketplace_item_id'";
	}

	$keeps_roles = false !== strpos( $code, "user_roles'" ) && false !== strpos( $code, 'nx_test_options_keep(' );

	foreach ( $lines as $i => $line ) {

		$n = $i + 1;

		if ( preg_match( '/^\s*(\/\/|\*|\/\*)/', $line ) ) {
			continue;
		}

		// 1. option writes and deletes
		if ( preg_match_all( '/\b(update_option|delete_option|add_option)\s*\(\s*([^,)]+)/', $line, $m, PREG_SET_ORDER ) ) {

			foreach ( $m as $hit ) {

				$arg   = trim( $hit[2] );
				$arg   = strtr( $arg, $constants );
				$plain = trim( $arg, "'\" " );
				$own   = false;

				foreach ( $own_prefixes as $prefix ) {
					if ( 0 === strpos( $plain, $prefix ) ) {
						$own = true;
					}
				}

				$scoped_text = strtr( $scoped, $constants );
				$in_scope    = '' !== $scoped_text && ( false !== strpos( $scoped_text, $arg ) || ( "'" === substr( $arg, 0, 1 ) && false !== strpos( $scoped_text, "'" . $plain . "'" ) ) );

				// A variable name (a name the test built or saved) is fine only for the restore idiom replaced by the helper.
				if ( ! $own && ! $in_scope ) {
					$violations[] = "$name:$n {$hit[1]}( $arg ) - not scoped with nx_test_options() and not created by the test";
				}
			}
		}

		// 2. destructive SQL
		if ( preg_match( '/\bTRUNCATE\b/i', $line ) ) {
			$violations[] = "$name:$n TRUNCATE";
		}

		if ( preg_match( '/\bDROP\s+TABLE\b/i', $line ) && ! preg_match( "/'\s*DROP|\"\s*'|; DROP|;DROP/", $line ) ) {
			// only on the scratch prefix, which the file must set first
			if ( false === strpos( $code, "\$wpdb->prefix   = 'nxtest_'" ) && false === strpos( $code, "\$wpdb->prefix = 'nxtest_'" ) ) {
				$violations[] = "$name:$n DROP TABLE without the nxtest_ scratch prefix";
			}
		}

		// 3. sweeping deletes (by meta or by query) and the role
		if ( preg_match( '/wp_delete_post\s*\(\s*\$leftover|foreach\s*\(\s*wc_get_products[^)]*\)[^{]*\{[^}]*wp_delete_post|get_posts\([^;]*\)\s*as\s*\$\w+\s*\)\s*\{\s*wp_delete_post/', $line ) ) {
			$violations[] = "$name:$n deletes products found by a sweep (could include real ones)";
		}

		if ( ! $keeps_roles && ( preg_match( '/(?<!->)\b(remove_role|add_role)\s*\(\s*(Account_Types::ROLE|\'nexora_vendor\'|"nexora_vendor")/', $line ) || preg_match( "/'roles'\s*=>\s*array\(\s*Account_Types::ROLE/", $line ) ) ) {
			$violations[] = "$name:$n touches the real vendor role (use a scratch role)";
		}

		if ( preg_match( '/\$wpdb->query\(\s*"DELETE FROM \{\$wpdb->options\}/', $line ) && 'bootstrap.php' !== $name ) {
			$violations[] = "$name:$n raw DELETE FROM options (use nx_test_flush_api_cache() / the scoped helpers)";
		}
	}
}

foreach ( $violations as $v ) {
	echo "  VIOLATION $v\n";
}
nx_assert_same( array(), $violations, 'no test changes or deletes anything it did not create or scope (' . count( $violations ) . ' violation(s) listed above)' );

// Every test starts from the known default settings (set once, in the bootstrap).
$boot = file_get_contents( __DIR__ . '/../bootstrap.php' );
nx_assert( 1 === preg_match( '/if \( ! getenv\( \'NX_TEST_KEEP_SETTINGS\' \) \) \{\s*nx_test_default_settings\(\);/', $boot ), 'the bootstrap puts every test on the known default settings (opt-out only with NX_TEST_KEEP_SETTINGS)' );

// The helpers themselves are in place.
foreach ( array( 'nx_test_options', 'nx_test_options_restore', 'nx_test_raw_option', 'nx_test_marketplace_item_scope', 'nx_test_marketplace_item_scope_restore', 'nx_test_ephemera_begin', 'nx_test_ephemera_restore', 'nx_test_flush_api_cache' ) as $fn ) {
	nx_assert( function_exists( $fn ), "the shared helper $fn() exists" );
}

// Every file that uses the Marketplace Item scopes it (so the real one is never edited or deleted).
foreach ( $files as $file ) {

	$name = basename( $file );
	$code = file_get_contents( $file );

	if ( $name !== $self && $name !== 'test-isolation-helper.php' && preg_match( '/Marketplace_Item::|Cart_Bridge::|Cart_Rules::|Cart_Panel::|nexora_add_to_cart|store\/v1\/cart/', $code ) ) {
		nx_assert( false !== strpos( $code, 'nx_test_marketplace_item_scope()' ), "$name uses the Marketplace Item, so it scopes it" );
	}
}

// purge.php must not sweep generic ephemera (it removes fixtures named nxtest_* only).
$purge = file_get_contents( __DIR__ . '/../purge.php' );
nx_assert( false === strpos( $purge, 'nexora\\\\_rl\\\\_%' ) && false === strpos( $purge, "nexora\\\\_vendor\\\\_lock\\\\_%" ), 'tests/purge.php no longer sweeps rate-limit counters or GSTIN locks it did not create' );

nx_test_finish();
