<?php
// Marketplace Phase 9: the order emails and in-app notifications. The customer hears when a seller ships their items; a seller hears when an
// order that contains their products reaches Processing (not while it is on hold), with their own lines only and never anything about the
// buyer. Mail is queued as a scheduled single event so checkout is not slowed; a mail failure never breaks an order or a status change.
// Nothing here sends real mail: pre_wp_mail captures it.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Fulfilment;
use Nexora\Orders\Mailer;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_capture_mail();
nx_test_reset_limits();
global $wpdb;

$admin = nx_test_admin();
wp_set_current_user( $admin );
$A = nx_test_vendor( 'oea', 'approved' );
$B = nx_test_vendor( 'oeb', 'approved' );
$C = nx_test_user( 'oec' );
$pa = nx_test_product( $A, 'OE-A1', '100.00', '900', array( 'name' => 'Alpha Lamp' ) );
$pb = nx_test_product( $B, 'OE-B1', '40.00', '900', array( 'name' => 'Beta Chair' ) );
$order  = function ( array $lines, $payment = 'cod' ) use ( $C ) { $r = nx_test_order( $C, $lines, array( 'payment' => $payment ) ); if ( ! $r['order'] ) { throw new RuntimeException( 'order failed: ' . $r['error'] ); } return $r['order']; };
$reload = function ( $o ) { return wc_get_order( is_object( $o ) ? $o->get_id() : $o ); };
$mails  = function () { return $GLOBALS['nx_test']['mail']; };
$queued = function ( $kind, $order_id, $vendor_id ) { return (bool) wp_next_scheduled( Mailer::HOOK, array( $kind, (int) $order_id, (int) $vendor_id ) ); };
$notes_for = function ( $uid ) use ( $wpdb ) { return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nexora_notifications WHERE receiver_user_id = %d ORDER BY id", $uid ), ARRAY_A ); };
$user_of = function ( $v ) { return get_userdata( $v['user_id'] ); };
$buyer_words = array( 'Bob', 'Billing', 'bob.billing', 'Sia', 'Ship Street', 'Billing Road', '9800000001', $C['login'] );
$has_buyer = function ( $text ) use ( $buyer_words ) { return array_values( array_filter( $buyer_words, function ( $w ) use ( $text ) { return false !== stripos( $text, $w ); } ) ); };
nx_assert_same( 'nexora_send_mail', Mailer::HOOK, 'the scheduled event is nexora_send_mail' );
nx_assert( false !== has_action( Mailer::HOOK ), 'and something is listening for it' );

/* ---------- a seller is told when the order reaches Processing ---------- */
$m0 = count( $mails() );
$n_a0 = count( $notes_for( $A['user_id'] ) ); $n_b0 = count( $notes_for( $B['user_id'] ) );
$o = $order( array( array( $pa, 2 ), array( $pb, 1 ) ), 'bacs' );     // on hold
nx_assert_same( 'on-hold', $reload( $o )->get_status(), 'setup: a bank-transfer order is On hold' );
nx_assert( ! $queued( 'new_order', $o->get_id(), $A['vendor_id'] ) && ! $queued( 'new_order', $o->get_id(), $B['vendor_id'] ), 'while On hold nothing is queued for the sellers' );
nx_assert_same( array( $n_a0, $n_b0 ), array( count( $notes_for( $A['user_id'] ) ), count( $notes_for( $B['user_id'] ) ) ), '... and no in-app notification either' );
$o = $reload( $o ); $o->update_status( 'processing' );
nx_assert( $queued( 'new_order', $o->get_id(), $A['vendor_id'] ) && $queued( 'new_order', $o->get_id(), $B['vendor_id'] ), 'when it reaches Processing a mail is queued for EACH seller (a scheduled single event)' );
$sent_now = array_filter( array_slice( $mails(), $m0 ), function ( $m ) use ( $user_of, $A, $B ) { return in_array( $m['to'], array( $user_of( $A )->user_email, $user_of( $B )->user_email ), true ) || in_array( $user_of( $A )->user_email, (array) $m['to'], true ); } );
nx_assert_same( array(), array_values( $sent_now ), 'nothing was sent during the status change itself (checkout is not slowed)' );
$na = $notes_for( $A['user_id'] ); $nb = $notes_for( $B['user_id'] );
nx_assert( count( $na ) === $n_a0 + 1 && 'order_new' === end( $na )['type'] && false !== strpos( end( $na )['message'], (string) $o->get_order_number() ), 'seller A got one in-app notification (type order_new, naming the order)' );
nx_assert( count( $nb ) === $n_b0 + 1, 'seller B got one too' );
nx_assert_same( array(), $has_buyer( wp_json_encode( end( $na ) ) ), 'the notification names no buyer (not even as the actor)' );
nx_assert_same( 0, (int) end( $na )['actor_user_id'], '(the actor is the system)' );

// Same event twice: once.
$o = $reload( $o ); do_action( 'woocommerce_order_status_changed', $o->get_id(), 'on-hold', 'processing', $o ); do_action( 'woocommerce_order_status_changed', $o->get_id(), 'on-hold', 'processing', $o );
nx_assert_same( count( $na ), count( $notes_for( $A['user_id'] ) ), 'the hook fired twice more: no second notification' );
$mine = 0; foreach ( _get_cron_array() as $ts => $hooks ) { foreach ( (array) ( $hooks[ Mailer::HOOK ] ?? array() ) as $ev ) { if ( array( 'new_order', $o->get_id(), $A['vendor_id'] ) === $ev['args'] ) { $mine++; } } }
nx_assert_same( 1, $mine, '... and exactly one queued mail for A' );

// Run the queued job (what wp-cron does).
$m1 = count( $mails() );
Mailer::run( 'new_order', $o->get_id(), $A['vendor_id'] );
$mail = $mails()[ $m1 ] ?? null;
nx_assert( null !== $mail && $user_of( $A )->user_email === ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ), 'the mail goes to seller A\'s account address' );
nx_assert( false !== stripos( $mail['subject'], 'new order' ) && false !== strpos( $mail['subject'], (string) $o->get_order_number() ), 'the subject says it is a new order and which' );
$body = $mail['message'];
nx_assert( false !== strpos( $body, 'Alpha Lamp' ) && false !== strpos( $body, 'OE-A1' ), 'the body lists A\'s own line (name and SKU)' );
nx_assert( false === strpos( $body, 'Beta Chair' ) && false === strpos( $body, 'OE-B1' ), 'and not B\'s line from the same order' );
nx_assert_same( array(), $has_buyer( $mail['subject'] . $body . wp_json_encode( $mail['headers'] ) ), 'the seller mail contains no buyer name, e-mail, phone or address' );
foreach ( array( $C['login'] . '@example.test', 'billing_', 'shipping_' ) as $w ) { nx_assert( false === stripos( $body, $w ), "nor '$w'" ); }
nx_assert( false !== strpos( implode( ' ', (array) $mail['headers'] ), 'text/html' ), 'it is an HTML mail' );
Mailer::run( 'new_order', $o->get_id(), $B['vendor_id'] );
$mailb = end( $GLOBALS['nx_test']['mail'] );
nx_assert( $user_of( $B )->user_email === ( is_array( $mailb['to'] ) ? $mailb['to'][0] : $mailb['to'] ) && false !== strpos( $mailb['message'], 'Beta Chair' ) && false === strpos( $mailb['message'], 'Alpha Lamp' ), 'seller B gets their own mail with their own line only' );

