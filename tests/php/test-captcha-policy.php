<?php
// Phase 1d (M2): the captcha bypass must not depend on WP_ENVIRONMENT_TYPE alone.
require_once __DIR__ . '/../bootstrap.php';

nx_assert_same( 'local', wp_get_environment_type(), 'precondition: this dev site reports environment type "local"' );
nx_assert( (bool) get_option( 'recaptcha_enabled' ) && get_option( 'recaptcha_site_key' ) && get_option( 'recaptcha_secret_key' ), 'precondition: captcha is enabled with keys on this dev DB' );

$c = new Nexora_ReCaptcha();

/* dev host (resolves to a private address) -> bypass keeps local development working */
nx_assert( true === $c->verify( '' )['success'], 'local environment + private-address host: captcha bypassed (dev workflow unchanged)' );
nx_assert_same( '', $c->render(), 'local environment + private-address host: no widget rendered' );

/* the same config on a public hostname must NOT bypass */
$public = function () { return 'https://nexora.zedthron.com'; };
add_filter( 'nexora_captcha_site_host', function () { return 'nexora.zedthron.com'; } );
add_filter( 'nexora_captcha_host_ip', function () { return '203.0.113.10'; } );   // documentation range: public, not private
$r = $c->verify( '' );
nx_assert( false === $r['success'] && 'Captcha is required' === $r['message'], '[PHASE1d] environment "local" on a public hostname does NOT bypass the captcha' );
nx_assert( false !== strpos( $c->render(), 'g-recaptcha' ), '[PHASE1d] widget is rendered on a public hostname even if environment says local' );

/* unresolvable host is treated as public (fail safe) */
remove_all_filters( 'nexora_captcha_host_ip' );
add_filter( 'nexora_captcha_site_host', function () { return 'no-such-host.invalid'; } );
nx_assert( false === $c->verify( '' )['success'], 'unresolvable hostname is treated as public (fails safe)' );

/* explicit opt-in still works, for CI or staging */
add_filter( 'nexora_skip_captcha', '__return_true' );
nx_assert( true === $c->verify( '' )['success'], 'explicit nexora_skip_captcha opt-in bypasses on any host' );
remove_all_filters( 'nexora_skip_captcha' );
remove_all_filters( 'nexora_captcha_site_host' );

/* the Host header of the request is never consulted */
$_SERVER['HTTP_HOST'] = 'localhost';
add_filter( 'nexora_captcha_site_host', function () { return 'nexora.zedthron.com'; } );
add_filter( 'nexora_captcha_host_ip', function () { return '203.0.113.10'; } );
nx_assert( false === $c->verify( '' )['success'], 'a client-supplied Host: localhost cannot switch the captcha off' );
remove_all_filters( 'nexora_captcha_site_host' );
remove_all_filters( 'nexora_captcha_host_ip' );

nx_test_finish();
