<?php

namespace Nexora\Imports;

use Nexora\Core\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The product API sources an administrator offers to every vendor ("Product API sources" in Nexora System > Settings),
 * the master switch that lets vendors use their own API, and the optional host allow-list for those. Keys are stored
 * encrypted (Core\Secrets) and are never shown back.
 */
class Sources {

	const OPTION            = 'nexora_api_sources';
	const SWITCH            = 'nexora_vendor_apis_enabled';
	const HOSTS             = 'nexora_api_host_allowlist';
	const MAX               = 20;
	const FORMATS           = array( 'nexora', 'dummyjson' );
	const AUTHS             = array( 'none', 'header', 'bearer' );
	const FORBIDDEN_HEADERS = array( 'host', 'content-length', 'content-type', 'cookie', 'set-cookie', 'transfer-encoding', 'connection', 'upgrade', 'te', 'trailer', 'expect', 'keep-alive', 'proxy-authorization', 'accept', 'accept-encoding', 'user-agent' );

	/**
	 * The built-in source: DummyJSON (the sample catalog this project started with).
	 *
	 * @return array[]
	 */
	public static function defaults() {

		return array(
			array(
				'id'          => 'dummyjson',
				'name'        => 'DummyJSON',
				'url'         => 'https://dummyjson.com/products',
				'format'      => 'dummyjson',
				'auth_type'   => 'none',
				'auth_header' => '',
				'secret'      => '',
				'enabled'     => true,
			),
		);
	}

	/**
	 * Every configured source (the defaults until an administrator saves the list).
	 *
	 * @return array[]
	 */
	public static function all() {

		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return self::defaults();
		}

		$out = array();

