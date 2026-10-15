<?php
// Marketplace Phase 5b: the outbound-request guard for product APIs (SSRF). https only; no loopback, private, link-local,
// reserved, multicast or cloud-metadata address, IPv4 or IPv6, whatever form it is written in; every resolved address is
// checked; no IP literals; no credentials, odd ports or odd characters; optional host allow-list.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Url_Guard;

$dns = array(
	'api.example.com'      => array( '93.184.216.34' ),
	'v6public.example.com' => array( '2606:2800:220:1:248:1893:25c8:1946' ),
	'internal.example.com' => array( '10.0.0.5' ),
	'meta.example.com'     => array( '169.254.169.254' ),
	'loop.example.com'     => array( '127.0.0.1' ),
	'v6loop.example.com'   => array( '::1' ),
	'v6ula.example.com'    => array( 'fd00:ec2::254' ),
	'v6ll.example.com'     => array( 'fe80::1' ),
	'mapped.example.com'   => array( '::ffff:10.0.0.1' ),
	'mapped2.example.com'  => array( '::ffff:7f00:1' ),
	'nat64.example.com'    => array( '64:ff9b::a00:1' ),
	'sixtofour.example.com' => array( '2002:7f00:1::' ),
	'mixed.example.com'    => array( '93.184.216.34', '10.0.0.1' ),
	'mixed6.example.com'   => array( '93.184.216.34', 'fe80::1' ),
	'cgnat.example.com'    => array( '100.64.0.9' ),
	'alibaba.example.com'  => array( '100.100.100.200' ),
	'azure.example.com'    => array( '168.63.129.16' ),
	'zero.example.com'     => array( '0.0.0.0' ),
	'bcast.example.com'    => array( '255.255.255.255' ),
	'multi.example.com'    => array( '224.0.0.1' ),
	'doc.example.com'      => array( '192.0.2.10' ),
	'bench.example.com'    => array( '198.18.0.1' ),
	'nxdomain.example.com' => array(),
);
add_filter( 'nexora_resolve_host', function ( $null, $host ) use ( $dns ) { return $dns[ $host ] ?? array( '93.184.216.34' ); }, 10, 2 );

$ok = function ( $url, array $opts = array() ) { $r = Url_Guard::check( $url, $opts ); return is_array( $r ) ? $r : null; };
$bad = function ( $url, array $opts = array() ) { $r = Url_Guard::check( $url, $opts ); return is_wp_error( $r ) && Url_Guard::MESSAGE === $r->get_error_message(); };

/* ---------- allowed ---------- */
$r = $ok( 'https://api.example.com/products?limit=100' );
nx_assert( null !== $r && 'https://api.example.com/products?limit=100' === $r['url'] && 'api.example.com' === $r['host'] && array( '93.184.216.34' ) === $r['ips'], 'a public https address passes and returns the host and the checked addresses' );
nx_assert( null !== $ok( 'HTTPS://API.Example.COM/x' ) && 'https://api.example.com/x' === $ok( 'HTTPS://API.Example.COM/x' )['url'], 'scheme and host are lower-cased' );
nx_assert( null !== $ok( 'https://api.example.com:443/x' ), 'an explicit port 443 is fine' );
nx_assert( null !== $ok( 'https://v6public.example.com/x' ), 'a host that resolves to a public IPv6 address passes' );
nx_assert( null !== $ok( 'https://api.example.com/x#frag' ) && false === strpos( $ok( 'https://api.example.com/x#frag' )['url'], '#' ), 'a fragment is dropped' );
nx_assert( null !== $ok( 'https://xn--mnchen-3ya.de/x' ), 'a punycode host is fine' );

