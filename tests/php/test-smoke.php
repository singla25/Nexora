<?php
// Smoke test: proves the harness loads WordPress, the plugin, and cleans up after itself.
require_once __DIR__ . '/../bootstrap.php';

$u = nx_test_user( 'smoke' );
nx_assert_same( $u['profile_id'], (int) get_user_meta( $u['user_id'], '_profile_id', true ), 'user -> profile link' );
nx_assert_same( $u['user_id'], (int) get_post_meta( $u['profile_id'], '_wp_user_id', true ), 'profile -> user link' );
nx_assert( class_exists( 'NEXORA_Notification' ), 'plugin classes are loaded' );

nx_test_finish();
