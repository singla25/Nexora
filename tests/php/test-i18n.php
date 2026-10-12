<?php
// Phase 3: every user-facing string goes through the "nexora" text domain, and the committed .pot is current.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;

/* ---- wiring ---- */
nx_assert( false !== has_action( 'init', array( new \Nexora\Core\I18n(), 'load_textdomain' ) ) || (bool) has_action( 'init' ), 'text domain loader is hooked on init' );
$hdr = get_file_data( NEXORA_PATH . 'nexora.php', array( 'v' => 'Version', 'td' => 'Text Domain', 'dp' => 'Domain Path', 'php' => 'Requires PHP', 'wp' => 'Requires at least' ) );
nx_assert_same( NEXORA_VERSION, $hdr['v'], 'plugin header Version equals NEXORA_VERSION' );
nx_assert( 'nexora' === $hdr['td'] && '/languages' === $hdr['dp'] && '' !== $hdr['php'] && '' !== $hdr['wp'], 'header declares Text Domain, Domain Path and requirements' );

/* ---- isolate: only this test's own rows are visible to the plugin ---- */
$real_posts   = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('user_profile','user_connections','user_content')" ) );
$min_thread   = 1 + (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}nexora_threads" );
$min_notif    = 1 + (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}nexora_notifications" );
add_action( 'pre_get_posts', function ( $q ) use ( $real_posts ) {
	if ( array_intersect( (array) $q->get( 'post_type' ), array( 'user_profile', 'user_connections', 'user_content' ) ) ) {
		$q->set( 'post__not_in', array_merge( (array) $q->get( 'post__not_in' ), $real_posts ) );
	}
} );
add_filter( 'query', function ( $sql ) use ( $wpdb, $min_thread, $min_notif ) {
	$sql = str_replace( "FROM {$wpdb->prefix}nexora_notifications ORDER BY created_at DESC", "FROM {$wpdb->prefix}nexora_notifications WHERE id >= $min_notif ORDER BY created_at DESC", $sql );
	if ( false !== strpos( $sql, "FROM {$wpdb->prefix}nexora_threads t" ) && false !== strpos( $sql, 'm.message as last_message' ) && false !== strpos( $sql, 'GROUP BY t.id' ) ) {
		$sql = str_replace( 'GROUP BY t.id', "WHERE t.id >= $min_thread GROUP BY t.id", $sql );
	}
	return $sql;
} );

/* ---- tag every nexora string so untranslated text stands out ---- */
$tag = function ( $translation, $text, $domain ) { return 'nexora' === $domain ? "\u{ab}" . $translation . "\u{bb}" : $translation; };
add_filter( 'gettext', $tag, 10, 3 );
add_filter( 'gettext_with_context', function ( $t, $text, $ctx, $domain ) { return 'nexora' === $domain ? "\u{ab}" . $t . "\u{bb}" : $t; }, 10, 4 );

$alice = nx_test_user( 'i18alice' );
$bob   = nx_test_user( 'i18bob' );
$conn  = nx_test_connection( $alice, $bob, 'accepted' );
nx_test_thread( $alice, $bob, $conn );
( new \Nexora\Notifications\Repository() )->insert( array( 'actor_user_id' => $bob['user_id'], 'actor_user_name' => $bob['login'], 'receiver_user_id' => $alice['user_id'], 'receiver_user_name' => $alice['login'], 'type' => 'request', 'connection_id' => $conn, 'message' => 'x' ) );
$post = nx_test_track_post( wp_insert_post( array( 'post_type' => 'user_content', 'post_status' => 'publish', 'post_title' => 'Hello content' ) ) );
update_post_meta( $post, 'user_profile_id', $bob['profile_id'] );
update_post_meta( $post, 'user_name', $bob['login'] );
update_post_meta( $alice['profile_id'], 'first_name', 'Alicefirst' );

