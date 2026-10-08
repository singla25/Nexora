<?php
// GOLDEN OUTPUT: exact markup of every screen the plugin renders, built from a fixed-ID world so the
// snapshots are deterministic. A structural refactor must leave every snapshot byte-for-byte equal
// (apart from whitespace between tags). Update deliberately with NX_UPDATE_GOLDEN=1.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;

const NXG_U = 9100000;   // users 9100001..
const NXG_P = 9000000;   // posts 9000001..

/* ---------- deterministic options (never depend on the dev site's settings) ---------- */
$opts = array(
	'default_profile_image' => '', 'default_cover_image' => '', 'default_document_image' => '', 'default_home_cover_image' => '',
	'default_feed_experience_image' => '', 'default_real_time_chat_image' => '', 'default_smart_connections_image' => '',
	'default_admin_mail' => 'admin@example.test', 'recaptcha_site_key' => 'SITEKEY', 'recaptcha_secret_key' => 'SECRET', 'recaptcha_enabled' => 1,
	'nexora_home_eyebrow' => '', 'nexora_home_title' => '', 'nexora_home_subtitle' => '', 'nexora_home_features' => '', 'nexora_home_testimonials' => "Jane Doe | Designer | Great <place> to meet people.\nJohn | Dev | Solid.",
);
foreach ( $opts as $k => $v ) { add_filter( "pre_option_$k", function () use ( $v ) { return $v; } ); }
set_transient( Nexora_Home_Page::STATS_TRANSIENT, array( 'members' => 1234, 'connections' => 56, 'posts' => 7890, 'chats' => 12 ), HOUR_IN_SECONDS );

/* ---------- clean slate (fixed ids only) ---------- */
$clean = function () use ( $wpdb ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID >= 9000000 AND ID < 9100000" ) as $id ) { wp_delete_post( (int) $id, true ); }
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE ID >= 9100000" ) as $id ) { wp_delete_user( (int) $id ); }
	foreach ( array( 'nexora_message_meta' => 'message_id >= 9300000', 'nexora_messages' => 'id >= 9300000', 'nexora_thread_participants' => 'thread_id >= 9200000', 'nexora_threads' => 'id >= 9200000', 'nexora_notifications' => 'id >= 9400000' ) as $t => $w ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}$t WHERE $w" );
	}
	delete_transient( Nexora_Home_Page::STATS_TRANSIENT );
	// Fixed ids push the auto-increment counters into the millions; give them back (InnoDB uses max(id)+1).
	foreach ( array( $wpdb->users, $wpdb->posts, "{$wpdb->prefix}nexora_threads", "{$wpdb->prefix}nexora_messages", "{$wpdb->prefix}nexora_notifications", "{$wpdb->prefix}nexora_thread_participants" ) as $table ) {
		$wpdb->query( "ALTER TABLE $table AUTO_INCREMENT = 1" );
	}
};
$clean();

/* ---------- isolation: during this test the plugin only "sees" the fixture rows ---------- */
// get_posts() suppresses the posts_* filters but still runs pre_get_posts: hide every pre-existing row of the three types
$real_ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID < 9000000 AND post_type IN ('user_profile','user_connections','user_content')" ) );
add_action( 'pre_get_posts', function ( $query ) use ( $real_ids ) {
	if ( array_intersect( (array) $query->get( 'post_type' ), array( 'user_profile', 'user_connections', 'user_content' ) ) ) {
		$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $real_ids ) );
	}
} );
add_filter( 'query', function ( $sql ) use ( $wpdb ) {
	// the two admin overviews list every row of a plugin table
	$sql = str_replace( "FROM {$wpdb->prefix}nexora_notifications ORDER BY created_at DESC", "FROM {$wpdb->prefix}nexora_notifications WHERE id >= 9400000 ORDER BY created_at DESC", $sql );
	if ( false !== strpos( $sql, "FROM {$wpdb->prefix}nexora_threads t" ) && false !== strpos( $sql, 'm.message as last_message' ) && false !== strpos( $sql, 'GROUP BY t.id' ) ) {
		$sql = str_replace( 'GROUP BY t.id', 'WHERE t.id >= 9200000 GROUP BY t.id', $sql );
	}
	return $sql;
} );

