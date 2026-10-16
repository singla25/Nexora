<?php
/**
 * Minimal test helpers, loaded inside WordPress by `wp eval-file` (see tests/run.sh).
 * No PHPUnit/Composer in this repo. Tests run against the local dev database, so
 * every test must create its own fixtures via nx_test_user()/nx_test_cleanup()
 * and never touch real members' data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// CLI has no headers to send; keep auth-cookie calls from emitting warnings.
add_filter( 'send_auth_cookies', '__return_false' );

// After an uncaught exception or fatal error WordPress' own handler ends the request with wp_die() inside a shutdown function, and that
// would stop the isolation restore (a later shutdown function) from running. While a fatal error is being handled, wp_die() does
// nothing so the remaining shutdown functions run; in every other situation wp_die() behaves as usual.
add_filter(
	'wp_die_handler',
	function ( $handler ) {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return function () {};
		}
		return $handler;
	},
	1000
);

$GLOBALS['nx_test'] = array( 'pass' => 0, 'fail' => 0, 'users' => array(), 'posts' => array(), 'threads' => array(), 'mail' => array(), 'orders' => array(), 'opt_snap' => array(), 'ephemera' => null, 'ephemera_aside' => false, 'item_scope' => null );

/* ------------------------------------------------------------------
 * Test isolation. A test may only change or delete what it created itself. Anything of the SITE'S that a test needs to
 * control (a saved setting, the Marketplace Item, rate-limit counters, API caches...) goes through the helpers below: they
 * remember the exact raw database rows, set known values, and put the originals back byte for byte, also when the test
 * fails, throws, dies or exits (the restore is a shutdown function as well as part of nx_test_finish()).
 * tests/php/test-isolation-lint.php fails when a test file bypasses them; tests/guard.sh proves it end to end.
 * ---------------------------------------------------------------- */

/** The raw row of an option, array( 'value' => ..., 'autoload' => ... ), or null when it does not exist. No filters, no cache. */
function nx_test_raw_option( $name ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	return $row ? array( 'value' => $row['option_value'], 'autoload' => $row['autoload'] ) : null;
}

/** Writes (or removes, for null) the raw option row and forgets WordPress' cached copy. */
function nx_test_write_raw_option( $name, $row ) {
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	if ( null !== $row ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['value'], 'autoload' => $row['autoload'] ) );
	}
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}

/**
 * Sets known values for site options for the rest of the test; null = the option does not exist. The first time a name is
 * scoped its original raw row is remembered; nx_test_options_restore() (called by nx_test_finish() and at shutdown) puts it back.
 * Values are written as given (arrays are serialised); no sanitize callback or filter runs.
 */
function nx_test_options( array $set ) {
	static $hooked = false;
	if ( ! $hooked ) {
		register_shutdown_function( 'nx_test_options_restore' );
		$hooked = true;
	}
	foreach ( $set as $name => $value ) {
		if ( ! array_key_exists( $name, $GLOBALS['nx_test']['opt_snap'] ) ) {
			$GLOBALS['nx_test']['opt_snap'][ $name ] = nx_test_raw_option( $name );
		}
		nx_test_write_raw_option( $name, null === $value ? null : array( 'value' => maybe_serialize( $value ), 'autoload' => 'auto' ) );
	}
}

/**
 * Remembers the exact raw rows of options WITHOUT changing them, for a test that edits them itself (update_option, a role change...).
 * The restore puts the originals back, so the edits never outlive the test.
 */
function nx_test_options_keep( array $names ) {
	foreach ( $names as $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['nx_test']['opt_snap'] ) ) {
			$GLOBALS['nx_test']['opt_snap'][ $name ] = nx_test_raw_option( $name );
		}
	}
	nx_test_options( array() ); // registers the shutdown restore
}

