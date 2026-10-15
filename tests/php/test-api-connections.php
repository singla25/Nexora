<?php
// Marketplace Phase 5b: a vendor's own API ("My own API"): up to 5 saved connections, test / preview / import, keys,
// ownership, the master switch, rate limits, and the administrator's read-only list.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Secrets;
use Nexora\Imports\Connection_Repository as Conns;
use Nexora\Imports\Sources;
use Nexora\Products\Repository as Products;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();
$saved = array( get_option( 'nexora_api_sources', '__unset__' ), get_option( 'nexora_vendor_apis_enabled', '__unset__' ), get_option( 'nexora_api_host_allowlist', '__unset__' ) );
delete_option( 'nexora_api_sources' );
update_option( 'nexora_vendor_apis_enabled', 1 );
delete_option( 'nexora_api_host_allowlist' );

$dns = array( 'api.acme.example' => array( '93.184.216.34' ), 'api.b.example' => array( '93.184.216.40' ), 'other.example' => array( '93.184.216.36' ), 'private.acme.example' => array( '10.0.0.7' ) );
add_filter( 'nexora_resolve_host', function ( $null, $host ) use ( $dns ) { return $dns[ $host ] ?? array( '93.184.216.34' ); }, 10, 2 );

$A = nx_test_vendor( 'aca', 'approved' );
$B = nx_test_vendor( 'acb', 'approved' );
$P = nx_test_vendor( 'acp', 'pending' );
$S = nx_test_vendor( 'acs', 'suspended' );
$C = nx_test_user( 'acc' );
$admin = nx_test_admin();
$KEY = 'sk_vendor_key_999';
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$logs  = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_import_log'" ); };
$conns = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_api_conn'" ); };
$track = function ( $v ) { foreach ( Products::ids_for_vendor( $v['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } foreach ( Conns::for_vendor( $v['vendor_id'] ) as $c ) { $GLOBALS['nx_test']['posts'][] = $c['id']; } };
$flush = function () use ( $wpdb ) { $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nexora\\_api\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nexora\\_api\\_%'" ); wp_cache_flush(); };

$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$forbidden = function ( $r ) { return nx_rejected( $r ) && is_array( $r['data'] ?? null ) && 'forbidden' === ( $r['data']['code'] ?? '' ); };
$item = function ( $id, array $o = array() ) { return array_merge( array( 'id' => $id, 'name' => "Own $id", 'price' => 50, 'stock' => 3, 'category' => 'other' ), $o ); };
$mock = function ( array $routes ) {
	$GLOBALS['nx_http'] = array();
	add_filter( 'pre_http_request', $f = function ( $pre, $args, $url ) use ( $routes ) {
		$GLOBALS['nx_http'][] = array( 'url' => $url, 'args' => $args );
		$r = $routes[ $url ] ?? array( 404, '{}' );
		if ( is_wp_error( $r ) ) { return $r; }
		return array( 'headers' => array(), 'body' => $r[1], 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}, 10, 3 );
	return $f;
};
$unmock = function ( $f ) { remove_filter( 'pre_http_request', $f, 10 ); };
$body   = function ( array $items, $next = null ) { return wp_json_encode( array_filter( array( 'products' => $items, 'next' => $next ), function ( $v ) { return null !== $v; } ) ); };
$URL = 'https://api.acme.example/v1/products';
$good = array( 'name' => 'Acme feed', 'url' => $URL, 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => $KEY );

nx_assert_same( 5, Conns::MAX, 'a vendor may save 5 connections' );
$pt = get_post_type_object( 'nexora_api_conn' );
nx_assert( (bool) $pt && false === $pt->public && true === $pt->exclude_from_search && false === $pt->publicly_queryable && 'nexora-system' === $pt->show_in_menu, 'nexora_api_conn is a private post type under Nexora System' );

/* ---------- who may use the connection actions ---------- */
foreach ( array( 'nexora_api_connection_save', 'nexora_api_connection_delete', 'nexora_api_test' ) as $action ) {
	nx_assert( nx_rejected( $call( 0, $action, $good ) ), "$action: a guest is rejected" );
	nx_assert( nx_rejected( $call( $A['user_id'], $action, $good, false ) ), "$action: no nonce is rejected" );
	foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor' => $S['user_id'] ) as $label => $uid ) {
		nx_assert( $forbidden( $call( $uid, $action, $good ) ), "$action: $label is forbidden" );
	}
	nx_assert( nx_rejected( $call( $admin, $action, $good ) ), "$action: an administrator (not a seller) is rejected" );
}

/* ---------- the master switch ---------- */
update_option( 'nexora_vendor_apis_enabled', 0 );
$r = $call( $A['user_id'], 'nexora_api_connection_save', $good );
nx_assert( nx_rejected( $r ) && 0 === $conns(), 'switch off: a vendor cannot save a connection' );
$f = $mock( array( 'https://dummyjson.com/products?limit=100&skip=0' => array( 200, wp_json_encode( array( 'products' => array(), 'total' => 0 ) ) ) ) );
$flush();
$r = $call( $A['user_id'], 'nexora_api_test', array( 'source' => 'adhoc' ) + $good );
nx_assert( nx_rejected( $r ) && array() === $GLOBALS['nx_http'], 'switch off: "My own API" is refused and no request is made' );
$r = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:dummyjson' ) );
nx_assert( ! empty( $r['success'] ), 'switch off: platform sources still work' );
$unmock( $f );
update_option( 'nexora_vendor_apis_enabled', 1 );

/* ---------- saving ---------- */
$r  = $call( $A['user_id'], 'nexora_api_connection_save', $good + array( 'vendor_id' => $B['vendor_id'], 'user_id' => $B['user_id'] ) );
nx_assert( ! empty( $r['success'] ) && ! empty( $r['data']['id'] ), 'a vendor can save a connection' );
$cid  = (int) ( $r['data']['id'] ?? 0 );
$list = Conns::for_vendor( $A['vendor_id'] );
nx_assert( 1 === count( $list ) && $cid === $list[0]['id'] && 'Acme feed' === $list[0]['name'] && $URL === $list[0]['url'] && 'header' === $list[0]['auth_type'] && 'X-Shop-Key' === $list[0]['auth_header'] && true === $list[0]['has_secret'], 'it is stored with name, address, auth and a "has key" flag' );
nx_assert( array() === Conns::for_vendor( $B['vendor_id'] ), 'a posted vendor_id / user_id does not put it under another vendor' );
nx_assert_same( $A['user_id'], (int) get_post_field( 'post_author', $cid ), 'the author is the vendor' );
$raw = (string) get_post_meta( $cid, 'secret', true );
nx_assert( Secrets::is_encrypted( $raw ) && $KEY === Secrets::decrypt( $raw ) && false === strpos( $raw, $KEY ), 'the key is stored encrypted' );
nx_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( $KEY ) . '%' ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s", '%' . $wpdb->esc_like( $KEY ) . '%', '%' . $wpdb->esc_like( $KEY ) . '%' ) ), 'the clear key is nowhere in the database' );
nx_assert( false === strpos( wp_json_encode( $r ), $KEY ) && false === strpos( wp_json_encode( $r ), 'v1:' ), 'the reply carries neither the key nor its ciphertext' );
$GLOBALS['nx_test']['posts'][] = $cid;

foreach ( array(
	'an http address' => array( 'url' => 'http://api.acme.example/p' ), 'localhost' => array( 'url' => 'https://localhost/p' ), 'an IP address' => array( 'url' => 'https://10.0.0.1/p' ),
	'a name that resolves to a private address' => array( 'url' => 'https://private.acme.example/p' ), 'credentials in the address' => array( 'url' => 'https://u:p@api.acme.example/p' ), 'a metadata address' => array( 'url' => 'https://169.254.169.254/latest' ),
) as $label => $over ) {
	$r = $call( $A['user_id'], 'nexora_api_connection_save', array_merge( $good, array( 'name' => 'X ' . $label ), $over ) );
	nx_assert( nx_rejected( $r ) && \Nexora\Imports\Url_Guard::MESSAGE === ( $r['data']['fields']['url'] ?? '' ), "refused ($label) with the one generic message on the address" );
}
foreach ( array( 'no name' => array( 'name' => '' ), 'a long name' => array( 'name' => str_repeat( 'n', 61 ) ), 'a bad header name' => array( 'auth_header' => 'Host' ), 'a key with a line break' => array( 'secret' => "a\r\nb" ), 'a non-ASCII key' => array( 'secret' => 'ünï' ), 'a huge key' => array( 'secret' => str_repeat( 'k', 600 ) ), 'an unknown auth type' => array( 'auth_type' => 'oauth' ), 'a header key without a key' => array( 'secret' => '' ) ) as $label => $over ) {
	$r = $call( $A['user_id'], 'nexora_api_connection_save', array_merge( $good, array( 'name' => 'Y ' . $label ), $over ) );
	nx_assert( nx_rejected( $r ) && ! empty( $r['data']['fields'] ), "refused ($label) with a message on the field" );
}
nx_assert_same( 1, $conns(), 'none of the refused saves stored anything' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'No auth', 'url' => 'https://api.acme.example/open', 'auth_type' => 'none' ) );
nx_assert( ! empty( $r['success'] ), 'a connection with no key is fine' );
$cid2 = (int) $r['data']['id'];
$GLOBALS['nx_test']['posts'][] = $cid2;
nx_assert( false === Conns::for_vendor( $A['vendor_id'] )[0]['has_secret'] || false === Conns::for_vendor( $A['vendor_id'] )[1]['has_secret'], 'and has no stored key' );

/* ---------- the limit of 5 ---------- */
foreach ( array( 3, 4, 5 ) as $n ) {
	$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => "Feed $n", 'url' => "https://api.acme.example/f$n", 'auth_type' => 'none' ) );
	$GLOBALS['nx_test']['posts'][] = (int) ( $r['data']['id'] ?? 0 );
}
nx_assert_same( 5, count( Conns::for_vendor( $A['vendor_id'] ) ), 'five connections are saved' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'Feed 6', 'url' => 'https://api.acme.example/f6', 'auth_type' => 'none' ) );
nx_assert( nx_rejected( $r ) && 5 === count( Conns::for_vendor( $A['vendor_id'] ) ), 'the sixth is refused' );
$last = Conns::for_vendor( $A['vendor_id'] );
$r = $call( $A['user_id'], 'nexora_api_connection_delete', array( 'connection_id' => (string) $last[4]['id'] ) );
nx_assert( ! empty( $r['success'] ) && 4 === count( Conns::for_vendor( $A['vendor_id'] ) ) && null === get_post( $last[4]['id'] ), 'deleting one frees a slot' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'Feed 7', 'url' => 'https://api.acme.example/f7', 'auth_type' => 'none' ) );
$GLOBALS['nx_test']['posts'][] = (int) ( $r['data']['id'] ?? 0 );
nx_assert( ! empty( $r['success'] ), 'and a new one can be saved' );

