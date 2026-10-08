<?php
// Child process for test-access-control.php: block_wp_admin()/block_wp_login() redirect and exit.
// Env: NX_CALL (method), NX_USER (user id, 0 = guest), NX_URI (REQUEST_URI), NX_SCRIPT (SCRIPT_NAME), NX_GET (JSON).
$uid = (int) getenv( 'NX_USER' );
$_SERVER['REQUEST_URI'] = (string) getenv( 'NX_URI' );
$_SERVER['SCRIPT_NAME'] = (string) getenv( 'NX_SCRIPT' ) ?: '/wp-admin/index.php';
$_GET = json_decode( (string) getenv( 'NX_GET' ), true ) ?: array();
wp_set_current_user( $uid );
add_filter( 'wp_redirect', function ( $l ) { echo 'REDIRECT ' . $l . "\n"; return $l; } );
$sys = new NEXORA_System();
$method = (string) getenv( 'NX_CALL' );
$sys->$method();
echo "NO_REDIRECT\n";
