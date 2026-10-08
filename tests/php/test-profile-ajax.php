<?php
// Characterization of includes/class-profile-ajax.php (profile, connections, notifications, content).
// Every assertion describes CURRENT behaviour that a refactor must preserve.
require_once __DIR__ . '/../bootstrap.php';

$alice = nx_test_user( 'alice' );
$bob   = nx_test_user( 'bob' );
$eve   = nx_test_user( 'eve' );
$noprofile = wp_insert_user( array( 'user_login' => 'nxtest_nop_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'nop' . wp_generate_password( 4, false ) . '@example.test' ) );
$GLOBALS['nx_test']['users'][] = $noprofile;

/* ---------- guard matrix on every state-changing / reading handler ---------- */
nx_assert_guard_matrix( 'update_personal_info', array( 'first_name' => 'A' ), $alice );
nx_assert_guard_matrix( 'update_address_info', array( 'perm_city' => 'X' ), $alice );
nx_assert_guard_matrix( 'update_work_info', array( 'company_name' => 'X' ), $alice );
nx_assert_guard_matrix( 'update_documents_info', array( 'profile_image' => '' ), $alice );
nx_assert_guard_matrix( 'get_add_new_users', array(), $alice );
nx_assert_guard_matrix( 'send_connection_request', array( 'receiver_profile_id' => $bob['profile_id'] ), $alice );
nx_assert_guard_matrix( 'get_requests', array(), $alice );
nx_assert_guard_matrix( 'get_history', array(), $alice );
nx_assert_guard_matrix( 'view_all_connection', array( 'profile_id' => $bob['profile_id'] ), $alice );
nx_assert_guard_matrix( 'view_mutual_connection', array( 'profile_id' => $bob['profile_id'] ), $alice );
nx_assert_guard_matrix( 'save_user_content', array( 'title' => 'T' ), $alice );
nx_assert_guard_matrix( 'get_user_content_history', array(), $alice );
nx_assert_guard_matrix( 'mark_notification_read', array( 'id' => 1 ), $alice );
nx_assert_guard_matrix( 'update_profile_password', array( 'current_password' => 'x', 'new_password' => 'yyyyyyyy', 'confirm_password' => 'yyyyyyyy' ), $alice );

// a WP user that has no profile post is refused by profile-bound handlers
wp_set_current_user( $noprofile );
nx_assert( nx_rejected( nx_call_ajax( 'update_personal_info', array( 'first_name' => 'Z' ) + nx_profile_nonce() ) ), 'user without profile post cannot edit a profile' );

/* ---------- personal / address / work ---------- */
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'update_personal_info', array( 'first_name' => 'Alice', 'last_name' => 'Tester', 'gender' => 'female', 'birthdate' => '1990-05-17', 'bio' => "Line1\nLine2 <b>x</b>" ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'personal info saved' );
nx_assert_same( 'Alice', get_post_meta( $alice['profile_id'], 'first_name', true ), 'first_name stored' );
nx_assert_same( 'female', get_post_meta( $alice['profile_id'], 'gender', true ), 'gender stored' );
nx_assert_same( "Line1\nLine2 x", get_post_meta( $alice['profile_id'], 'bio', true ), 'bio is sanitized textarea (tags stripped, newline kept)' );

nx_call_ajax( 'update_personal_info', array( 'gender' => 'robot' ) + nx_profile_nonce() );
nx_assert_same( 'female', get_post_meta( $alice['profile_id'], 'gender', true ), 'invalid gender ignored (kept previous value)' );

$r = nx_call_ajax( 'update_personal_info', array( 'birthdate' => '2999-01-01' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'future birthdate rejected' );
$r = nx_call_ajax( 'update_personal_info', array( 'birthdate' => '31/12/1990' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'malformed birthdate rejected' );
nx_assert_same( '1990-05-17', get_post_meta( $alice['profile_id'], 'birthdate', true ), 'birthdate unchanged after rejected input' );

// editing never touches somebody else's profile, even when ids are injected
nx_call_ajax( 'update_personal_info', array( 'first_name' => 'Hacked', 'profile_id' => $bob['profile_id'], 'post_id' => $bob['profile_id'] ) + nx_profile_nonce() );
nx_assert( 'Hacked' !== get_post_meta( $bob['profile_id'], 'first_name', true ), 'IDOR: injected profile_id does not edit another profile' );

nx_call_ajax( 'update_address_info', array( 'perm_city' => '<i>Delhi</i>', 'corr_pincode' => '110001' ) + nx_profile_nonce() );
nx_assert_same( 'Delhi', get_post_meta( $alice['profile_id'], 'perm_city', true ), 'address field sanitized' );

$r = nx_call_ajax( 'update_work_info', array( 'company_email' => 'not-an-email' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'invalid company email rejected' );
$r = nx_call_ajax( 'update_work_info', array( 'company_name' => 'Acme', 'company_email' => 'hr@acme.test' ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 'Acme' === get_post_meta( $alice['profile_id'], 'company_name', true ), 'work info saved' );

/* ---------- documents / attachments ---------- */
$mine   = nx_test_attachment( $alice['user_id'], 'mine' );
$theirs = nx_test_track_post( wp_insert_attachment( array( 'post_title' => 'theirs', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $bob['user_id'] ) ) );
$r = nx_call_ajax( 'update_documents_info', array( 'aadhaar_card' => (string) $mine ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && (int) get_post_meta( $alice['profile_id'], 'aadhaar_card', true ) === $mine, 'own attachment can be linked as document' );
$r = nx_call_ajax( 'update_documents_info', array( 'driving_license' => (string) $theirs ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ) && '' === (string) get_post_meta( $alice['profile_id'], 'driving_license', true ), "someone else's attachment cannot be linked" );
$r = nx_call_ajax( 'update_documents_info', array( 'company_id_card' => 'abc' ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'non-numeric attachment id rejected' );
nx_call_ajax( 'update_documents_info', array( 'aadhaar_card' => '' ) + nx_profile_nonce() );
nx_assert_same( '', (string) get_post_meta( $alice['profile_id'], 'aadhaar_card', true ), 'empty value removes the document link' );

/* ---------- connections ---------- */
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $alice['profile_id'] ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'cannot connect with yourself' );
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => 999999999 ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'unknown receiver rejected' );
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => (string) $mine ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'non-profile post id rejected as receiver' );

$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $bob['profile_id'] ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'connection request sent' );
$conn = get_posts( array( 'post_type' => 'user_connections', 'fields' => 'ids', 'meta_key' => 'sender_profile_id', 'meta_value' => $alice['profile_id'], 'numberposts' => 1 ) );
$conn_id = (int) ( $conn[0] ?? 0 );
nx_test_track_post( $conn_id );
nx_assert_same( 'pending', get_post_meta( $conn_id, 'status', true ), 'new connection is pending' );
nx_assert_same( $bob['user_id'], (int) get_post_meta( $conn_id, 'receiver_user_id', true ), 'connection stores receiver user id' );
$notes = ( new NEXORA_Notification() )->get_notifications( $bob['user_id'] );
nx_assert( 1 === count( $notes ) && 'request' === $notes[0]->type, 'receiver got a "request" notification' );

$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $bob['profile_id'] ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'duplicate request rejected' );
wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'send_connection_request', array( 'receiver_profile_id' => $alice['profile_id'] ) + nx_profile_nonce() );
nx_assert( nx_rejected( $r ), 'reverse duplicate rejected while pending' );

$r = nx_call_ajax( 'get_requests', nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 1 === count( $r['data'] ) && (int) $r['data'][0]['connection_id'] === $conn_id, 'receiver sees the pending request' );
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'get_requests', nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 0 === count( $r['data'] ), 'sender does not see own request as incoming' );

// status transitions
foreach ( array( 'accepted', 'rejected' ) as $st ) {
	wp_set_current_user( $alice['user_id'] );
	nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => $st ) + nx_profile_nonce() ) ), "sender cannot set $st" );
	wp_set_current_user( $eve['user_id'] );
	nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => $st ) + nx_profile_nonce() ) ), "third party cannot set $st" );
}
wp_set_current_user( $bob['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'hacked' ) + nx_profile_nonce() ) ), 'unknown status rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'removed' ) + nx_profile_nonce() ) ), 'pending request cannot be "removed"' );
nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $mine, 'status' => 'accepted' ) + nx_profile_nonce() ) ), 'non-connection post id rejected' );
$r = nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'accepted' ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 'accepted' === get_post_meta( $conn_id, 'status', true ), 'receiver accepts' );
nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'accepted' ) + nx_profile_nonce() ) ), 'accepted request cannot be accepted again' );
$notes = ( new NEXORA_Notification() )->get_notifications( $alice['user_id'] );
nx_assert( 1 === count( $notes ) && 'accepted' === $notes[0]->type, 'sender got an "accepted" notification' );