/* ---------- ownership ---------- */
$before = Conns::find( $cid, $A['vendor_id'] );
$r = $call( $B['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'HIJACK', 'url' => 'https://api.b.example/x', 'auth_type' => 'none', 'vendor_id' => $A['vendor_id'] ) );
nx_assert( $forbidden( $r ), "another vendor cannot edit a connection, even naming its vendor_id" );
nx_assert_same( $before, Conns::find( $cid, $A['vendor_id'] ), 'it is unchanged' );
nx_assert( $forbidden( $call( $B['user_id'], 'nexora_api_connection_delete', array( 'connection_id' => (string) $cid ) ) ) && null !== get_post( $cid ), 'another vendor cannot delete it' );
nx_assert( null === Conns::find( $cid, $B['vendor_id'] ) && array() === Conns::for_vendor( $B['vendor_id'] ), 'another vendor cannot see or find it' );
nx_assert( false === strpos( wp_json_encode( $r ), 'Acme' ) && false === strpos( wp_json_encode( $r ), $A['login'] ), 'and the refusal does not say whose it is' );
foreach ( array( 'a missing id' => '99999999', 'zero' => '0', 'a negative id' => '-3', 'text' => 'abc', 'a vendor application' => (string) $A['vendor_id'], 'a profile' => (string) $C['profile_id'] ) as $label => $bad ) {
	nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_connection_delete', array( 'connection_id' => $bad ) ) ), "deleting $label is refused" );
}
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_connection_delete', array( 'connection_id' => array( 1 ) ) ) ), 'deleting with an array id is refused cleanly' );

/* ---------- editing: a blank key keeps it, a new one replaces it, "remove" clears it ---------- */
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => '' ) );
nx_assert( ! empty( $r['success'] ) && 'Acme feed 2' === Conns::find( $cid, $A['vendor_id'] )['name'] && $KEY === Secrets::decrypt( Conns::find( $cid, $A['vendor_id'] )['secret'] ), 'editing with a blank key keeps the saved key' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => '••••••••••••' ) );
nx_assert( $KEY === Secrets::decrypt( Conns::find( $cid, $A['vendor_id'] )['secret'] ), 'the dots placeholder posted back does not replace the key' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => 'sk_replaced_1' ) );
nx_assert( 'sk_replaced_1' === Secrets::decrypt( Conns::find( $cid, $A['vendor_id'] )['secret'] ), 'a new key replaces it' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => 'https://private.acme.example/x', 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key' ) );
nx_assert( nx_rejected( $r ) && $URL === Conns::find( $cid, $A['vendor_id'] )['url'], 'editing the address to a private one is refused and the old address stays' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'none' ) );
nx_assert( ! empty( $r['success'] ) && false === Conns::for_vendor( $A['vendor_id'] )[0]['has_secret'] && '' === Conns::find( $cid, $A['vendor_id'] )['secret'], 'switching to no auth removes the key' );
update_option( 'nexora_api_host_allowlist', 'acme.example' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'Elsewhere', 'url' => 'https://other.example/p', 'auth_type' => 'none' ) ) ), 'the allow-list applies when saving a connection' );
nx_assert( ! empty( $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'none' ) )['success'] ), 'and an allowed host is fine' );
delete_option( 'nexora_api_host_allowlist' );
$r = $call( $A['user_id'], 'nexora_api_connection_save', array( 'connection_id' => (string) $cid, 'name' => 'Acme feed 2', 'url' => $URL, 'auth_type' => 'header', 'auth_header' => 'X-Shop-Key', 'secret' => $KEY ) );