/**
 * Known default settings for a test whose output depends on the site's configuration: no API sources saved (so the built-in list),
 * vendor APIs off, no host allow-list, the default product categories, delete-on-uninstall off, commission at its default of 10%. $override changes single values.
 * The site's own values are put back at the end.
 */
function nx_test_default_settings( array $override = array() ) {
	nx_test_options(
		$override + array(
			'nexora_api_sources'               => null,
			'nexora_vendor_apis_enabled'       => null,
			'nexora_api_host_allowlist'        => null,
			'nexora_product_categories'        => null,
			'nexora_delete_data_on_uninstall'  => null,
			'nexora_commission_rate'           => null,
		)
	);
}

/** Puts every scoped option back exactly as it was. Safe to call more than once. */
function nx_test_options_restore() {
	global $wpdb;
	$roles_changed = array_key_exists( $wpdb->prefix . 'user_roles', $GLOBALS['nx_test']['opt_snap'] );
	foreach ( $GLOBALS['nx_test']['opt_snap'] as $name => $row ) {
		nx_test_write_raw_option( $name, $row );
	}
	$GLOBALS['nx_test']['opt_snap'] = array();
	if ( $roles_changed && function_exists( 'wp_roles' ) ) {
		wp_roles()->for_site(); // re-read the role list from the restored option
	}
	nx_test_ephemera_restore();
	nx_test_marketplace_item_scope_restore();
}

/**
 * Ephemeral rows (rate-limit counters, API page caches, import previews, locks, OTP references) belong to whoever is using the
 * site. nx_test_ephemera_begin() sets the ones that exist aside (when $set_aside) so the test sees a clean slate; the matching
 * restore removes what the run created and puts the originals back. Only the run's own rows are ever deleted.
 */
function nx_test_ephemera_patterns() {
	return array( '\\_transient\\_nx\\_%', '\\_transient\\_timeout\\_nx\\_%', 'nexora\\_rl\\_%', '\\_transient\\_nexora\\_api\\_%', '\\_transient\\_timeout\\_nexora\\_api\\_%', '\\_transient\\_nexora\\_import\\_%', '\\_transient\\_timeout\\_nexora\\_import\\_%', 'nexora\\_lock\\_%', 'nexora\\_vendor\\_lock\\_%', '\\_transient\\_nexora\\_otp%', '\\_transient\\_timeout\\_nexora\\_otp%' );
}

function nx_test_ephemera_names() {
	global $wpdb;
	$names = array();
	foreach ( nx_test_ephemera_patterns() as $like ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $n ) {
			$names[ $n ] = true;
		}
	}
	return $names;
}

function nx_test_ephemera_begin( $set_aside = false ) {
	static $hooked = false;
	if ( ! $hooked ) {
		register_shutdown_function( 'nx_test_ephemera_restore' );
		$hooked = true;
	}
	if ( null === $GLOBALS['nx_test']['ephemera'] ) {
		$kept = array();
		foreach ( array_keys( nx_test_ephemera_names() ) as $name ) {
			$kept[ $name ] = nx_test_raw_option( $name );
		}
		$GLOBALS['nx_test']['ephemera'] = $kept;
		$GLOBALS['nx_test']['ephemera_aside'] = false;
	}
	// Set aside (once) the rows the site already has, so the test sees a clean slate. They are put back by the restore.
	if ( $set_aside && empty( $GLOBALS['nx_test']['ephemera_aside'] ) ) {
		foreach ( array_keys( $GLOBALS['nx_test']['ephemera'] ) as $name ) {
			nx_test_write_raw_option( $name, null );
		}
		$GLOBALS['nx_test']['ephemera_aside'] = true;
	}
}