// A direct processing order (cash on delivery) also notifies.
$o2 = $order( array( array( $pa, 1 ) ) );
nx_assert( $queued( 'new_order', $o2->get_id(), $A['vendor_id'] ), 'a cash-on-delivery order (Processing at once) queues the seller mail' );

/* ---------- the customer hears when items ship ---------- */
$cn0 = count( $notes_for( $C['user_id'] ) );
wp_set_current_user( $A['user_id'] );
Fulfilment::ship( $A['user_id'], $o->get_id() );
nx_assert( $queued( 'shipped', $o->get_id(), $A['vendor_id'] ), 'shipping queues the customer mail' );
$cn = $notes_for( $C['user_id'] );
nx_assert( count( $cn ) === $cn0 + 1 && 'order_shipped' === end( $cn )['type'] && false !== stripos( end( $cn )['message'], 'Test Shop oea' ), 'and adds an in-app notification for the customer naming the business' );
Fulfilment::ship( $A['user_id'], $o->get_id() );
nx_assert_same( $cn0 + 1, count( $notes_for( $C['user_id'] ) ), 'shipping again adds nothing' );
$m2 = count( $mails() );
Mailer::run( 'shipped', $o->get_id(), $A['vendor_id'] );
$mail = $mails()[ $m2 ] ?? null;
nx_assert( null !== $mail && 'bob.billing@example.test' === ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ), 'the customer mail goes to the order\'s billing e-mail address' );
nx_assert( false !== stripos( $mail['subject'], 'shipped' ) && false !== strpos( $mail['subject'], 'Test Shop oea' ), 'the subject says the items from <business> have shipped' );
nx_assert( false !== strpos( $mail['message'], 'Alpha Lamp' ) && false === strpos( $mail['message'], 'Beta Chair' ), 'the body lists the shipped items of that seller only' );
foreach ( array( 'oea@example.test', '9811100000', '1 Test Road', '110001', 'GSTIN' ) as $private ) { nx_assert( false === stripos( $mail['message'], $private ), "no seller private detail ('$private') in the customer mail" ); }
nx_assert( false !== strpos( $mail['message'], (string) $o->get_order_number() ), 'it names the order' );
$m3 = count( $mails() );
Mailer::run( 'unknown', $o->get_id(), $A['vendor_id'] ); Mailer::run( 'shipped', 0, 0 ); Mailer::run( 'new_order', 999999999, 999999999 ); Mailer::run( 'shipped', $o->get_id(), $B['vendor_id'] );
nx_assert_same( $m3, count( $mails() ), 'a job for an unknown kind, a missing order or a seller with nothing shipped sends nothing and does not fail' );

