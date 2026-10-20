<?php
// Child process for test-admin-gate.php: everything about the request is a genuine admin action EXCEPT that it is a REST request
// (the REST_REQUEST constant is defined). The gate must stay closed. Input: env NX_UID (an administrator), NX_POST, NX_OP. Prints OPEN=0|1.
define( 'REST_REQUEST', true );

$uid  = (int) getenv( 'NX_UID' );
$post = (int) getenv( 'NX_POST' );
$op   = (string) getenv( 'NX_OP' );

wp_set_current_user( $uid );

$GLOBALS['pagenow'] = 'post.php';
set_current_screen( 'post' );

if ( 'edit' === $op ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array( 'action' => 'editpost', 'post_ID' => $post, '_wpnonce' => wp_create_nonce( 'update-post_' . $post ) );
	$_GET                      = array();
} else {
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_GET                      = array( 'action' => $op, 'post' => $post, '_wpnonce' => wp_create_nonce( $op . '-post_' . $post ) );
	$_POST                     = array();
}

$_REQUEST = array_merge( $_GET, $_POST );

echo 'OPEN=' . ( \Nexora\Core\Admin_Gate::open( $post, $op ) ? '1' : '0' ) . "\n";