/* ---------- test / preview / import with a saved connection ---------- */
$items = array( $item( 'T1' ), $item( 'T2' ), $item( 'T3', array( 'price' => 0 ) ), $item( 'T4', array( 'name' => '' ) ), $item( 'T5', array( 'category' => 'cars' ) ), $item( 'T6', array( 'image' => 'https://other.example/i.jpg' ) ), array( 'name' => 'no id', 'price' => 1, 'stock' => 1, 'category' => 'other' ) );
$f = $mock( array( $URL => array( 200, $body( $items, 'https://api.acme.example/v1/products?page=2' ) ), 'https://api.acme.example/v1/products?page=2' => array( 200, $body( array( $item( 'T9' ) ) ) ) ) );
$flush();
$n_products = $count(); $n_logs = $logs();
$r = $call( $A['user_id'], 'nexora_api_test', array( 'source' => 'conn:' . $cid ) );
nx_assert( ! empty( $r['success'] ), 'test: a saved connection can be tested' );
$d = $r['data'] ?? array();
nx_assert( 1 === count( $GLOBALS['nx_http'] ), 'test: reads only the first page' );
nx_assert( 'X-Shop-Key' === array_key_first( array_diff_key( $GLOBALS['nx_http'][0]['args']['headers'], array( 'Accept' => 1 ) ) ) && $KEY === $GLOBALS['nx_http'][0]['args']['headers']['X-Shop-Key'], 'test: the saved key is sent in the saved header' );
nx_assert( 7 === ( $d['checked'] ?? 0 ) && 3 === ( $d['valid'] ?? 0 ), 'test: 7 items checked, 3 valid (T1, T2 and T6, whose image is only ignored)' );
$text = implode( "\n", array_map( function ( $p ) { return $p['message']; }, $d['problems'] ?? array() ) );
nx_assert( false !== strpos( $text, 'T3' ) && false !== stripos( $text, 'price' ), 'test: names the item and the field in plain words (T3 price)' );
nx_assert( false !== strpos( $text, 'T4' ) && false !== stripos( $text, 'name' ), 'test: T4 has no name' );
nx_assert( false !== strpos( $text, 'T5' ) && false !== stripos( $text, 'category' ), 'test: T5 has an unknown category' );
nx_assert( false !== strpos( $text, 'id' ) && false !== stripos( $text, 'no usable' ), 'test: the item without an id is explained' );
nx_assert( false !== stripos( $text, 'T6' ) && false !== stripos( $text, 'image' ), 'test: the ignored image of T6 is mentioned as a note' );
nx_assert( $n_products === $count() && $n_logs === $logs(), 'test: writes no product and no history' );
nx_assert( false === strpos( wp_json_encode( $r ), $KEY ) && false === strpos( wp_json_encode( $r ), 'v1:' ) && false === strpos( wp_json_encode( $r ), '93.184' ), 'test: the reply has no key, ciphertext or IP address' );
nx_assert( count( $d['problems'] ?? array() ) <= 20, 'test: at most 20 problems are listed' );
$unmock( $f );