		foreach ( $stored as $row ) {

			if ( is_array( $row ) && isset( $row['id'], $row['url'] ) ) {
				$out[] = array(
					'id'          => (string) $row['id'],
					'name'        => (string) ( $row['name'] ?? $row['id'] ),
					'url'         => (string) $row['url'],
					'format'      => in_array( $row['format'] ?? '', self::FORMATS, true ) ? $row['format'] : 'nexora',
					'auth_type'   => in_array( $row['auth_type'] ?? '', self::AUTHS, true ) ? $row['auth_type'] : 'none',
					'auth_header' => (string) ( $row['auth_header'] ?? '' ),
					'secret'      => (string) ( $row['secret'] ?? '' ),
					'enabled'     => ! empty( $row['enabled'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * The enabled sources.
	 *
	 * @return array[]
	 */
	public static function enabled() {

		return array_values(
			array_filter(
				self::all(),
				function ( $source ) {
					return $source['enabled'];
				}
			)
		);
	}

	/**
	 * One source by id.
	 *
	 * @param mixed $id Source id.
	 * @return array|null
	 */
	public static function get( $id ) {

		if ( ! is_string( $id ) ) {
			return null;
		}

		foreach ( self::all() as $source ) {

			if ( $source['id'] === $id ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * A source without its key, safe for a template or a browser.
	 *
	 * @param array $source A source.
	 * @return array
	 */
	public static function public_view( array $source ) {

		unset( $source['secret'] );

		$source['has_secret'] = '' !== ( self::get( $source['id'] )['secret'] ?? '' );

		return $source;
	}

	/**
	 * Whether vendors may use their own APIs (off until an administrator says so).
	 *
	 * @return bool
	 */
	public static function vendor_apis_enabled() {
		return (bool) get_option( self::SWITCH, 0 );
	}

	/**
	 * The optional allow-list for vendor-owned APIs (empty = any public https host).
	 *
	 * @return string[]
	 */
	public static function host_allowlist() {

		$list = array();

		foreach ( preg_split( '/\R/', (string) get_option( self::HOSTS, '' ) ) as $line ) {

			$line = strtolower( trim( $line ) );

			if ( '' !== $line ) {
				$list[] = $line;
			}
		}

		return $list;
	}

	/**
	 * Cleans the list posted by the settings form. A blank key field keeps the saved key, the dots placeholder never
	 * replaces it, "remove key" clears it; a key is stored only encrypted.
	 *
	 * @param mixed $input The posted rows.
	 * @return array[]
	 */
	public static function sanitize( $input ) {

		if ( ! is_array( $input ) ) {
			return array();
		}

		$known = array();

		foreach ( self::all() as $source ) {
			$known[ $source['id'] ] = $source;
		}

		$out  = array();
		$used = array();

		foreach ( $input as $row ) {

			if ( count( $out ) >= self::MAX || ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$name = self::text( $row['name'] ?? '' );
			$url  = isset( $row['url'] ) && is_string( $row['url'] ) ? $row['url'] : '';

			if ( '' === $name ) {
				continue;
			}

			$syntax = Url_Guard::syntax( $url );

			if ( is_wp_error( $syntax ) ) {
				self::notice( sprintf( /* translators: %s: source name. */ __( 'The address of "%s" is not a public https address, so the source was not saved.', 'nexora' ), $name ) );
				continue;
			}

			$id = isset( $row['id'] ) && is_string( $row['id'] ) && isset( $known[ $row['id'] ] ) && ! isset( $used[ $row['id'] ] ) ? $row['id'] : self::new_id( $name, $used, $known );

			$used[ $id ] = true;
			$auth        = isset( $row['auth_type'] ) && in_array( $row['auth_type'], self::AUTHS, true ) ? $row['auth_type'] : 'none';
			$existing    = $known[ $id ]['secret'] ?? '';

			$out[] = array(
				'id'          => $id,
				'name'        => $name,
				'url'         => $syntax['url'],
				'format'      => isset( $row['format'] ) && in_array( $row['format'], self::FORMATS, true ) ? $row['format'] : 'nexora',
				'auth_type'   => $auth,
				'auth_header' => 'header' === $auth ? self::header_name( $row['auth_header'] ?? '' ) : '',
				'secret'      => 'none' === $auth ? '' : self::secret( $row, $existing, $name ),
				'enabled'     => ! empty( $row['enabled'] ),
			);
		}

		return $out;
	}

	/**
	 * The master switch.
	 *
	 * @param mixed $value Posted value.
	 * @return int 1 or 0.
	 */
	public static function sanitize_switch( $value ) {
		return $value ? 1 : 0;
	}

	/**
	 * The allow-list: one plain host name per line, lower-cased, once each. Addresses, URLs, wildcards and local names are dropped.
	 *
	 * @param mixed $value Posted text.
	 * @return string
	 */
	public static function sanitize_hosts( $value ) {

		$hosts = array();

		foreach ( preg_split( '/\R/', is_string( $value ) ? $value : '' ) as $line ) {

			$line = strtolower( trim( $line ) );

			if ( '' !== $line && ! is_wp_error( Url_Guard::syntax( 'https://' . $line . '/' ) ) ) {
				$hosts[ $line ] = $line;
			}
		}

		return implode( "\n", array_values( $hosts ) );
	}

	/**
	 * Decides what to store for a row's key.
	 *
	 * @param array  $row      Posted row.
	 * @param string $existing The stored (encrypted) key.
	 * @param string $name     Source name (for a notice).
	 * @return string Encrypted key, or ''.
	 */
	private static function secret( array $row, $existing, $name ) {

		if ( ! empty( $row['clear_secret'] ) ) {
			return '';
		}

		$typed = isset( $row['secret'] ) && is_string( $row['secret'] ) ? $row['secret'] : '';

		if ( '' === $typed || preg_match( '/^[•*]+$/u', $typed ) ) {
			return $existing;
		}

		if ( ! self::key_valid( $typed ) ) {
			self::notice( sprintf( /* translators: %s: source name. */ __( 'The key for "%s" must be 1-512 visible ASCII characters, so it was not changed.', 'nexora' ), $name ) );
			return $existing;
		}

		return Secrets::encrypt( $typed );
	}

	/**
	 * A safe header name for "header key" auth.
	 *
	 * @param mixed $name Posted name.
	 * @return string
	 */
	private static function header_name( $name ) {
		return self::header_name_valid( $name ) ? trim( $name ) : 'X-API-Key';
	}

	/**
	 * Whether a header name is a plain one that may carry a key (not Host, Cookie, Content-Length and the like).
	 *
	 * @param mixed $name Header name.
	 * @return bool
	 */
	public static function header_name_valid( $name ) {

		$name = is_string( $name ) ? trim( $name ) : '';

		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9-]{0,39}$/', $name ) && ! in_array( strtolower( $name ), self::FORBIDDEN_HEADERS, true );
	}

	/**
	 * Whether a key or token is plain visible ASCII, 1-512 characters (nothing that could add a header line).
	 *
	 * @param mixed $key The key.
	 * @return bool
	 */
	public static function key_valid( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[\x21-\x7e]{1,512}$/', $key );
	}

	/**
	 * An id for a new source, from its name.
	 *
	 * @param string $name  Source name.
	 * @param array  $used  Ids taken in this save.
	 * @param array  $known Ids already stored.
	 * @return string
	 */
	private static function new_id( $name, array $used, array $known ) {

		$base = substr( sanitize_title( $name ), 0, 36 );
		$base = '' !== $base ? $base : 'source';
		$id   = $base;
		$n    = 2;

		while ( isset( $used[ $id ] ) || isset( $known[ $id ] ) ) {
			$id = $base . '-' . $n;
			++$n;
		}

		return $id;
	}

	/**
	 * A single-line, tag-free name of at most 60 characters.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, 60 ) : '';
	}

	/**
	 * Shows a message on the settings screen.
	 *
	 * @param string $message Text.
	 */
	private static function notice( $message ) {

		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'nexora_api_sources', 'nexora_api_sources', $message );
		}
	}
}
