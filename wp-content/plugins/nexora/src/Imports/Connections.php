<?php

namespace Nexora\Imports;

use Nexora\Core\Secrets;
use Nexora\Products\Service as Product_Service;
use Nexora\Vendors\Repository as Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for a vendor's own API: only approved vendors, only while the administrator allows vendor APIs, only their
 * own connections, only a public https address (Url_Guard, with the optional allow-list), a key only as plain visible
 * ASCII. Also turns a request ("platform:dummyjson", "conn:12", "adhoc" + address) into the source to fetch from; a
 * vendor can never name an address for a platform source.
 */
class Connections {

	/**
	 * Saves a connection (connection_id 0 = new).
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $raw     name, url, auth_type, auth_header, secret, clear_secret.
	 * @param int   $conn_id 0 to add, or the connection to edit.
	 * @return array|\WP_Error array( 'id' => int )
	 */
	public static function save( $user_id, array $raw, $conn_id = 0 ) {

		$vendor_id = Product_Service::vendor_for( (int) $user_id );

		if ( ! $vendor_id ) {
			return self::forbidden();
		}

		if ( ! Sources::vendor_apis_enabled() ) {
			return self::disabled();
		}

		$conn_id  = (int) $conn_id;
		$existing = $conn_id > 0 ? Connection_Repository::find( $conn_id, $vendor_id ) : null;

		if ( $conn_id < 0 || ( $conn_id > 0 && ! $existing ) ) {
			return self::forbidden();
		}

		if ( ! $existing && Connection_Repository::count_for_vendor( $vendor_id ) >= Connection_Repository::MAX ) {
			/* translators: %d: how many connections are allowed. */
			return new \WP_Error( 'nexora_limit', sprintf( __( 'You can save up to %d connections. Delete one first.', 'nexora' ), Connection_Repository::MAX ) );
		}

		$checked = self::check( $raw, $existing );

		if ( $checked['errors'] ) {
			return new \WP_Error( 'nexora_invalid', __( 'Please correct the highlighted fields.', 'nexora' ), array( 'fields' => $checked['errors'] ) );
		}

		if ( $existing ) {
			Connection_Repository::update( $conn_id, $checked['data'] );
			return array( 'id' => $conn_id );
		}

		$id = Connection_Repository::create( (int) $user_id, $vendor_id, $checked['data'] );

		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'nexora_save_failed', __( 'Could not save the connection. Please try again.', 'nexora' ) );
		}

		return array( 'id' => $id );
	}

	/**
	 * Deletes one of the vendor's own connections.
	 *
	 * @param int $user_id The vendor's user ID.
	 * @param int $conn_id Connection to delete.
	 * @return true|\WP_Error
	 */
	public static function delete( $user_id, $conn_id ) {

		$vendor_id = Product_Service::vendor_for( (int) $user_id );

		if ( ! $vendor_id || (int) $conn_id <= 0 || ! Connection_Repository::find( (int) $conn_id, $vendor_id ) ) {
			return self::forbidden();
		}

		Connection_Repository::delete( (int) $conn_id );

		return true;
	}

	/**
	 * Turns the request's "source" into something fetch() can read.
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $request source, and for "adhoc": url, auth_type, auth_header, secret.
	 * @return array|\WP_Error array( 'source' => array, 'conn_id' => int )
	 */
	public static function resolve_source( $user_id, array $request ) {

		$vendor_id = Product_Service::vendor_for( (int) $user_id );

		if ( ! $vendor_id ) {
			return self::forbidden();
		}

		$source = isset( $request['source'] ) ? $request['source'] : '';

		if ( '' === $source ) {

			$enabled = Sources::enabled();

			return $enabled
				? array(
					'source'  => Api_Source::from_platform( $enabled[0] ),
					'conn_id' => 0,
				)
				: new \WP_Error( 'nexora_source', __( 'No product source is available.', 'nexora' ) );
		}

		if ( ! is_string( $source ) ) {
			return new \WP_Error( 'nexora_source', __( 'Choose a source.', 'nexora' ) );
		}

		if ( 0 === strpos( $source, 'platform:' ) ) {

			$row = Sources::get( substr( $source, 9 ) );

			return $row && $row['enabled']
				? array(
					'source'  => Api_Source::from_platform( $row ),
					'conn_id' => 0,
				)
				: new \WP_Error( 'nexora_source', __( 'This source is not available.', 'nexora' ) );
		}

		if ( ! Sources::vendor_apis_enabled() ) {
			return self::disabled();
		}

		if ( 0 === strpos( $source, 'conn:' ) && ctype_digit( substr( $source, 5 ) ) ) {

			$conn = Connection_Repository::find( (int) substr( $source, 5 ), $vendor_id );

			if ( ! $conn ) {
				return self::forbidden();
			}

			return array(
				'source'  => self::vendor_source( 'c-' . $conn['id'], $conn['name'], $conn['url'], $conn['auth_type'], $conn['auth_header'], Secrets::decrypt( $conn['secret'] ) ),
				'conn_id' => $conn['id'],
			);
		}

		if ( 'adhoc' === $source ) {

			$checked = self::check( $request, null, false );

			if ( $checked['errors'] ) {
				return new \WP_Error( 'nexora_source', __( 'Check the address and the key.', 'nexora' ), array( 'fields' => $checked['errors'] ) );
			}

			$d = $checked['data'];

			return array(
				'source'  => self::vendor_source( 'a-' . substr( md5( (int) $user_id . '|' . $d['url'] ), 0, 8 ), __( 'My own API', 'nexora' ), $d['url'], $d['auth_type'], $d['auth_header'], $d['secret'] ),
				'conn_id' => 0,
			);
		}

		return new \WP_Error( 'nexora_source', __( 'Choose a source.', 'nexora' ) );
	}

	/**
	 * Validates the fields of a connection (or of a typed "My own API").
	 *
	 * @param array      $raw       Posted values.
	 * @param array|null $existing  The connection being edited (a blank key keeps its key).
	 * @param bool       $with_name Whether a name is required.
	 * @return array data (clean fields; secret PLAIN or '' to keep) and errors (field => message)
	 */
	private static function check( array $raw, $existing, $with_name = true ) {

		$errors = array();
		$name   = isset( $raw['name'] ) && is_string( $raw['name'] ) ? mb_substr( sanitize_text_field( $raw['name'] ), 0, 61 ) : '';
		$auth   = isset( $raw['auth_type'] ) && is_string( $raw['auth_type'] ) ? $raw['auth_type'] : 'none';
		$header = isset( $raw['auth_header'] ) && is_string( $raw['auth_header'] ) && '' !== trim( $raw['auth_header'] ) ? trim( $raw['auth_header'] ) : 'X-API-Key';
		$typed  = isset( $raw['secret'] ) && is_string( $raw['secret'] ) ? $raw['secret'] : '';
		$url    = '';

		if ( $with_name && ( '' === $name || mb_strlen( $name ) > 60 ) ) {
			$errors['name'] = __( 'Give the connection a name (up to 60 characters).', 'nexora' );
		}

		if ( ! in_array( $auth, Sources::AUTHS, true ) ) {
			$errors['auth_type'] = __( 'Choose how your API expects the key.', 'nexora' );
			$auth                = 'none';
		}

		$guard = Url_Guard::check( isset( $raw['url'] ) ? $raw['url'] : '', array( 'host_allowlist' => Sources::host_allowlist() ) );

		if ( is_wp_error( $guard ) ) {
			$errors['url'] = Url_Guard::MESSAGE;
		} else {
			$url = $guard['url'];
		}

		if ( 'header' === $auth && ! Sources::header_name_valid( $header ) ) {
			$errors['auth_header'] = __( 'Use a simple header name such as X-API-Key.', 'nexora' );
		}

		$secret = '';

		if ( 'none' !== $auth ) {

			$keep = $existing && '' !== $existing['secret'];

			if ( '' === $typed || preg_match( '/^[•*]+$/u', $typed ) ) {

				if ( ! $keep ) {
					$errors['secret'] = __( 'Enter the key or token your API expects.', 'nexora' );
				}
			} elseif ( ! Sources::key_valid( $typed ) ) {
				$errors['secret'] = __( 'The key must be 1-512 visible characters without spaces.', 'nexora' );
			} else {
				$secret = $typed;
			}
		}

		return array(
			'data'   => array(
				'name'         => mb_substr( $name, 0, 60 ),
				'url'          => $url,
				'auth_type'    => $auth,
				'auth_header'  => 'header' === $auth ? $header : '',
				'secret'       => $secret,
				'clear_secret' => 'none' === $auth,
			),
			'errors' => $errors,
		);
	}

	/**
	 * A vendor-owned source, ready to fetch.
	 *
	 * @param string $key    Source key (prefixes the external ids).
	 * @param string $name   Label.
	 * @param string $url    Address.
	 * @param string $auth   Auth type.
	 * @param string $header Header name.
	 * @param string $secret The key in plain text (memory only).
	 * @return array
	 */
	private static function vendor_source( $key, $name, $url, $auth, $header, $secret ) {

		return array(
			'key'            => $key,
			'name'           => $name,
			'url'            => $url,
			'format'         => 'nexora',
			'auth_type'      => $auth,
			'auth_header'    => $header,
			'secret'         => $secret,
			'kind'           => 'vendor',
			'host_allowlist' => Sources::host_allowlist(),
		);
	}

	/**
	 * The refusal for "not allowed / not yours" (it says nothing about other vendors).
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
	}

	/**
	 * The refusal when vendor-owned APIs are switched off.
	 *
	 * @return \WP_Error
	 */
	private static function disabled() {
		return new \WP_Error( 'nexora_disabled', __( 'Importing from your own API is not available.', 'nexora' ) );
	}
}
