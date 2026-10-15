<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets (API keys) before they are stored. XSalsa20-Poly1305 (libsodium's secretbox) with a key derived from
 * the site's own salts, so a copy of the database alone cannot read them. Nothing here ever returns a stored key to a
 * browser: show mask() instead.
 */
class Secrets {

	const PREFIX = 'v1:';

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain The secret.
	 * @return string "v1:" + base64(nonce + ciphertext), or '' for an empty secret.
	 */
	public static function encrypt( $plain ) {

		if ( ! is_string( $plain ) || '' === $plain ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext, not obfuscation.
	}

	/**
	 * Decrypts a stored secret; '' for anything that is not a value this site encrypted.
	 *
	 * @param mixed $stored Value from the database.
	 * @return string
	 */
	public static function decrypt( $stored ) {

		if ( ! self::is_encrypted( $stored ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key()
		);

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a value looks like one of ours.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX ) && strlen( $value ) > strlen( self::PREFIX );
	}

	/**
	 * What to show instead of a saved secret: dots, never any part of it.
	 *
	 * @param mixed $stored Value from the database.
	 * @return string
	 */
	public static function mask( $stored ) {
		return self::is_encrypted( $stored ) ? '••••••••••••' : '';
	}

	/**
	 * The 32-byte key, derived from the site salts.
	 *
	 * @return string
	 */
	private static function key() {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . '|' . wp_salt( 'auth' ) . '|nexora-secrets-v1', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
