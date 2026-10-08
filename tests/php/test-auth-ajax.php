<?php
// Characterization of includes/class-login.php and class-registration.php (guest-facing AJAX).
// Assertions tagged [PHASE1] describe known weaknesses that Phase 1 changes ON PURPOSE.
require_once __DIR__ . '/../bootstrap.php';

$mail = &nx_test_capture_mail();
nx_test_reset_limits();
wp_set_current_user( 0 );

$pw   = 'Correct#Horse9';
$u    = nx_test_user( 'auth' );
wp_set_password( $pw, $u['user_id'] );
$email = get_userdata( $u['user_id'] )->user_email;

$guest = function ( $action, array $post ) { wp_set_current_user( 0 ); return nx_call_ajax( $action, $post + nx_profile_nonce(), true ); };

/* ---------- nonce is mandatory on every guest endpoint ---------- */
foreach ( array( 'profile_login', 'send_otp', 'verify_otp', 'reset_password', 'profile_register' ) as $a ) {
	wp_set_current_user( 0 );
	nx_assert( nx_rejected( nx_call_ajax( $a, array( 'user_name' => $u['login'], 'password' => $pw ), true ) ), "$a: missing nonce rejected" );
	nx_assert( nx_rejected( nx_call_ajax( $a, array( 'nonce' => 'bad' ), true ) ), "$a: wrong nonce rejected" );
}

/* ---------- login ---------- */
$r = $guest( 'profile_login', array( 'user_name' => $u['login'], 'password' => 'nope' ) );
nx_assert( nx_rejected( $r ) && 'Invalid username or password' === $r['data'], 'wrong password -> generic error' );
$r = $guest( 'profile_login', array( 'user_name' => 'nobody@example.test', 'password' => 'x' ) );
nx_assert( nx_rejected( $r ) && 'Invalid username or password' === $r['data'], 'unknown email -> same generic error (no enumeration)' );
$r = $guest( 'profile_login', array( 'user_name' => '', 'password' => '' ) );
nx_assert( nx_rejected( $r ), 'empty credentials rejected' );
$r = $guest( 'profile_login', array( 'user_name' => $u['login'], 'password' => $pw ) );
nx_assert( ! empty( $r['success'] ) && home_url( '/profile-page/' . rawurlencode( $u['login'] ) ) === $r['data']['redirect'], 'login by username -> own profile URL' );
$r = $guest( 'profile_login', array( 'user_name' => $email, 'password' => $pw ) );
nx_assert( ! empty( $r['success'] ), 'login by email works' );
nx_test_reset_limits();
for ( $i = 0; $i < 10; $i++ ) { $guest( 'profile_login', array( 'user_name' => $u['login'], 'password' => 'bad' ) ); }
$r = $guest( 'profile_login', array( 'user_name' => $u['login'], 'password' => $pw ) );
nx_assert( nx_rejected( $r ) && false !== stripos( (string) $r['data'], 'too many' ), 'login rate limit: 10 attempts / window, then blocked (even with right password)' );
nx_test_reset_limits();

/* ---------- send_otp ---------- */
$is_ref = function ( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $v ); };
$r_unknown = $guest( 'send_otp', array( 'username' => 'ghost_user_x', 'email' => 'ghost@example.test' ) );
nx_assert( ! empty( $r_unknown['success'] ) && $is_ref( $r_unknown['data']['user_id'] ) && 0 === count( $mail ), '[PHASE1d] unknown user: generic success with an opaque reference, no mail' );
nx_assert( false === get_transient( 'nexora_otp_ref_' . $r_unknown['data']['user_id'] ), '[PHASE1d] the fake reference maps to nobody' );
$r = $guest( 'send_otp', array( 'username' => $u['login'], 'email' => 'other@example.test' ) );
nx_assert( ! empty( $r['success'] ) && $is_ref( $r['data']['user_id'] ) && 0 === count( $mail ), 'wrong email: same shape as unknown user, no mail' );
$r = $guest( 'send_otp', array( 'username' => $u['login'], 'email' => strtoupper( $email ) ) );
nx_assert( ! empty( $r['success'] ), 'matching username+email (case-insensitive) succeeds' );
$ref = $r['data']['user_id'];
nx_assert( $is_ref( $ref ) && (string) $u['user_id'] !== $ref, '[PHASE1d] reply carries an opaque reference, never the real user id' );
nx_assert_same( $u['user_id'], (int) get_transient( 'nexora_otp_ref_' . $ref ), 'the reference resolves server-side to the account' );
nx_assert_same( array_keys( $r_unknown['data'] ), array_keys( $r['data'] ), '[PHASE1d] real and fake replies have identical fields' );
nx_assert_same( $r_unknown['data']['message'], $r['data']['message'], '[PHASE1d] real and fake replies have identical messages' );
nx_assert_same( 1, count( $mail ), 'one OTP mail sent' );
preg_match( '/OTP is: (\d{6})/', $mail[0]['message'] ?? '', $m );
$otp = $m[1] ?? '';
nx_assert( 6 === strlen( $otp ), 'mail contains a 6-digit OTP' );
$stored = get_user_meta( $u['user_id'], 'reset_otp', true );
nx_assert( $otp !== $stored && wp_check_password( $otp, $stored ), 'OTP stored hashed, not plaintext' );
$exp = (int) get_user_meta( $u['user_id'], 'otp_expiry', true );
nx_assert( $exp > time() + 590 && $exp <= time() + 600, 'OTP expires in 10 minutes' );
$r = $guest( 'send_otp', array( 'username' => $u['login'], 'email' => $email ) );
nx_assert( 1 === count( $mail ) && $r_unknown['data']['message'] === $r['data']['message'] && $is_ref( $r['data']['user_id'] ), '[PHASE1d] no second OTP while one is valid, and the reply looks the same (anti mail-flood, no oracle)' );
$ref = $r['data']['user_id'];
nx_test_reset_limits();
for ( $i = 0; $i < 5; $i++ ) { $guest( 'send_otp', array( 'username' => 'ghost', 'email' => 'g@example.test' ) ); }
$r = $guest( 'send_otp', array( 'username' => 'ghost', 'email' => 'g@example.test' ) );
nx_assert( nx_rejected( $r ), 'send_otp rate limit: 5 / window' );
nx_test_reset_limits();

