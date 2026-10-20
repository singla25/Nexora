<?php
// Removes leftovers of test runs that crashed before they could clean up. Touches fixture data ONLY:
// users named nxtest_*, profile posts titled nxtest_*, and everything that hangs off those users.
// Run by tests/run.sh before the suites (wp eval-file tests/purge.php).
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';

$user_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'nxtest\\_%'" ) );
$post_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nexora_customer' AND post_title LIKE 'nxtest\\_%'" ) );

$removed = array( 'users' => 0, 'posts' => 0, 'threads' => 0, 'notifications' => 0, 'orders' => 0 );

// Orders made by the test fixtures (tests/bootstrap.php nx_test_order): the fixture billing e-mail is their signature (an example.test address
// no real customer can have), or the buyer is an nxtest_ user. Real orders are never matched.
if ( function_exists( 'wc_get_orders' ) ) {
	$fixture_orders = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ), 'billing_email' => 'bob.billing@example.test' ) );
	if ( $user_ids ) {
		$fixture_orders = array_merge( $fixture_orders, wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ), 'customer_id' => $user_ids ) ) );
	}
	foreach ( array_unique( $fixture_orders ) as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
			$removed['orders']++;
		}
	}
}

if ( $user_ids ) {
	$in = implode( ',', $user_ids );

	// chat
	$thread_ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT thread_id FROM {$wpdb->prefix}nexora_thread_participants WHERE user_id IN ($in)" ) );
	if ( $thread_ids ) {
		$tin = implode( ',', $thread_ids );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}nexora_message_meta WHERE message_id IN (SELECT id FROM {$wpdb->prefix}nexora_messages WHERE thread_id IN ($tin))" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}nexora_messages WHERE thread_id IN ($tin)" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}nexora_thread_participants WHERE thread_id IN ($tin)" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}nexora_threads WHERE id IN ($tin)" );
		$removed['threads'] = count( $thread_ids );
	}
	$removed['notifications'] = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}nexora_notifications WHERE actor_user_id IN ($in) OR receiver_user_id IN ($in)" );

	// connections, content and attachments of those users
	$extra = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('sender_user_id','receiver_user_id','user_id')
		WHERE (p.post_type IN ('user_connections','user_content') AND m.meta_value IN ($in)) OR (p.post_type = 'attachment' AND p.post_author IN ($in))" );
	foreach ( $extra as $id ) {
		wp_delete_post( (int) $id, true );
		$removed['posts']++;
	}
	foreach ( $user_ids as $uid ) {
		$pid = (int) get_user_meta( $uid, '_profile_id', true );
		if ( $pid ) { $post_ids[] = $pid; }
	}
}

foreach ( array_unique( $post_ids ) as $pid ) {
	if ( get_post( $pid ) ) { wp_delete_post( $pid, true ); $removed['posts']++; }
}
foreach ( $user_ids as $uid ) {
	wp_delete_user( $uid );
	$removed['users']++;
}

// Rate-limit counters, cached API pages, import previews and locks are NOT swept here: they belong to whoever is using the site, and a
// test run puts back what it set aside and removes only what it made itself (tests/bootstrap.php, nx_test_ephemera_*).

if ( array_sum( $removed ) ) {
	echo 'purged leftover test data: ' . wp_json_encode( $removed ) . "\n";
}
