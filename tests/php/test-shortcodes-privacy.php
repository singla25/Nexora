<?php
// Characterization of shortcodes, contact form, home stats and what profile data reaches non-owners.
// Assertions tagged [PHASE1] describe known weaknesses that Phase 1 changes ON PURPOSE.
require_once __DIR__ . '/../bootstrap.php';
global $wpdb;
nx_test_reset_limits();

$alice = nx_test_user( 'salice' );   // profile owner
$bob   = nx_test_user( 'sbob' );     // other member
$secret = array( 'email' => 'alice.secret@example.test', 'phone' => '+91-98765-00000', 'perm_address' => '12 Hidden Lane', 'perm_city' => 'Secretville',
	'perm_pincode' => '560001', 'birthdate' => '1990-01-01', 'company_phone' => '+91-1111', 'first_name' => 'Alice', 'last_name' => 'Owner', 'bio' => 'public bio' );
foreach ( $secret as $k => $v ) { update_post_meta( $alice['profile_id'], $k, $v ); }
$doc = nx_test_attachment( $alice['user_id'], 'aadhaar-doc' );
update_post_meta( $alice['profile_id'], 'aadhaar_card', $doc );
$doc_url = wp_get_attachment_url( $doc );

/** Run enqueue_assets()/render for $viewer looking at $username ('' = no username in URL). */
$local = function ( $viewer_id, $username ) use ( $doc_url ) {
	wp_set_current_user( $viewer_id );
	$GLOBALS['wp_query'] = new WP_Query();
	set_query_var( 'username', $username );
	wp_deregister_script( 'profile-page-js' );
	( new NEXORA_PROFILE_PAGE() )->enqueue_assets();
	$data = wp_scripts()->get_data( 'profile-page-js', 'data' );
	preg_match( '/profilePageData = (\{.*\});/s', (string) $data, $m );
	return json_decode( $m[1] ?? '{}', true );
};
$private_keys = array( 'email', 'phone', 'birthdate', 'perm_address', 'perm_city', 'perm_pincode', 'company_phone', 'aadhaar_card', 'aadhaar_card_id' );

/* ---------- profilePageData: who gets private fields ---------- */
$d = $local( 0, $alice['login'] );
nx_assert( 'guest' === $d['roleType'] && empty( array_intersect( $private_keys, array_keys( $d['userData'] ) ) ), 'guest receives no private profile fields' );
$d = $local( $bob['user_id'], $alice['login'] );
nx_assert( 'viewer' === $d['roleType'] && empty( array_intersect( $private_keys, array_keys( $d['userData'] ) ) ), 'other member receives no private profile fields' );
nx_assert_same( 'Alice', $d['userData']['first_name'], 'viewer still gets the public name' );
nx_assert( false === strpos( wp_json_encode( $d ), $secret['email'] ) && false === strpos( wp_json_encode( $d ), $doc_url ), 'viewer payload contains neither email nor document URL' );
$d = $local( $alice['user_id'], $alice['login'] );
nx_assert( 'owner' === $d['roleType'] && $secret['email'] === $d['userData']['email'] && $doc_url === $d['userData']['aadhaar_card'], 'owner receives own private fields on own profile URL' );
$d = $local( $alice['user_id'], '' );
nx_assert( 'owner' === $d['roleType'] && $secret['email'] === $d['userData']['email'], '[PHASE1] owner data is also inlined when NO profile page is being viewed (H2)' );

