<?php

namespace Nexora\Imports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The guard in front of every outbound product-API request (server-side request forgery). A vendor, or an admin
 * typing in a setting, must never be able to make this server call its own network. Rules:
 *  - https only, optional port 443 only, no credentials in the address, plain ASCII characters only;
 *  - no IP literals in any spelling (decimal, hex, octal, IPv6, mapped), only real host names;
 *  - names that mean "here" (localhost, .local, .internal, .lan ...) are refused;
 *  - the name is resolved and EVERY address it resolves to (IPv4 and IPv6) must be public: loopback, private, CGNAT,
 *    link-local, reserved, documentation, multicast, broadcast and cloud-metadata addresses are refused;
 *  - the request is then pinned to a checked address, so a second DNS answer cannot change where it goes;
 *  - an optional host allow-list.
 * Every refusal has the same message: it says nothing about why, so it cannot be used to probe the network.
 */
class Url_Guard {

	const MESSAGE = 'This address cannot be used. Check that it is a public https address.';

	/**
	 * IPv4 ranges that are not for the public internet (CIDR).
	 *
	 * @return string[]
	 */
	private static function blocked_v4() {

		return array(
			'0.0.0.0/8',
			'10.0.0.0/8',
			'100.64.0.0/10',
			'127.0.0.0/8',
			'169.254.0.0/16',
			'172.16.0.0/12',
			'192.0.0.0/24',
			'192.0.2.0/24',
			'192.88.99.0/24',
			'192.168.0.0/16',
			'198.18.0.0/15',
			'198.51.100.0/24',
			'203.0.113.0/24',
			'224.0.0.0/4',
			'240.0.0.0/4',
			'168.63.129.16/32',
		);
	}

	/**
	 * Checks an address.
	 *
	 * @param mixed $url  The address.
	 * @param array $opts host_allowlist (hosts; empty = none), same_host (the host it must equal).
	 * @return array|\WP_Error url (normalised), host, ips (the checked public addresses)
	 */
	public static function check( $url, array $opts = array() ) {

		$parsed = self::syntax( $url, $opts );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$host = $parsed['host'];
		$ips  = self::resolve( $host );

		if ( ! $ips ) {
			return self::refuse();
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return self::refuse();
			}
		}

		$parsed['ips'] = array_values( $ips );

