<?php
// Marketplace Phase 2: vendor profile CPT, the application (sign-up "Seller" and "Become a seller"),
// GSTIN uniqueness, rollback, privacy of business data, rate limit, mails.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Vendors\Fields;
use Nexora\Vendors\Repository;
use Nexora\Vendors\Service;

global $wpdb;
$mail = &nx_test_capture_mail();
nx_test_reset_limits();
wp_set_current_user( 0 );

$GST = array( '27AAPFU0939F1ZV', '29ABCDE1234F1Z5', '07AAACR5055K1Z8', '33AABCU9603R1ZX', '19AAACI1681G1ZB', '24AAACC1206D1ZM', '09AAACH7409R1ZZ' );
$biz = function ( $n = 0, array $over = array() ) use ( $GST ) {
	return array_merge(
		array(
			'business_name'    => 'Sharma Textiles ' . $n,
			'business_type'    => 'sole_proprietorship',
			'gstin'            => $GST[ $n ],
			'business_email'   => "shop$n@example.test",
			'business_phone'   => '98765432' . sprintf( '%02d', 10 + $n ),
			'business_address' => '12 MG Road',
			'business_city'    => 'Pune',
			'business_state'   => 'Maharashtra',
			'business_pincode' => '411001',
		),
		$over
	);
};
$track = function ( $uid ) {
	$vid = Repository::id_for_user( $uid );
	if ( $vid ) { $GLOBALS['nx_test']['posts'][] = $vid; }
	return $vid;
};
$count_vendor_posts = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_cust_seller'" );
};
$count_profiles = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_customer'" );
};
$admin_mail = get_option( 'default_admin_mail' ) ?: get_option( 'admin_email' );

/* ---------- the CPT ---------- */
$pt = get_post_type_object( 'nexora_cust_seller' );
nx_assert( (bool) $pt, 'nexora_cust_seller post type is registered' );
nx_assert( $pt && false === $pt->public && true === $pt->show_ui && 'nexora-system' === $pt->show_in_menu, 'it is private and sits under "Nexora System"' );
nx_assert( $pt && false === $pt->publicly_queryable && true === $pt->exclude_from_search && ! $pt->has_archive, 'it has no front-end URL and is not searchable' );

/* ---------- Service::apply ---------- */
$alice = nx_test_user( 'vaalice' );
$base  = $count_vendor_posts();
$sent  = count( $mail );
$r = Service::apply( $alice['user_id'], $biz( 0 ) );
nx_assert( is_array( $r ) && ! empty( $r['vendor_id'] ), 'a valid application succeeds' );
$vid = $track( $alice['user_id'] );
nx_assert_same( $r['vendor_id'] ?? 0, $vid, 'the user is linked to the vendor post (_vendor_id)' );
nx_assert( 'nexora_cust_seller' === get_post_type( $vid ) && (int) get_post_meta( $vid, '_wp_user_id', true ) === $alice['user_id'], 'the post links back to the user (_wp_user_id)' );
nx_assert_same( $biz( 0 ), Repository::data( $vid ), 'business details are stored' );
nx_assert_same( 'Sharma Textiles 0', get_the_title( $vid ), 'the post title is the business name' );
nx_assert_same( 'pending', Account_Types::vendor_status( $alice['user_id'] ), 'the applicant is pending' );
nx_assert( ! in_array( Account_Types::ROLE_CUSTOMER_SELLER, (array) get_userdata( $alice['user_id'] )->roles, true ) && ! Account_Types::can_sell( $alice['user_id'] ), 'pending: no vendor role, cannot sell' );
nx_assert_same( $base + 1, $count_vendor_posts(), 'exactly one vendor post created' );
nx_assert_same( $alice['user_id'], Repository::user_for( $vid ), 'Repository::user_for resolves the owner' );
nx_assert_same( $vid, Repository::find_by_gstin( strtolower( $GST[0] ) ), 'find_by_gstin is case-insensitive' );