/* ---------- the scheme and the shape ---------- */
foreach ( array( 'http://api.example.com/x', 'ftp://api.example.com/x', 'file:///etc/passwd', 'gopher://api.example.com/', 'javascript:alert(1)', 'data:text/plain,x', '//api.example.com/x', 'api.example.com/x', '/x', '', '   ', 'https://', 'https:///x', 'https://user@api.example.com/x', 'https://user:pw@api.example.com/x', 'https://api.example.com:8443/x', 'https://api.example.com:80/x', 'https://api.example.com:0/x', 'https://exa mple.com/x', "https://api.example.com/x\r\nHost: evil.example", "https://api.example.com/\x00", 'https://api.example.com\\@evil.example/', 'https://api.example.com@evil.example/', 'https://münchen.de/x', 'https://' . str_repeat( 'a', 64 ) . '.example.com/x', 'https://api.example.com/' . str_repeat( 'a', 2100 ), 'https://a..example.com/x', 'https://-bad.example.com/x', 'https://example/x' ) as $url ) {
	nx_assert( $bad( $url ), 'refused: ' . substr( wp_json_encode( $url ), 0, 70 ) );
}
nx_assert( $bad( array( 'https://api.example.com' ) ) && $bad( null ) && $bad( 12345 ), 'refused: not a string' );

/* ---------- IP literals in every spelling ---------- */
foreach ( array(
	'127.0.0.1', '10.0.0.1', '172.16.0.5', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.2.1', '198.18.0.1', '1.1.1.1', '93.184.216.34',
	'2130706433', '0x7f000001', '017700000001', '127.1', '0177.0.0.1', '0x7f.0.0.1', '127.0.0.1.', '[::1]', '[fe80::1]', '[fc00::1]', '[fd00:ec2::254]', '[::ffff:127.0.0.1]', '[64:ff9b::7f00:1]', '[2001:db8::1]', '[2002:7f00:1::]', '[::]', '[2606:2800:220:1:248:1893:25c8:1946]', '::1',
) as $host ) {
	nx_assert( $bad( "https://$host/x" ), "refused: an IP literal ($host)" );
}

/* ---------- names that mean "this machine / this network" ---------- */
foreach ( array( 'localhost', 'LOCALHOST', 'foo.localhost', 'printer.local', 'db.internal', 'x.lan', 'x.home.arpa', 'x.intranet', 'x.corp', 'x.private', 'singlelabel', 'x.localdomain', 'kubernetes.default.svc.cluster.local', 'metadata.google.internal' ) as $host ) {
	nx_assert( $bad( "https://$host/x" ), "refused: the name $host" );
}

/* ---------- names that RESOLVE to something private (every address is checked) ---------- */
foreach ( array( 'internal.example.com' => '10.x', 'meta.example.com' => 'metadata 169.254.169.254', 'loop.example.com' => 'loopback', 'v6loop.example.com' => '::1', 'v6ula.example.com' => 'AWS IPv6 metadata', 'v6ll.example.com' => 'IPv6 link-local', 'mapped.example.com' => 'IPv4-mapped private', 'mapped2.example.com' => 'IPv4-mapped loopback (hex)', 'nat64.example.com' => 'NAT64 private', 'sixtofour.example.com' => '6to4 loopback', 'mixed.example.com' => 'one public and one private', 'mixed6.example.com' => 'public and IPv6 link-local', 'cgnat.example.com' => 'carrier-grade NAT', 'alibaba.example.com' => 'Alibaba metadata', 'azure.example.com' => 'Azure wireserver', 'zero.example.com' => '0.0.0.0', 'bcast.example.com' => 'broadcast', 'multi.example.com' => 'multicast', 'doc.example.com' => 'documentation range', 'bench.example.com' => 'benchmarking range', 'nxdomain.example.com' => 'does not resolve' ) as $host => $why ) {
	nx_assert( $bad( "https://$host/x" ), "refused: $host resolves to $why" );
}