		return $parsed;
	}

	/**
	 * The shape checks only (no DNS): used when an administrator saves a source, where a lookup would be slow and
	 * pointless; check() always runs before any request.
	 *
	 * @param mixed $url  The address.
	 * @param array $opts As for check().
	 * @return array|\WP_Error url, host
	 */
	public static function syntax( $url, array $opts = array() ) {

		if ( ! is_string( $url ) ) {
			return self::refuse();
		}

		$url = trim( $url, " \t\n\r" );

		if ( '' === $url || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return self::refuse();
		}

		$parts = wp_parse_url( $url );

		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return self::refuse();
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return self::refuse();
		}

		$host = strtolower( $parts['host'] );

		if ( ! self::host_ok( $host ) ) {
			return self::refuse();
		}

		if ( ! empty( $opts['same_host'] ) && strtolower( (string) $opts['same_host'] ) !== $host ) {
			return self::refuse();
		}

		if ( ! empty( $opts['host_allowlist'] ) && ! self::on_list( $host, (array) $opts['host_allowlist'] ) ) {
			return self::refuse();
		}

		return array(
			'url'  => 'https://' . $host . ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
			'host' => $host,
		);
	}

	/**
	 * Whether an IP address is for the public internet.
	 *
	 * @param mixed $ip Address text.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {

		if ( ! is_string( $ip ) || '' === $ip ) {
			return false;
		}

		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid text simply means "not an address".

		if ( false === $bin ) {
			return false;
		}

		if ( 4 === strlen( $bin ) ) {
			return self::public_v4( $bin );
		}

		return self::public_v6( $bin );
	}

	/**
	 * The addresses a name resolves to (IPv4 and IPv6). Tests answer through the nexora_resolve_host filter.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	public static function resolve( $host ) {

		/**
		 * Answers a lookup without touching DNS (tests).
		 *
		 * @param string[]|null $ips  Addresses, or null to use DNS.
		 * @param string        $host Host name.
		 */
		$answer = apply_filters( 'nexora_resolve_host', null, $host );

		if ( is_array( $answer ) ) {
			return array_values( array_filter( array_map( 'strval', $answer ) ) );
		}

		$ips = array();
		$v4  = gethostbynamel( $host );

		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}

		$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an empty answer.

		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * The cURL "resolve" entry that pins a request to the first checked public address.
	 *
	 * @param string   $host Host name.
	 * @param string[] $ips  Addresses (checked or not; only public ones are used).
	 * @return string[] Entries for CURLOPT_RESOLVE (empty when none is public).
	 */
	public static function curl_pin( $host, array $ips ) {

		foreach ( $ips as $ip ) {

			if ( self::is_public_ip( $ip ) ) {
				return array( $host . ':443:' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip ) );
			}
		}

		return array();
	}

	/**
	 * The one refusal.
	 *
	 * @return \WP_Error
	 */
	private static function refuse() {
		return new \WP_Error( 'nexora_url', self::MESSAGE );
	}

	/**
	 * A real, plain-ASCII host name: at least two labels, a letters-only ending (never a number), and not a name for
	 * "this machine / this network".
	 *
	 * @param string $host Lower-cased host.
	 * @return bool
	 */
	private static function host_ok( $host ) {

		if ( strlen( $host ) > 253 || false !== strpos( $host, ':' ) || false !== strpos( $host, '[' ) || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return false;
		}

		$labels = explode( '.', $host );

		if ( count( $labels ) < 2 ) {
			return false;
		}

		foreach ( $labels as $label ) {

			if ( ! preg_match( '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label ) ) {
				return false;
			}
		}

		$tld = end( $labels );

		// A number or 0x... last label is an IP address in disguise.
		if ( ! preg_match( '/^[a-z]{2,63}$|^xn--[a-z0-9-]{1,59}$/', $tld ) ) {
			return false;
		}

		/**
		 * Name endings that mean "this machine or network" and are always refused.
		 *
		 * @param string[] $suffixes Endings.
		 */
		$suffixes = (array) apply_filters( 'nexora_blocked_host_suffixes', array( 'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'home.arpa', 'test', 'invalid', 'onion' ) );

		foreach ( $suffixes as $suffix ) {

			if ( $host === $suffix || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a host is, or is under, one of the listed hosts.
	 *
	 * @param string   $host  Host.
	 * @param string[] $hosts Allowed hosts.
	 * @return bool
	 */
	public static function on_list( $host, array $hosts ) {

		foreach ( $hosts as $allowed ) {

			$allowed = strtolower( trim( (string) $allowed ) );

			if ( '' !== $allowed && ( $host === $allowed || substr( $host, -strlen( $allowed ) - 1 ) === '.' . $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a packed IPv4 address is public.
	 *
	 * @param string $bin 4 bytes.
	 * @return bool
	 */
	private static function public_v4( $bin ) {

		$long = (int) sprintf( '%u', ip2long( inet_ntop( $bin ) ) );

		foreach ( self::blocked_v4() as $cidr ) {

			list( $net, $bits ) = explode( '/', $cidr );

			$mask = 0 === (int) $bits ? 0 : ( ~( ( 1 << ( 32 - (int) $bits ) ) - 1 ) & 0xFFFFFFFF );

			if ( ( $long & $mask ) === ( (int) sprintf( '%u', ip2long( $net ) ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a packed IPv6 address is public. Addresses that embed an IPv4 address are judged by that address.
	 *
	 * @param string $bin 16 bytes.
	 * @return bool
	 */
	private static function public_v6( $bin ) {

		$hex = bin2hex( $bin );

		// ::/96 (unspecified, loopback, IPv4-compatible) and ::ffff:0:0/96 (IPv4-mapped): judged by the IPv4 part.
		if ( 0 === strpos( $hex, str_repeat( '0', 24 ) ) || 0 === strpos( $hex, str_repeat( '0', 20 ) . 'ffff' ) ) {
			return self::public_v4( substr( $bin, 12, 4 ) );
		}

		// NAT64 64:ff9b::/96: judged by the IPv4 part.
		if ( 0 === strpos( $hex, '0064ff9b' . str_repeat( '0', 16 ) ) ) {
			return self::public_v4( substr( $bin, 12, 4 ) );
		}

		// Only global unicast 2000::/3 is public, minus the special ranges inside it.
		if ( ( ord( $bin[0] ) & 0xE0 ) !== 0x20 ) {
			return false;
		}

		foreach ( array( '2001', '2002', '3ffe' ) as $special ) {

			if ( 0 === strpos( $hex, $special ) && ( '2001' !== $special || 0 === strpos( $hex, '20010000' ) || 0 === strpos( $hex, '20010db8' ) || 0 === strpos( $hex, '20010002' ) || 0 === strpos( $hex, '2001001' ) ) ) {
				return false;
			}
		}

		return true;
	}
}
