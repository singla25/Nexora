<?php
// Marketplace Phase 9: the low-stock alert. A setting "Low-stock threshold" (whole number 0 to 100000, default 5, 0 = off). When a product's
// stock falls to the threshold or below, its seller gets one e-mail and one in-app notification; the alert is remembered per product and
// armed again only after the stock rises above the threshold. It runs after the atomic decrement at checkout and when stock is edited.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Saver;
use Nexora\Orders\Low_Stock;
use Nexora\Orders\Mailer;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
nx_test_options( array( 'nexora_low_stock_threshold' => null ) );
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$A = nx_test_vendor( 'lsa', 'approved' );
$C = nx_test_user( 'lsc' );
$stock = function ( $pid ) { clean_post_cache( $pid ); wp_cache_delete( $pid, 'post_meta' ); return (int) get_post_meta( $pid, 'stock', true ); };
$notes = function ( $uid, $type = 'low_stock' ) use ( $wpdb ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}nexora_notifications WHERE receiver_user_id = %d AND type = %s", $uid, $type ) ); };
$queued = function ( $pid ) { $n = 0; foreach ( _get_cron_array() as $hooks ) { foreach ( (array) ( $hooks[ Mailer::HOOK ] ?? array() ) as $ev ) { if ( isset( $ev['args'][0], $ev['args'][1] ) && 'low_stock' === $ev['args'][0] && (int) $ev['args'][1] === (int) $pid ) { $n++; } } } return $n; };
$buy = function ( $pid, $qty ) use ( $C ) { $r = nx_test_order( $C, array( array( $pid, $qty ) ), array( 'payment' => 'cod' ) ); if ( ! $r['order'] ) { throw new RuntimeException( $r['error'] ); } return $r['order']; };
$edit = function ( $pid, $stock ) { wp_set_current_user( nx_test_admin() ); return Saver::save( $pid, array( 'stock' => (string) $stock ) ); };

/* ---------- the setting ---------- */
nx_assert_same( 'nexora_low_stock_threshold', Low_Stock::OPTION, 'the option is nexora_low_stock_threshold' );
nx_assert_same( 5, Low_Stock::threshold(), 'the default is 5' );
foreach ( array( '0' => 0, '1' => 1, '5' => 5, '100000' => 100000, '  12 ' => 12 ) as $in => $want ) {
	nx_assert_same( (string) $want, Low_Stock::sanitize( $in ), "the form accepts '$in' (saved as $want)" );
}
update_option( Low_Stock::OPTION, '9' );
foreach ( array( '-1', 'abc', '100001', '5.5', '1e3', '', '0x10', array( '5' ), null ) as $bad ) {
	nx_assert_same( '9', Low_Stock::sanitize( $bad ), 'the form refuses ' . wp_json_encode( $bad ) . ' and keeps the saved value' );
}
foreach ( array( 'abc', '-3', '100001', '2.5', '' ) as $stored ) {
	update_option( Low_Stock::OPTION, $stored );
	nx_assert_same( 5, Low_Stock::threshold(), 'a bad stored value (' . wp_json_encode( $stored ) . ') reads as the default 5' );
}
update_option( Low_Stock::OPTION, '0' );
nx_assert_same( 0, Low_Stock::threshold(), '0 is a real value: alerts off' );
delete_option( Low_Stock::OPTION );
$register = file_get_contents( NEXORA_PATH . 'src/Admin/Settings.php' ) . file_get_contents( NEXORA_PATH . 'templates/admin/settings.php' );
nx_assert( false !== strpos( $register, 'Low_Stock::OPTION' ) && false !== strpos( $register, 'Low-stock threshold' ), 'the setting is registered with its sanitiser and has a field on Nexora System > Settings' );
nx_assert( in_array( Low_Stock::OPTION, \Nexora\Database\Uninstaller::defaults()['options'], true ), 'and is removed on uninstall (when the owner asked for it)' );