$new = array_slice( $mail, $sent );
nx_assert_same( 2, count( $new ), 'two mails: admin and applicant' );
$to = array_map( function ( $m ) { return is_array( $m['to'] ) ? implode( ',', $m['to'] ) : $m['to']; }, $new );
nx_assert( in_array( $admin_mail, $to, true ) && in_array( get_userdata( $alice['user_id'] )->user_email, $to, true ), 'mails go to the site admin and to the applicant' );
$admin_msg = $new[ array_search( $admin_mail, $to, true ) ]['message'];
nx_assert( false !== strpos( $admin_msg, 'Sharma Textiles 0' ) && false !== strpos( $admin_msg, $GST[0] ), 'the admin mail shows the business name and GSTIN' );
$app_msg = $new[ array_search( get_userdata( $alice['user_id'] )->user_email, $to, true ) ]['message'];
nx_assert( false === strpos( $app_msg, $GST[0] ), 'the applicant mail does not repeat the GSTIN' );
foreach ( $new as $m ) { nx_assert( false === strpbrk( $m['subject'], "\r\n" ), 'mail subject has no line break' ); }

/* ---------- duplicate application / invalid / privileges ---------- */
$r = Service::apply( $alice['user_id'], $biz( 1 ) );
nx_assert( is_wp_error( $r ) && 'nexora_already_applied' === $r->get_error_code(), 'a second application by a pending applicant is refused' );
nx_assert_same( $base + 1, $count_vendor_posts(), 'no second vendor post' );
nx_assert_same( 'Sharma Textiles 0', get_the_title( $vid ), 'the first application is untouched' );

$bob = nx_test_user( 'vabob' );
$r   = Service::apply( $bob['user_id'], array( 'business_name' => 'X' ) + $biz( 1 ) );
nx_assert( is_wp_error( $r ) && isset( $r->get_error_data()['fields']['business_name'] ), 'invalid details are refused with per-field errors' );
nx_assert( 0 === Repository::id_for_user( $bob['user_id'] ) && 'none' === Account_Types::vendor_status( $bob['user_id'] ) && $count_vendor_posts() === $base + 1, 'a refused application leaves nothing behind' );
$r = Service::apply( 0, $biz( 1 ) );
nx_assert( is_wp_error( $r ), 'an unknown user cannot apply' );
$r = Service::apply( $bob['user_id'], $biz( 1 ) + array( 'status' => 'approved', 'role' => 'administrator', 'vendor_status' => 'approved' ) );
$track( $bob['user_id'] );
nx_assert( is_array( $r ) && 'pending' === Account_Types::vendor_status( $bob['user_id'] ), 'extra status/role input cannot make an applicant approved' );
nx_assert_same( array( 'subscriber' ), array_values( (array) get_userdata( $bob['user_id'] )->roles ), 'the applicant keeps only the original role' );

/* ---------- GSTIN uniqueness ---------- */
$carol = nx_test_user( 'vacarol' );
$r = Service::apply( $carol['user_id'], $biz( 2, array( 'gstin' => strtolower( $GST[0] ) ) ) );
nx_assert( is_wp_error( $r ) && isset( $r->get_error_data()['fields']['gstin'] ), 'a GSTIN already in use is refused (any letter case)' );
nx_assert( false === strpos( $r->get_error_data()['fields']['gstin'] ?? '', 'Sharma' ) && false === strpos( $r->get_error_data()['fields']['gstin'] ?? '', 'alice' ), 'the duplicate message does not reveal who owns it' );
nx_assert( 0 === Repository::id_for_user( $carol['user_id'] ) && 'none' === Account_Types::vendor_status( $carol['user_id'] ), 'a duplicate leaves nothing behind' );
nx_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'nexora\\_vendor\\_lock\\_%'" ), 'no GSTIN lock is left behind' );
add_option( 'nexora_vendor_lock_' . md5( $GST[2] ), time(), '', false );
$r = Service::apply( $carol['user_id'], $biz( 2 ) );
nx_assert( is_wp_error( $r ) && 'nexora_busy' === $r->get_error_code(), 'a GSTIN being registered right now is refused (lock)' );
delete_option( 'nexora_vendor_lock_' . md5( $GST[2] ) );
$r = Service::apply( $carol['user_id'], $biz( 2 ) );
$track( $carol['user_id'] );
nx_assert( is_array( $r ), 'with the lock gone the application succeeds' );

