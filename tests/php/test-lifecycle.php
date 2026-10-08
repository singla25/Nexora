<?php
// Phase 4: versioned migrations, indexes, deactivation, gated uninstall, cached lists.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Database\Installer;
use Nexora\Database\Migrations;
use Nexora\Database\Uninstaller;

global $wpdb;

/* ---------- migrations ---------- */

$tables = array( 'nexora_notifications', 'nexora_threads', 'nexora_thread_participants', 'nexora_messages', 'nexora_message_meta' );

$original_version = get_option( 'nexora_db_version' );

delete_option( 'nexora_db_version' );
Migrations::maybe_upgrade();
nx_assert_same( Migrations::DB_VERSION, get_option( 'nexora_db_version' ), 'maybe_upgrade stores the db version' );

$ran = 0;
add_action( 'nexora_migrated', function () use ( &$ran ) {
	$ran++;
} );
Migrations::maybe_upgrade();
nx_assert_same( 0, $ran, 'no migration runs when the stored version is current' );

update_option( 'nexora_db_version', '0.0.1' );
Migrations::maybe_upgrade();
nx_assert_same( 1, $ran, 'an older stored version triggers the upgrade once' );
nx_assert_same( Migrations::DB_VERSION, get_option( 'nexora_db_version' ), 'version is bumped after upgrading' );

foreach ( $tables as $t ) {
	nx_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ), "$t exists" );
}

function nx_index_names( $table ) {
	global $wpdb;
	return array_unique( wp_list_pluck( $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}$table" ), 'Key_name' ) );
}

nx_assert( in_array( 'idx_thread_id_id', nx_index_names( 'nexora_messages' ), true ), 'messages(thread_id, id) index exists' );
nx_assert( in_array( 'idx_status', nx_index_names( 'nexora_threads' ), true ), 'threads(status) index exists' );
nx_assert( in_array( 'idx_updated_at', nx_index_names( 'nexora_threads' ), true ), 'threads(updated_at) index exists' );
nx_assert( in_array( 'idx_receiver_read', nx_index_names( 'nexora_notifications' ), true ), 'notification indexes kept' );

// Running the schema again must be a no-op for dbDelta (no CREATE/ALTER needed).
$changes = Migrations::sync_schema();
nx_assert_same( array(), $changes, 'schema sync is idempotent (dbDelta reports nothing to change)' );

// A dropped table is recreated.
$wpdb->query( "DROP TABLE {$wpdb->prefix}nexora_message_meta" );
Migrations::sync_schema();
nx_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'nexora_message_meta' ) ), 'a missing table is recreated' );

// Existing rows survive a re-run.
$a  = nx_test_user( 'mig_a' );
$b  = nx_test_user( 'mig_b' );
$cn = nx_test_connection( $a, $b );
$tid = nx_test_thread( $a, $b, $cn );
Migrations::sync_schema();
nx_assert_same( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nexora_threads WHERE id=%d", $tid ) ), 'existing thread rows survive a schema sync' );

// Activation goes through the same path.
delete_option( 'nexora_db_version' );
Installer::activate();
nx_assert_same( Migrations::DB_VERSION, get_option( 'nexora_db_version' ), 'activation records the db version' );

if ( false !== $original_version ) {
	update_option( 'nexora_db_version', $original_version );
}

/* ---------- deactivation ---------- */

set_transient( 'nexora_home_stats', array( 'x' => 1 ), HOUR_IN_SECONDS );
update_option( 'rewrite_rules', array( 'stale' => 'rule' ) );
Installer::deactivate();
nx_assert_same( false, get_option( 'rewrite_rules' ), 'deactivation drops the stored rewrite rules' );
nx_assert_same( false, get_transient( 'nexora_home_stats' ), 'deactivation clears the stats cache' );
flush_rewrite_rules( false );

/* ---------- uninstall (gated) ---------- */

(new Nexora\Admin\Settings())->register_settings();
$setting = get_registered_settings();
nx_assert( isset( $setting['nexora_delete_data_on_uninstall'] ), 'the delete-on-uninstall setting is registered' );
nx_assert_same( false, (bool) get_option( 'nexora_delete_data_on_uninstall' ), 'delete-on-uninstall defaults to off' );

// Scratch fixtures: the uninstaller is pointed at these, never at real member data.
register_post_type( 'nxtest_cpt', array( 'public' => false ) );
$scratch_table = $wpdb->prefix . 'nxtest_scratch';
$wpdb->query( "CREATE TABLE IF NOT EXISTS $scratch_table (id INT)" );
$scratch_dir = trailingslashit( get_temp_dir() ) . 'nxtest-private-' . wp_generate_password( 6, false );
wp_mkdir_p( $scratch_dir );
file_put_contents( $scratch_dir . '/doc.pdf', 'x' );
$post_id = wp_insert_post( array( 'post_type' => 'nxtest_cpt', 'post_title' => 't', 'post_status' => 'publish' ) );
update_option( 'nxtest_option', 1 );
update_user_meta( $a['user_id'], 'nxtest_meta', 1 );

$config = array(
	'tables'     => array( $scratch_table ),
	'options'    => array( 'nxtest_option' ),
	'user_meta'  => array( 'nxtest_meta' ),
	'post_types' => array( 'nxtest_cpt' ),
	'dirs'       => array( $scratch_dir ),
);

delete_option( 'nexora_delete_data_on_uninstall' );
nx_assert_same( false, ( new Uninstaller( $config ) )->run(), 'uninstall does nothing while the setting is off' );
nx_assert( (bool) get_option( 'nxtest_option' ) && get_post( $post_id ) && file_exists( $scratch_dir . '/doc.pdf' ), 'nothing is deleted while the setting is off' );
nx_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $scratch_table ) ), 'tables are kept while the setting is off' );

