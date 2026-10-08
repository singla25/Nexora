<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 autoloader for the Nexora\ namespace (src/), no Composer needed on the server.
 *
 * Old global class names (NEXORA_System, NEXORA_CHAT_DB, ...) keep resolving through a
 * lazy alias map, so code and tests written against them continue to work.
 */
class Autoloader {

	const PREFIX = 'Nexora\\';

	/** @var string */
	private static $base = '';

	/** @var array<string,string> lower-cased legacy name => current class */
	private static $legacy = array();

	public static function register( $base_dir, array $legacy_map = array() ) {

		self::$base = rtrim( $base_dir, '/\\' ) . '/';

		foreach ( $legacy_map as $old => $new ) {
			self::$legacy[ strtolower( $old ) ] = $new;
		}

		spl_autoload_register( array( self::class, 'load' ) );
	}

	public static function load( $class ) {

		if ( strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) === 0 ) {

			$file = self::$base . str_replace( '\\', '/', substr( $class, strlen( self::PREFIX ) ) ) . '.php';

			if ( is_file( $file ) ) {
				require_once $file;
			}

			return;
		}

		$key = strtolower( $class );

		if ( isset( self::$legacy[ $key ] ) && class_exists( self::$legacy[ $key ] ) ) {
			class_alias( self::$legacy[ $key ], $class );
		}
	}
}
