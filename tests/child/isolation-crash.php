<?php
// Child process for test-isolation-helper.php: scopes two options, changes them, then ends the way NX_ISO_MODE says
// (uncaught exception, fatal error, exit, a failed assertion, or a normal finish). The parent checks that the options are back.
require_once __DIR__ . '/../bootstrap.php';

$mode = (string) getenv( 'NX_ISO_MODE' );

nx_test_options( array( 'nx_iso_crash' => 'scratch-value', 'nx_iso_crash_absent' => 'made-by-the-child' ) );
update_option( 'nx_iso_crash', 'changed-again' );
add_option( 'nx_iso_crash_extra', 'never-scoped-by-name' ); // not scoped: proves only scoped names are touched

switch ( $mode ) {
	case 'exception':
		throw new RuntimeException( 'boom' );
	case 'fatal':
		nx_this_function_does_not_exist(); // phpcs:ignore
		break;
	case 'exit':
		exit( 3 );
	case 'fail':
		nx_assert( false, 'a deliberate failure' );
		nx_test_finish();
		break;
	default:
		nx_test_finish();
}