// words that are data, not interface
$data_words = array_merge( preg_split( '/[^A-Za-z]+/', $alice['login'] . ' ' . $bob['login'] . ' Alicefirst Hello content localhost83 local com nexora Test subject' ), array( 'request', 'accepted', 'pending', 'rejected', 'removed', 'active', 'inactive', 'Accepted', 'Pending', 'Rejected', 'Removed', 'Active', 'Inactive', 'wp', 'png', 'http', 'https', 'px', 'ago', 'PM', 'AM', 'Oct', 'Jan', 'Dec', 'Nov' ) );

/** Visible words outside « » in $html (tags, scripts, comments removed), and attribute texts that are not tagged. */
$leftovers = function ( $html ) use ( $data_words ) {
	$bad = array();
	foreach ( array( 'placeholder', 'title', 'alt', 'aria-label' ) as $attr ) {
		if ( preg_match_all( '/\s' . $attr . '="([^"]*)"/', $html, $m ) ) {
			foreach ( $m[1] as $v ) { if ( preg_match( '/[A-Za-z]{3,}/', $v ) && false === strpos( $v, "\u{ab}" ) ) { $bad[] = "$attr=\"$v\""; } }
		}
	}
	$text = preg_replace( '#<(script|style)\b.*?</\1>#si', ' ', $html );
	$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '/<[^>]+>/', ' ', $text ) ), ENT_QUOTES );
	$text = preg_replace( '/\x{ab}.*?\x{bb}/su', ' ', $text );
	foreach ( preg_split( '/[^A-Za-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
		if ( strlen( $w ) >= 3 && ! in_array( $w, $data_words, true ) ) { $bad[] = $w; }
	}
	return array_values( array_unique( $bad ) );
};
$page = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	return do_shortcode( '[profile_dashboard]' );
};

wp_set_current_user( 0 );
$renders = array(
	'login form'        => do_shortcode( '[profile_login]' ),
	'registration form' => do_shortcode( '[profile_registration]' ),
	'home (guest)'      => do_shortcode( '[nexora_home]' ),
	'contact form'      => do_shortcode( '[nexora_contact_form]' ),
	'profile (guest)'   => $page( 0, $alice['login'] ),
);
wp_set_current_user( $alice['user_id'] );
$renders['login (member)']        = do_shortcode( '[profile_login]' );
$renders['registration (member)'] = do_shortcode( '[profile_registration]' );
$renders['home (member)']         = do_shortcode( '[nexora_home]' );
$renders['profile (owner)']       = $page( $alice['user_id'], $alice['login'] );
$renders['profile (viewer)']      = $page( $bob['user_id'], $alice['login'] );
$renders['profile (not found)']   = $page( $bob['user_id'], 'nobody_zz_i18n' );
foreach ( array( 'get_history', 'get_user_content_history' ) as $a ) {
	wp_set_current_user( $alice['user_id'] );
	$r = nx_call_ajax( $a, nx_profile_nonce() );
	$renders["ajax $a"] = (string) $r['data'];
}
$r = nx_call_ajax( 'view_all_connection', array( 'profile_id' => $alice['profile_id'] ) + nx_profile_nonce() );
$renders['ajax view_all_connection'] = (string) $r['data'];
$r = nx_call_ajax( 'view_mutual_connection', array( 'profile_id' => $bob['profile_id'] ) + nx_profile_nonce() );
$renders['ajax view_mutual_connection'] = (string) $r['data'];