function nx_test_ephemera_restore() {
	if ( null === $GLOBALS['nx_test']['ephemera'] ) {
		return;
	}
	$kept = $GLOBALS['nx_test']['ephemera'];
	$GLOBALS['nx_test']['ephemera'] = null;
	$GLOBALS['nx_test']['ephemera_aside'] = false;
	foreach ( array_keys( nx_test_ephemera_names() ) as $name ) {
		if ( ! array_key_exists( $name, $kept ) ) {
			nx_test_write_raw_option( $name, null ); // made by this run
		}
	}
	foreach ( $kept as $name => $row ) {
		nx_test_write_raw_option( $name, $row );
	}
}

/** Ids of the Marketplace Items made inside nx_test_marketplace_item_scope() (every flagged item except the real ones set aside). */
function nx_test_marketplace_item_scratch_ids() {
	global $wpdb;
	if ( null === $GLOBALS['nx_test']['item_scope'] ) {
		return array();
	}
	$real = array_map( 'intval', array_column( $GLOBALS['nx_test']['item_scope']['meta'], 'post_id' ) );
	$all  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", \Nexora\Marketplace\Marketplace_Item::META ) ) );
	return array_values( array_diff( $all, $real ) );
}

/** Clears the API page caches the test itself made (and, via the begin snapshot, nothing else). */
function nx_test_flush_api_cache() {
	global $wpdb;
	nx_test_ephemera_begin( true );
	foreach ( array( '\\_transient\\_nexora\\_api\\_%', '\\_transient\\_timeout\\_nexora\\_api\\_%' ) as $like ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) {
			if ( ! array_key_exists( $name, (array) $GLOBALS['nx_test']['ephemera'] ) ) {
				nx_test_write_raw_option( $name, null );
			}
		}
	}
	wp_cache_flush();
}

/**
 * The site has ONE Marketplace Item and real orders point at it. A test that needs the item (or its absence) works on a scratch
 * item instead: the real one is un-flagged for the duration (only its flag meta row and the option are set aside), the test's
 * Marketplace_Item::id() then makes a scratch item, and the restore removes every scratch item and puts the real one's meta row
 * (same meta id) and the option back. The real product post is never edited or deleted.
 */
function nx_test_marketplace_item_scope() {
	global $wpdb;
	if ( null !== $GLOBALS['nx_test']['item_scope'] || ! class_exists( '\Nexora\Marketplace\Marketplace_Item' ) ) {
		return;
	}
	static $hooked = false;
	if ( ! $hooked ) {
		register_shutdown_function( 'nx_test_marketplace_item_scope_restore' );
		$hooked = true;
	}
	$meta = \Nexora\Marketplace\Marketplace_Item::META;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta ), ARRAY_A );
	$GLOBALS['nx_test']['item_scope'] = array(
		'meta'   => $rows,
		'option' => nx_test_raw_option( \Nexora\Marketplace\Marketplace_Item::OPTION ),
	);
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta ) );
	nx_test_write_raw_option( \Nexora\Marketplace\Marketplace_Item::OPTION, null );
	foreach ( $rows as $r ) {
		clean_post_cache( (int) $r['post_id'] );
		wp_cache_delete( (int) $r['post_id'], 'post_meta' );
	}
	wp_cache_flush();
}

function nx_test_marketplace_item_scope_restore() {
	global $wpdb;
	if ( null === $GLOBALS['nx_test']['item_scope'] ) {
		return;
	}
	$scope = $GLOBALS['nx_test']['item_scope'];
	$GLOBALS['nx_test']['item_scope'] = null;
	$meta = \Nexora\Marketplace\Marketplace_Item::META;
	$real = array_map( 'intval', array_column( $scope['meta'], 'post_id' ) );

	// Scratch items: everything flagged now that was not flagged before. Healing is switched off while they are removed.
	remove_action( 'deleted_post', array( '\Nexora\Marketplace\Marketplace_Item', 'after_delete' ) );
	nx_test_write_raw_option( \Nexora\Marketplace\Marketplace_Item::OPTION, null );
	foreach ( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta ) ) ) as $id ) {
		if ( ! in_array( $id, $real, true ) ) {
			wp_delete_post( $id, true );
		}
	}
	add_action( 'deleted_post', array( '\Nexora\Marketplace\Marketplace_Item', 'after_delete' ) );

	foreach ( $scope['meta'] as $r ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_id' => $r['meta_id'] ) );
		$wpdb->insert( $wpdb->postmeta, array( 'meta_id' => $r['meta_id'], 'post_id' => $r['post_id'], 'meta_key' => $r['meta_key'], 'meta_value' => $r['meta_value'] ) );
		clean_post_cache( (int) $r['post_id'] );
		wp_cache_delete( (int) $r['post_id'], 'post_meta' );
	}
	nx_test_write_raw_option( \Nexora\Marketplace\Marketplace_Item::OPTION, $scope['option'] );
	wp_cache_flush();
}