/* ---------- the world ---------- */
$mkuser = function ( $id, $login, $name, $role = 'subscriber' ) use ( $wpdb ) {
	$wpdb->insert( $wpdb->users, array( 'ID' => $id, 'user_login' => $login, 'user_pass' => wp_hash_password( 'x' ), 'user_nicename' => $login, 'user_email' => "$login@example.test",
		'user_registered' => '2026-01-01 00:00:00', 'display_name' => $name ) );
	update_user_meta( $id, $wpdb->prefix . 'capabilities', array( $role => true ) );
	update_user_meta( $id, $wpdb->prefix . 'user_level', 'administrator' === $role ? 10 : 0 );
	clean_user_cache( $id );
};
$mkuser( NXG_U + 1, 'nxgold_alice', 'Alice Gold' );
$mkuser( NXG_U + 2, 'nxgold_bob', 'Bob Silver' );
$mkuser( NXG_U + 3, 'nxgold_eve', 'Eve Bronze' );
$mkuser( NXG_U + 4, 'nxgold_admin', 'Ada Admin', 'administrator' );
$mkuser( NXG_U + 5, 'nxgold_carol', 'Carol Copper' );   // not connected to anyone: shows up in "add new"
$A = NXG_U + 1; $B = NXG_U + 2; $E = NXG_U + 3; $AD = NXG_U + 4;

