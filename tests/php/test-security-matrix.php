<?php
// Phase 10: docs/SECURITY-MATRIX.md lists EVERY way into the plugin (AJAX actions, admin-post actions, row actions, shortcodes, REST routes,
// WP-Cron events, request hooks and admin screen hooks). This test finds the entry points in the running site and fails when one is not in
// the matrix (or the matrix lists one that no longer exists). It proves itself with deliberately unlisted fake entry points, checks that
// every row says who may call it, the nonce, the permission check, whether a guest may, the rate limit, the input checks and the output
// escaping, and cross-checks what the matrix claims against the code (rate-limit buckets exist, guest actions are really nopriv, ...).
require_once __DIR__ . '/../bootstrap.php';

global $wp_filter, $shortcode_tags;

$root  = dirname( __DIR__, 2 );
$doc   = $root . '/docs/SECURITY-MATRIX.md';
nx_assert( is_file( $doc ), 'docs/SECURITY-MATRIX.md exists' );
$text = is_file( $doc ) ? (string) file_get_contents( $doc ) : '';

const NX_KINDS = array( 'ajax', 'ajax-alias', 'admin-post', 'row-action', 'shortcode', 'rest', 'cron', 'request-hook', 'screen-hook', 'file' );

/** Is this callback Nexora's? Returns "Class::method" / "closure" / null. */
$own = function ( $cb ) {
	if ( is_array( $cb ) ) {
		$c = is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0];
		return ( 0 === strpos( ltrim( $c, '\\' ), 'Nexora\\' ) || 0 === strpos( $c, 'NEXORA' ) ) ? ltrim( $c, '\\' ) . '::' . $cb[1] : null;
	}
	if ( $cb instanceof Closure ) {
		$f = ( new ReflectionFunction( $cb ) )->getFileName();
		return false !== strpos( (string) $f, 'plugins/nexora/' ) || false !== strpos( (string) $f, "eval()'d code" ) ? 'closure' : null;
	}
	return null;
};

/** Every entry point of the running site as "kind|id" strings. */
$discover = function () use ( $own ) {
	global $wp_filter, $shortcode_tags;
	$found = array();
	foreach ( $wp_filter as $hook => $obj ) {
		$kind = null; $id = null;
		if ( 0 === strpos( $hook, 'wp_ajax_nopriv_nexora_' ) ) { $kind = 'ajax'; $id = substr( $hook, strlen( 'wp_ajax_nopriv_' ) ); }
		elseif ( 0 === strpos( $hook, 'wp_ajax_nexora_' ) ) { $kind = 'ajax'; $id = substr( $hook, strlen( 'wp_ajax_' ) ); }
		elseif ( 0 === strpos( $hook, 'admin_post_nopriv_nexora_' ) ) { $kind = 'admin-post'; $id = substr( $hook, strlen( 'admin_post_nopriv_' ) ); }
		elseif ( 0 === strpos( $hook, 'admin_post_nexora_' ) ) { $kind = 'admin-post'; $id = substr( $hook, strlen( 'admin_post_' ) ); }
		if ( $kind ) {
			foreach ( $obj->callbacks as $cbs ) { foreach ( $cbs as $cb ) { if ( $own( $cb['function'] ) ) { $found[ "$kind|$id" ] = true; } } }
			continue;
		}
		if ( 0 === strpos( $hook, 'wp_ajax_' ) && 0 !== strpos( $hook, 'wp_ajax_nopriv_' ) ) { continue; } // aliases are checked separately
		$map = array( 'post_row_actions' => 'row-action', 'user_row_actions' => 'row-action', 'page_row_actions' => 'row-action' );
		if ( isset( $map[ $hook ] ) || 0 === strpos( $hook, 'bulk_actions-' ) || 0 === strpos( $hook, 'handle_bulk_actions-' ) ) {
			foreach ( $obj->callbacks as $cbs ) { foreach ( $cbs as $cb ) { $n = $own( $cb['function'] ); if ( $n && 'closure' !== $n ) { $found[ 'row-action|' . $hook . ' ' . $n ] = true; } } }
			continue;
		}
		if ( in_array( $hook, array( 'init', 'wp_loaded', 'template_redirect', 'admin_init', 'parse_request', 'send_headers', 'login_init', 'rest_api_init', 'wp_login', 'plugins_loaded', 'save_post', 'delete_user', 'pre_get_posts' ), true ) ) {
			foreach ( $obj->callbacks as $cbs ) { foreach ( $cbs as $cb ) { $n = $own( $cb['function'] ); if ( $n && 'closure' !== $n ) { $found[ 'request-hook|' . $hook . ' ' . $n ] = true; } } }
			continue;
		}
		if ( 0 === strpos( $hook, 'load-' ) ) {
			foreach ( $obj->callbacks as $cbs ) { foreach ( $cbs as $cb ) { $n = $own( $cb['function'] ); if ( $n && 'closure' !== $n ) { $found[ 'screen-hook|' . $hook . ' ' . $n ] = true; } } }
		}
	}
	foreach ( $shortcode_tags as $tag => $cb ) { if ( $own( $cb ) && 'closure' !== $own( $cb ) ) { $found[ 'shortcode|' . $tag ] = true; } }
	foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) { if ( false !== stripos( $route, 'nexora' ) ) { $found[ 'rest|' . $route ] = true; } }
	// Cron: every hook the plugin schedules (a static scan of the source for wp_schedule_* calls, resolved to the hook name).
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NEXORA_PATH . 'src', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( 'php' !== $f->getExtension() ) { continue; }
		$src = (string) file_get_contents( $f->getPathname() );
		if ( preg_match_all( '/wp_schedule_(?:single_)?event\(\s*[^,]+,\s*(?:self::|[A-Za-z_\\\\]+::)?([A-Za-z_]+|\'[a-z_]+\')/', $src, $m ) ) {
			foreach ( $m[1] as $h ) {
				if ( preg_match( '/const\s+' . preg_quote( $h, '/' ) . '\s*=\s*\'([a-z_]+)\'/', $src, $c ) ) { $found[ 'cron|' . $c[1] ] = true; } elseif ( 0 === strpos( $h, "'" ) ) { $found[ 'cron|' . trim( $h, "'" ) ] = true; }
			}
		}
	}
	// Anything else that serves a file or reads the request on its own is an AJAX action or a hook above (the private document download is the
	// ajax action nexora_document).
	return array_keys( $found );
};