/* ---------- verify_otp ---------- */
$r = $guest( 'verify_otp', array( 'user_id' => (string) ( $u['user_id'] ), 'otp' => '123456' ) );
nx_assert( nx_rejected( $r ) && 'Invalid or expired OTP' === $r['data'], '[PHASE1d] a raw numeric user id is not accepted any more' );
$r = $guest( 'verify_otp', array( 'user_id' => $r_unknown['data']['user_id'], 'otp' => '123456' ) );
nx_assert( nx_rejected( $r ) && 'Invalid or expired OTP' === $r['data'], '[PHASE1d] fake reference answers the same generic error' );
$r = $guest( 'verify_otp', array( 'user_id' => $ref, 'otp' => '000000' ) );
nx_assert( nx_rejected( $r ) && 'Invalid OTP' === $r['data'] && 1 === (int) get_user_meta( $u['user_id'], 'otp_attempts', true ), 'real reference + wrong OTP -> "Invalid OTP" and the attempt is counted' );
for ( $i = 0; $i < 4; $i++ ) { $guest( 'verify_otp', array( 'user_id' => $ref, 'otp' => '000000' ) ); }
$r = $guest( 'verify_otp', array( 'user_id' => $ref, 'otp' => $otp ) );
nx_assert( nx_rejected( $r ) && '' === (string) get_user_meta( $u['user_id'], 'reset_otp', true ), 'after 5 wrong attempts even the right OTP is refused and the OTP is wiped' );

// fresh OTP, expiry path (the reference stays valid, the OTP itself expires)
update_user_meta( $u['user_id'], 'reset_otp', wp_hash_password( '111111' ) );
update_user_meta( $u['user_id'], 'otp_expiry', time() - 5 );
update_user_meta( $u['user_id'], 'otp_attempts', 0 );
$r = $guest( 'verify_otp', array( 'user_id' => $ref, 'otp' => '111111' ) );
nx_assert( nx_rejected( $r ) && 'OTP expired' === $r['data'] && '' === (string) get_user_meta( $u['user_id'], 'reset_otp', true ), 'expired OTP rejected and cleared' );

// success path -> token
update_user_meta( $u['user_id'], 'reset_otp', wp_hash_password( '222222' ) );
update_user_meta( $u['user_id'], 'otp_expiry', time() + 600 );
update_user_meta( $u['user_id'], 'otp_attempts', 0 );
$r = $guest( 'verify_otp', array( 'user_id' => $ref, 'otp' => '222222' ) );
nx_assert( ! empty( $r['success'] ) && 32 === strlen( $r['data']['token'] ), 'correct OTP returns a 32-char reset token' );
$token = $r['data']['token'] ?? '';
nx_assert( '' === (string) get_user_meta( $u['user_id'], 'reset_otp', true ) && wp_check_password( $token, get_user_meta( $u['user_id'], 'reset_token', true ) ), 'OTP consumed; token stored hashed' );

