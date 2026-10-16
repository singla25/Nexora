<?php
// Child process for test-orders-concurrent.php: places one already-created order the moment the shared start time arrives, so two of
// these really do compete for the same stock at the same instant. It loads WordPress only (not the test bootstrap): the parent made
// the order and owns every fixture and every setting snapshot.
// Input: env NX_ORDER_ID, NX_GO_AT (unix time with decimals). Prints one line: RESULT <json>.
$order_id = (int) getenv( 'NX_ORDER_ID' );
$go_at    = (float) getenv( 'NX_GO_AT' );

wp_set_current_user( 0 );
$order = wc_get_order( $order_id );

while ( microtime( true ) < $go_at ) {
	usleep( 200 );
}

$started = microtime( true );
$result  = array( 'ok' => false, 'message' => '' );

try {
	do_action( 'woocommerce_checkout_order_processed', $order_id, array(), $order );
	$result['ok'] = true;
} catch ( \Throwable $e ) {
	$result['message'] = $e->getMessage();
}

$result['status']  = wc_get_order( $order_id )->get_status();
$result['started'] = $started;

echo 'RESULT ' . wp_json_encode( $result ) . "\n";
