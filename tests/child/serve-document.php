<?php
// Child for test-private-documents.php: runs the document download action as a given user, prints status/headers/body hash.
$_GET = $_REQUEST = array( 'action' => 'nexora_document', 'id' => (string) getenv( 'NX_ID' ), 'size' => (string) getenv( 'NX_SIZE' ) );
wp_set_current_user( (int) getenv( 'NX_USER' ) );
if ( ! (int) getenv( 'NX_USER' ) ) { echo "GUEST_HOOK=" . ( has_action( 'wp_ajax_nopriv_nexora_document' ) ? 'yes' : 'no' ) . "\n"; }
add_filter( 'nexora_document_headers', function ( $h ) { foreach ( $h as $k => $v ) { echo "H $k: $v\n"; } return $h; } );
add_filter( 'wp_die_handler', function () { return function ( $m, $t = '', $a = array() ) { echo 'DIED ' . ( is_array( $a ) && isset( $a['response'] ) ? $a['response'] : $t ) . "\n"; }; } );
do_action( 'wp_ajax_nexora_document' );