/* ------------------------------------------------------------------
 * Order fixtures (Phase 6b). nx_test_order() builds a REAL WooCommerce order the way checkout does: the buyer's cart (through
 * Nexora's own Cart_Bridge::add), WC_Checkout::create_order() (which fires woocommerce_checkout_create_order_line_item), then the
 * woocommerce_checkout_order_processed hook, then the gateway's status. The order is tracked and deleted by nx_test_cleanup(); only
 * orders made here are ever deleted.
 * ---------------------------------------------------------------- */

/**
 * @param array $buyer nx_test_user() / nx_test_vendor() array.
 * @param array $lines array( array( product post id, quantity ), ... ).
 * @param array $opts  payment (bacs|cod), status (set after placing; default on-hold for bacs, processing for cod), place (false = do not
 *                     fire the order-processed hook), billing (array of overrides).
 * @return array order (WC_Order|null), error (string, '' when placed), id (int).
 */
function nx_test_delete_orders() {
	foreach ( $GLOBALS['nx_test']['orders'] as $oid ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : null;
		if ( $o ) {
			$o->delete( true );
		}
	}
	$GLOBALS['nx_test']['orders'] = array();
}

function nx_test_order( array $buyer, array $lines, array $opts = array() ) {

	static $hooked = false;
	if ( ! $hooked ) {
		register_shutdown_function( 'nx_test_delete_orders' ); // also when the run dies
		$hooked = true;
	}

	$opts = $opts + array( 'payment' => 'bacs', 'status' => null, 'place' => true, 'billing' => array() );
	$was  = get_current_user_id();
	$out  = array( 'order' => null, 'error' => '', 'id' => 0 );

	wp_set_current_user( $buyer['user_id'] );
	WC()->initialize_session();
	WC()->initialize_cart();
	WC()->cart->empty_cart( true );

	foreach ( $lines as $line ) {
		$added = \Nexora\Marketplace\Cart_Bridge::add( $buyer['user_id'], $line[0], $line[1] );
		if ( is_wp_error( $added ) ) {
			$out['error'] = $added->get_error_message();
			WC()->cart->empty_cart( true );
			wp_set_current_user( $was );
			return $out;
		}
	}

	WC()->cart->calculate_totals();

	$data = $opts['billing'] + array(
		'payment_method' => $opts['payment'],
		'billing_first_name' => 'Bob', 'billing_last_name' => 'Billing', 'billing_email' => 'bob.billing@example.test', 'billing_phone' => '9800000001',
		'billing_address_1' => '9 Billing Road', 'billing_city' => 'Mumbai', 'billing_state' => 'MH', 'billing_postcode' => '400001', 'billing_country' => 'IN',
		'shipping_first_name' => 'Sia', 'shipping_last_name' => 'Shipping', 'shipping_address_1' => '1 Ship Street', 'shipping_city' => 'Delhi',
		'shipping_state' => 'DL', 'shipping_postcode' => '110001', 'shipping_country' => 'IN', 'ship_to_different_address' => true, 'order_comments' => '',
	);

	$order_id = WC()->checkout()->create_order( $data );

	if ( is_wp_error( $order_id ) ) {
		$out['error'] = $order_id->get_error_message();
		WC()->cart->empty_cart( true );
		wp_set_current_user( $was );
		return $out;
	}

	$GLOBALS['nx_test']['orders'][] = (int) $order_id;
	$order                          = wc_get_order( $order_id );
	$out['id']                      = (int) $order_id;

	if ( $opts['place'] ) {
		try {
			do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );
		} catch ( \Throwable $e ) {
			$out['error'] = $e->getMessage();
		}
		$order = wc_get_order( $order_id );
	}

	if ( '' === $out['error'] ) {
		$status = $opts['status'] ? $opts['status'] : ( 'cod' === $opts['payment'] ? 'processing' : 'on-hold' );
		$order->update_status( $status );
		$order = wc_get_order( $order_id );
	}

	WC()->cart->empty_cart( true );
	wp_set_current_user( $was );
	$out['order'] = $order;

	return $out;
}

