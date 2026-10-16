<?php
// Marketplace Phase 5b: "Product API sources" in Nexora System > Settings: the platform sources, the master switch for
// vendor-owned APIs and the host allow-list. Keys are encrypted and never shown back.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Secrets;
use Nexora\Imports\Sources;

global $wpdb;
nx_test_options( array( 'nexora_api_sources' => null, 'nexora_vendor_apis_enabled' => null, 'nexora_api_host_allowlist' => null ) );

/* ---------- seeded with DummyJSON, vendor APIs off ---------- */
$all = Sources::all();
nx_assert_same( 2, count( $all ), 'out of the box there are two sources: DummyJSON (enabled) and Fake Store (disabled)' );
nx_assert( 'dummyjson' === $all[0]['id'] && 'DummyJSON' === $all[0]['name'] && 'https://dummyjson.com/products' === $all[0]['url'] && 'dummyjson' === $all[0]['format'] && 'none' === $all[0]['auth_type'] && true === $all[0]['enabled'], 'it is DummyJSON: https://dummyjson.com/products, the DummyJSON adapter, no auth, enabled' );
nx_assert_same( false, Sources::vendor_apis_enabled(), 'vendors may NOT use their own APIs until an administrator switches it on' );
nx_assert_same( array(), Sources::host_allowlist(), 'there is no host allow-list by default' );
nx_assert_same( array( 'dummyjson' ), array_column( Sources::enabled(), 'id' ), 'enabled() lists the enabled platform sources' );
nx_assert( null !== Sources::get( 'dummyjson' ) && null === Sources::get( 'nope' ) && null === Sources::get( array( 'x' ) ), 'get() finds a source by id and nothing else' );
nx_assert( false === strpos( file_get_contents( NEXORA_PATH . 'src/Imports/Api_Source.php' ), 'dummyjson.com' ), 'the DummyJSON address is no longer hard-coded in Api_Source (it lives in the settings)' );

/* ---------- sanitising what the settings form posts ---------- */
$row = function ( array $over = array() ) {
	return array_merge( array( 'id' => '', 'name' => 'Acme Catalog', 'url' => 'https://api.acme.example/v1/products', 'format' => 'nexora', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'clear_secret' => '', 'enabled' => '1', 'remove' => '' ), $over );
};
$clean = Sources::sanitize( array( $row() ) );
nx_assert_same( 1, count( $clean ), 'a valid row is kept' );
nx_assert( 'acme-catalog' === $clean[0]['id'] && 'Acme Catalog' === $clean[0]['name'] && 'nexora' === $clean[0]['format'] && true === $clean[0]['enabled'] && '' === $clean[0]['secret'], 'it gets an id from its name, and a clean shape' );

foreach ( array( 'http://api.acme.example/p', 'ftp://api.acme.example/p', 'https://localhost/p', 'https://127.0.0.1/p', 'https://user:pw@api.acme.example/p', 'https://api.acme.example:8443/p', 'not a url', '', 'javascript:alert(1)' ) as $url ) {
	nx_assert_same( 0, count( Sources::sanitize( array( $row( array( 'url' => $url ) ) ) ) ), 'a row with this address is dropped: ' . $url );
}
nx_assert_same( 0, count( Sources::sanitize( array( $row( array( 'name' => '' ) ) ) ) ), 'a row without a name is dropped' );
nx_assert_same( 0, count( Sources::sanitize( array( $row( array( 'remove' => '1' ) ) ) ) ), 'a row ticked "remove" is dropped' );
nx_assert_same( 'nexora', Sources::sanitize( array( $row( array( 'format' => 'xml' ) ) ) )[0]['format'], 'an unknown format falls back to the Nexora format' );
nx_assert_same( 'dummyjson', Sources::sanitize( array( $row( array( 'format' => 'dummyjson' ) ) ) )[0]['format'], 'the DummyJSON adapter can be chosen' );
nx_assert_same( 'none', Sources::sanitize( array( $row( array( 'auth_type' => 'oauth' ) ) ) )[0]['auth_type'], 'an unknown auth type means none' );
nx_assert_same( false, Sources::sanitize( array( $row( array( 'enabled' => '' ) ) ) )[0]['enabled'], 'an unticked "enabled" is disabled' );
nx_assert( 'Bad name &lt;b&gt;' !== Sources::sanitize( array( $row( array( 'name' => '<b>Bad</b> name' ) ) ) )[0]['name'] && false === strpos( Sources::sanitize( array( $row( array( 'name' => '<b>Bad</b> name' ) ) ) )[0]['name'], '<' ), 'markup is stripped from the name' );
nx_assert_same( 60, mb_strlen( Sources::sanitize( array( $row( array( 'name' => str_repeat( 'n', 200 ) ) ) ) )[0]['name'] ), 'a long name is cut to 60 characters' );
nx_assert_same( array( 'acme', 'acme-2' ), array_column( Sources::sanitize( array( $row( array( 'name' => 'Acme' ) ), $row( array( 'name' => 'Acme', 'url' => 'https://b.acme.example/p' ) ) ) ), 'id' ), 'two sources with the same name get different ids' );
nx_assert( count( Sources::sanitize( array_fill( 0, 40, $row() ) ) ) <= 20, 'at most 20 sources are kept' );
nx_assert_same( array(), Sources::sanitize( 'nonsense' ), 'a non-array post gives no sources' );
nx_assert_same( array(), Sources::sanitize( array( 'x', 5, null, array( 'name' => array( 'a' ) ) ) ), 'junk rows are dropped without notices' );