/* ---------- falling to the threshold alerts once ---------- */
$p = nx_test_product( $A, 'LS-1', '10.00', '10' );
$au = get_userdata( $A['user_id'] );
nx_assert( 0 === $notes( $A['user_id'] ) && 0 === $queued( $p ), 'setup: no alert yet' );
$buy( $p, 4 );
nx_assert_same( array( 6, 0, 0 ), array( $stock( $p ), $notes( $A['user_id'] ), $queued( $p ) ), 'stock 10 -> 6, above the threshold 5: no alert' );
$buy( $p, 1 );
nx_assert_same( array( 5, 1, 1 ), array( $stock( $p ), $notes( $A['user_id'] ), $queued( $p ) ), 'stock 6 -> 5 (= the threshold): one in-app notification and one queued e-mail' );
$buy( $p, 1 );
nx_assert_same( array( 4, 1, 1 ), array( $stock( $p ), $notes( $A['user_id'] ), $queued( $p ) ), 'stock 5 -> 4: no second alert for the same drop' );
$buy( $p, 4 );
nx_assert_same( array( 0, 1, 1 ), array( $stock( $p ), $notes( $A['user_id'] ), $queued( $p ) ), 'stock to 0: still the same drop, still one alert' );

// The mail itself.
$m = count( $GLOBALS['nx_test']['mail'] );
Mailer::run( 'low_stock', $p, 0 );
$mail = $GLOBALS['nx_test']['mail'][ $m ] ?? null;
nx_assert( null !== $mail && $au->user_email === ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ), 'the e-mail goes to the product\'s seller' );
nx_assert( false !== stripos( $mail['subject'], 'low stock' ) && false !== strpos( $mail['message'], 'LS-1' ) && false !== strpos( $mail['message'], 'Order Test LS-1' ), 'it names the product and SKU' );
foreach ( array( 'Bob', 'Billing', 'bob.billing', '9800000001', 'Ship Street', $C['login'] ) as $w ) { nx_assert( false === stripos( $mail['subject'] . $mail['message'], $w ), "no buyer detail ('$w') in it" ); }
$msg = $wpdb->get_var( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}nexora_notifications WHERE receiver_user_id = %d AND type = 'low_stock' ORDER BY id DESC LIMIT 1", $A['user_id'] ) );
nx_assert( false !== strpos( $msg, 'Order Test LS-1' ) && false === stripos( $msg, 'Bob' ), 'the in-app message names the product' );

/* ---------- re-arming: only after the stock rises above the threshold ---------- */
$edit( $p, 3 );
nx_assert_same( array( 3, 1, 1 ), array( $stock( $p ), $notes( $A['user_id'] ), $queued( $p ) ), 'restocked to 3 (still at or below 5): not re-armed, no new alert' );
$edit( $p, 20 );
$buy( $p, 14 );
nx_assert_same( array( 6, 1 ), array( $stock( $p ), $notes( $A['user_id'] ) ), 'restocked to 20, sold down to 6 (above 5): no alert' );
$buy( $p, 2 );
nx_assert_same( array( 4, 2 ), array( $stock( $p ), $notes( $A['user_id'] ) ), 'after restocking above the threshold, the next drop alerts again (a second alert)' );

/* ---------- editing the stock by hand (administrator) ---------- */
$q = nx_test_product( $A, 'LS-2', '10.00', '30' );
$n0 = $notes( $A['user_id'] );
$edit( $q, 2 );
nx_assert_same( array( 2, $n0 + 1 ), array( $stock( $q ), $notes( $A['user_id'] ) ), 'an administrator lowers the stock to 2: the seller is alerted' );
$edit( $q, 1 );
nx_assert_same( $n0 + 1, $notes( $A['user_id'] ), '... 2 -> 1: no repeat' );
$edit( $q, 9 );
$edit( $q, 5 );
nx_assert_same( $n0 + 2, $notes( $A['user_id'] ), 'raised above the threshold (9), then lowered to 5 (= threshold): alerted again' );
$edit( $q, 5 );
nx_assert_same( $n0 + 2, $notes( $A['user_id'] ), 'saving the same value again: nothing' );
$r = nx_test_product( $A, 'LS-3', '10.00', '2' );
nx_assert_same( $n0 + 2, $notes( $A['user_id'] ), 'a product created with a low stock is not a "drop": no alert at creation' );
$edit( $r, 4 );
nx_assert_same( $n0 + 2, $notes( $A['user_id'] ), 'raising a low stock (2 -> 4) never alerts' );
// The seller's own edit uses the same path.
$s = nx_test_product( $A, 'LS-4', '10.00', '30' );
$n1 = $notes( $A['user_id'] );
\Nexora\Products\Service::save( $A['user_id'], array( 'name' => 'Order Test LS-4', 'description' => 'd', 'price' => '10.00', 'stock' => '1', 'sku' => 'LS-4', 'category' => 'other' ), $s );
nx_assert_same( array( 1, $n1 + 1 ), array( $stock( $s ), $notes( $A['user_id'] ) ), 'the seller lowering their own stock through the product form alerts too' );

