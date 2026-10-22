<?php
// Design system conformance: the CSS of the plugin and the theme must use the shared tokens instead of private values.
// Reads files only, so it needs no WordPress: `wp eval-file tests/php/test-design-system.php` (via tests/run.sh) or plain `php`.
if ( ! function_exists( 'nx_assert' ) ) {
	$GLOBALS['nx_test'] = array( 'pass' => 0, 'fail' => 0 );
	function nx_assert( $cond, $label ) {
		$GLOBALS['nx_test'][ $cond ? 'pass' : 'fail' ]++;
		echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	}
	function nx_assert_same( $expected, $actual, $label ) {
		nx_assert( $expected === $actual, $label . ( $expected === $actual ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
	}
}

$root   = dirname( __DIR__, 2 ) . '/wp-content';
$plugin = $root . '/plugins/nexora/assets/css';
$theme  = $root . '/themes/nexora-theme/assets/css';
$files  = array_merge( glob( $plugin . '/*.css' ), glob( $theme . '/*.css' ) );

/** CSS with comments removed. */
$read = function ( $file ) {
	return preg_replace( '#/\*.*?\*/#s', '', file_get_contents( $file ) );
};
/** The same CSS with every `:root { ... }` block removed (tokens may hold raw values, nothing else may). */
$strip_root = function ( $css ) {
	return preg_replace( '/:root\s*\{[^}]*\}/', '', $css );
};

$tokens_css = $read( $plugin . '/tokens.css' );
nx_assert( '' !== $tokens_css, 'setup: tokens.css readable (' . count( $files ) . ' stylesheets scanned)' );

/* ---- tokens: every scale exists ---- */
$required = array(
	'--nx-primary', '--nx-primary-dark', '--nx-primary-soft', '--nx-primary-text', '--nx-ink', '--nx-bg', '--nx-text', '--nx-text-muted', '--nx-text-faint', '--nx-white', '--nx-border',
	'--nx-surface', '--nx-surface-muted', '--nx-accent-bg', '--nx-accent-text', '--nx-danger', '--nx-success', '--nx-warning', '--nx-info',
	'--nx-success-bg', '--nx-success-text', '--nx-warning-bg', '--nx-warning-text', '--nx-danger-bg', '--nx-danger-text', '--nx-info-bg', '--nx-info-text', '--nx-neutral-bg', '--nx-neutral-text',
	'--nx-space-1', '--nx-space-2', '--nx-space-3', '--nx-space-4', '--nx-space-5', '--nx-space-6', '--nx-space-8', '--nx-space-12',
	'--nx-text-xs', '--nx-text-sm', '--nx-text-base', '--nx-text-md', '--nx-text-lg', '--nx-text-xl', '--nx-text-2xl',
	'--nx-radius-sm', '--nx-radius-md', '--nx-radius-lg', '--nx-radius-pill',
	'--nx-z-sticky', '--nx-z-dropdown', '--nx-z-modal', '--nx-z-chat', '--nx-z-toast',
	'--nx-duration', '--nx-ease', '--nx-focus-ring',
);
foreach ( $required as $name ) {
	nx_assert( (bool) preg_match( '/' . preg_quote( $name, '/' ) . '\s*:/', $tokens_css ), "tokens.css defines $name" );
}

/* ---- every var(--x) used anywhere is defined somewhere ---- */
$defined = array();
$used    = array();
foreach ( $files as $f ) {
	$css = $read( $f );
	preg_match_all( '/(--[a-z0-9-]+)\s*:/i', $css, $m );
	foreach ( $m[1] as $n ) { $defined[ $n ] = true; }
	preg_match_all( '/var\(\s*(--[a-z0-9-]+)/i', $css, $m );
	foreach ( $m[1] as $n ) { $used[ $n ][ basename( $f ) ] = true; }
}
$undefined = array();
foreach ( $used as $name => $in ) {
	if ( empty( $defined[ $name ] ) && 0 !== strpos( $name, '--e-' ) && 0 !== strpos( $name, '--swal' ) ) {
		$undefined[] = $name . ' (' . implode( ',', array_keys( $in ) ) . ')';
	}
}
nx_assert_same( array(), $undefined, 'every var(--token) used is defined' );

/* ---- per-file conformance ---- */
$bad = array( 'hex' => array(), 'rgba' => array(), 'font' => array(), 'space' => array(), 'radius' => array(), 'z' => array(), 'motion' => array(), 'bp' => array(), 'important' => array(), 'outline' => array() );
foreach ( $files as $f ) {
	$name = basename( dirname( $f, 3 ) ) . '/' . basename( $f );
	$css  = $strip_root( $read( $f ) );

	// Colours are measured in declarations only, so id selectors such as #chat-input are not counted.
	preg_match_all( '/[a-z-]+\s*:\s*[^;{}]*#[0-9a-fA-F]{3,8}\b[^;{}]*;/i', $css, $m );
	$hexdecl = count( $m[0] );
	if ( $hexdecl ) { $bad['hex'][] = $name . ' x' . $hexdecl; }

	if ( preg_match_all( '/rgba?\(/', $css, $m ) ) { $bad['rgba'][] = $name . ' x' . count( $m[0] ); }

	preg_match_all( '/font-size\s*:\s*([^;}]+)/', $css, $m );
	foreach ( $m[1] as $v ) {
		$v = trim( $v );
		if ( ! preg_match( '/^(var\(--[a-z0-9-]+\)|clamp\(.*\)|inherit|[0-9.]+(em|%)|calc\(.*var\(.*\)|smaller|larger)$/i', $v ) ) { $bad['font'][ $name ][] = $v; }
	}

	preg_match_all( '/(?<![a-z-])(?:padding|margin|gap|row-gap|column-gap)(?:-[a-z]+)?\s*:\s*([^;}]+)/', $css, $m );
	foreach ( $m[1] as $v ) {
		if ( preg_match( '/(?<![\w.-])(?!0px)[0-9.]+px/', $v ) ) { $bad['space'][ $name ][] = trim( $v ); }
	}

	preg_match_all( '/border-radius\s*:\s*([^;}]+)/', $css, $m );
	foreach ( $m[1] as $v ) {
		if ( preg_match( '/[0-9.]+(px|rem)/', $v ) && ! preg_match( '/^(0|var\(--nx[t]?-radius[a-z-]*\))(\s+(0|var\(--nx[t]?-radius[a-z-]*\)))*(\s*!important)?$/', trim( $v ) ) ) { $bad['radius'][ $name ][] = trim( $v ); }
	}

	preg_match_all( '/z-index\s*:\s*([^;}]+)/', $css, $m );
	foreach ( $m[1] as $v ) {
		if ( ! preg_match( '/^(-?[0-1]|var\(--nx[t]?-z-[a-z]+\)|auto)$/', trim( $v ) ) ) { $bad['z'][ $name ][] = trim( $v ); }
	}

	preg_match_all( '/transition(?:-duration)?\s*:\s*([^;}]+)/', $css, $m );
	foreach ( 'tokens.css' === basename( $f ) ? array() : $m[1] as $v ) {
		if ( preg_match( '/[0-9.]+m?s/', $v ) ) { $bad['motion'][ $name ][] = trim( $v ); }
	}

	preg_match_all( '/@media[^{]*\(\s*(?:max|min)-width\s*:\s*([0-9]+)px/', $css, $m );
	foreach ( $m[1] as $w ) {
		if ( ! in_array( (int) $w, array( 600, 900, 1200 ), true ) ) { $bad['bp'][ $name ][ $w ] = true; }
	}

	if ( 'elementor.css' !== basename( $f ) && preg_match_all( '/!important/', $css, $m ) ) { $bad['important'][] = $name . ' x' . count( $m[0 ] ); }

	// A rule that removes the outline must give the field another visible focus style.
	preg_match_all( '/\{[^{}]*outline\s*:\s*(?:none|0)\b[^{}]*\}/', $css, $m );
	foreach ( $m[0] as $rule ) {
		if ( false === strpos( $rule, 'focus-ring' ) ) { $bad['outline'][] = $name . ': ' . trim( preg_replace( '/\s+/', ' ', substr( $rule, 0, 90 ) ) ); }
	}
}
nx_assert_same( array(), $bad['hex'], 'no raw hex colours outside the :root token blocks' );
nx_assert_same( array(), $bad['rgba'], 'no raw rgb()/rgba() outside the :root token blocks' );
nx_assert_same( array(), $bad['font'], 'font sizes come from the type scale (var(--nx-text-*), clamp, em or %)' );
nx_assert_same( array(), $bad['space'], 'padding, margin and gap use the spacing scale, not px literals' );
nx_assert_same( array(), $bad['radius'], 'border-radius uses the radius tokens' );
nx_assert_same( array(), $bad['z'], 'z-index uses the z tokens' );
nx_assert_same( array(), $bad['motion'], 'transitions use --nx-duration / --nx-ease tokens' );
nx_assert_same( array(), $bad['bp'], 'media queries use only the 600, 900 and 1200px breakpoints' );
nx_assert_same( array(), $bad['important'], 'no !important outside the Elementor override sheet' );
nx_assert_same( array(), $bad['outline'], 'every rule that removes an outline provides var(--nx-focus-ring)' );

/* ---- motion preferences ---- */
nx_assert( (bool) preg_match( '/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/', $tokens_css . $read( $plugin . '/components.css' ) ), 'prefers-reduced-motion is handled in the shared sheets' );

/* ---- component layer ---- */
$components = file_exists( $plugin . '/components.css' ) ? $read( $plugin . '/components.css' ) : '';
foreach ( array( '.nx-btn', '.nx-btn--primary', '.nx-btn--secondary', '.nx-btn--danger', '.nx-btn--sm', '.nx-badge', '.nx-badge--success', '.nx-badge--warning', '.nx-badge--danger', '.nx-badge--info', '.nx-badge--neutral', '.nx-badge--accent', '.nx-table', '.nx-stat-bar', '.nx-input', '.nx-card', '.nx-swal' ) as $cls ) {
	nx_assert( false !== strpos( $components, $cls ), "components.css defines $cls" );
}

/* ---- contrast: text pairs from the tokens meet WCAG AA (4.5:1) ---- */
$val = function ( $name ) use ( $tokens_css ) {
	return preg_match( '/' . preg_quote( $name, '/' ) . '\s*:\s*(#[0-9a-fA-F]{6})/', $tokens_css, $m ) ? $m[1] : null;
};
$lum = function ( $hex ) {
	$hex = ltrim( $hex, '#' );
	$c   = array_map( function ( $i ) use ( $hex ) {
		$v = hexdec( substr( $hex, $i, 2 ) ) / 255;
		return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
	}, array( 0, 2, 4 ) );
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = function ( $a, $b ) use ( $lum ) {
	$x = $lum( $a ); $y = $lum( $b );
	return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
};
$pairs = array(
	array( '--nx-ink', '--nx-white' ), array( '--nx-text', '--nx-white' ), array( '--nx-text-muted', '--nx-white' ), array( '--nx-text-muted', '--nx-surface-muted' ),
	array( '--nx-text-faint', '--nx-white' ), array( '--nx-text-faint', '--nx-surface-muted' ), array( '--nx-text-faint', '--nx-bg' ),
	array( '--nx-primary', '--nx-white' ), array( '--nx-primary-text', '--nx-white' ), array( '--nx-primary-text', '--nx-primary-soft' ), array( '--nx-primary-text', '--nx-surface-muted' ),
	array( '--nx-white', '--nx-primary' ), array( '--nx-white', '--nx-primary-dark' ), array( '--nx-white', '--nx-danger' ), array( '--nx-white', '--nx-success' ), array( '--nx-white', '--nx-warning' ), array( '--nx-white', '--nx-disabled-bg' ),
	array( '--nx-success-text', '--nx-success-bg' ), array( '--nx-warning-text', '--nx-warning-bg' ), array( '--nx-danger-text', '--nx-danger-bg' ),
	array( '--nx-info-text', '--nx-info-bg' ), array( '--nx-accent-text', '--nx-accent-bg' ), array( '--nx-neutral-text', '--nx-neutral-bg' ),
);
foreach ( $pairs as $p ) {
	$a = $val( $p[0] ); $b = $val( $p[1] );
	if ( ! $a || ! $b ) { nx_assert( false, "contrast {$p[0]} on {$p[1]}: both tokens must be 6-digit hex" ); continue; }
	$r = $ratio( $a, $b );
	nx_assert( $r >= 4.5, sprintf( 'contrast %s on %s is %.2f (needs 4.5)', $p[0], $p[1], $r ) );
}

/* ---- one token vocabulary: the theme uses --nx-* and keeps an identical copy of tokens.css for when the plugin is off ---- */
nx_assert( file_exists( $theme . '/tokens.css' ) && file_get_contents( $theme . '/tokens.css' ) === file_get_contents( $plugin . '/tokens.css' ), 'theme tokens.css is a byte-identical copy of the plugin tokens.css' );
$nxt = array();
foreach ( $files as $f ) {
	if ( preg_match( '/--nxt-[a-z0-9-]+/', $read( $f ), $m ) ) { $nxt[] = basename( $f ) . ': ' . $m[0]; }
}
nx_assert_same( array(), $nxt, 'no --nxt-* tokens remain (the theme shares the --nx-* vocabulary)' );

if ( function_exists( 'nx_test_finish' ) ) {
	nx_test_finish();
} else {
	$t = $GLOBALS['nx_test'];
	echo sprintf( "%d passed, %d failed\n", $t['pass'], $t['fail'] );
	exit( $t['fail'] ? 1 : 0 );
}
