<?php
// Marketplace Phase 5b: API keys are encrypted with the site salts and are never readable back.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Core\Secrets;

$plain = 'sk_live_Abc123/+=secret';
$a = Secrets::encrypt( $plain );
$b = Secrets::encrypt( $plain );
nx_assert( '' !== $a && false === strpos( $a, 'sk_live' ) && false === strpos( $a, $plain ), 'the stored value does not contain the key' );
nx_assert( $a !== $b, 'encrypting twice gives different ciphertext (random nonce)' );
nx_assert( 0 === strpos( $a, 'v1:' ) && Secrets::is_encrypted( $a ) && ! Secrets::is_encrypted( $plain ), 'a version prefix tells encrypted values from plain ones' );
nx_assert_same( $plain, Secrets::decrypt( $a ), 'it decrypts back to the key' );
nx_assert_same( $plain, Secrets::decrypt( $b ), 'both ciphertexts decrypt' );
nx_assert_same( '', Secrets::encrypt( '' ), 'an empty key stays empty' );
nx_assert_same( '', Secrets::decrypt( '' ), 'decrypting nothing gives nothing' );

// Tampering, truncation, garbage: never a partial key, never an error.
$raw = base64_decode( substr( $a, 3 ), true );
$tampered = 'v1:' . base64_encode( substr( $raw, 0, -1 ) . chr( ord( substr( $raw, -1 ) ) ^ 1 ) );
nx_assert_same( '', Secrets::decrypt( $tampered ), 'a tampered value does not decrypt' );
foreach ( array( 'v1:', 'v1:!!!', 'v1:' . base64_encode( 'short' ), 'v2:' . substr( $a, 3 ), $plain, 'nonsense', array( 'x' ), null ) as $junk ) {
	nx_assert_same( '', Secrets::decrypt( $junk ), 'garbage does not decrypt: ' . substr( wp_json_encode( $junk ), 0, 30 ) );
}

// The key comes from the site salts: with other salts the value is useless.
$other = function ( $salt, $scheme ) { return 'a-different-salt-' . $scheme; };
add_filter( 'salt', $other, 10, 2 );
nx_assert_same( '', Secrets::decrypt( $a ), 'with different site salts the stored key cannot be read' );
$c = Secrets::encrypt( $plain );
remove_filter( 'salt', $other, 10 );
nx_assert_same( '', Secrets::decrypt( $c ), 'and a key encrypted under other salts cannot be read with these' );
nx_assert_same( $plain, Secrets::decrypt( $a ), 'the original still decrypts once the salts are back' );

// Never in the database in clear.
global $wpdb;
update_option( 'nx_test_secret_probe', $a );
nx_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $plain ) . '%' ) ), 'the clear key is nowhere in the options table' );
delete_option( 'nx_test_secret_probe' );

// A masked display value.
nx_assert_same( '', Secrets::mask( '' ), 'no key: nothing to show' );
$m = Secrets::mask( $a );
nx_assert( '' !== $m && false === strpos( $m, 'sk_' ) && false === strpos( $m, 'secret' ) && 1 === preg_match( '/^[•*]+$/u', $m ), 'a saved key is shown only as dots' );

nx_test_finish();