/** A published, approved-vendor product for order tests. Returns the post id (cleaned up with the rest). */
function nx_test_product( array $vendor, $sku, $price = '100', $stock = '5', array $extra = array() ) {
	$d  = \Nexora\Products\Validator::validate( array_merge( array( 'name' => "Order Test $sku", 'description' => 'd', 'price' => $price, 'stock' => $stock, 'sku' => $sku, 'category' => 'other' ), $extra ) );
	$id = \Nexora\Products\Repository::create( $vendor['user_id'], $vendor['vendor_id'], $d['data'], 'manual' );
	$GLOBALS['nx_test']['posts'][] = $id;
	return $id;
}


function nx_assert( $cond, $label ) {
	$GLOBALS['nx_test'][ $cond ? 'pass' : 'fail' ]++;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
}

function nx_assert_same( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	nx_assert( $ok, $label . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

/** Create a throwaway WP user plus linked user_profile post (same links as registration). */
function nx_test_user( $name ) {
	$login = 'nxtest_' . $name . '_' . wp_generate_password( 4, false );
	$uid   = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test' ) );
	$pid   = wp_insert_post( array( 'post_type' => 'user_profile', 'post_status' => 'publish', 'post_title' => $login ) );
	update_post_meta( $pid, '_wp_user_id', $uid );
	update_post_meta( $pid, 'user_name', $login );
	update_user_meta( $uid, '_profile_id', $pid );
	$GLOBALS['nx_test']['users'][] = $uid;
	$GLOBALS['nx_test']['posts'][] = $pid;
	return array( 'user_id' => $uid, 'profile_id' => $pid, 'login' => $login );
}

/** A throwaway administrator (cleaned up with the rest). Returns its user ID. */
function nx_test_admin() {
	static $id = 0;
	if ( $id && get_userdata( $id ) ) { return $id; }
	$login = 'nxtest_adm_' . wp_generate_password( 5, false );
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'administrator' ) );
	$GLOBALS['nx_test']['users'][] = $id;
	return $id;
}

/**
 * A member with a vendor application in the given state: 'pending', 'approved' or 'suspended'. Returns the
 * nx_test_user() array plus 'vendor_id' (the application post) and 'gstin'. Cleaned up with the rest.
 */
