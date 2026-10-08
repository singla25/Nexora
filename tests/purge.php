<?php
// Removes leftovers of test runs that crashed before they could clean up. Touches fixture data ONLY:
// users named nxtest_*, profile posts titled nxtest_*, and everything that hangs off those users.
// Run by tests/run.sh before the suites (wp eval-file tests/purge.php).
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';

$user_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'nxtest\\_%'" ) );
$post_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'user_profile' AND post_title LIKE 'nxtest\\_%'" ) );

$removed = array( 'users' => 0, 'posts' => 0, 'threads' => 0, 'notifications' => 0 );

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

// rate-limit counters and OTP references made by tests
foreach ( array( '\\_transient\\_nexora\\_otp%', '\\_transient\\_timeout\\_nexora\\_otp%', 'nexora\\_rl\\_%', '\\_transient\\_nx\\_%', '\\_transient\\_timeout\\_nx\\_%' ) as $like ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
}

if ( array_sum( $removed ) ) {
	echo 'purged leftover test data: ' . wp_json_encode( $removed ) . "\n";
}