/* ---------- rollback when the vendor post cannot be created ---------- */
$dave = nx_test_user( 'vadave' );
$fail = function ( $empty, $postarr ) { return 'nexora_cust_seller' === ( $postarr['post_type'] ?? '' ) ? true : $empty; };
add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
$before = $count_vendor_posts();
$r = Service::apply( $dave['user_id'], $biz( 3 ) );
remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
nx_assert( is_wp_error( $r ) && 'nexora_save_failed' === $r->get_error_code(), 'a failing insert is reported' );
nx_assert( 'none' === Account_Types::vendor_status( $dave['user_id'] ) && 0 === Repository::id_for_user( $dave['user_id'] ) && $before === $count_vendor_posts(), 'rollback: no status, no link, no orphan post' );
nx_assert( '' === (string) get_user_meta( $dave['user_id'], '_vendor_id', true ), 'rollback: no _vendor_id' );

/* ---------- AJAX: "Become a seller" ---------- */
nx_test_reset_limits();
$call = function ( $uid, $action, array $post = array(), $nonce = true ) {
	wp_set_current_user( $uid );
	$r = nx_call_ajax( $action, $post + ( $nonce ? nx_profile_nonce() : array() ) );
	wp_set_current_user( 0 );
	return $r;
};
$erin = nx_test_user( 'vaerin' );
nx_assert( nx_rejected( $call( 0, 'nexora_vendor_apply', $biz( 3 ) ) ), 'ajax apply: a guest is rejected' );
nx_assert( nx_rejected( $call( $erin['user_id'], 'nexora_vendor_apply', $biz( 3 ), false ) ), 'ajax apply: a missing nonce is rejected' );
nx_assert_same( 'none', Account_Types::vendor_status( $erin['user_id'] ), 'rejected calls change nothing' );
$r = $call( $erin['user_id'], 'nexora_vendor_apply', array( 'gstin' => 'bad' ) + $biz( 3 ) );
nx_assert( nx_rejected( $r ) && is_array( $r['data'] ) && isset( $r['data']['fields']['gstin'] ), 'ajax apply: validation errors come back per field' );
$r = $call( $erin['user_id'], 'nexora_vendor_apply', $biz( 3 ) + array( 'vendor_status' => 'approved', 'role' => 'administrator', 'status' => 'approved', 'intent' => 'seller', 'user_id' => $alice['user_id'] ) );
nx_assert( ! empty( $r['success'] ), 'ajax apply: a valid application succeeds' );
nx_assert_same( 'pending', Account_Types::vendor_status( $erin['user_id'] ), 'ajax apply: the status is pending, never what the client sent' );
$track( $erin['user_id'] );
nx_assert_same( 'Sharma Textiles 0', get_the_title( Repository::id_for_user( $alice['user_id'] ) ), 'ajax apply: a posted user_id does not touch someone else' );
nx_assert( nx_rejected( $call( $erin['user_id'], 'nexora_vendor_apply', $biz( 4 ) ) ), 'ajax apply: applying twice is rejected' );

// Rate limit: only well-formed attempts (the ones that reach the GSTIN lookup) count, 3 a day.
nx_test_reset_limits();
$fred = nx_test_user( 'vafred' );
for ( $i = 0; $i < 6; $i++ ) { $call( $fred['user_id'], 'nexora_vendor_apply', array( 'gstin' => 'bad' ) + $biz( 4 ) ); }
nx_assert( ! \Nexora\Http\Rate_Limiter::blocked( 'vendor_apply', (string) $fred['user_id'] ), 'badly formed attempts do not use the allowance' );
for ( $i = 0; $i < 3; $i++ ) { $r = $call( $fred['user_id'], 'nexora_vendor_apply', $biz( 4, array( 'gstin' => $GST[0] ) ) ); }
nx_assert( nx_rejected( $r ), 'duplicate-GSTIN attempts are refused' );
$r = $call( $fred['user_id'], 'nexora_vendor_apply', $biz( 4 ) );
nx_assert( nx_rejected( $r ) && 'none' === Account_Types::vendor_status( $fred['user_id'] ), 'the 4th lookup in a day is rate limited, even with valid data' );
nx_test_reset_limits();