/* ---------- the address test itself ---------- */
foreach ( array( '93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:2800:220:1:248:1893:25c8:1946', '2a00:1450:4001:81b::200e', '::ffff:93.184.216.34' ) as $ip ) {
	nx_assert( Url_Guard::is_public_ip( $ip ), "public: $ip" );
}
foreach ( array( '127.0.0.1', '127.255.255.254', '10.1.2.3', '172.16.0.1', '172.31.255.254', '192.168.0.1', '169.254.169.254', '169.254.170.2', '100.64.0.0', '100.127.255.255', '100.100.100.200', '168.63.129.16', '192.0.0.192', '0.0.0.0', '0.1.2.3', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255', '192.0.2.1', '198.51.100.1', '203.0.113.1', '198.18.0.1', '::', '::1', 'fe80::1', 'febf::1', 'fc00::1', 'fd12:3456::1', 'fd00:ec2::254', 'ff02::1', '2001:db8::1', '2001::1', '2002:0a00:1::', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:a9fe:a9fe', '64:ff9b::a00:1', 'not an ip', '', '1.2.3', '256.1.1.1' ) as $ip ) {
	nx_assert( ! Url_Guard::is_public_ip( $ip ), "not public: " . wp_json_encode( $ip ) );
}
nx_assert( ! Url_Guard::is_public_ip( array( '8.8.8.8' ) ) && ! Url_Guard::is_public_ip( null ), 'not public: non-strings' );

/* ---------- allow-list ---------- */
$allow = array( 'host_allowlist' => array( 'example.org' ) );
nx_assert( null !== $ok( 'https://example.org/x', $allow ) && null !== $ok( 'https://api.example.org/x', $allow ), 'allow-list: the host and its subdomains pass' );
foreach ( array( 'https://evil-example.org/x', 'https://example.org.evil.com/x', 'https://notexample.org/x', 'https://api.example.com/x' ) as $url ) {
	nx_assert( $bad( $url, $allow ), "allow-list: refused $url" );
}
nx_assert( null !== $ok( 'https://api.example.com/x', array( 'host_allowlist' => array() ) ), 'an empty allow-list restricts nothing' );
nx_assert( null !== $ok( 'https://api.example.org/x', array( 'same_host' => 'api.example.org' ) ) && $bad( 'https://other.example.org/x', array( 'same_host' => 'api.example.org' ) ) && $bad( 'https://api.example.org.evil.com/x', array( 'same_host' => 'api.example.org' ) ), 'same_host: only exactly that host' );

/* ---------- errors say nothing about why ---------- */
foreach ( array( 'https://localhost/x', 'https://internal.example.com/x', 'https://nxdomain.example.com/x', 'http://api.example.com/x', 'https://10.0.0.1/x' ) as $url ) {
	$e = Url_Guard::check( $url );
	nx_assert( is_wp_error( $e ) && Url_Guard::MESSAGE === $e->get_error_message() && 'nexora_url' === $e->get_error_code() && null === $e->get_error_data(), 'one generic message for every refusal: ' . $url );
}
nx_assert( false === stripos( Url_Guard::MESSAGE, 'private' ) && false === stripos( Url_Guard::MESSAGE, 'resolve' ) && false === stripos( Url_Guard::MESSAGE, 'loopback' ), 'the message does not hint at the reason (no network probing)' );

/* ---------- DNS pinning for the real request ---------- */
nx_assert_same( array( 'api.example.com:443:93.184.216.34' ), Url_Guard::curl_pin( 'api.example.com', array( '93.184.216.34', '10.0.0.1' ) ), 'the request is pinned to a checked public address (no second DNS lookup can change it)' );
nx_assert_same( array( 'v6.example.com:443:[2606:2800:220:1:248:1893:25c8:1946]' ), Url_Guard::curl_pin( 'v6.example.com', array( '2606:2800:220:1:248:1893:25c8:1946' ) ), 'IPv6 pins are bracketed' );
nx_assert_same( array(), Url_Guard::curl_pin( 'x.example.com', array( '10.0.0.1' ) ), 'nothing is pinned when there is no public address' );

/* ---------- a real lookup for a real public name (skipped if offline) ---------- */
remove_all_filters( 'nexora_resolve_host' );
$real = Url_Guard::resolve( 'dummyjson.com' );
if ( $real ) {
	nx_assert( null !== $ok( 'https://dummyjson.com/products' ), 'a real public host (dummyjson.com) passes with real DNS' );
} else {
	echo "  (no DNS available: real lookup skipped)\n";
}
nx_assert( $bad( 'https://localhost/x' ) && $bad( 'https://127.0.0.1/x' ), 'and localhost / 127.0.0.1 are refused with real DNS too' );

nx_test_finish();