/* ---------- reset_password ---------- */
$r = $guest( 'reset_password', array( 'user_id' => $ref, 'token' => 'wrong-token', 'password' => 'Brand#New123' ) );
nx_assert( nx_rejected( $r ), 'wrong token rejected' );
$r = $guest( 'reset_password', array( 'user_id' => $ref, 'token' => '', 'password' => 'Brand#New123' ) );
nx_assert( nx_rejected( $r ), 'empty token rejected' );
$r = $guest( 'reset_password', array( 'user_id' => $ref, 'token' => $token, 'password' => 'short' ) );
nx_assert( nx_rejected( $r ) && wp_check_password( $pw, get_userdata( $u['user_id'] )->user_pass ), 'short password rejected, old password still valid' );
$mail_before = count( $mail );
$r = $guest( 'reset_password', array( 'user_id' => $ref, 'token' => $token, 'password' => 'Brand#New123' ) );
nx_assert( ! empty( $r['success'] ) && home_url( '/profile-page/' . rawurlencode( $u['login'] ) ) === $r['data']['redirect'], 'valid token resets password and returns profile redirect' );
nx_assert( wp_check_password( 'Brand#New123', get_userdata( $u['user_id'] )->user_pass ), 'new password active' );
nx_assert( count( $mail ) === $mail_before + 1, 'confirmation mail sent' );
$r = $guest( 'reset_password', array( 'user_id' => $ref, 'token' => $token, 'password' => 'Another#Pass1' ) );
nx_assert( nx_rejected( $r ), 'token is single use' );
nx_assert( false === get_transient( 'nexora_otp_ref_' . $ref ), '[PHASE1d] the reference is discarded after a successful reset' );
// token expiry
update_user_meta( $u['user_id'], 'reset_token', wp_hash_password( 'T' ) );
update_user_meta( $u['user_id'], 'reset_token_expiry', time() - 1 );
nx_assert( nx_rejected( $guest( 'reset_password', array( 'user_id' => $ref, 'token' => 'T', 'password' => 'Another#Pass1' ) ) ), 'expired token rejected' );
nx_test_reset_limits();

/* ---------- registration ---------- */
$tag = strtolower( wp_generate_password( 6, false ) );
$ok  = array( 'email' => "reg$tag@example.test", 'user_name' => "nxtest_reg_$tag", 'password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x',
	'first_name' => 'Reg', 'last_name' => 'Tester', 'phone' => '9999999999', 'gender' => 'other', 'birthdate' => '1995-01-31' );
$bad = function ( $over, $label ) use ( $guest, $ok ) { $r = $guest( 'profile_register', $over + $ok ); nx_assert( nx_rejected( $r ), $label ); return $r; };
$bad( array( 'email' => '' ), 'registration: missing email rejected' );
$bad( array( 'email' => 'not-email' ), 'registration: invalid email rejected' );
$bad( array( 'user_name' => 'a b' ), 'registration: username with space rejected' );
$bad( array( 'user_name' => 'ab' ), 'registration: username under 3 chars rejected' );
$bad( array( 'password' => 'short', 'confirm_password' => 'short' ), 'registration: short password rejected' );
$bad( array( 'confirm_password' => 'Different1!' ), 'registration: password mismatch rejected' );
$bad( array( 'birthdate' => '2999-01-01' ), 'registration: future birthdate rejected' );
$bad( array( 'user_name' => $u['login'] ), 'registration: duplicate username rejected' );
$bad( array( 'email' => $email ), 'registration: duplicate email rejected' );
nx_assert( ! username_exists( "nxtest_reg_$tag" ), 'rejected registrations create no user' );

$mail_before = count( $mail );
$r = $guest( 'profile_register', $ok );
nx_assert( ! empty( $r['success'] ) && home_url( '/profile-page/' . rawurlencode( $ok['user_name'] ) ) === $r['data']['redirect'], 'valid registration returns profile redirect' );
$new = get_user_by( 'login', $ok['user_name'] );
nx_assert( (bool) $new, 'WP user created' );
if ( $new ) {
	$GLOBALS['nx_test']['users'][] = $new->ID;
	$pid = (int) get_user_meta( $new->ID, '_profile_id', true );
	$GLOBALS['nx_test']['posts'][] = $pid;
	nx_assert( $pid && 'user_profile' === get_post_type( $pid ) && (int) get_post_meta( $pid, '_wp_user_id', true ) === $new->ID, 'profile post created and linked both ways' );
	foreach ( array( 'user_name' => $ok['user_name'], 'first_name' => 'Reg', 'last_name' => 'Tester', 'email' => $ok['email'], 'phone' => '9999999999', 'gender' => 'other', 'birthdate' => '1995-01-31' ) as $k => $v ) {
		nx_assert_same( $v, get_post_meta( $pid, $k, true ), "profile meta stored: $k" );
	}
	nx_assert( in_array( 'subscriber', $new->roles, true ), 'new member gets the subscriber role' );
	nx_assert_same( $new->ID, get_current_user_id(), 'member is auto-logged-in after registration' );
}
nx_assert( count( $mail ) === $mail_before + 1 && false !== strpos( $mail[ count( $mail ) - 1 ]['message'], $ok['user_name'] ), 'admin notified of the new member' );

// invalid gender is dropped, not rejected
$tag2 = strtolower( wp_generate_password( 6, false ) );
$r = $guest( 'profile_register', array( 'email' => "g$tag2@example.test", 'user_name' => "nxtest_g_$tag2", 'gender' => 'zzz' ) + $ok );
if ( $nu = get_user_by( 'login', "nxtest_g_$tag2" ) ) {
	$GLOBALS['nx_test']['users'][] = $nu->ID;
	$GLOBALS['nx_test']['posts'][] = (int) get_user_meta( $nu->ID, '_profile_id', true );
	nx_assert_same( '', get_post_meta( (int) get_user_meta( $nu->ID, '_profile_id', true ), 'gender', true ), 'unknown gender stored as empty' );
}
nx_test_reset_limits();
nx_test_finish();