/* ---------- 0 turns it off; a bad setting means 5 ---------- */
nx_test_options( array( 'nexora_low_stock_threshold' => '0' ) );
$z = nx_test_product( $A, 'LS-5', '10.00', '10' );
$n2 = $notes( $A['user_id'] ); $buy( $z, 10 );
nx_assert_same( array( 0, $n2, 0 ), array( $stock( $z ), $notes( $A['user_id'] ), $queued( $z ) ), 'threshold 0: even a stock of 0 sends nothing' );
nx_test_options( array( 'nexora_low_stock_threshold' => 'nonsense' ) );
$y = nx_test_product( $A, 'LS-6', '10.00', '8' );
$n3 = $notes( $A['user_id'] ); $buy( $y, 3 );
nx_assert_same( array( 5, $n3 + 1 ), array( $stock( $y ), $notes( $A['user_id'] ) ), 'a nonsense stored setting counts as the default 5' );
nx_test_options( array( 'nexora_low_stock_threshold' => '20' ) );
$x = nx_test_product( $A, 'LS-7', '10.00', '25' );
$n4 = $notes( $A['user_id'] ); $buy( $x, 5 );
nx_assert_same( array( 20, $n4 + 1 ), array( $stock( $x ), $notes( $A['user_id'] ) ), 'a threshold of 20 alerts at 20' );
nx_test_options( array( 'nexora_low_stock_threshold' => null ) );

/* ---------- failure is harmless; the order and the atomic decrement are unaffected ---------- */
$boom = function () { throw new RuntimeException( 'mail down' ); };
add_filter( 'pre_wp_mail', $boom, 5 );
$w = nx_test_product( $A, 'LS-8', '10.00', '6' );
$threw = false; try { $buy( $w, 3 ); Mailer::run( 'low_stock', $w, 0 ); } catch ( \Throwable $e ) { $threw = true; }
remove_filter( 'pre_wp_mail', $boom, 5 );
nx_assert( ! $threw && 3 === $stock( $w ), 'a broken mail layer does not stop the order or the stock decrement' );
$last = nx_test_product( $A, 'LS-9', '10.00', '1' );
$buy( $last, 1 );
$again = nx_test_order( $C, array( array( $last, 1 ) ), array( 'payment' => 'cod' ) );
nx_assert( ! $again['order'] || '' !== $again['error'] || 0 === $stock( $last ), 'two buyers still cannot both get the last unit' );
nx_assert_same( 0, $stock( $last ), '(the stock never goes below 0)' );

/* ---------- jobs for things that do not exist ---------- */
$m5 = count( $GLOBALS['nx_test']['mail'] );
Mailer::run( 'low_stock', 0, 0 ); Mailer::run( 'low_stock', 999999999, 0 );
nx_assert_same( $m5, count( $GLOBALS['nx_test']['mail'] ), 'a job for a missing product sends nothing' );
$orphan = nx_test_product( $A, 'LS-10', '10.00', '3' );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => '0' ), array( 'post_id' => $orphan, 'meta_key' => 'vendor_id' ) );
Low_Stock::check( $orphan );
nx_assert_same( $m5, count( $GLOBALS['nx_test']['mail'] ), 'a product whose seller record is gone alerts nobody (and does not fail)' );

nx_test_finish();