$f = $mock( array( $URL => array( 200, $body( array( $item( 'V1' ), $item( 'V2' ) ), 'https://api.acme.example/v1/products?page=2' ) ), 'https://api.acme.example/v1/products?page=2' => array( 200, $body( array( $item( 'V3' ) ) ) ) ) );
$flush();
$r = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'conn:' . $cid, 'url' => 'https://evil.example/steal', 'secret' => 'x' ) );
nx_assert( ! empty( $r['success'] ) && 3 === $r['data']['summary']['create'], 'preview: three creates over two pages' );
nx_assert( 0 === strpos( $GLOBALS['nx_http'][0]['url'], 'https://api.acme.example/' ) && false === strpos( wp_json_encode( array_column( $GLOBALS['nx_http'], 'url' ) ), 'evil.example' ), 'preview: a saved connection uses ITS address, whatever url the request carries' );
nx_assert_same( $n_products, $count(), 'preview: nothing is saved' );
$tok = $r['data']['token'];
$r = $call( $B['user_id'], 'nexora_api_preview', array( 'source' => 'conn:' . $cid ) );
nx_assert( $forbidden( $r ) || nx_rejected( $r ), "preview: another vendor cannot use this vendor's connection" );
$r = $call( $A['user_id'], 'nexora_api_import', array( 'token' => $tok ) );
$track( $A );
nx_assert( ! empty( $r['success'] ) && 3 === $r['data']['summary']['created'], 'import: the previewed plan is applied' );
$p = Products::find_by_external( $A['vendor_id'], 'c-' . $cid . ':V1' );
nx_assert( $p > 0 && 'api' === Products::data( $p )['source'] && $A['vendor_id'] === Products::data( $p )['vendor_id'], 'import: products carry source api and an external id that includes the connection (two feeds never collide)' );
$c = Conns::for_vendor( $A['vendor_id'] );
nx_assert( '' !== $c[0]['last_import'] && 'ok' === $c[0]['last_status'], 'import: the connection records its last import' );
$hist = \Nexora\Imports\Log_Repository::for_vendor( $A['vendor_id'], 5 );
nx_assert( 'api' === $hist[0]['source'] && 'Acme feed 2' === $hist[0]['label'] && 3 === $hist[0]['created'], 'import: the history is labelled with the connection name' );
nx_assert( false === strpos( wp_json_encode( $hist ), $KEY ), 'import: the history never holds the key' );
// A second connection with the same item ids does not overwrite the first one's products.
$call( $A['user_id'], 'nexora_api_connection_delete', array( 'connection_id' => (string) $cid2 ) ); // make room (5 is the limit)
$r2 = $call( $A['user_id'], 'nexora_api_connection_save', array( 'name' => 'Second feed', 'url' => 'https://api.acme.example/v2/products', 'auth_type' => 'none' ) );
$cid3 = (int) $r2['data']['id'];
$GLOBALS['nx_test']['posts'][] = $cid3;
$unmock( $f );
$f = $mock( array( 'https://api.acme.example/v2/products' => array( 200, $body( array( $item( 'V1', array( 'name' => 'Different V1', 'sku' => 'V1-B' ) ) ) ) ) ) );
$flush();
$pr = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'conn:' . $cid3 ) );
nx_assert( 1 === ( $pr['data']['summary']['create'] ?? 0 ), 'a second feed with the same item id creates its own product, not an update of the first' );
$unmock( $f );