/* ---------- output is escaped; no markup from a name ---------- */
$item = current( $reload( $o2 )->get_items() ); $item->update_meta_data( '_nx_name', '<script>alert(1)</script><b>Evil</b> & Co' ); $item->save();
Mailer::run( 'new_order', $o2->get_id(), $A['vendor_id'] );
$mail = end( $GLOBALS['nx_test']['mail'] );
nx_assert( false === strpos( $mail['message'], '<script>alert' ) && false !== strpos( $mail['message'], '&lt;script&gt;' ), 'a stored name with markup is escaped in the mail' );

/* ---------- a mail failure never breaks an order or a status change ---------- */
$fail = function () { return false; };
add_filter( 'pre_wp_mail', $fail, 5 );
$threw = false;
try { $r = Mailer::run( 'new_order', $o2->get_id(), $A['vendor_id'] ); } catch ( \Throwable $e ) { $threw = true; }
remove_filter( 'pre_wp_mail', $fail, 5 );
nx_assert( ! $threw, 'a mail that fails to send does not throw' );
$boom = function () { throw new RuntimeException( 'mail server exploded' ); };
add_filter( 'pre_wp_mail', $boom, 5 );
$threw = false;
try { Mailer::run( 'shipped', $o->get_id(), $A['vendor_id'] ); } catch ( \Throwable $e ) { $threw = true; }
nx_assert( ! $threw, 'nor does a mail layer that throws' );
$nogo = function () { return false; };
add_filter( 'schedule_event', $nogo );
$o3 = null; $threw = false;
try { $o3 = $order( array( array( $pb, 1 ) ) ); } catch ( \Throwable $e ) { $threw = true; }
remove_filter( 'schedule_event', $nogo );
nx_assert( ! $threw && $o3 && 'processing' === $reload( $o3 )->get_status(), 'an order is placed and reaches Processing even if the event cannot be scheduled' );
$o4 = $order( array( array( $pb, 1 ) ), 'bacs' ); $o4 = $reload( $o4 );
add_filter( 'pre_wp_mail', $boom, 5 );
$ok = true; try { $o4->update_status( 'processing' ); Mailer::run( 'new_order', $o4->get_id(), $B['vendor_id'] ); } catch ( \Throwable $e ) { $ok = false; }
remove_filter( 'pre_wp_mail', $boom, 5 );
nx_assert( $ok && 'processing' === $reload( $o4 )->get_status(), 'a status change goes through while the mail layer is broken' );
// Shipping with scheduling broken.
add_filter( 'schedule_event', $nogo );
wp_set_current_user( $B['user_id'] );
$res = Fulfilment::ship( $B['user_id'], $o4->get_id() );
remove_filter( 'schedule_event', $nogo );
nx_assert( ! is_wp_error( $res ) && 1 === $res['shipped'], 'shipping still works when the mail cannot be scheduled' );

/* ---------- admin messages stay as they are ---------- */
nx_assert( method_exists( '\Nexora\Vendors\Notifier', 'application_received' ), 'the new seller application admin message exists (Vendors\Notifier::application_received) and is unchanged' );
wp_set_current_user( $admin );
$m4 = count( $mails() );
$N = nx_test_user( 'oen' );
$r = \Nexora\Vendors\Service::apply( $N['user_id'], array( 'business_name' => 'Notify Co', 'business_type' => 'other', 'gstin' => '27ABCDE7777F1Z5', 'business_email' => 'n@example.test', 'business_phone' => '9811100077', 'business_address' => '7 Road', 'business_city' => 'Pune', 'business_state' => 'MH', 'business_pincode' => '411001' ) );
if ( is_array( $r ) ) { $GLOBALS['nx_test']['posts'][] = $r['vendor_id']; }
$sent = array_slice( $mails(), $m4 );
nx_assert( count( $sent ) >= 2 && (bool) array_filter( $sent, function ( $m ) { return false !== stripos( $m['subject'], 'New seller application' ); } ), 'applying to sell still sends the admin the "New seller application" mail and thanks the applicant' );

/* ---------- templates live where asked ---------- */
foreach ( array( 'new-order-seller', 'shipped-customer', 'low-stock-seller' ) as $t ) { nx_assert( is_file( NEXORA_PATH . "templates/emails/$t.php" ), "templates/emails/$t.php exists" ); }

nx_test_finish();