/* ---------- profile page HTML ---------- */
$render = function ( $viewer_id, $username ) {
	wp_set_current_user( $viewer_id );
	$GLOBALS['wp_query'] = new WP_Query( array( 'pagename' => 'profile-page' ) );
	set_query_var( 'username', $username );
	return do_shortcode( '[profile_dashboard]' );
};
$html = $render( 0, $alice['login'] );
nx_assert( false !== stripos( $html, 'Access Restricted' ) && false === strpos( $html, $secret['email'] ), 'guest sees the login wall, no data' );
$html = $render( $bob['user_id'], $alice['login'] );
nx_assert( false !== strpos( $html, 'profile-container' ), 'member sees a profile page' );
foreach ( array( 'email', 'phone', 'perm_address', 'perm_city', 'perm_pincode' ) as $k ) {
	nx_assert( false === strpos( $html, $secret[ $k ] ), "other member's page HTML does not contain owner's $k" );
}
nx_assert( false === strpos( $html, $doc_url ), "other member's page HTML does not contain a document URL" );
// [DECISION] Work info is rendered server-side for every logged-in viewer, although the JS data treats company_* as owner-only.
update_post_meta( $alice['profile_id'], 'company_name', 'Acme Corp' );
$html_work = $render( $bob['user_id'], $alice['login'] );
nx_assert( false !== strpos( $html_work, '+91-1111' ) && false !== strpos( $html_work, 'Acme Corp' ), '[DECISION] other members currently SEE work info (company name/phone) in the page HTML' );
$html = $render( $alice['user_id'], $alice['login'] );
nx_assert( false !== strpos( $html, $secret['email'] ) && false !== strpos( $html, $secret['phone'] ), 'owner sees own email and phone' );
$html = $render( $bob['user_id'], 'no_such_member_zz' );
nx_assert( false !== stripos( $html, 'User not found' ), 'unknown username shows "User not found"' );

/* ---------- shortcodes ---------- */
wp_set_current_user( 0 );
$h = do_shortcode( '[nexora_auth_buttons]' );
nx_assert( false !== strpos( $h, '/login-page/' ) && false !== strpos( $h, '/registration-page/' ) && false !== strpos( $h, 'Log in' ), 'auth buttons (guest): Log in / Sign up' );
wp_set_current_user( $alice['user_id'] );
$h = do_shortcode( '[nexora_auth_buttons]' );
nx_assert( false !== strpos( $h, rawurlencode( $alice['login'] ) ) && false !== strpos( $h, 'Log out' ) && false !== strpos( $h, 'action=logout' ), 'auth buttons (member): profile link + logout' );
wp_set_current_user( 0 );
delete_transient( Nexora_Home_Page::STATS_TRANSIENT );
$members = (int) wp_count_posts( 'user_profile' )->publish;
nx_assert_same( Nexora_Home_Page::short_number( $members ), do_shortcode( '[nexora_stat type="members"]' ), '[nexora_stat members] matches published profiles' );
nx_assert_same( '', do_shortcode( '[nexora_stat type="bogus"]' ), '[nexora_stat] unknown type renders nothing' );
foreach ( array( 'connections', 'posts', 'chats' ) as $t ) {
	nx_assert( preg_match( '/^[0-9.]+[KM]?$/', do_shortcode( "[nexora_stat type=\"$t\"]" ) ) === 1, "[nexora_stat $t] renders a compact number" );
}
nx_assert_same( '999', Nexora_Home_Page::short_number( 999 ), 'short_number 999' );
nx_assert_same( '1.3K', Nexora_Home_Page::short_number( 1250 ), 'short_number 1250 -> 1.3K (PHP round half up)' );
nx_assert_same( '2.5M', Nexora_Home_Page::short_number( 2500000 ), 'short_number 2.5M' );
nx_assert( is_array( get_transient( Nexora_Home_Page::STATS_TRANSIENT ) ), 'stats are cached in a transient' );
$h = do_shortcode( '[profile_login]' );
nx_assert( false !== strpos( $h, 'id="profile-login-form"' ) && false !== strpos( $h, 'forgot-password-btn' ), 'login form renders for guests' );
$h = do_shortcode( '[profile_registration]' );
nx_assert( false !== strpos( $h, 'id="profile-registration-form"' ) && false !== strpos( $h, 'name="confirm_password"' ), 'registration form renders for guests' );
wp_set_current_user( $alice['user_id'] );
nx_assert( false !== strpos( do_shortcode( '[profile_login]' ), 'already logged in' ), 'login form shows "already logged in" for members' );
nx_assert( false !== strpos( do_shortcode( '[profile_registration]' ), 'already logged in' ), 'registration shows "already logged in" for members' );
wp_set_current_user( 0 );
nx_assert( false !== strpos( do_shortcode( '[nexora_home]' ), 'nx-' ) || '' !== do_shortcode( '[nexora_home]' ), '[nexora_home] renders' );
$h = do_shortcode( '[nexora_contact_form]' );
nx_assert( false !== strpos( $h, 'name="nx_contact_nonce"' ) && false !== strpos( $h, 'name="nx_website"' ) && false !== strpos( $h, 'admin-post.php' ), 'contact form has nonce, honeypot and posts to admin-post.php' );