function nx_test_vendor( $name, $state = 'approved', $gstin = null ) {
	static $n = 0;
	$n++;
	$u     = nx_test_user( $name );
	$gstin = $gstin ? $gstin : sprintf( '27ABCD%s%04dA1Z5', chr( 65 + ( $n % 26 ) ), wp_rand( 0, 9999 ) );
	$r     = \Nexora\Vendors\Service::apply( $u['user_id'], array(
		'business_name' => 'Test Shop ' . $name, 'business_type' => 'other', 'gstin' => $gstin, 'business_email' => $name . '@example.test',
		'business_phone' => '9811100000', 'business_address' => '1 Test Road', 'business_city' => 'Delhi', 'business_state' => 'Delhi', 'business_pincode' => '110001',
	) );
	if ( ! is_array( $r ) ) { throw new RuntimeException( 'nx_test_vendor: application failed: ' . ( is_wp_error( $r ) ? wp_json_encode( $r->get_error_data() ) : 'unknown' ) ); }
	$GLOBALS['nx_test']['posts'][] = $r['vendor_id'];
	$u['vendor_id'] = $r['vendor_id'];
	$u['gstin']     = $gstin;
	if ( 'pending' !== $state ) {
		$was = get_current_user_id();
		wp_set_current_user( nx_test_admin() );
		\Nexora\Vendors\Service::change_status( $r['vendor_id'], 'approve' );
		if ( 'suspended' === $state ) { \Nexora\Vendors\Service::change_status( $r['vendor_id'], 'suspend' ); }
		wp_set_current_user( $was );
	}
	return $u;
}

/** Track any extra post (connection, content) for cleanup. */
function nx_test_track_post( $post_id ) {
	$GLOBALS['nx_test']['posts'][] = $post_id;
	return $post_id;
}

/**
 * Invoke a wp_ajax_* handler in-process as the current user and return the decoded JSON reply.
 * wp_send_json_* ends with wp_die(); the filter below turns that into an exception.
 * $guest = true fires the wp_ajax_nopriv_* hook (call wp_set_current_user(0) first).
 * Returns array( 'success' => bool, 'data' => mixed ) or array( 'died' => message ).
 */
function nx_call_ajax( $action, array $post = array(), $guest = false ) {
	$_POST = $_REQUEST = array_merge( array( 'action' => $action ), $post );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$handler = function () {
		return function ( $message ) {
			throw new RuntimeException( is_scalar( $message ) ? (string) $message : 'died' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $handler, 99 );
	add_filter( 'wp_die_handler', $handler, 99 );
	ob_start();
	$died = null;
	try {
		do_action( ( $guest ? 'wp_ajax_nopriv_' : 'wp_ajax_' ) . $action );
	} catch ( RuntimeException $e ) {
		$died = $e->getMessage();
	}
	$raw = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $handler, 99 );
	remove_filter( 'wp_die_handler', $handler, 99 );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$json = json_decode( $raw, true );
	return is_array( $json ) ? $json : array( 'died' => $died, 'raw' => $raw );
}

/** Create a user_connections post between two nx_test_user() fixtures. */
function nx_test_connection( array $from, array $to, $status = 'accepted' ) {
	$id = nx_test_track_post( wp_insert_post( array( 'post_type' => 'user_connections', 'post_status' => 'publish', 'post_title' => $from['login'] . '->' . $to['login'] ) ) );
	update_post_meta( $id, 'sender_user_id', $from['user_id'] );
	update_post_meta( $id, 'sender_profile_id', $from['profile_id'] );
	update_post_meta( $id, 'sender_user_name', $from['login'] );
	update_post_meta( $id, 'receiver_user_id', $to['user_id'] );
	update_post_meta( $id, 'receiver_profile_id', $to['profile_id'] );
	update_post_meta( $id, 'receiver_user_name', $to['login'] );
	update_post_meta( $id, 'status', $status );
	return $id;
}

/** Create a real 1x1 PNG attachment (file + metadata) authored by $user_id; tracked for cleanup. */
function nx_test_attachment( $user_id, $title = 'nx-test-image' ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . 'nxtest-' . wp_generate_password( 8, false ) . '.png';
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
	$id = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $user_id ), $file );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	return nx_test_track_post( $id );
}

/** Create a chat thread for two fixtures; tracked for cleanup. */
function nx_test_thread( array $a, array $b, $connection_id, $status = 'active', $subject = 'Test subject' ) {
	$db  = new NEXORA_CHAT_DB();
	$tid = $db->create_thread( array( $a['user_id'], $b['user_id'] ), $connection_id, $status, 'private', $subject );
	$GLOBALS['nx_test']['threads'][] = $tid;
	return $tid;
}