/* ---------- "My own API" typed in the popup (not saved) ---------- */
$f = $mock( array( 'https://api.b.example/items' => array( 200, $body( array( $item( 'B1' ) ) ) ) ) );
$flush();
$r = $call( $B['user_id'], 'nexora_api_preview', array( 'source' => 'adhoc', 'url' => 'https://api.b.example/items', 'auth_type' => 'bearer', 'secret' => $KEY ) );
nx_assert( ! empty( $r['success'] ) && 1 === $r['data']['summary']['create'], 'adhoc: a typed address and key can be previewed without saving' );
nx_assert( 'Bearer ' . $KEY === ( $GLOBALS['nx_http'][0]['args']['headers']['Authorization'] ?? '' ), 'adhoc: the typed key is sent as a bearer token' );
nx_assert( array() === Conns::for_vendor( $B['vendor_id'] ), 'adhoc: nothing was saved' );
nx_assert( false === strpos( wp_json_encode( $r ), $KEY ), 'adhoc: the reply does not echo the key' );
$r = $call( $B['user_id'], 'nexora_api_import', array( 'token' => $r['data']['token'] ) );
$track( $B );
nx_assert( ! empty( $r['success'] ), 'adhoc: the previewed plan imports (no key needed any more)' );
nx_assert( 1 === count( \Nexora\Imports\Log_Repository::for_vendor( $B['vendor_id'], 5 ) ) && false === strpos( wp_json_encode( \Nexora\Imports\Log_Repository::for_vendor( $B['vendor_id'], 5 ) ), $KEY ), 'adhoc: the history entry exists and holds no key' );
foreach ( array( 'a private address' => 'https://private.acme.example/x', 'localhost' => 'https://localhost/x', 'http' => 'http://api.b.example/items' ) as $label => $url ) {
	$GLOBALS['nx_http'] = array();
	$r = $call( $B['user_id'], 'nexora_api_preview', array( 'source' => 'adhoc', 'url' => $url, 'auth_type' => 'none' ) );
	nx_assert( nx_rejected( $r ) && \Nexora\Imports\Url_Guard::MESSAGE === ( $r['data']['fields']['url'] ?? '' ) && array() === $GLOBALS['nx_http'], "adhoc: $label gives the one generic message on the address and no request is made" );
}
nx_assert( nx_rejected( $call( $B['user_id'], 'nexora_api_preview', array( 'source' => 'adhoc' ) ) ), 'adhoc: no address at all is rejected' );
$unmock( $f );

