<?php
// Marketplace Phase 3: Woo's own account and checkout sign-up routes must not bypass the Nexora front end.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Core\Urls;
use Nexora\Integrations\Woo_Routes;

if ( ! function_exists( 'wc_get_page_id' ) ) {
	echo "WooCommerce not active: skipped\n";
	nx_test_finish();
	return;
}

global $wpdb;

/* ---------- Woo's sign-up and login-at-checkout options are forced off ---------- */
$keys = array( 'woocommerce_enable_signup_and_login_from_checkout', 'woocommerce_enable_checkout_login_reminder', 'woocommerce_enable_myaccount_registration' );
$raw  = array();
foreach ( $keys as $k ) { $raw[ $k ] = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $k ) ); }
foreach ( $keys as $k ) {
	update_option( $k, 'yes' );
	nx_assert_same( 'no', get_option( $k ), "$k reads as 'no' even when the database says yes" );
}
nx_assert( false === WC()->checkout()->is_registration_enabled(), 'Woo reports checkout registration as disabled' );
nx_assert( 'no' === get_option( 'woocommerce_enable_checkout_login_reminder' ), 'the login reminder at checkout is off' );
foreach ( $keys as $k ) {
	if ( null === $raw[ $k ] ) { delete_option( $k ); } else { $wpdb->update( $wpdb->options, array( 'option_value' => $raw[ $k ] ), array( 'option_name' => $k ) ); }
}
wp_cache_flush();
foreach ( $keys as $k ) {
	nx_assert_same( $raw[ $k ], $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $k ) ), "the test restored the real stored value of $k" );
}

/* ---------- /my-account/ ---------- */
$guest  = 0;
$member = nx_test_user( 'wrmember' );
$vend   = nx_test_user( 'wrvendor' );
Account_Types::set_status( $vend['user_id'], 'pending' );
$susp = nx_test_user( 'wrsusp' );
foreach ( array( 'pending', 'approved', 'suspended' ) as $s ) { Account_Types::set_status( $susp['user_id'], $s ); }
$admin = nx_test_admin();

$visit = function ( $uid, $page_id, $endpoint = '' ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'page_id' => $page_id ) );
	if ( $GLOBALS['wp_query']->have_posts() ) { $GLOBALS['wp_query']->the_post(); $GLOBALS['post'] = $GLOBALS['wp_query']->post; }
	if ( $endpoint ) { set_query_var( $endpoint, '' ); $GLOBALS['wp']->query_vars[ $endpoint ] = ''; }
	$throw = function ( $location ) { throw new RuntimeException( $location ); };
	add_filter( 'wp_redirect', $throw, 1 );
	$out = 'none';
	try { Woo_Routes::redirect_account_page(); } catch ( RuntimeException $e ) { $out = $e->getMessage(); }
	remove_filter( 'wp_redirect', $throw, 1 );
	unset( $GLOBALS['wp']->query_vars['orders'], $GLOBALS['wp']->query_vars['lost-password'], $GLOBALS['wp']->query_vars['customer-logout'] );
	wp_set_current_user( 0 );
	return $out;
};
$account = wc_get_page_id( 'myaccount' );
nx_assert( $account > 0, 'setup: the My account page exists' );

nx_assert_same( Urls::login(), $visit( $guest, $account ), '/my-account/ as a guest goes to the Nexora login' );
foreach ( array( 'orders', 'lost-password', 'customer-logout' ) as $endpoint ) {
	nx_assert_same( Urls::login(), $visit( $guest, $account, $endpoint ), "/my-account/$endpoint as a guest goes to the Nexora login" );
}
nx_assert_same( Urls::profile( $member['login'] ), $visit( $member['user_id'], $account ), '/my-account/ as a customer goes to their Nexora profile' );
nx_assert_same( Urls::profile( $member['login'] ), $visit( $member['user_id'], $account, 'orders' ), '/my-account/orders as a customer goes to their profile' );
nx_assert_same( Urls::profile( $vend['login'] ), $visit( $vend['user_id'], $account ), '/my-account/ as a pending vendor goes to their profile' );
nx_assert_same( Urls::profile( $susp['login'] ), $visit( $susp['user_id'], $account ), '/my-account/ as a suspended vendor goes to their profile' );
nx_assert_same( 'none', $visit( $admin, $account ), '/my-account/ as an administrator is left alone' );

foreach ( array( 'shop' => wc_get_page_id( 'shop' ), 'cart' => wc_get_page_id( 'cart' ), 'checkout' => wc_get_page_id( 'checkout' ) ) as $label => $id ) {
	foreach ( array( 'guest' => $guest, 'member' => $member['user_id'] ) as $who => $uid ) {
		nx_assert_same( 'none', $visit( $uid, $id ), "the $label page is not touched for a $who (Phase 6 gates the cart and checkout)" );
	}
}
$other = get_page_by_path( 'sample-page' );
if ( $other ) { nx_assert_same( 'none', $visit( $member['user_id'], $other->ID ), 'an ordinary page is not touched' ); }

$src = file_get_contents( NEXORA_PATH . 'src/Integrations/Woo_Routes.php' );
nx_assert( false !== strpos( $src, "function_exists( 'is_account_page' )" ), 'the redirect does nothing when WooCommerce is not active' );
nx_assert( false !== has_filter( 'woocommerce_prevent_admin_access', array( 'Nexora\\Integrations\\Woo_Routes', 'allow_ajax_requests' ) ), 'WooCommerce\'s admin lock-down is told to leave AJAX requests (media uploads) alone' );
nx_assert( false !== has_action( 'template_redirect', array( 'Nexora\\Integrations\\Woo_Routes', 'redirect_account_page' ) ), 'the redirect is hooked to template_redirect' );

nx_test_finish();