/* ---------- AJAX: edit own details ---------- */
$r = $call( $fred['user_id'], 'nexora_vendor_update', array( 'business_phone' => '9000000001' ) );
nx_assert( nx_rejected( $r ), 'ajax update: a member who never applied is rejected' );
$r = $call( $alice['user_id'], 'nexora_vendor_update', array( 'business_name' => 'Renamed Textiles', 'gstin' => $GST[4], 'business_phone' => '9000000001', 'vendor_id' => Repository::id_for_user( $bob['user_id'] ) ) );
nx_assert( ! empty( $r['success'] ), 'ajax update: a pending applicant may correct the application' );
$d = Repository::data( $vid );
nx_assert( 'Renamed Textiles' === $d['business_name'] && $GST[4] === $d['gstin'] && '9000000001' === $d['business_phone'], 'ajax update: pending applicants can change every field' );
nx_assert_same( 'Sharma Textiles 1', get_the_title( Repository::id_for_user( $bob['user_id'] ) ), 'ajax update: a posted vendor_id never selects another vendor' );
$r = $call( $alice['user_id'], 'nexora_vendor_update', array( 'gstin' => $GST[1] ) );
nx_assert( nx_rejected( $r ), 'ajax update: a GSTIN used by another application is refused' );
$bob_post = Repository::id_for_user( $bob['user_id'] );
nx_assert_same( $GST[1], Repository::data( $bob_post )['gstin'], 'ajax update: the other application keeps its GSTIN' );

// After a decision the identity fields are locked.
wp_set_current_user( nx_test_admin() );
Service::change_status( $vid, 'approve' );
wp_set_current_user( 0 );
$r = $call( $alice['user_id'], 'nexora_vendor_update', array( 'business_name' => 'Hijack Ltd', 'gstin' => $GST[5], 'business_phone' => '9000000002', 'business_city' => 'Nashik' ) );
$d = Repository::data( $vid );
nx_assert( ! empty( $r['success'] ) && '9000000002' === $d['business_phone'] && 'Nashik' === $d['business_city'], 'ajax update: an approved vendor may change contact and address details' );
nx_assert( 'Renamed Textiles' === $d['business_name'] && $GST[4] === $d['gstin'], 'ajax update: business name and GSTIN are locked after approval' );
nx_assert_same( 'approved', Account_Types::vendor_status( $alice['user_id'] ), 'ajax update: the status is untouched' );

/* ---------- sign-up with the Seller choice ---------- */
nx_test_reset_limits();
$tag = strtolower( wp_generate_password( 6, false ) );
$reg = function ( $name, array $extra = array() ) {
	$tag = strtolower( wp_generate_password( 5, false ) );
	wp_set_current_user( 0 );
	return nx_call_ajax( 'nexora_profile_register', array_merge( array(
		'email' => "$name$tag@example.test", 'user_name' => "nxtest_{$name}_$tag", 'password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x',
		'first_name' => 'Su', 'last_name' => 'Tester', 'phone' => '9999999999', 'gender' => 'other', 'birthdate' => '1995-01-31',
	), $extra ) + nx_profile_nonce(), true );
};
$find = function ( $r_data_redirect ) {
	$login = rawurldecode( basename( untrailingslashit( $r_data_redirect ) ) );
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		$GLOBALS['nx_test']['users'][] = $u->ID;
		$GLOBALS['nx_test']['posts'][] = (int) get_user_meta( $u->ID, '_profile_id', true );
		$v = (int) get_user_meta( $u->ID, '_vendor_id', true );
		if ( $v ) { $GLOBALS['nx_test']['posts'][] = $v; }
	}
	return $u;
};

// customer intents: identical outcome, no vendor data at all
foreach ( array( 'omitted' => array(), 'customer' => array( 'intent' => 'customer' ), 'junk' => array( 'intent' => 'administrator' ) ) as $label => $extra ) {
	nx_test_reset_limits();
	$posts_before = $count_vendor_posts();
	$r = $reg( 'cu', $extra );
	$u = ! empty( $r['success'] ) ? $find( $r['data']['redirect'] ) : null;
	nx_assert( $u instanceof WP_User, "sign-up ($label intent) creates a member" );
	nx_assert( $u && 'none' === Account_Types::vendor_status( $u->ID ) && array( 'subscriber' ) === array_values( $u->roles ), "sign-up ($label intent): a plain customer" );
	nx_assert_same( $posts_before, $count_vendor_posts(), "sign-up ($label intent): no vendor post" );
}

