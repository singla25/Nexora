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

	/**
	 * Absolute path of src/ with a trailing slash.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Old class names (lower-cased) mapped to the current class.
	 *
	 * @var array<string,string>
	 */
	private static $legacy = array();

	/**
	 * Registers the PSR-4 autoloader and the legacy class-name aliases.
	 *
	 * @param string $base_dir Absolute path of the src/ folder.
	 * @param array  $legacy_map Old class name => current class name.
	 */
	public static function register( $base_dir, array $legacy_map = array() ) {

		self::$base = rtrim( $base_dir, '/\\' ) . '/';

		foreach ( $legacy_map as $old => $new ) {
			self::$legacy[ strtolower( $old ) ] = $new;
		}

		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads a Nexora\ class from src/, or aliases an old global class name.
	 *
	 * @param string $class_name Fully qualified class name being loaded.
	 */
	public static function load( $class_name ) {

		if ( strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) === 0 ) {

			$file = self::$base . str_replace( '\\', '/', substr( $class_name, strlen( self::PREFIX ) ) ) . '.php';

			if ( is_file( $file ) ) {
				require_once $file;
			}

			return;
		}

		$key = strtolower( $class_name );

		if ( isset( self::$legacy[ $key ] ) && class_exists( self::$legacy[ $key ] ) ) {
			class_alias( self::$legacy[ $key ], $class_name );
		}
	}
}