/* ---------- auth and the key ---------- */
$keyed = Sources::sanitize( array( $row( array( 'id' => '', 'auth_type' => 'header', 'auth_header' => 'X-Api-Key', 'secret' => 'sk_live_TopSecret123' ) ) ) );
nx_assert( Secrets::is_encrypted( $keyed[0]['secret'] ) && false === strpos( wp_json_encode( $keyed ), 'TopSecret123' ) && 'sk_live_TopSecret123' === Secrets::decrypt( $keyed[0]['secret'] ), 'a typed key is stored encrypted, never in clear' );
nx_assert_same( 'X-Api-Key', $keyed[0]['auth_header'], 'the header name is kept' );
$acme_id = $keyed[0]['id'];
update_option( 'nexora_api_sources', $keyed );
$kept = Sources::sanitize( array( $row( array( 'id' => $acme_id, 'auth_type' => 'header', 'auth_header' => 'X-Api-Key', 'secret' => '' ) ) ) );
nx_assert_same( $keyed[0]['secret'], $kept[0]['secret'], 'a blank key field keeps the saved key' );
$masked = Sources::sanitize( array( $row( array( 'id' => $acme_id, 'auth_type' => 'header', 'auth_header' => 'X-Api-Key', 'secret' => '••••••••••••' ) ) ) );
nx_assert_same( $keyed[0]['secret'], $masked[0]['secret'], 'the dots placeholder posted back does not replace the key' );
$new = Sources::sanitize( array( $row( array( 'id' => $acme_id, 'auth_type' => 'header', 'auth_header' => 'X-Api-Key', 'secret' => 'sk_new_value' ) ) ) );
nx_assert( 'sk_new_value' === Secrets::decrypt( $new[0]['secret'] ), 'a newly typed key replaces it' );
$cleared = Sources::sanitize( array( $row( array( 'id' => $acme_id, 'auth_type' => 'header', 'auth_header' => 'X-Api-Key', 'clear_secret' => '1' ) ) ) );
nx_assert_same( '', $cleared[0]['secret'], 'the "remove key" tickbox removes it' );
$none = Sources::sanitize( array( $row( array( 'id' => $acme_id, 'auth_type' => 'none' ) ) ) );
nx_assert_same( '', $none[0]['secret'], 'switching auth to none drops the key' );
$bearer = Sources::sanitize( array( $row( array( 'auth_type' => 'bearer', 'secret' => 'tok_abc' ) ) ) );
nx_assert( 'bearer' === $bearer[0]['auth_type'] && 'tok_abc' === Secrets::decrypt( $bearer[0]['secret'] ), 'a bearer token is stored encrypted too' );
foreach ( array( "bad\r\nvalue", str_repeat( 'k', 600 ), "tab\tvalue", 'ünïcode' ) as $badkey ) {
	$r = Sources::sanitize( array( $row( array( 'auth_type' => 'bearer', 'secret' => $badkey ) ) ) );
	nx_assert( ! isset( $r[0] ) || '' === $r[0]['secret'], 'a key that is not plain visible ASCII (or too long) is refused: ' . substr( wp_json_encode( $badkey ), 0, 24 ) );
}
foreach ( array( 'Host', 'Content-Length', 'Cookie', 'Transfer-Encoding', 'Connection', 'Upgrade', 'Authorization-X Y', 'X Bad', "X-A\r\nB", '', '1abc' ) as $name ) {
	$r = Sources::sanitize( array( $row( array( 'auth_type' => 'header', 'auth_header' => $name, 'secret' => 'abc' ) ) ) );
	nx_assert( ! in_array( $r[0]['auth_header'] ?? 'x', array( 'Host', 'Content-Length', 'Cookie', 'Transfer-Encoding', 'Connection', 'Upgrade', 'Authorization-X Y', 'X Bad', "X-A\r\nB", '1abc' ), true ), 'a forbidden or malformed header name is not kept: ' . wp_json_encode( $name ) );
}
nx_assert_same( 'X-API-Key', Sources::sanitize( array( $row( array( 'auth_type' => 'header', 'auth_header' => '', 'secret' => 'abc' ) ) ) )[0]['auth_header'], 'no header name means X-API-Key' );