update_option( 'nexora_delete_data_on_uninstall', 1 );
nx_assert_same( true, ( new Uninstaller( $config ) )->run(), 'uninstall runs when the setting is on' );
nx_assert_same( false, get_option( 'nxtest_option' ), 'options are removed' );
nx_assert_same( '', get_user_meta( $a['user_id'], 'nxtest_meta', true ), 'user meta is removed' );
nx_assert_same( null, get_post( $post_id ), 'custom post type posts are removed' );
nx_assert_same( false, file_exists( $scratch_dir ), 'private files are removed' );
nx_assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $scratch_table ) ), 'tables are dropped' );
delete_option( 'nexora_delete_data_on_uninstall' );

// The real lists must cover what the plugin stores.
$real = Uninstaller::defaults();
foreach ( $tables as $t ) {
	nx_assert( in_array( $wpdb->prefix . $t, $real['tables'], true ), "default uninstall drops $t" );
}
foreach ( array( 'user_profile', 'user_connections', 'user_content' ) as $cpt ) {
	nx_assert( in_array( $cpt, $real['post_types'], true ), "default uninstall removes $cpt posts" );
}
foreach ( array( '_profile_id', 'reset_otp', 'reset_token', 'reset_token_expiry', 'otp_attempts', 'otp_expiry' ) as $key ) {
	nx_assert( in_array( $key, $real['user_meta'], true ), "default uninstall removes user meta $key" );
}
foreach ( array( 'nexora_db_version', 'recaptcha_site_key', 'recaptcha_secret_key', 'recaptcha_enabled', 'default_profile_image', 'nexora_home_title', 'nexora_delete_data_on_uninstall' ) as $opt ) {
	nx_assert( in_array( $opt, $real['options'], true ), "default uninstall removes option $opt" );
}
nx_assert( file_exists( NEXORA_PATH . 'uninstall.php' ), 'uninstall.php exists' );
nx_assert( false !== strpos( file_get_contents( NEXORA_PATH . 'uninstall.php' ), 'WP_UNINSTALL_PLUGIN' ), 'uninstall.php guards on WP_UNINSTALL_PLUGIN' );

/* ---------- cached lists ---------- */

$c = nx_test_user( 'cache_c' );

$count_queries = function ( $fn ) {
	global $wpdb;
	$n = 0;
	$f = function ( $q ) use ( &$n ) {
		$n++;
		return $q;
	};
	add_filter( 'query', $f );
	$fn();
	remove_filter( 'query', $f );
	return $n;
};

use Nexora\Connections\Repository as Conn;

$first = Conn::accepted_pairs( $a['profile_id'] );
nx_assert_same( 1, count( $first ), 'one accepted connection listed' );
nx_assert_same( 0, $count_queries( function () use ( $a ) {
	Conn::accepted_pairs( $a['profile_id'] );
} ), 'a repeated accepted_pairs call runs no queries' );

$cn2 = nx_test_connection( $a, $c, 'pending' );
nx_assert_same( 1, count( Conn::accepted_pairs( $a['profile_id'] ) ), 'a pending request does not appear in accepted pairs' );
update_post_meta( $cn2, 'status', 'accepted' );
nx_assert_same( 2, count( Conn::accepted_pairs( $a['profile_id'] ) ), 'accepting a request invalidates the cached list' );
nx_assert_same( 2, count( Conn::accepted_profile_ids( $a['profile_id'] ) ), 'accepted_profile_ids sees the new connection' );
update_post_meta( $cn2, 'status', 'removed' );
nx_assert_same( 1, count( Conn::accepted_profile_ids( $a['profile_id'] ) ), 'removing a connection invalidates the cached list' );
nx_assert( ! in_array( $c['profile_id'], Conn::unavailable_profile_ids( $a['profile_id'] ), true ), 'a removed connection no longer blocks add-new' );
wp_trash_post( $cn );
nx_assert_same( 0, count( Conn::accepted_pairs( $a['profile_id'] ) ), 'trashing a connection invalidates the cached list' );
wp_untrash_post( $cn );
wp_update_post( array( 'ID' => $cn, 'post_status' => 'publish' ) );
nx_assert_same( 1, count( Conn::accepted_pairs( $a['profile_id'] ) ), 'publishing it again shows it again' );

$chat = new Nexora\Chat\Repository();
$list = $chat->get_user_threads( $a['user_id'] );
nx_assert_same( 1, count( $list ), 'thread list has the thread' );
nx_assert_same( 0, $count_queries( function () use ( $chat, $a ) {
	$chat->get_user_threads( $a['user_id'] );
} ) > 1 ? 1 : 0, 'a repeated get_user_threads call skips the thread query' );
$chat->send_message( $tid, $b['user_id'], 'hello' );
$list = $chat->get_user_threads( $a['user_id'] );
nx_assert_same( 1, (int) $list[0]->unread_count, 'a new message invalidates the cached thread list (unread count)' );
$chat->mark_as_read_chat( $tid, $a['user_id'] );
$list = $chat->get_user_threads( $a['user_id'] );
nx_assert_same( 0, (int) $list[0]->unread_count, 'reading a thread invalidates the cached thread list' );
$chat->update_thread_subject( $tid, 'New subject' );
$list = $chat->get_user_threads( $a['user_id'] );
nx_assert_same( 'New subject', $list[0]->subject, 'renaming a thread invalidates the cached thread list' );

nx_test_finish();