// lists
$r = nx_call_ajax( 'view_all_connection', array( 'profile_id' => $alice['profile_id'] ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && is_string( $r['data'] ) && false !== strpos( $r['data'], 'connection-card' ), 'bob sees alice\'s connection list (public network, accepted design)' );
nx_assert( nx_rejected( nx_call_ajax( 'view_all_connection', array( 'profile_id' => 0 ) + nx_profile_nonce() ) ), 'view_all_connection: missing profile id rejected' );
$r = nx_call_ajax( 'get_add_new_users', nx_profile_nonce() );
$ids = array_map( function ( $u ) { return (int) $u['profile_id']; }, $r['data'] );
nx_assert( ! in_array( $alice['profile_id'], $ids, true ) && ! in_array( $bob['profile_id'], $ids, true ), 'add-new-users excludes self and accepted connections' );
nx_assert( in_array( $eve['profile_id'], $ids, true ), 'add-new-users includes unrelated members' );
foreach ( $r['data'] as $u ) {
	nx_assert( ! isset( $u['email'] ) && ! isset( $u['phone'] ), 'add-new-users row never carries email/phone' );
	break;
}
$r = nx_call_ajax( 'get_history', nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && false !== strpos( $r['data'], 'history-wrapper' ), 'history returns HTML' );

// removal
wp_set_current_user( $eve['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'removed' ) + nx_profile_nonce() ) ), 'third party cannot remove a connection' );
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'update_connection_status', array( 'connection_id' => $conn_id, 'status' => 'removed' ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 'removed' === get_post_meta( $conn_id, 'status', true ), 'either party can remove an accepted connection' );

/* ---------- notifications ---------- */
$n   = new NEXORA_Notification();
$row = $n->get_notifications( $bob['user_id'] )[0];
wp_set_current_user( $eve['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'mark_notification_read', array( 'id' => $row->id ) + nx_profile_nonce() ) ), 'cannot mark someone else\'s notification read' );
nx_assert_same( 0, (int) $n->get_row( $row->id )->is_read, 'notification stays unread after rejected attempt' );
wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'mark_notification_read', array( 'id' => $row->id ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && 1 === (int) $n->get_row( $row->id )->is_read, 'owner marks own notification read' );
nx_assert( nx_rejected( nx_call_ajax( 'mark_notification_read', array( 'id' => 0 ) + nx_profile_nonce() ) ), 'notification id 0 rejected' );

/* ---------- user content ---------- */
wp_set_current_user( $alice['user_id'] );
nx_assert( nx_rejected( nx_call_ajax( 'save_user_content', array( 'title' => '   ' ) + nx_profile_nonce() ) ), 'content without title rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'save_user_content', array( 'title' => 'T', 'image' => $theirs ) + nx_profile_nonce() ) ), "content with someone else's image rejected" );
$r = nx_call_ajax( 'save_user_content', array( 'title' => 'Hello <script>x</script> World', 'description' => 'Body <b>b</b>', 'image' => $mine ) + nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ), 'content saved' );
$post = get_posts( array( 'post_type' => 'user_content', 'meta_key' => 'user_profile_id', 'meta_value' => $alice['profile_id'], 'numberposts' => 1 ) )[0] ?? null;
nx_assert( (bool) $post, 'content post created' );
if ( $post ) {
	nx_test_track_post( $post->ID );
	nx_assert_same( 'Hello World', $post->post_title, 'content title sanitized' );
	nx_assert_same( (int) $alice['user_id'], (int) $post->post_author, 'content authored by the member' );
	nx_assert_same( $mine, (int) get_post_thumbnail_id( $post->ID ), 'content image attached' );
}
$r = nx_call_ajax( 'get_user_content_history', nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && false !== strpos( $r['data'], 'Hello World' ), 'owner sees own content history' );
wp_set_current_user( $bob['user_id'] );
$r = nx_call_ajax( 'get_user_content_history', nx_profile_nonce() );
nx_assert( ! empty( $r['success'] ) && false === strpos( $r['data'], 'Hello World' ), 'content history is per member (no leak to others)' );

/* ---------- password change ---------- */
wp_set_current_user( $alice['user_id'] );
$pw = 'OldPass#12345';
wp_set_password( $pw, $alice['user_id'] );
$base = nx_profile_nonce();
nx_assert( nx_rejected( nx_call_ajax( 'update_profile_password', array( 'current_password' => 'wrong', 'new_password' => 'NewPass#12345', 'confirm_password' => 'NewPass#12345' ) + $base ) ), 'wrong current password rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'update_profile_password', array( 'current_password' => $pw, 'new_password' => 'short', 'confirm_password' => 'short' ) + $base ) ), 'short new password rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'update_profile_password', array( 'current_password' => $pw, 'new_password' => 'NewPass#12345', 'confirm_password' => 'Different#1' ) + $base ) ), 'mismatched confirmation rejected' );
nx_assert( nx_rejected( nx_call_ajax( 'update_profile_password', array( 'current_password' => $pw, 'new_password' => $pw, 'confirm_password' => $pw ) + $base ) ), 'unchanged password rejected' );
$r = nx_call_ajax( 'update_profile_password', array( 'current_password' => $pw, 'new_password' => 'NewPass#12345', 'confirm_password' => 'NewPass#12345' ) + $base );
nx_assert( ! empty( $r['success'] ) && wp_check_password( 'NewPass#12345', get_userdata( $alice['user_id'] )->user_pass ), 'password changed' );

nx_test_finish();
