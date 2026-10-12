<?php
// Profile AJAX guard: a bad nonce is rejected, a logged-out caller is rejected, a valid member is served.
require_once __DIR__ . '/../bootstrap.php';

$u = nx_test_user( 'guard' );

wp_set_current_user( $u['user_id'] );
$ok = nx_call_ajax( 'get_requests', array( 'nonce' => wp_create_nonce( 'profile_nonce' ) ) );
nx_assert( ! empty( $ok['success'] ), 'valid member + nonce succeeds' );

$bad = nx_call_ajax( 'get_requests', array( 'nonce' => 'bad' ) );
nx_assert( isset( $bad['died'] ) || empty( $bad['success'] ), 'bad nonce is rejected' );

wp_set_current_user( 0 );
$anon = nx_call_ajax( 'get_requests', array( 'nonce' => wp_create_nonce( 'profile_nonce' ) ) );
nx_assert( isset( $anon['died'] ) || empty( $anon['success'] ), 'logged-out caller is rejected' );

nx_test_finish();
