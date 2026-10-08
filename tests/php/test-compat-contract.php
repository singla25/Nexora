<?php
// COMPATIBILITY CONTRACT: names the live site and its data depend on. A refactor may move code
// anywhere, but every assertion here must keep passing (or be changed deliberately and listed
// as a user-visible change). Deprecated AJAX aliases must keep registering the OLD names too.
require_once __DIR__ . '/../bootstrap.php';

global $wpdb, $shortcode_tags;

// ---- AJAX actions: logged-in
$private = array(
	'update_personal_info', 'update_address_info', 'update_work_info', 'update_documents_info', 'update_profile_password',
	'get_add_new_users', 'send_connection_request', 'get_requests', 'update_connection_status', 'get_history',
	'view_all_connection', 'view_mutual_connection', 'mark_notification_read', 'save_user_content', 'get_user_content_history',
	'nexora_search_users', 'nexora_get_messages', 'nexora_get_user_threads', 'nexora_send_message',
	'nexora_create_thread_with_subject', 'nexora_get_thread_subject', 'nexora_update_subject',
	'nexora_get_latest_thread_between_users',
);
foreach ( $private as $a ) {
	nx_assert( (bool) has_action( "wp_ajax_$a" ), "ajax action registered: $a" );
	nx_assert( ! has_action( "wp_ajax_nopriv_$a" ), "ajax action is NOT open to guests: $a" );
}

// ---- AJAX actions: guests allowed (the only ones)
foreach ( array( 'profile_login', 'send_otp', 'verify_otp', 'reset_password', 'profile_register' ) as $a ) {
	nx_assert( (bool) has_action( "wp_ajax_$a" ) && (bool) has_action( "wp_ajax_nopriv_$a" ), "ajax action open to guests: $a" );
}
nx_assert( (bool) has_action( 'admin_post_nopriv_nexora_contact' ), 'contact form admin_post (guest) registered' );

// No other guest-reachable plugin actions may appear.
$guest_actions = array();
foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
	if ( 0 === strpos( $hook, 'wp_ajax_nopriv_' ) ) {
		$guest_actions[] = substr( $hook, strlen( 'wp_ajax_nopriv_' ) );
	}
}
$known_core = array( 'heartbeat', 'nopriv_heartbeat', 'generate-password', 'search-install-plugins' );
$unexpected = array();
foreach ( $guest_actions as $g ) {
	// [PHASE1e] the nexora_-prefixed twins of the five guest actions are the new canonical names
	if ( ! in_array( $g, array( 'profile_login', 'send_otp', 'verify_otp', 'reset_password', 'profile_register',
		'nexora_profile_login', 'nexora_send_otp', 'nexora_verify_otp', 'nexora_reset_password', 'nexora_profile_register' ), true ) ) {
		$unexpected[] = $g;
	}
}
$plugin_unexpected = array_filter( $unexpected, function ( $g ) {
	foreach ( $GLOBALS['wp_filter'][ 'wp_ajax_nopriv_' . $g ]->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && 0 === stripos( get_class( $cb['function'][0] ), 'nexora' ) ) {
				return true;
			}
		}
	}
	return false;
} );
nx_assert_same( array(), array_values( $plugin_unexpected ), 'no extra guest AJAX actions from Nexora classes' );

// ---- Shortcodes
foreach ( array( 'profile_registration', 'profile_login', 'profile_dashboard', 'nexora_home', 'nexora_stat', 'nexora_auth_buttons', 'nexora_contact_form' ) as $sc ) {
	nx_assert( isset( $shortcode_tags[ $sc ] ), "shortcode registered: [$sc]" );
}

// ---- Post types
foreach ( array( 'user_profile', 'user_connections', 'user_content' ) as $pt ) {
	$o = get_post_type_object( $pt );
	nx_assert( $o && ! $o->public && ! $o->show_in_rest, "CPT $pt exists, non-public, not in REST" );
}

// ---- Tables and columns
$expected = array(
	'nexora_notifications'       => array( 'id', 'actor_user_id', 'actor_user_name', 'receiver_user_id', 'receiver_user_name', 'type', 'connection_id', 'message', 'is_read', 'created_at' ),
	'nexora_threads'             => array( 'id', 'type', 'subject', 'last_message_id', 'created_at', 'updated_at', 'connection_id', 'status' ),
	'nexora_thread_participants' => array( 'id', 'thread_id', 'user_id', 'last_read', 'unread_count', 'is_muted', 'is_pinned', 'created_at' ),
	'nexora_messages'            => array( 'id', 'thread_id', 'sender_id', 'message', 'created_at' ),
	'nexora_message_meta'        => array( 'id', 'message_id', 'meta_key', 'meta_value' ),
);
foreach ( $expected as $table => $cols ) {
	$actual = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . $table );
	$missing = array_diff( $cols, $actual );
	nx_assert( empty( $missing ), "table $table has all columns" . ( $missing ? ' (missing: ' . implode( ',', $missing ) . ')' : '' ) );
}

// ---- Options registered by the settings screen
( new NEXORA_CPT() )->register_settings();
global $wp_registered_settings;
foreach ( array( 'default_profile_image', 'default_cover_image', 'default_document_image', 'default_home_cover_image', 'default_feed_experience_image',
	'default_real_time_chat_image', 'default_smart_connections_image', 'default_admin_mail', 'nexora_home_eyebrow', 'nexora_home_title',
	'nexora_home_subtitle', 'nexora_home_features', 'nexora_home_testimonials', 'recaptcha_site_key', 'recaptcha_secret_key', 'recaptcha_enabled' ) as $opt ) {
	nx_assert( isset( $wp_registered_settings[ $opt ] ), "option registered: $opt" );
}

// ---- Rewrite rule and required pages
$rules = get_option( 'rewrite_rules' );
nx_assert( isset( $rules['^profile-page/([^/]+)/?$'] ) && 'index.php?pagename=profile-page&username=$matches[1]' === $rules['^profile-page/([^/]+)/?$'], '/profile-page/<username> rewrite rule is flushed and unchanged' );
nx_assert( in_array( 'username', apply_filters( 'query_vars', array() ), true ), 'username query var registered' );
foreach ( array( 'login-page', 'profile-page', 'registration-page' ) as $slug ) {
	nx_assert( (bool) get_page_by_path( $slug ), "page exists: $slug" );
}

// ---- Constants other code relies on
nx_assert( defined( 'NEXORA_PATH' ) && defined( 'NEXORA_URL' ) && defined( 'NEXORA_VERSION' ), 'NEXORA_PATH/URL/VERSION defined' );
nx_assert( method_exists( 'NEXORA_System', 'enqueue_tokens' ), 'NEXORA_System::enqueue_tokens() exists (used by feature classes)' );
nx_assert( method_exists( 'Nexora_Home_Page', 'get_stats' ) && method_exists( 'Nexora_Home_Page', 'short_number' ), 'Nexora_Home_Page::get_stats()/short_number() exist (used by shortcodes)' );

nx_test_finish();
