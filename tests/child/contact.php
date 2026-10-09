<?php
// Child process for test-shortcodes-contact.php: the contact handler ends in exit, so it must run in its own process.
// Input: env NX_POST (JSON). Prints REDIRECT <url> and MAILED lines.
$post = json_decode( (string) getenv( 'NX_POST' ), true );
$_POST = $_REQUEST = is_array( $post ) ? $post : array();
add_filter( 'pre_wp_mail', function ( $null, $a ) {
	echo 'MAILED to=' . ( is_array( $a['to'] ) ? implode( ',', $a['to'] ) : $a['to'] ) . ' subject=' . $a['subject'] . ' headers=' . json_encode( $a['headers'] ) . "\n";
	return true;
}, 10, 2 );
add_filter( 'wp_redirect', function ( $location ) {
	echo 'REDIRECT ' . $location . "\n";
	return $location;
} );
do_action( 'admin_post_nopriv_nexora_contact' );