// seller intent
nx_test_reset_limits();
$sent = count( $mail );
$r = $reg( 'se', array( 'intent' => 'seller' ) + $biz( 5 ) + array( 'vendor_status' => 'approved', 'role' => 'administrator' ) );
$u = ! empty( $r['success'] ) ? $find( $r['data']['redirect'] ) : null;
nx_assert( $u instanceof WP_User, 'sign-up as Seller creates the member' );
nx_assert( $u && 'pending' === Account_Types::vendor_status( $u->ID ), 'sign-up as Seller: pending, whatever status was posted' );
nx_assert( $u && array( Account_Types::ROLE_SELLER ) === array_values( $u->roles ), 'sign-up as Seller: the Seller role (not permission to sell: the status is pending)' );
nx_assert( $u && Repository::data( Repository::id_for_user( $u->ID ) )['gstin'] === $GST[5], 'sign-up as Seller: business details saved' );
nx_assert_same( 3, count( $mail ) - $sent, 'sign-up as Seller: new-member mail + application mail to admin + acknowledgement' );
nx_assert( $u && (int) get_user_meta( $u->ID, '_profile_id', true ) > 0 && 'nexora_seller' === get_post_type( (int) get_user_meta( $u->ID, '_profile_id', true ) ), 'sign-up as Seller: the seller post is their profile (no separate customer post)' );

// seller intent with bad data: nothing is created
nx_test_reset_limits();
$users_before = (int) count_users()['total_users'];
$prof_before  = $count_profiles();
$posts_before = $count_vendor_posts();
$r = $reg( 'sb', array( 'intent' => 'seller' ) + $biz( 1, array( 'gstin' => 'nope' ) ) );
nx_assert( nx_rejected( $r ), 'sign-up as Seller with a bad GSTIN is refused' );
$r2 = $reg( 'sd', array( 'intent' => 'seller' ) + $biz( 1, array( 'gstin' => $GST[4] ) ) );
nx_assert( nx_rejected( $r2 ), 'sign-up as Seller with a GSTIN already in use is refused' );
$r3 = $reg( 'sm', array( 'intent' => 'seller' ) );
nx_assert( nx_rejected( $r3 ), 'sign-up as Seller without business details is refused' );
nx_assert( $users_before === (int) count_users()['total_users'] && $prof_before === $count_profiles() && $posts_before === $count_vendor_posts(), 'refused seller sign-ups create no user, no profile, no vendor post' );

// seller sign-up where saving the vendor post fails: the whole sign-up is rolled back
nx_test_reset_limits();
add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
$r = $reg( 'sr', array( 'intent' => 'seller' ) + $biz( 1 ) );
remove_filter( 'wp_insert_post_empty_content', $fail, 10 );
nx_assert( nx_rejected( $r ), 'sign-up as Seller: a vendor save failure ends in an error' );
nx_assert( $users_before === (int) count_users()['total_users'], 'rollback: the new user is deleted' );
nx_assert( $prof_before === $count_profiles(), 'rollback: the new profile post is deleted' );
nx_assert( $posts_before === $count_vendor_posts(), 'rollback: no vendor post' );
nx_assert( ! is_user_logged_in() && 0 === get_current_user_id(), 'rollback: nobody is left logged in' );

/* ---------- business data is owner-only ---------- */
$viewer = nx_test_user( 'vaviewer' );
$secrets = array( $GST[0], $GST[4], 'Renamed Textiles', '9000000002', 'shop0@example.test', '12 MG Road', '411001' );
$render = function ( $uid, $username ) {
	wp_set_current_user( $uid );
	$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	$html = do_shortcode( '[profile_dashboard]' );
	set_query_var( 'username', '' );
	wp_set_current_user( 0 );
	return $html;
};
nx_assert( '' !== $render( $alice['user_id'], $alice['login'] ), 'the render helper reaches the profile page' );
$owner_html = $render( $alice['user_id'], $alice['login'] );
nx_assert( false !== strpos( $owner_html, 'Renamed Textiles' ) && false !== strpos( $owner_html, $GST[4] ), 'owner: sees their own business details' );
nx_assert( false !== strpos( $owner_html, 'nx-vendor-card' ), 'owner: the vendor card is shown' );
foreach ( array( 'viewer' => $viewer['user_id'], 'guest' => 0 ) as $who => $uid ) {
	$html = $render( $uid, $alice['login'] );
	$leak = array_filter( $secrets, function ( $s ) use ( $html ) { return false !== strpos( $html, $s ); } );
	nx_assert_same( array(), array_values( $leak ), "$who: no business detail in another member's profile page" );
	nx_assert( false === strpos( $html, 'nx-vendor-card' ) && false === strpos( $html, 'nexora-vendor-apply-form' ), "$who: no vendor card or apply form on someone else's profile" );
}
$admin_id = nx_test_admin();
$html = $render( $admin_id, $alice['login'] );
nx_assert( false === strpos( $html, $GST[4] ) && false === strpos( $html, 'nx-vendor-card' ), 'an administrator browsing the front-end profile is a viewer: no business details there (admin screens show them)' );
foreach ( array( 'viewer', 'guest' ) as $role ) {
	$data = \Nexora\Profile\Privacy::script_data( $alice['profile_id'], $role );
	nx_assert( array() === array_intersect( Fields::keys(), array_keys( $data ) ) && false === strpos( wp_json_encode( $data ), $GST[4] ), "script data for a $role has no business fields" );
}
$data = \Nexora\Profile\Privacy::script_data( $alice['profile_id'], 'owner' );
nx_assert( array() === array_intersect( Fields::keys(), array_keys( $data ) ), 'script data never carries business fields (the owner card is rendered on the server)' );