$mkpost = function ( $id, $type, $title, $date, $extra = array() ) {
	return wp_insert_post( array( 'import_id' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_date' => $date, 'post_date_gmt' => $date, 'post_name' => 'nxg-' . $id ) + $extra );
};
$PA = NXG_P + 1; $PB = NXG_P + 2; $PE = NXG_P + 3;
$CA = NXG_U + 5; $PC = NXG_P + 4;
foreach ( array( array( $PA, 'nxgold_alice', $A ), array( $PB, 'nxgold_bob', $B ), array( $PE, 'nxgold_eve', $E ), array( $PC, 'nxgold_carol', $CA ) ) as list( $pid, $login, $uid ) ) {
	$mkpost( $pid, 'user_profile', $login, '2026-01-10 09:00:00' );
	update_post_meta( $pid, '_wp_user_id', $uid );
	update_post_meta( $pid, 'user_name', $login );
	update_user_meta( $uid, '_profile_id', $pid );
}
// rich, hostile profile data for alice
foreach ( array( 'first_name' => 'Alice', 'last_name' => '<b>Gold</b>', 'email' => 'alice.gold@example.test', 'phone' => '+91 98765 43210', 'gender' => 'female', 'birthdate' => '1990-05-17',
	'linkedin_id' => 'alice-gold', 'bio' => "Hello \"world\" & <script>alert(1)</script>\nSecond line", 'perm_address' => '12 <Hidden> Lane', 'perm_city' => 'Delhi', 'perm_state' => 'DL', 'perm_pincode' => '110001',
	'corr_address' => '7 Mail St', 'corr_city' => 'Noida', 'corr_state' => 'UP', 'corr_pincode' => '201301', 'company_name' => 'Acme & Co', 'designation' => 'Engineer',
	'company_email' => 'hr@acme.test', 'company_phone' => '+91 11 2222', 'company_address' => '1 Corp Rd' ) as $k => $v ) { update_post_meta( $PA, $k, $v ); }
update_post_meta( $PB, 'first_name', 'Bob' ); update_post_meta( $PB, 'last_name', 'Silver' );
update_post_meta( $PE, 'first_name', 'Eve' ); update_post_meta( $PE, 'last_name', 'Bronze' );
update_post_meta( $PC, 'first_name', 'Carol' ); update_post_meta( $PC, 'last_name', 'Copper' );

// attachments: a public profile image (no file needed) and a private ID document (real file, moved by the plugin)
$up = wp_upload_dir();
wp_mkdir_p( $up['basedir'] . '/2026/01' );
$img = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
file_put_contents( $up['basedir'] . '/2026/01/nxg-avatar.png', $img );
file_put_contents( $up['basedir'] . '/2026/01/nxg-aadhaar.png', $img );
$ATT1 = NXG_P + 31; $ATT2 = NXG_P + 32;
foreach ( array( array( $ATT1, 'nxg-avatar' ), array( $ATT2, 'nxg-aadhaar' ) ) as list( $aid, $slug ) ) {
	wp_insert_attachment( array( 'import_id' => $aid, 'post_title' => $slug, 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $A, 'post_date' => '2026-01-12 08:00:00', 'post_date_gmt' => '2026-01-12 08:00:00' ), $up['basedir'] . "/2026/01/$slug.png" );
}
update_post_meta( $PA, 'profile_image', $ATT1 );
update_post_meta( $PA, 'aadhaar_card', $ATT2 );   // moves the file to the private folder

// connections
$mkconn = function ( $id, $from, $to, $status, $date ) use ( $mkpost ) {
	$mkpost( $id, 'user_connections', $from['login'] . '->' . $to['login'], $date );
	foreach ( array( 'sender' => $from, 'receiver' => $to ) as $side => $x ) {
		update_post_meta( $id, "{$side}_user_id", $x['uid'] ); update_post_meta( $id, "{$side}_profile_id", $x['pid'] ); update_post_meta( $id, "{$side}_user_name", $x['login'] );
	}
	update_post_meta( $id, 'status', $status );
};
$ua = array( 'uid' => $A, 'pid' => $PA, 'login' => 'nxgold_alice' ); $ub = array( 'uid' => $B, 'pid' => $PB, 'login' => 'nxgold_bob' ); $ue = array( 'uid' => $E, 'pid' => $PE, 'login' => 'nxgold_eve' );
$C1 = NXG_P + 11; $C2 = NXG_P + 12; $C3 = NXG_P + 13;
$mkconn( $C1, $ua, $ub, 'accepted', '2026-02-01 10:00:00' );
$mkconn( $C2, $ue, $ua, 'pending',  '2026-02-02 11:00:00' );
$mkconn( $C3, $ub, $ue, 'rejected', '2026-02-03 12:00:00' );

// content
$CT = NXG_P + 21;
$mkpost( $CT, 'user_content', 'My <first> post', '2026-02-05 13:00:00', array( 'post_content' => "Body with \"quotes\" & <i>tags</i>", 'post_author' => $A ) );
update_post_meta( $CT, 'user_id', $A ); update_post_meta( $CT, 'user_profile_id', $PA ); update_post_meta( $CT, 'user_name', 'nxgold_alice' );

// chat + notifications (explicit ids and timestamps)
$T1 = 9200001; $T2 = 9200002; $M1 = 9300001; $M2 = 9300002;
$wpdb->insert( "{$wpdb->prefix}nexora_threads", array( 'id' => $T1, 'type' => 'private', 'subject' => 'Project <Alpha>', 'last_message_id' => $M2, 'created_at' => '2026-02-01 10:05:00', 'updated_at' => '2026-02-01 10:20:00', 'connection_id' => $C1, 'status' => 'active' ) );
$wpdb->insert( "{$wpdb->prefix}nexora_threads", array( 'id' => $T2, 'type' => 'private', 'subject' => '', 'last_message_id' => null, 'created_at' => '2026-02-01 11:05:00', 'updated_at' => '2026-02-01 11:05:00', 'connection_id' => $C1, 'status' => 'inactive' ) );
foreach ( array( array( $T1, $A, 0 ), array( $T1, $B, 1 ), array( $T2, $A, 0 ), array( $T2, $B, 0 ) ) as list( $t, $u, $unread ) ) {
	$wpdb->insert( "{$wpdb->prefix}nexora_thread_participants", array( 'thread_id' => $t, 'user_id' => $u, 'unread_count' => $unread, 'created_at' => '2026-02-01 10:05:00' ) );
}
$wpdb->insert( "{$wpdb->prefix}nexora_messages", array( 'id' => $M1, 'thread_id' => $T1, 'sender_id' => $B, 'message' => 'Hi Alice <3', 'created_at' => '2026-02-01 10:10:00' ) );
$wpdb->insert( "{$wpdb->prefix}nexora_messages", array( 'id' => $M2, 'thread_id' => $T1, 'sender_id' => $A, 'message' => 'Hello "Bob" & welcome', 'created_at' => '2026-02-01 10:20:00' ) );
$wpdb->insert( "{$wpdb->prefix}nexora_notifications", array( 'id' => 9400001, 'actor_user_id' => $E, 'actor_user_name' => 'nxgold_eve', 'receiver_user_id' => $A, 'receiver_user_name' => 'nxgold_alice', 'type' => 'request', 'connection_id' => $C2, 'message' => 'nxgold_eve sent a connection request to nxgold_alice', 'is_read' => 0, 'created_at' => '2026-02-02 11:00:00' ) );
$wpdb->insert( "{$wpdb->prefix}nexora_notifications", array( 'id' => 9400002, 'actor_user_id' => $B, 'actor_user_name' => 'nxgold_bob', 'receiver_user_id' => $A, 'receiver_user_name' => 'nxgold_alice', 'type' => 'accepted', 'connection_id' => $C1, 'message' => 'nxgold_bob accepted <alice> connection request', 'is_read' => 1, 'created_at' => '2026-02-01 10:01:00' ) );

set_transient( Nexora_Home_Page::STATS_TRANSIENT, array( 'members' => 1234, 'connections' => 56, 'posts' => 7890, 'chats' => 12 ), HOUR_IN_SECONDS );

/* ---------- helpers ---------- */
$capture = function ( callable $fn ) { ob_start(); $r = $fn(); $out = ob_get_clean(); return is_string( $r ) ? $out . $r : $out; };
$post = function ( $id ) { return get_post( $id ); };
$page = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	return do_shortcode( '[profile_dashboard]' );
};

/* ================= ADMIN SCREENS ================= */
wp_set_current_user( $AD );
set_current_screen( 'dashboard' );
$settings = new \Nexora\Admin\Settings();
$pages    = new \Nexora\Admin\Pages();
$boxes    = new \Nexora\Admin\Meta_Boxes();
$cols     = new \Nexora\Admin\List_Columns();
$menu     = new \Nexora\Admin\Menu( $settings, $pages );
nx_assert_golden( 'admin-settings-page',      $capture( [ $settings, 'settings_page' ] ) );
nx_assert_golden( 'admin-notifications-page', $capture( function () use ( $pages ) { $pages->notifications_page(); } ) );
nx_assert_golden( 'admin-chat-page',          $capture( function () use ( $pages ) { $pages->nexora_user_chat(); } ) );
foreach ( array( 'user_personal_details', 'user_address_details', 'user_work_details', 'user_document_details', 'user_connection_details', 'user_content_details', 'user_chat_details' ) as $mb ) {
	nx_assert_golden( "admin-metabox-$mb", $capture( function () use ( $boxes, $mb, $post, $PA ) { $boxes->$mb( $post( $PA ) ); } ) );
}
nx_assert_golden( 'admin-metabox-connection',      $capture( function () use ( $boxes, $post, $C1 ) { $boxes->user_connection_meta_box( $post( $C1 ) ); } ) );
nx_assert_golden( 'admin-metabox-connection-chat', $capture( function () use ( $boxes, $post, $C1 ) { $boxes->user_connection_chat_box( $post( $C1 ) ); } ) );
nx_assert_golden( 'admin-metabox-content',         $capture( function () use ( $boxes, $post, $CT ) { $boxes->render_user_content_meta_box( $post( $CT ) ); } ) );
$cols_obj = $cols;
$cols = array( 'cb' => 'x', 'title' => 'Title', 'date' => 'Date' );
$colout = json_encode( array( $cols_obj->add_name_column( $cols ), $cols_obj->add_status_column( $cols ), $cols_obj->add_user_name_column( $cols ) ) );
foreach ( array( array( 'manage_name_column', 'user_full_name', $PA ), array( 'manage_status_column', 'connection_status', $C1 ), array( 'manage_status_column', 'connection_status', $C2 ), array( 'manage_status_column', 'connection_status', $C3 ), array( 'manage_user_name_column', 'user_name', $CT ) ) as list( $m, $col, $pid ) ) {
	$colout .= $capture( function () use ( $cols_obj, $m, $col, $pid ) { $cols_obj->$m( $col, $pid ); } ) . '|';
}
nx_assert_golden( 'admin-list-columns', $colout );
$menu_before = $GLOBALS['menu'] ?? array();
$GLOBALS['menu'] = array(); $GLOBALS['submenu'] = array();
$menu->register_main_menu();
nx_assert_golden( 'admin-menu', json_encode( array( array_values( $GLOBALS['menu'] ), $GLOBALS['submenu'] ) ) );

/* ================= PROFILE PAGE ================= */
nx_assert_golden( 'profile-owner',   $page( $A, 'nxgold_alice' ) );
nx_assert_golden( 'profile-own-url', $page( $A, '' ) );
nx_assert_golden( 'profile-viewer',  $page( $B, 'nxgold_alice' ) );
nx_assert_golden( 'profile-guest',   $page( 0, 'nxgold_alice' ) );
nx_assert_golden( 'profile-admin',   $page( $AD, '' ) );
nx_assert_golden( 'profile-notfound', $page( $B, 'nxgold_nobody' ) );

/* ================= AJAX-RENDERED HTML ================= */
wp_set_current_user( $A );
foreach ( array( 'get_history' => array(), 'get_user_content_history' => array(), 'view_all_connection' => array( 'profile_id' => $PA ), 'view_mutual_connection' => array( 'profile_id' => $PB ) ) as $action => $args ) {
	$r = nx_call_ajax( $action, $args + nx_profile_nonce() );
	nx_assert_golden( "ajax-$action", is_string( $r['data'] ?? null ) ? $r['data'] : json_encode( $r ) );
}
foreach ( array( 'get_add_new_users', 'get_requests' ) as $action ) {
	$r = nx_call_ajax( $action, nx_profile_nonce() );
	nx_assert_golden( "ajax-$action", json_encode( $r ) );
}
wp_set_current_user( $B );
$r = nx_call_ajax( 'view_all_connection', array( 'profile_id' => $PA ) + nx_profile_nonce() );
nx_assert_golden( 'ajax-view_all_connection-as-bob', (string) $r['data'] );

/* ================= SHORTCODES / FORMS ================= */
wp_set_current_user( 0 );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query();
nx_assert_golden( 'form-login-guest',        do_shortcode( '[profile_login]' ) );
nx_assert_golden( 'form-registration-guest', do_shortcode( '[profile_registration]' ) );
nx_assert_golden( 'home-guest',              do_shortcode( '[nexora_home]' ) );
nx_assert_golden( 'contact-form',            do_shortcode( '[nexora_contact_form]' ) );
nx_assert_golden( 'auth-buttons-guest',      do_shortcode( '[nexora_auth_buttons]' ) );
wp_set_current_user( $A );
nx_assert_golden( 'form-login-member',        do_shortcode( '[profile_login]' ) );
nx_assert_golden( 'form-registration-member', do_shortcode( '[profile_registration]' ) );
nx_assert_golden( 'home-member',              do_shortcode( '[nexora_home]' ) );
nx_assert_golden( 'auth-buttons-member',      do_shortcode( '[nexora_auth_buttons]' ) );
nx_assert_golden( 'stat-members',             do_shortcode( '[nexora_stat type="members"]' ) . '|' . do_shortcode( '[nexora_stat type="posts"]' ) );
wp_set_current_user( $AD );
nx_assert_golden( 'home-admin', do_shortcode( '[nexora_home]' ) );

/* ================= CHAT POPUP ================= */
$chat = new NEXORA_CHAT_CORE();
wp_set_current_user( $A );
nx_assert_golden( 'chat-popup-member', $capture( function () use ( $chat ) { $chat->load_chat_template(); } ) );
wp_set_current_user( $AD );
nx_assert_golden( 'chat-popup-admin', $capture( function () use ( $chat ) { $chat->load_chat_template(); } ) );

/* ================= ADMIN SAVE ================= */
wp_set_current_user( $AD );
set_current_screen( 'edit-user_profile' );
$_POST = array( '_wpnonce' => wp_create_nonce( 'update-post_' . $PB ), 'first_name' => '<i>Bobby</i>', 'bio' => "line1\nline2", 'profile_image' => '123abc', 'aadhaar_card' => (string) $ATT1, 'email' => 'bob@new.test' );
$boxes->save_meta_boxes( $PB );
nx_assert_same( 'Bobby', get_post_meta( $PB, 'first_name', true ), 'admin save: text sanitized' );
nx_assert_same( 123, (int) get_post_meta( $PB, 'profile_image', true ), 'admin save: attachment id absint' );
nx_assert_same( 'bob@new.test', get_post_meta( $PB, 'email', true ), 'admin save: email stored' );
$_POST['_wpnonce'] = 'bad'; $_POST['first_name'] = 'Hacked';
$boxes->save_meta_boxes( $PB );
nx_assert_same( 'Bobby', get_post_meta( $PB, 'first_name', true ), 'admin save: bad nonce ignored' );
wp_set_current_user( $B );
$_POST = array( '_wpnonce' => wp_create_nonce( 'update-post_' . $PB ), 'first_name' => 'Member' );
$boxes->save_meta_boxes( $PB );
nx_assert_same( 'Bobby', get_post_meta( $PB, 'first_name', true ), 'admin save: non-admin ignored' );
wp_set_current_user( $AD );
$_POST = array( '_wpnonce' => wp_create_nonce( 'update-post_' . $C2 ), 'status' => 'accepted', 'sender_user_name' => 'x<y>' );
$boxes->save_meta_boxes( $C2 );
nx_assert_same( 'accepted', get_post_meta( $C2, 'status', true ), 'admin save: connection status' );
$_POST = array();

$clean();
wp_cache_flush();
nx_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID >= 9000000" ), 'golden world fully removed' );
nx_test_finish();