/* ---------- master switch and allow-list ---------- */
nx_assert_same( 1, Sources::sanitize_switch( 'on' ), 'the master switch saves as 1' );
nx_assert_same( 0, Sources::sanitize_switch( '' ) + Sources::sanitize_switch( null ), 'unticked is 0' );
update_option( 'nexora_vendor_apis_enabled', 1 );
nx_assert_same( true, Sources::vendor_apis_enabled(), 'and reads back as on' );
nx_assert_same( "example.org\napi.acme.example", Sources::sanitize_hosts( "Example.ORG\n api.acme.example \n\nexample.org\n*.bad\nhttp://x.example\n10.0.0.1\nlocalhost\n" ), 'the allow-list keeps plain host names, lower-cased, once each; addresses, URLs, wildcards and localhost are dropped' );
update_option( 'nexora_api_host_allowlist', "example.org\napi.acme.example" );
nx_assert_same( array( 'example.org', 'api.acme.example' ), Sources::host_allowlist(), 'the allow-list reads back as a list' );

/* ---------- the settings screen: the key is never shown back ---------- */
update_option( 'nexora_api_sources', array_merge( Sources::defaults(), array( $keyed[0] ) ) );
wp_set_current_user( nx_test_admin() );
set_current_screen( 'dashboard' );
ob_start();
( new \Nexora\Admin\Settings() )->settings_page();
$html = ob_get_clean();
wp_set_current_user( 0 );
nx_assert( false !== strpos( $html, 'Product API sources' ), 'the Settings screen has a "Product API sources" section' );
nx_assert( false !== strpos( $html, 'DummyJSON' ) && false !== strpos( $html, 'https://dummyjson.com/products' ), 'it lists DummyJSON with its address' );
nx_assert( false !== strpos( $html, 'Acme Catalog' ), 'and the other source' );
nx_assert( false === strpos( $html, 'TopSecret123' ) && false === strpos( $html, 'sk_live' ) && false === strpos( $html, Secrets::PREFIX ) && false === strpos( $html, $keyed[0]['secret'] ), 'the key (and its ciphertext) is nowhere in the page' );
nx_assert( 1 === preg_match( '/<input[^>]*type="password"[^>]*name="nexora_api_sources\[\d+\]\[secret\]"[^>]*value=""|<input[^>]*name="nexora_api_sources\[\d+\]\[secret\]"[^>]*type="password"[^>]*value=""/', $html ), 'the key field is an empty password field' );
nx_assert( false !== strpos( $html, 'name="nexora_vendor_apis_enabled"' ) && false !== strpos( $html, 'name="nexora_api_host_allowlist"' ), 'the master switch and the allow-list are on the screen' );
nx_assert( 1 === preg_match( '/name="nexora_api_sources\[\d+\]\[format\]"/', $html ) && false !== strpos( $html, 'Nexora format' ) && false !== strpos( $html, 'DummyJSON adapter' ), 'each source has a format: "Nexora format" or the DummyJSON adapter' );
nx_assert( 1 === preg_match( '/name="nexora_api_sources\[\d+\]\[auth_type\]"/', $html ) && false !== strpos( $html, 'Header key' ) && false !== strpos( $html, 'Bearer' ), 'and an auth choice (none, header key, bearer)' );
nx_assert( preg_match_all( '/name="nexora_api_sources\[\d+\]\[name\]"/', $html ) >= count( Sources::all() ) + 2, 'there are blank rows to add sources' );

nx_test_finish();
