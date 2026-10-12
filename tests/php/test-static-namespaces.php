<?php
// Static check of every file under src/: each class reference must resolve once PHP applies the file's
// namespace and `use` imports. Catches the classic refactor bug of an unqualified WP_Query / DateTime /
// WP_Error inside a namespaced file, which would only fail when that exact line runs.
require_once __DIR__ . '/../bootstrap.php';

$root = NEXORA_PATH . 'src';
nx_assert( is_dir( $root ), 'src/ exists' );
$builtin = array( 'self', 'static', 'parent', 'array', 'callable', 'int', 'string', 'bool', 'float', 'mixed', 'void', 'iterable', 'object', 'null', 'false', 'true', 'never', 'integer', 'boolean', 'resource' );

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$count = 0; $problems = array();
foreach ( $files as $f ) {
	if ( 'php' !== $f->getExtension() || false !== strpos( $f->getPathname(), '/Views/' ) ) { continue; }
	$count++;
	$tokens = token_get_all( file_get_contents( $f->getPathname() ) );
	$tokens = array_values( array_filter( $tokens, function ( $t ) { return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ); } ) );
	$ns = ''; $imports = array(); $declared = array(); $n = count( $tokens );
	$val = function ( $i ) use ( $tokens ) { return is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ]; };
	$tid = function ( $i ) use ( $tokens, $n ) { return $i >= 0 && $i < $n && is_array( $tokens[ $i ] ) ? $tokens[ $i ][0] : null; };
	$is_name = function ( $i ) use ( $tid ) { return in_array( $tid( $i ), array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ); };
	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_NAMESPACE === $tid( $i ) && $is_name( $i + 1 ) ) { $ns = $val( $i + 1 ); }
		if ( T_USE === $tid( $i ) && $is_name( $i + 1 ) ) {
			// top-level imports only (class-body `use` of traits would follow `{`); good enough: record alias
			$fq = ltrim( $val( $i + 1 ), '\\' ); $alias = substr( strrchr( '\\' . $fq, '\\' ), 1 );
			if ( T_AS === $tid( $i + 2 ) ) { $alias = $val( $i + 3 ); }
			$imports[ $alias ] = $fq;
		}
		if ( in_array( $tid( $i ), array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) && T_STRING === $tid( $i + 1 ) && T_DOUBLE_COLON !== $tid( $i - 1 ) ) { $declared[] = $val( $i + 1 ); }
	}
	$check = function ( $i, $why ) use ( &$problems, $f, $val, $ns, $imports, $declared, $builtin, $tid ) {
		$name = $val( $i );
		if ( T_NAME_FULLY_QUALIFIED === $tid( $i ) || in_array( strtolower( $name ), $builtin, true ) ) { return; }
		$first = strtok( $name, '\\' );
		$rest  = strpos( $name, '\\' ) !== false ? substr( $name, strlen( $first ) ) : '';
		if ( isset( $imports[ $first ] ) ) { $fq = $imports[ $first ] . $rest; }
		elseif ( in_array( $first, $declared, true ) && $rest === '' ) { return; }
		else { $fq = ( $ns !== '' ? $ns . '\\' : '' ) . $name; }
		if ( ! class_exists( $fq ) && ! interface_exists( $fq ) && ! trait_exists( $fq ) ) {
			$problems[] = str_replace( NEXORA_PATH, '', $f->getPathname() ) . ": $why `$name` resolves to $fq which does not exist";
		}
	};
	$param_depth = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tid( $i );
		if ( T_NEW === $t && $is_name( $i + 1 ) ) { $check( $i + 1, 'new' ); }
		if ( T_INSTANCEOF === $t && $is_name( $i + 1 ) ) { $check( $i + 1, 'instanceof' ); }
		if ( $is_name( $i ) && T_DOUBLE_COLON === $tid( $i + 1 ) && ! in_array( $tid( $i - 1 ), array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ), true ) ) { $check( $i, 'static call' ); }
		if ( T_CATCH === $t ) { for ( $j = $i + 2; $j < $n && ')' !== $val( $j ); $j++ ) { if ( $is_name( $j ) ) { $check( $j, 'catch' ); } } }
		if ( in_array( $t, array( T_EXTENDS, T_IMPLEMENTS ), true ) ) { for ( $j = $i + 1; $j < $n && '{' !== $val( $j ); $j++ ) { if ( $is_name( $j ) ) { $check( $j, 'extends/implements' ); } } }
		if ( T_FUNCTION === $t || T_FN === $t ) {
			$j = $i + 1; while ( $j < $n && '(' !== $val( $j ) ) { $j++; }
			$d = 0;
			for ( ; $j < $n; $j++ ) {
				if ( '(' === $val( $j ) ) { $d++; }
				if ( ')' === $val( $j ) ) { $d--; if ( 0 === $d ) { break; } }
				if ( $is_name( $j ) && in_array( $tid( $j + 1 ), array( T_VARIABLE, T_ELLIPSIS ), true ) || ( $is_name( $j ) && in_array( $val( $j + 1 ), array( '&' ), true ) ) ) { $check( $j, 'type hint' ); }
			}
			if ( ':' === $val( $j + 1 ) && $is_name( $j + 2 ) ) { $check( $j + 2, 'return type' ); }
		}
	}
}
nx_assert_same( array(), $problems, "all class references in src/ resolve ($count files checked)" );
if ( $problems ) { echo implode( "\n", $problems ), "\n"; }
nx_test_finish();