/* ---------- platform sources: a vendor cannot choose the address ---------- */
$f = $mock( array( 'https://dummyjson.com/products?limit=100&skip=0' => array( 200, wp_json_encode( array( 'products' => array( array( 'id' => 1, 'title' => 'E', 'description' => '', 'category' => 'beauty', 'price' => 1, 'stock' => 1, 'sku' => 'E-1', 'thumbnail' => '' ) ), 'total' => 1 ) ) ) ) );
$flush();
$r = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:dummyjson', 'url' => 'https://evil.example/steal', 'auth_type' => 'bearer', 'secret' => 'abc' ) );
nx_assert( ! empty( $r['success'] ) && 1 === count( $GLOBALS['nx_http'] ) && 0 === strpos( $GLOBALS['nx_http'][0]['url'], 'https://dummyjson.com/' ) && empty( $GLOBALS['nx_http'][0]['args']['headers']['Authorization'] ), 'platform: a posted url / key is ignored, the administrator\'s address is used' );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:nope' ) ) ) && nx_rejected( $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'bogus' ) ) ) && nx_rejected( $call( $A['user_id'], 'nexora_api_preview', array( 'source' => array( 'x' ) ) ) ), 'platform: an unknown source id, junk or an array is rejected' );
$rows = Sources::all(); $rows[0]['enabled'] = false; update_option( 'nexora_api_sources', $rows );
nx_assert( nx_rejected( $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:dummyjson' ) ) ), 'platform: a disabled source cannot be used' );
delete_option( 'nexora_api_sources' );
$r = $call( $A['user_id'], 'nexora_api_preview' );
nx_assert( ! empty( $r['success'] ), 'platform: with no source given, the first enabled platform source is used' );
// A platform source with a key: sent to the source, shown nowhere.
$keyed = Sources::sanitize( array( array( 'id' => '', 'name' => 'Keyed', 'url' => 'https://api.acme.example/keyed', 'format' => 'nexora', 'auth_type' => 'bearer', 'auth_header' => '', 'secret' => 'platform_secret_77', 'enabled' => '1' ) ) );
update_option( 'nexora_api_sources', $keyed );
$unmock( $f );
$f = $mock( array( 'https://api.acme.example/keyed' => array( 200, $body( array( $item( 'K1' ) ) ) ) ) );
$flush();
$r = $call( $A['user_id'], 'nexora_api_preview', array( 'source' => 'platform:keyed' ) );
nx_assert( 'Bearer platform_secret_77' === ( $GLOBALS['nx_http'][0]['args']['headers']['Authorization'] ?? '' ) && false === strpos( wp_json_encode( $r ), 'platform_secret_77' ), 'platform: its key is sent to the source and never shown to the vendor' );
$unmock( $f );
delete_option( 'nexora_api_sources' );

/* ---------- the key appears nowhere a vendor (or the browser) can see ---------- */
$GLOBALS['nx_http'] = array();
$html = (function () use ( $A ) {
	wp_set_current_user( $A['user_id'] );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $A['login'] );
	$h = do_shortcode( '[profile_dashboard]' );
	wp_set_current_user( 0 );
	return $h;
})();
nx_assert( false === strpos( $html, $KEY ) && false === strpos( $html, 'sk_replaced_1' ) && false === strpos( $html, 'v1:' ) && false !== strpos( $html, 'Acme feed 2' ), 'the vendor\'s profile page lists the connection and holds no key or ciphertext' );
$GLOBALS['wp_scripts'] = null;
do_action( 'wp_enqueue_scripts' );
$data = ''; foreach ( wp_scripts()->queue as $h ) { $data .= (string) wp_scripts()->get_data( $h, 'data' ); }
nx_assert( false === strpos( $data, $KEY ) && false === strpos( $data, 'v1:' ), 'no script data holds a key' );

/* ---------- rate limits ---------- */
add_filter( 'nexora_rate_limits', function ( $l ) { $l['api_test'] = array( 2, HOUR_IN_SECONDS ); $l['api_connection'] = array( 2, HOUR_IN_SECONDS ); $l['api_apply'] = array( 1, HOUR_IN_SECONDS ); $l['api_import'] = array( 2, HOUR_IN_SECONDS ); return $l; } );
nx_test_reset_limits();
$R = nx_test_vendor( 'acr', 'approved' );
$f = $mock( array( $URL => array( 200, $body( array( $item( 'RL' ) ) ) ) ) );
$flush();
wp_set_current_user( $R['user_id'] );
$t = array(); for ( $i = 0; $i < 3; $i++ ) { $t[] = nx_call_ajax( 'nexora_api_test', array( 'source' => 'adhoc', 'url' => $URL, 'auth_type' => 'none' ) + nx_profile_nonce() ); }
$s = array(); for ( $i = 0; $i < 3; $i++ ) { $s[] = nx_call_ajax( 'nexora_api_connection_save', array( 'name' => "RL $i", 'url' => $URL . "?n=$i", 'auth_type' => 'none' ) + nx_profile_nonce() ); }
$pv = array(); for ( $i = 0; $i < 3; $i++ ) { $pv[] = nx_call_ajax( 'nexora_api_preview', array( 'source' => 'adhoc', 'url' => $URL, 'auth_type' => 'none' ) + nx_profile_nonce() ); }
wp_set_current_user( 0 );
$too = function ( $r ) { return nx_rejected( $r ) && false !== stripos( (string) ( $r['data']['message'] ?? '' ), 'too many' ); };
nx_assert( ! empty( $t[0]['success'] ) && ! empty( $t[1]['success'] ) && $too( $t[2] ), 'rate limit: the 3rd "Test my API" in the window is refused' );
nx_assert( ! empty( $s[0]['success'] ) && ! empty( $s[1]['success'] ) && $too( $s[2] ), 'rate limit: the 3rd connection save in the window is refused' );
nx_assert( ! empty( $pv[0]['success'] ) && ! empty( $pv[1]['success'] ) && $too( $pv[2] ), 'rate limit: the 3rd API preview in the window is refused' );
$track( $R );
$f2 = $f;
remove_all_filters( 'nexora_rate_limits' );
$unmock( $f );

/* ---------- the administrator's list ---------- */
$admin_cols = apply_filters( 'manage_nexora_api_conn_posts_columns', array( 'cb' => 'x', 'title' => 'T', 'date' => 'D' ) );
nx_assert( isset( $admin_cols['title'], $admin_cols['vendor'], $admin_cols['address'], $admin_cols['auth'], $admin_cols['last_import'] ), 'admin list: connection, vendor, address, auth and last import columns' );
nx_assert( ! isset( $admin_cols['secret'] ) && ! isset( $admin_cols['key'] ), 'admin list: no key column' );
$cell = function ( $col, $id ) { ob_start(); do_action( 'manage_nexora_api_conn_posts_custom_column', $col, $id ); return ob_get_clean(); };
nx_assert( false !== strpos( $cell( 'vendor', $cid ), 'Test Shop aca' ) && false !== strpos( $cell( 'address', $cid ), 'api.acme.example/v1/products' ), 'admin list: the vendor and the address are shown' );
nx_assert( false !== stripos( $cell( 'auth', $cid ), 'Header key' ) && false !== strpos( $cell( 'auth', $cid ), 'X-Shop-Key' ), 'admin list: the auth type is shown (header name, "saved", never the key)' );
nx_assert( '' !== trim( $cell( 'last_import', $cid ) ), 'admin list: the last import is shown' );
$all = '';
foreach ( array( 'vendor', 'address', 'auth', 'last_import' ) as $col ) { $all .= $cell( $col, $cid ); }
nx_assert( false === strpos( $all, $KEY ) && false === strpos( $all, 'v1:' ), 'admin list: never the key' );
nx_assert_same( array( 'edit' ), array_keys( apply_filters( 'post_row_actions', array( 'edit' => 'E', 'trash' => 'T', 'inline hide-if-no-js' => 'Q', 'view' => 'V' ), get_post( $cid ) ) ), 'admin list: only "Edit" (to read) remains' );
$wp_meta = array();
ob_start();
$box = new \Nexora\Imports\Connection_Admin();
$box->render_box( get_post( $cid ) );
$boxhtml = ob_get_clean();
nx_assert( false !== strpos( $boxhtml, 'api.acme.example' ) && false === strpos( $boxhtml, $KEY ) && false === strpos( $boxhtml, 'v1:' ), 'admin details box: shows the address and never the key' );
wp_set_current_user( $admin );
wp_trash_post( $cid ); wp_delete_post( $cid, true );
$forged = wp_insert_post( array( 'post_type' => 'nexora_api_conn', 'post_title' => 'forged', 'post_status' => 'publish' ) );
wp_update_post( array( 'ID' => $cid, 'post_title' => 'Renamed' ) );
wp_set_current_user( 0 );
nx_assert( null !== get_post( $cid ) && 'Acme feed 2' === get_post_field( 'post_title', $cid ) && ( ! $forged || is_wp_error( $forged ) ), 'a connection cannot be trashed, deleted, renamed or forged from outside' );

/* ---------- deleting the vendor deletes their connections ---------- */
require_once ABSPATH . 'wp-admin/includes/user.php';
$gone = nx_test_vendor( 'acgone', 'approved' );
$g = $call( $gone['user_id'], 'nexora_api_connection_save', array( 'name' => 'Gone', 'url' => 'https://api.acme.example/g', 'auth_type' => 'none' ) );
$gid = (int) $g['data']['id'];
wp_delete_user( $gone['user_id'] );
nx_assert_same( null, get_post( $gid ), 'deleting the vendor deletes their connections' );

foreach ( array( 'nexora_api_sources', 'nexora_vendor_apis_enabled', 'nexora_api_host_allowlist' ) as $i => $opt ) {
	if ( '__unset__' === $saved[ $i ] ) { delete_option( $opt ); } else { update_option( $opt, $saved[ $i ] ); }
}
$flush();
nx_test_finish();