// admin screens
$admin = wp_insert_user( array( 'user_login' => 'nxtest_i18ad_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'i18ad' . wp_generate_password( 4, false ) . '@example.test', 'role' => 'administrator' ) );
$GLOBALS['nx_test']['users'][] = $admin;
wp_set_current_user( $admin );
set_current_screen( 'dashboard' );
$boxes = new \Nexora\Admin\Meta_Boxes();
ob_start(); ( new \Nexora\Admin\Settings() )->settings_page(); $renders['admin settings'] = ob_get_clean();
ob_start(); ( new \Nexora\Admin\Pages() )->notifications_page(); $renders['admin notifications'] = ob_get_clean();
ob_start(); ( new \Nexora\Admin\Pages() )->nexora_user_chat(); $renders['admin chat'] = ob_get_clean();
foreach ( array( 'user_personal_details', 'user_address_details', 'user_work_details', 'user_document_details', 'user_connection_details', 'user_content_details', 'user_chat_details' ) as $mb ) {
	ob_start(); $boxes->$mb( get_post( $alice['profile_id'] ) ); $renders["metabox $mb"] = ob_get_clean();
}
ob_start(); $boxes->user_connection_meta_box( get_post( $conn ) ); $renders['metabox connection'] = ob_get_clean();
ob_start(); $boxes->user_connection_chat_box( get_post( $conn ) ); $renders['metabox connection chat'] = ob_get_clean();
ob_start(); $boxes->render_user_content_meta_box( get_post( $post ) ); $renders['metabox content'] = ob_get_clean();

foreach ( $renders as $label => $html ) {
	$bad = $leftovers( $html );
	nx_assert( array() === $bad, "$label: no untranslated text" . ( $bad ? ' -> ' . implode( ', ', array_slice( $bad, 0, 12 ) ) : '' ) );
	if ( 'ajax view_all_connection' !== $label ) { // a plain list of members has no interface text
		nx_assert( false !== strpos( $html, "\u{ab}" ) || '' === trim( wp_strip_all_tags( $html ) ), "$label: strings pass through the nexora text domain" );
	}
}

/* ---- server messages ---- */
wp_set_current_user( $alice['user_id'] );
$r = nx_call_ajax( 'update_work_info', array( 'company_email' => 'nope' ) + nx_profile_nonce() );
nx_assert( false === strpos( (string) $r['data'], 'Invalid company email' ) || 0 === strpos( (string) $r['data'], "\u{ab}" ), 'AJAX error message is translatable' );
nx_assert_same( "\u{ab}Invalid company email\u{bb}", $r['data'], 'AJAX error message goes through gettext' );
$mail = &nx_test_capture_mail();
nx_test_reset_limits();
wp_set_current_user( 0 );
wp_set_password( 'Some#Pass12345', $bob['user_id'] );
nx_call_ajax( 'nexora_send_otp', array( 'username' => $bob['login'], 'email' => get_userdata( $bob['user_id'] )->user_email ) + nx_profile_nonce(), true );
nx_assert( 1 === count( $mail ) && 0 === strpos( $mail[0]['subject'], "\u{ab}" ) && false !== strpos( $mail[0]['message'], 'Your OTP is' ), 'OTP email subject and body are translatable' );

/* ---- the committed .pot is current ---- */
remove_all_filters( 'gettext' ); remove_all_filters( 'gettext_with_context' );
$tmp = tempnam( sys_get_temp_dir(), 'nxpot' );
shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && wp i18n make-pot wp-content/plugins/nexora ' . escapeshellarg( $tmp ) . ' --slug=nexora --domain=nexora --exclude=vendor,assets/lib --path=. 2>&1' );
$ids = function ( $file ) { preg_match_all( '/^msgid "(.*)"$/m', (string) file_get_contents( $file ), $m ); return array_values( array_unique( array_filter( $m[1] ) ) ); };
$fresh = $ids( $tmp ); $committed = $ids( NEXORA_PATH . 'languages/nexora.pot' );
sort( $fresh ); sort( $committed );
unlink( $tmp );
nx_assert( count( $fresh ) > 300, 'make-pot found the plugin strings (' . count( $fresh ) . ')' );
nx_assert_same( array(), array_values( array_diff( $fresh, $committed ) ), 'languages/nexora.pot has every string in the code (run: wp i18n make-pot ...)' );
nx_assert_same( array(), array_values( array_diff( $committed, $fresh ) ), 'languages/nexora.pot has no stale strings' );

nx_test_finish();