/** The rows of the matrix: "kind|id" => array of cells. */
$parse = function ( $text ) {
	$rows = array();
	foreach ( explode( "\n", $text ) as $line ) {
		if ( 0 !== strpos( ltrim( $line ), '|' ) ) { continue; }
		$cells = array_map( 'trim', explode( '|', trim( trim( $line ), '|' ) ) );
		if ( count( $cells ) < 9 || ! in_array( trim( $cells[0], '` ' ), NX_KINDS, true ) ) { continue; }
		$rows[ trim( $cells[0], '` ' ) . '|' . trim( $cells[1], '` ' ) ] = $cells;
	}
	return $rows;
};
$rows = $parse( $text );
nx_assert( count( $rows ) > 40, 'the matrix has a row per entry point (' . count( $rows ) . ' found)' );

/* ---------- every entry point of the site is in the matrix, and nothing in the matrix is gone ---------- */
$gaps = function ( array $found, array $listed ) { return array( array_values( array_diff( $found, $listed ) ), array_values( array_diff( $listed, $found ) ) ); };
$listed = array_values( array_filter( array_keys( $rows ), function ( $k ) { return 0 !== strpos( $k, 'ajax-alias|' ) && 'rest|none' !== $k; } ) );
list( $missing, $stale ) = $gaps( $discover(), $listed );
nx_assert_same( array(), $missing, 'every entry point of the site is in the matrix' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );
nx_assert_same( array(), $stale, 'every row of the matrix is an entry point that exists' . ( $stale ? ' (gone: ' . implode( ', ', $stale ) . ')' : '' ) );

/* ---------- the detector really detects: deliberately unlisted fake entry points ---------- */
add_action( 'wp_ajax_nexora_zz_fake_endpoint', function () {} );
add_action( 'wp_ajax_nopriv_nexora_zz_fake_guest', function () {} );
add_action( 'admin_post_nexora_zz_fake_post', function () {} );
add_shortcode( 'nexora_zz_fake_shortcode', 'nx_zz_fake_shortcode' );
add_action( 'rest_api_init', function () { register_rest_route( 'nexora-zz/v1', '/fake', array( 'methods' => 'GET', 'callback' => '__return_true', 'permission_callback' => '__return_true' ) ); } );
function nx_zz_fake_shortcode() { return ''; }
// A class method callback is what the real plugin uses; the closure fakes above are recognised too because they live in tests/php.
class NEXORA_Zz_Fake { public function hook() {} }
add_action( 'wp_ajax_nexora_zz_fake_class', array( new NEXORA_Zz_Fake(), 'hook' ) );
do_action( 'rest_api_init' );
list( $missing2 ) = $gaps( $discover(), $listed );
foreach ( array( 'ajax|nexora_zz_fake_endpoint', 'ajax|nexora_zz_fake_guest', 'admin-post|nexora_zz_fake_post' ) as $fake ) {
	nx_assert( in_array( $fake, $missing2, true ), "a new unlisted entry point ($fake) is reported as missing from the matrix" );
}
nx_assert( in_array( 'ajax|nexora_zz_fake_class', $missing2, true ), 'a handler that is a class method is recognised the same way' );
nx_assert( (bool) array_filter( $missing2, function ( $k ) { return 0 === strpos( $k, 'rest|' ) && false !== strpos( $k, 'nexora-zz' ); } ), 'a new REST route is reported too' );
// Remove the fakes again.
remove_all_actions( 'wp_ajax_nexora_zz_fake_endpoint' ); remove_all_actions( 'wp_ajax_nopriv_nexora_zz_fake_guest' ); remove_all_actions( 'admin_post_nexora_zz_fake_post' ); remove_all_actions( 'wp_ajax_nexora_zz_fake_class' );
remove_shortcode( 'nexora_zz_fake_shortcode' );
list( $after ) = $gaps( $discover(), $listed );
$after = array_values( array_filter( $after, function ( $k ) { return false === strpos( $k, 'nexora-zz' ); } ) );
nx_assert_same( array(), $after, 'with the fakes removed the matrix is complete again (the REST fake stays registered for this request only and is ignored)' );