/* ---------- contact handler (runs in a child process because it exits) ---------- */
$contact = function ( array $post ) {
	putenv( 'NX_POST=' . wp_json_encode( $post ) );
	return (string) shell_exec( 'cd ' . escapeshellarg( ABSPATH ) . ' && wp eval-file tests/child/contact.php --path=. 2>/dev/null' );
};
wp_set_current_user( 0 );
$nonce = wp_create_nonce( 'nexora_contact' );
$good  = array( 'nx_contact_nonce' => $nonce, 'nx_name' => 'Visitor', 'nx_email' => 'visitor@example.test', 'nx_subject' => 'Hi', 'nx_message' => 'Hello there', 'redirect_to' => home_url( '/contact/' ) );
$out = $contact( array( 'nx_contact_nonce' => 'bad' ) + $good );
nx_assert( false !== strpos( $out, 'nx_contact=error' ) && false === strpos( $out, 'MAILED' ), 'contact: bad nonce -> error, no mail' );
$out = $contact( array( 'nx_website' => 'http://spam.test' ) + $good );
nx_assert( false !== strpos( $out, 'nx_contact=sent' ) && false === strpos( $out, 'MAILED' ), 'contact: honeypot -> fake success, no mail' );
$out = $contact( array( 'nx_email' => 'nope' ) + $good );
nx_assert( false !== strpos( $out, 'nx_contact=invalid' ) && false === strpos( $out, 'MAILED' ), 'contact: invalid email -> invalid, no mail' );
$out = $contact( array( 'redirect_to' => 'https://evil.example/' ) + $good );
nx_assert( false === strpos( $out, 'evil.example' ), 'contact: off-site redirect_to is not followed' );
nx_test_reset_limits();
$out = $contact( $good );
nx_assert( false !== strpos( $out, 'nx_contact=sent' ) && 1 === substr_count( $out, 'MAILED' ), 'contact: valid message mailed once, redirect "sent"' );
nx_assert( false !== strpos( $out, 'visitor@example.test' ) && false !== strpos( $out, 'Reply-To' ), 'contact: Reply-To header set from sender' );
$out = $contact( array( 'nx_name' => "Evil\r\nBcc: x@y.test" ) + $good );
nx_assert( false === strpos( $out, "\nBcc:" ), 'contact: CRLF in name cannot inject headers' );
nx_test_reset_limits();
for ( $i = 0; $i < 3; $i++ ) { $contact( $good ); }
$out = $contact( $good );
nx_assert( false !== strpos( $out, 'nx_contact=limit' ) && false === strpos( $out, 'MAILED' ), 'contact: 4th message within the window is rate limited' );
nx_test_reset_limits();

/* ---------- registration rate limit (5 successful sign-ups / hour / IP) ---------- */
set_transient( 'nx_reg_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 5, HOUR_IN_SECONDS );
$r = nx_call_ajax( 'profile_register', array( 'email' => 'rl@example.test', 'user_name' => 'nxtest_rl_user', 'password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x' ) + nx_profile_nonce(), true );
nx_assert( nx_rejected( $r ) && ! username_exists( 'nxtest_rl_user' ), 'registration rate limit blocks the 6th sign-up' );

nx_test_finish();