/** Clear every rate-limit transient the plugin sets (nx_rl_*, nx_reg_*, nx_contact_*). */
function nx_test_reset_limits() {
	global $wpdb;
	// The first call sets aside whatever the site already has (counters, caches, previews, locks); after that everything matching
	// is this run's own, and is cleared here. The originals come back at the end (nx_test_ephemera_restore()).
	nx_test_ephemera_begin( true );
	foreach ( array( '\\_transient\\_nx\\_%', '\\_transient\\_timeout\\_nx\\_%', 'nexora\\_rl\\_%' ) as $like ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) {
			nx_test_write_raw_option( $name, null );
		}
	}
	wp_cache_flush();
}

/** Capture wp_mail() instead of sending. Returns a reference to the captured list. */
function &nx_test_capture_mail() {
	$GLOBALS['nx_test']['mail'] = array();
	add_filter( 'pre_wp_mail', function ( $null, $atts ) {
		$GLOBALS['nx_test']['mail'][] = $atts;
		return true;
	}, 10, 2 );
	return $GLOBALS['nx_test']['mail'];
}

/** Standard nonce payloads. */
function nx_profile_nonce() {
	return array( 'nonce' => wp_create_nonce( 'profile_nonce' ) );
}

function nx_chat_nonce() {
	return array( 'nonce' => wp_create_nonce( 'nexora_chat_nonce' ) );
}

/** True when an nx_call_ajax() reply is a rejection (died, or success=false). */
function nx_rejected( $reply ) {
	return isset( $reply['died'] ) || ( isset( $reply['success'] ) && false === $reply['success'] );
}

/**
 * Standard "who may call this" matrix: no nonce, wrong nonce, logged-out, valid.
 * $valid_post must be a complete valid payload WITHOUT the nonce.
 */
function nx_assert_guard_matrix( $action, array $valid_post, array $member, $nonce_fn = 'nx_profile_nonce' ) {
	wp_set_current_user( $member['user_id'] );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post ) ), "$action: no nonce rejected" );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post + array( 'nonce' => 'bad' ) ) ), "$action: wrong nonce rejected" );
	$nonce = $nonce_fn();
	wp_set_current_user( 0 );
	nx_assert( nx_rejected( nx_call_ajax( $action, $valid_post + $nonce ) ), "$action: logged-out rejected" );
	wp_set_current_user( $member['user_id'] );
}

/** Drop OTP reference transients (kept separate: resetting rate limits must not break a flow in progress). */
function nx_test_reset_otp_refs() {
	global $wpdb;
	nx_test_ephemera_begin( true );
	foreach ( array( '\\_transient\\_nexora\\_otp%', '\\_transient\\_timeout\\_nexora\\_otp%' ) as $like ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) {
			nx_test_write_raw_option( $name, null );
		}
	}
}

function nx_test_cleanup() {
	global $wpdb;
	nx_test_reset_otp_refs();
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $GLOBALS['nx_test']['threads'] ?? array() as $tid ) {
		foreach ( array( 'nexora_message_meta' => 'message_id IN (SELECT id FROM %1$snexora_messages WHERE thread_id=%2$d)', 'nexora_messages' => 'thread_id=%2$d', 'nexora_thread_participants' => 'thread_id=%2$d', 'nexora_threads' => 'id=%2$d' ) as $t => $where ) {
			$wpdb->query( sprintf( "DELETE FROM {$wpdb->prefix}$t WHERE " . $where, $wpdb->prefix, (int) $tid ) );
		}
	}
	// counters, import previews, mocked API pages and locks made by this run go; the ones the site had are put back
	nx_test_ephemera_restore();
	wp_set_current_user( 0 );
	nx_test_delete_orders();
	foreach ( $GLOBALS['nx_test']['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $GLOBALS['nx_test']['users'] as $id ) {
		$wpdb->delete( $wpdb->prefix . 'nexora_notifications', array( 'receiver_user_id' => $id ) );
		$wpdb->delete( $wpdb->prefix . 'nexora_notifications', array( 'actor_user_id' => $id ) );
		wp_delete_user( $id );
	}
}

