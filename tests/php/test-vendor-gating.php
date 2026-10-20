<?php
// Marketplace Phase 3: server-side gating of vendor actions. Hiding a tab is never the security: every vendor
// action re-checks the account type, the status and the ownership of what it touches.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Vendors\Service;

nx_test_capture_mail();

/** A stand-in for the Phase 4+ endpoints, built on the real base class. */
class NX_Probe_Ajax extends \Nexora\Vendors\Vendor_Ajax {
	public function __construct() {
		\Nexora\Http\Ajax::register( 'test_probe_sell', array( $this, 'sell' ) );
		\Nexora\Http\Ajax::register( 'test_probe_earn', array( $this, 'earn' ) );
		\Nexora\Http\Ajax::register( 'test_probe_object', array( $this, 'object' ) );
	}
	public function sell() {
		$m = $this->seller();
		wp_send_json_success( array( 'vendor_id' => $m['vendor_id'], 'user_id' => $m['user_id'] ) );
	}
	public function earn() {
		$m = $this->earnings_viewer();
		wp_send_json_success( array( 'user_id' => $m['user_id'] ) );
	}
	public function object() {
		$m = $this->seller();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->require_owner( isset( $_POST['owner_user_id'] ) ? (int) $_POST['owner_user_id'] : 0, $m );
		wp_send_json_success( 'mine' );
	}
}
new NX_Probe_Ajax();

$mk = function ( $name, $steps ) {
	$u = nx_test_user( $name );
	foreach ( $steps as $s ) { Account_Types::set_status( $u['user_id'], $s ); }
	if ( $steps ) { $v = \Nexora\Vendors\Repository::id_for_user( $u['user_id'] ); }
	return $u;
};
$biz = function ( $gst, $n ) {
	return array( 'business_name' => "Gate Shop $n", 'business_type' => 'other', 'gstin' => $gst, 'business_email' => "g$n@example.test", 'business_phone' => '98111000' . sprintf( '%02d', $n ),
		'business_address' => '1 Gate Road', 'business_city' => 'Delhi', 'business_state' => 'Delhi', 'business_pincode' => '110001' );
};
$gst = array( '27AAPFU0939F1ZV', '29ABCDE1234F1Z5', '07AAACR5055K1Z8', '33AABCU9603R1ZX', '19AAACI1681G1ZB' );
$apply_as = function ( $u, $i ) use ( $biz, $gst ) {
	$r = Service::apply( $u['user_id'], $biz( $gst[ $i ], $i ) );
	if ( is_array( $r ) ) { $GLOBALS['nx_test']['posts'][] = $r['vendor_id']; }
};
$admin_id = nx_test_admin();
$approve  = function ( $u ) use ( $admin_id ) {
	wp_set_current_user( $admin_id );
	$vid = \Nexora\Vendors\Repository::id_for_user( $u['user_id'] );
	$r1  = Service::change_status( $vid, 'approve' );
	wp_set_current_user( 0 );
	return $r1;
};
$suspend = function ( $u ) use ( $admin_id ) {
	wp_set_current_user( $admin_id );
	Service::change_status( \Nexora\Vendors\Repository::id_for_user( $u['user_id'] ), 'suspend' );
	wp_set_current_user( 0 );
};

$customer = nx_test_user( 'gtcust' );
$pending  = nx_test_user( 'gtpend' );  $apply_as( $pending, 0 );
$approved = nx_test_user( 'gtappr' );  $apply_as( $approved, 1 ); $approve( $approved );
$other    = nx_test_user( 'gtother' ); $apply_as( $other, 2 );    $approve( $other );
$suspend_ = nx_test_user( 'gtsusp' );  $apply_as( $suspend_, 3 ); $approve( $suspend_ ); $suspend( $suspend_ );

$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	nx_test_reset_limits();
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$forbidden = function ( $r ) { return nx_rejected( $r ) && is_array( $r['data'] ?? null ) && 'forbidden' === ( $r['data']['code'] ?? '' ) && ! empty( $r['data']['message'] ); };

/* ---------- seller actions (list, edit, delete, import ...): approved vendors only ---------- */
nx_assert( nx_rejected( $call( 0, 'nexora_test_probe_sell' ) ), 'sell: a guest is rejected' );
nx_assert( nx_rejected( $call( $approved['user_id'], 'nexora_test_probe_sell', array(), false ) ), 'sell: an approved vendor without a nonce is rejected' );
nx_assert( nx_rejected( $call( $approved['user_id'], 'nexora_test_probe_sell', array( 'nonce' => 'bad' ), false ) ), 'sell: a bad nonce is rejected' );
foreach ( array( 'a customer' => $customer, 'a pending applicant' => $pending, 'a suspended vendor' => $suspend_ ) as $label => $u ) {
	$r = $call( $u['user_id'], 'nexora_test_probe_sell' );
	nx_assert( $forbidden( $r ), "sell: $label is refused with a forbidden error" );
}
$r = $call( $admin_id, 'nexora_test_probe_sell' );
nx_assert( nx_rejected( $r ), 'sell: an administrator who is not a vendor is refused too (admins use wp-admin)' );
$r = $call( $approved['user_id'], 'nexora_test_probe_sell' );
nx_assert( ! empty( $r['success'] ) && $r['data']['user_id'] === $approved['user_id'] && $r['data']['vendor_id'] === \Nexora\Vendors\Repository::id_for_user( $approved['user_id'] ), 'sell: an approved vendor passes and the handler gets THEIR vendor id' );
$r = $call( $approved['user_id'], 'nexora_test_probe_sell', array( 'user_id' => $other['user_id'], 'vendor_id' => \Nexora\Vendors\Repository::id_for_user( $other['user_id'] ) ) );
nx_assert( ! empty( $r['success'] ) && $r['data']['user_id'] === $approved['user_id'], 'sell: a posted user_id / vendor_id never switches to another vendor' );

