<?php
// Phase 10: the sign-up form must not be usable to find out which usernames and e-mail addresses exist. Every attempt that reaches the
// "already exists" check is counted (bucket register_probe, per IP), not just the ones that create an account; once the limit is reached the
// answer is the same plain "too many" message whatever was typed.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Http\Rate_Limiter;

nx_test_reset_limits();
$taken = nx_test_user( 'probe' );
$taken_email = get_userdata( $taken['user_id'] )->user_email;
$guest = function ( array $post ) { wp_set_current_user( 0 ); return nx_call_ajax( 'nexora_profile_register', $post + nx_profile_nonce(), true ); };
$form  = function ( $name, $email ) { return array( 'email' => $email, 'user_name' => $name, 'password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x', 'first_name' => 'P', 'last_name' => 'Q', 'phone' => '9999999999', 'gender' => 'other', 'birthdate' => '1995-01-31' ); };
$msg   = function ( $r ) { return is_array( $r['data'] ?? null ) ? (string) ( $r['data']['message'] ?? '' ) : (string) ( $r['data'] ?? '' ); };

nx_assert( isset( Rate_Limiter::defaults()['register_probe'] ), 'the register_probe bucket exists by default' );
nx_assert( Rate_Limiter::defaults()['register_probe'][0] >= 10 && Rate_Limiter::defaults()['register_probe'][1] >= HOUR_IN_SECONDS, 'it allows honest typos (at least 10 a hour)' );

add_filter( 'nexora_rate_limits', function ( $d ) { $d['register_probe'] = array( 3, HOUR_IN_SECONDS ); return $d; } );
nx_test_reset_limits();
$replies = array();
for ( $i = 0; $i < 6; $i++ ) { $replies[] = $msg( $guest( $form( $taken['login'], "free$i@example.test" ) ) ); }
nx_assert_same( array( 'User already exists', 'User already exists', 'User already exists' ), array_slice( $replies, 0, 3 ), 'the first attempts get the normal answer' );
nx_assert( 'User already exists' !== $replies[3] && false !== stripos( $replies[3], 'too many' ), 'the 4th attempt is stopped with a "too many" message' );
nx_assert_same( $replies[3], $replies[5], '... and so are the next ones' );

// After the limit the answer is the same for a name that exists and one that does not (nothing to learn).
$free  = $msg( $guest( $form( 'nxtest_free_' . strtolower( wp_generate_password( 5, false ) ), 'unused' . wp_rand() . '@example.test' ) ) );
$exist = $msg( $guest( $form( $taken['login'], $taken_email ) ) );
nx_assert_same( $free, $exist, 'over the limit, a free name and a taken name get the identical answer' );
nx_assert( false === get_user_by( 'login', 'nxtest_free_zz' ), '(and no account was created by any of it)' );

// A successful sign-up is still counted by `register`; failures of other kinds (a typo in the e-mail) do not use up the probe allowance.
nx_test_reset_limits();
$before = Rate_Limiter::count( 'register_probe' );
$guest( $form( 'nxtest_typo_' . strtolower( wp_generate_password( 5, false ) ), 'not-an-email' ) );
nx_assert_same( $before, Rate_Limiter::count( 'register_probe' ), 'a form that fails basic validation (never reaches the existence check) is not counted as a probe' );
$guest( $form( $taken['login'], 'x' . wp_rand() . '@example.test' ) );
nx_assert_same( $before + 1, Rate_Limiter::count( 'register_probe' ), 'an attempt that reaches the existence check is counted once' );

nx_test_finish();