function nx_test_finish() {
	nx_test_cleanup();
	nx_test_options_restore(); // scoped options, ephemera and the Marketplace Item scope: the site's originals, exactly
	$t = $GLOBALS['nx_test'];
	echo sprintf( "%d passed, %d failed\n", $t['pass'], $t['fail'] );
	if ( $t['fail'] ) {
		WP_CLI::halt( 1 );
	}
}


/* ------------------------------------------------------------------
 * Golden-output snapshots: pin the exact HTML a refactor must keep producing.
 * Run with NX_UPDATE_GOLDEN=1 to (re)write tests/golden/<name>.html.
 * Whitespace between tags and nonces (form fields and _wpnonce URL args) are normalised; nothing else is.
 * ---------------------------------------------------------------- */
function nx_golden_normalize( $html ) {
	$html = (string) $html;
	$html = preg_replace( '/(name="[\w-]*nonce[\w-]*"[^>]*value=")[a-f0-9]{10}/', '$1{NONCE}', $html );
	$html = preg_replace( '/(id="[\w-]*nonce[\w-]*"[^>]*value=")[a-f0-9]{10}/', '$1{NONCE}', $html );
	$html = preg_replace( '/([?&]|&#038;|&amp;)(_wpnonce=)[a-f0-9]{10}/', '$1$2{NONCE}', $html );
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '/>\s+</', '><', $html );
	return trim( $html );
}

function nx_assert_golden( $name, $html ) {
	$file = dirname( __DIR__ ) . '/tests/golden/' . $name . '.html';
	$norm = nx_golden_normalize( $html );
	if ( getenv( 'NX_UPDATE_GOLDEN' ) || ! file_exists( $file ) ) {
		if ( ! is_dir( dirname( $file ) ) ) { mkdir( dirname( $file ), 0775, true ); }
		file_put_contents( $file, $norm . "\n" );
		echo "  wrote golden: $name (" . strlen( $norm ) . " bytes)\n";
		nx_assert( strlen( $norm ) > 0, "golden $name is not empty" );
		return;
	}
	$expected = nx_golden_normalize( rtrim( file_get_contents( $file ), "\n" ) ); // Stored snapshots may predate nonce normalisation.
	$ok = ( $expected === $norm );
	if ( ! $ok ) {
		$i = 0; $n = min( strlen( $expected ), strlen( $norm ) );
		while ( $i < $n && $expected[ $i ] === $norm[ $i ] ) { $i++; }
		$detail = ' first difference at byte ' . $i . ': expected "' . substr( $expected, max( 0, $i - 40 ), 120 ) . '" got "' . substr( $norm, max( 0, $i - 40 ), 120 ) . '"';
		file_put_contents( sys_get_temp_dir() . '/nx-golden-' . $name . '.actual.html', $norm );
	}
	nx_assert( $ok, "golden output: $name" . ( $ok ? '' : $detail ) );
}

/* Every test starts from the same known settings, whatever the site has saved. The site's own values are put back at the end. */
if ( ! getenv( 'NX_TEST_KEEP_SETTINGS' ) ) {
	nx_test_default_settings();
}
// From the start, remember which rate-limit counters, caches, previews and locks the site already has: whatever the run creates (also
// through real web requests) is removed at the end, and the site's own are never deleted.
nx_test_ephemera_begin( false );
// The home-page numbers cache is set and cleared by some tests; the site's own cached copy is put back at the end.
nx_test_options_keep( array( '_transient_nexora_home_stats', '_transient_timeout_nexora_home_stats' ) );