// Both the status AND the role are needed.
$sneak = nx_test_user( 'gtsneak' );
( new WP_User( $sneak['user_id'] ) )->add_role( Account_Types::ROLE_CUSTOMER_SELLER );
nx_assert( $forbidden( $call( $sneak['user_id'], 'nexora_test_probe_sell' ) ), 'sell: the vendor role without an approved status is refused' );
$norole = nx_test_user( 'gtnorole' ); $apply_as( $norole, 4 ); $approve( $norole );
( new WP_User( $norole['user_id'] ) )->set_role( 'subscriber' );
nx_assert( $forbidden( $call( $norole['user_id'], 'nexora_test_probe_sell' ) ), 'sell: an approved status without a seller role is refused' );
( new WP_User( $norole['user_id'] ) )->set_role( Account_Types::ROLE_CUSTOMER_SELLER );
nx_assert( ! empty( $call( $norole['user_id'], 'nexora_test_probe_sell' )['success'] ), 'sell: with both, the vendor passes' );

/* ---------- earnings: approved and suspended (read-only), nobody else ---------- */
nx_assert( nx_rejected( $call( 0, 'nexora_test_probe_earn' ) ), 'earnings: a guest is rejected' );
foreach ( array( 'a customer' => $customer, 'a pending applicant' => $pending ) as $label => $u ) {
	nx_assert( $forbidden( $call( $u['user_id'], 'nexora_test_probe_earn' ) ), "earnings: $label is refused" );
}
nx_assert( ! empty( $call( $approved['user_id'], 'nexora_test_probe_earn' )['success'] ), 'earnings: an approved vendor may read them' );
nx_assert( ! empty( $call( $suspend_['user_id'], 'nexora_test_probe_earn' )['success'] ), 'earnings: a suspended vendor keeps read-only access' );

/* ---------- object level: a vendor touches only their own things ---------- */
$r = $call( $approved['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => $approved['user_id'] ) );
nx_assert( ! empty( $r['success'] ), 'object: an approved vendor may touch their own object' );
$r = $call( $approved['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => $other['user_id'] ) );
nx_assert( $forbidden( $r ), "object: another vendor's object is refused" );
nx_assert( false === strpos( wp_json_encode( $r ), 'Gate Shop' ) && false === strpos( wp_json_encode( $r ), $other['login'] ), "object: the refusal does not reveal whose it is" );
nx_assert( $forbidden( $call( $approved['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => 0 ) ) ), 'object: an object without an owner is refused' );
nx_assert( $forbidden( $call( $approved['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => $customer['user_id'] ) ) ), "object: a customer's object is refused" );
nx_assert( $forbidden( $call( $suspend_['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => $suspend_['user_id'] ) ) ), 'object: a suspended vendor cannot even touch their own (status first)' );
nx_assert( $forbidden( $call( $customer['user_id'], 'nexora_test_probe_object', array( 'owner_user_id' => $customer['user_id'] ) ) ), 'object: a customer cannot touch their own either (not a seller)' );

/* ---------- the application actions, by status ---------- */
$gst_new = '24AAACC1206D1ZM';
$form    = $biz( $gst_new, 9 );
$matrix  = array(
	'customer'  => array( $customer, true, false ),
	'pending'   => array( $pending, false, true ),
	'approved'  => array( $approved, false, true ),
	'suspended' => array( $suspend_, false, true ),
);
foreach ( $matrix as $label => list( $u, $may_apply, $may_update ) ) {
	$before = Account_Types::vendor_status( $u['user_id'] );
	if ( 'customer' !== $label ) {
		$r = $call( $u['user_id'], 'nexora_vendor_apply', $form );
		nx_assert( nx_rejected( $r ), "apply: a $label member cannot apply again / is refused" );
		nx_assert_same( $before, Account_Types::vendor_status( $u['user_id'] ), "apply: $label status unchanged by the refused call" );
	}
	$r = $call( $u['user_id'], 'nexora_vendor_update', array( 'business_phone' => '9000011111' ) );
	nx_assert( $may_update ? ! empty( $r['success'] ) : nx_rejected( $r ), "update: $label " . ( $may_update ? 'may correct contact details' : 'has nothing to update' ) );
}
$r = $call( $customer['user_id'], 'nexora_vendor_apply', $form );
nx_assert( ! empty( $r['success'] ) && 'pending' === Account_Types::vendor_status( $customer['user_id'] ), 'apply: a customer can apply and becomes pending' );
$GLOBALS['nx_test']['posts'][] = \Nexora\Vendors\Repository::id_for_user( $customer['user_id'] );
nx_assert( ! Account_Types::can_sell( $customer['user_id'] ), 'apply: and still cannot sell' );
nx_assert( $forbidden( $call( $customer['user_id'], 'nexora_test_probe_sell' ) ), 'apply: the new applicant is refused by seller actions' );

/* ---------- source: seller actions must use the gate ---------- */
$base = file_get_contents( NEXORA_PATH . 'src/Vendors/Vendor_Ajax.php' );
nx_assert( false !== strpos( $base, 'can_sell' ) && false !== strpos( $base, 'can_view_earnings' ), 'Vendor_Ajax checks can_sell() and can_view_earnings()' );

nx_test_finish();