/* ---------- every row is complete ---------- */
$cols = array( 'kind', 'id', 'who', 'nonce', 'permission', 'guest', 'rate limit', 'input', 'output' );
$bad  = array();
foreach ( $rows as $key => $cells ) {
	for ( $i = 2; $i < 9; $i++ ) { if ( '' === ( $cells[ $i ] ?? '' ) || '?' === $cells[ $i ] || 'TODO' === strtoupper( $cells[ $i ] ) ) { $bad[] = "$key: empty column {$cols[$i]}"; } }
	if ( ! in_array( strtolower( $cells[5] ), array( 'yes', 'no' ), true ) && 0 !== strpos( strtolower( $cells[5] ), 'yes' ) && 0 !== strpos( strtolower( $cells[5] ), 'no' ) ) { $bad[] = "$key: 'guest' must start with yes or no"; }
}
nx_assert_same( array(), $bad, 'every matrix row answers all of: who, nonce, permission, guest, rate limit, input, output' . ( $bad ? ' (' . $bad[0] . ')' : '' ) );

/* ---------- what the matrix says about guests and rate limits is true ---------- */
$guest_listed = array(); $buckets_named = array();
foreach ( $rows as $key => $cells ) {
	if ( 0 === strpos( $key, 'ajax|' ) && 0 === strpos( strtolower( $cells[5] ), 'yes' ) ) { $guest_listed[] = substr( $key, 5 ); }
	if ( preg_match_all( '/`([a-z_]+)`\s*\(\d/', $cells[6], $m ) ) { foreach ( $m[1] as $b ) { $buckets_named[ $b ] = $key; } }
}
$real_guest = array();
foreach ( array_keys( $wp_filter ) as $hook ) { if ( 0 === strpos( $hook, 'wp_ajax_nopriv_nexora_' ) ) { $real_guest[] = substr( $hook, strlen( 'wp_ajax_nopriv_' ) ); } }
sort( $guest_listed ); sort( $real_guest );
nx_assert_same( $real_guest, $guest_listed, 'the AJAX actions the matrix says a guest may call are exactly the ones registered for guests' );
$defaults = \Nexora\Http\Rate_Limiter::defaults();
foreach ( $buckets_named as $b => $key ) { nx_assert( isset( $defaults[ $b ] ), "rate-limit bucket '$b' named in the matrix ($key) exists" ); }
foreach ( array_keys( $defaults ) as $b ) { nx_assert( isset( $buckets_named[ $b ] ), "rate-limit bucket '$b' is used by (and named in) some row of the matrix" ); }

/* ---------- the deprecated un-prefixed AJAX names are exactly the prefixed ones ---------- */
$prefixed = array();
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( preg_match( '/^wp_ajax_(?:nopriv_)?nexora_(?!zz_)(.+)$/', $hook, $m ) ) { $prefixed[ $m[1] ] = true; }
}
$chat = array( 'search_users', 'get_messages', 'get_user_threads', 'send_message', 'create_thread_with_subject', 'get_thread_subject', 'update_subject', 'get_latest_thread_between_users', 'document' );
foreach ( array_keys( $prefixed ) as $name ) {
	$has_alias = false !== has_action( 'wp_ajax_' . $name );
	nx_assert( in_array( $name, $chat, true ) ? ! $has_alias : $has_alias, "$name: the deprecated un-prefixed name " . ( in_array( $name, $chat, true ) ? 'does not exist (chat and download were never aliased)' : 'exists, as the matrix row ajax-alias says' ) );
}
nx_assert( isset( $rows['ajax-alias|*'] ), 'the matrix has the ajax-alias row for the un-prefixed names' );

/* ---------- no REST routes (the matrix says none) and the only cron event is the queued mail ---------- */
nx_assert_same( array(), array_values( array_filter( $discover(), function ( $k ) { return 0 === strpos( $k, 'rest|' ) && false === strpos( $k, 'nexora-zz' ); } ) ), 'REST: the plugin registers no route' );
nx_assert( isset( $rows['rest|none'] ), 'the matrix says plainly that there are no REST routes' );

nx_test_finish();