// every member AJAX action, called by another member while pointing at alice, leaks nothing
global $wp_filter;
$acts = array();
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( preg_match( '/^wp_ajax_(nexora_.+)$/', $hook, $m ) && ! in_array( $m[1], array( 'nexora_profile_login', 'nexora_vendor_apply', 'nexora_vendor_update' ), true ) ) { $acts[] = $m[1]; }
}
$leaks = array();
foreach ( $acts as $a ) {
	foreach ( array( nx_profile_nonce(), nx_chat_nonce() ) as $n ) {
		nx_test_reset_limits();
		wp_set_current_user( $viewer['user_id'] );
		$out = nx_call_ajax( $a, array( 'user_id' => $alice['user_id'], 'profile_id' => $alice['profile_id'], 'search' => 'Textiles', 'keyword' => 'Renamed', 'query' => 'Renamed', 'username' => $alice['login'] ) + $n );
		wp_set_current_user( 0 );
		$text = wp_json_encode( $out );
		foreach ( $secrets as $s ) { if ( false !== strpos( $text, $s ) ) { $leaks[] = "$a:$s"; } }
	}
}
nx_assert_same( array(), $leaks, 'no member AJAX action returns another member\'s business details (' . count( $acts ) . ' actions swept)' );

/* ---------- deleting a member removes their application and frees the GSTIN ---------- */
require_once ABSPATH . 'wp-admin/includes/user.php';
$gone = nx_test_user( 'vagone' );
$gr   = Service::apply( $gone['user_id'], $biz( 0, array( 'gstin' => $GST[6] ) ) );
$gv   = is_array( $gr ) ? $gr['vendor_id'] : 0;
nx_assert( $gv > 0 && Repository::find_by_gstin( $GST[6] ) === $gv, 'setup: an application exists for the member to be deleted' );
wp_delete_user( $gone['user_id'] );
nx_assert_same( null, get_post( $gv ), 'deleting the member deletes their vendor application' );
nx_assert_same( 0, Repository::find_by_gstin( $GST[6] ), 'the GSTIN is free again after the member is deleted' );

/* ---------- re-apply after rejection frees the GSTIN ---------- */
wp_set_current_user( $admin_id );
$bob_vid = Repository::id_for_user( $bob['user_id'] );
nx_assert( true === Service::change_status( $bob_vid, 'reject' ), 'an admin can reject a pending application' );
wp_set_current_user( 0 );
nx_assert( 'rejected' === Account_Types::vendor_status( $bob['user_id'] ) && $bob_vid === Repository::id_for_user( $bob['user_id'] ), 'rejected: status rejected, the record stays linked' );
nx_assert( 0 === Repository::find_by_gstin( $GST[1] ), 'rejected: the GSTIN is free again' );
nx_assert( 'publish' === get_post_status( $bob_vid ), 'rejected: the application is kept (not trashed) for the record' );
$r = Service::apply( $bob['user_id'], $biz( 1 ) );
$track( $bob['user_id'] );
nx_assert( is_array( $r ) && 'pending' === Account_Types::vendor_status( $bob['user_id'] ) && $bob_vid === $r['vendor_id'], 'a rejected applicant may apply again, on the same record' );

nx_test_finish();
