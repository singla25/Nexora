<?php
/**
 * Keeps the test runner away from the site's real database. Loaded FIRST by tests/bootstrap.php, tests/purge.php and
 * tests/tools/guard-state.php (and mirrored in shell by tests/run.sh and tests/guard.sh).
 *
 * The rule: the database in use must be the scratch copy named in NX_TEST_DB, and that name must differ from the database in
 * wp-config.php. The one exception is the explicit override NX_ALLOW_LIVE_READONLY=1, and only for a test file listed in
 * tests/live-readonly.txt (a few tests that write nothing). tests/purge.php and the guard tool never get the override.
 */

if ( ! function_exists( 'nx_live_guard_check' ) ) {

	/**
	 * The database name in wp-config.php (read from the file, because a test run replaces the constant with the scratch name).
	 *
	 * @return string Empty when it cannot be read.
	 */
	function nx_live_guard_live_name() {

		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $file ) {
			if ( is_readable( $file ) && preg_match( '/define\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $file ), $m ) ) {
				return $m[1];
			}
		}

		return '';
	}

	/**
	 * Decides whether a run may go ahead.
	 *
	 * @param string   $live     The database in wp-config.php.
	 * @param string   $db       The database in use now.
	 * @param string   $scratch  NX_TEST_DB.
	 * @param string   $override NX_ALLOW_LIVE_READONLY.
	 * @param string   $file     Base name of the test file being run.
	 * @param string[] $allow    Base names allowed with the override.
	 * @return string Empty to go ahead, otherwise the reason it is refused.
	 */
	function nx_live_guard_check( $live, $db, $scratch, $override, $file, array $allow ) {

		if ( '' === $live || '' === $db ) {
			return 'cannot tell which database is the live one or which is in use';
		}

		if ( '' !== $scratch && 0 !== strcasecmp( $scratch, $live ) && $db === $scratch && 0 !== strcasecmp( $db, $live ) ) {
			return '';
		}

		if ( '1' === $override && '' !== $file && basename( $file ) === $file && in_array( $file, $allow, true ) ) {
			return '';
		}

		return 'tests run only on a scratch copy of the database: set NX_TEST_DB=<scratch database> (not the one in wp-config.php)';
	}

	/**
	 * The test file being run (the argument after "eval-file"), by base name.
	 *
	 * @return string
	 */
	function nx_live_guard_current_file() {

		$argv = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : array();
		$at   = array_search( 'eval-file', $argv, true );

		return false !== $at && isset( $argv[ $at + 1 ] ) ? basename( (string) $argv[ $at + 1 ] ) : '';
	}

	/**
	 * Stops the process (exit 3) unless the run is allowed.
	 *
	 * @param bool $allow_override Whether the read-only override may apply to the file being run.
	 */
	function nx_live_guard_enforce( $allow_override = false ) {

		global $wpdb;

		$allow = array();

		if ( $allow_override && is_readable( __DIR__ . '/live-readonly.txt' ) ) {
			$allow = array_values( array_filter( array_map( 'trim', file( __DIR__ . '/live-readonly.txt' ) ), function ( $l ) { return '' !== $l && 0 !== strpos( $l, '#' ); } ) );
		}

		$scratch = (string) getenv( 'NX_TEST_DB' );
		$db      = defined( 'DB_NAME' ) ? DB_NAME : '';
		$why     = nx_live_guard_check( nx_live_guard_live_name(), $db, $scratch, (string) getenv( 'NX_ALLOW_LIVE_READONLY' ), nx_live_guard_current_file(), $allow );

		if ( '' !== $why ) {
			fwrite( STDERR, "REFUSED: $why\n" );
			exit( 3 );
		}

		// On the live database (read-only override) the default settings are NOT written either.
		if ( '' === $scratch || 0 === strcasecmp( $scratch, nx_live_guard_live_name() ) ) {
			putenv( 'NX_TEST_KEEP_SETTINGS=1' );
		}
	}
}
