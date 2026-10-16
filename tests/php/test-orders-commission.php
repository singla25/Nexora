<?php
// Marketplace Phase 6b: the commission. 10% by default, configurable in Settings (nexora_commission_rate), worked out from the stored
// line price, rounded to 2 decimals; the line keeps the rate it was ordered at.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Orders\Commission;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }

nx_assert_same( 'nexora_commission_rate', Commission::OPTION, 'the setting is nexora_commission_rate' );

/* ---------- the rate ---------- */
nx_assert_same( 10.0, Commission::rate(), 'with nothing saved the commission is 10%' );
foreach ( array( '12.5' => 12.5, '0' => 0.0, '100' => 100.0, '7' => 7.0, '33.33' => 33.33 ) as $saved => $want ) {
	nx_test_options( array( Commission::OPTION => $saved ) );
	nx_assert_same( $want, Commission::rate(), "a saved rate of $saved% is used" );
}
foreach ( array( 'abc', '-5', '101', '', '1e2', '12,5', '10%', '5.123', array( '5' ) ) as $bad ) {
	nx_test_options( array( Commission::OPTION => $bad ) );
	nx_assert( Commission::rate() >= 0.0 && Commission::rate() <= 100.0, 'a damaged stored value (' . wp_json_encode( $bad ) . ') never gives a rate outside 0-100' );
}
nx_test_options( array( Commission::OPTION => 'abc' ) );
nx_assert_same( 10.0, Commission::rate(), 'text falls back to 10%' );
nx_test_options( array( Commission::OPTION => '250' ) );
nx_assert_same( 10.0, Commission::rate(), 'a rate above 100 falls back to 10%' );
nx_test_options( array( Commission::OPTION => null ) );

/* ---------- what the Settings form may save ---------- */
foreach ( array( '12.5' => '12.5', '12.50' => '12.5', '0' => '0', '100' => '100', ' 8 ' => '8', '15.255' => '15.26', '0.005' => '0.01' ) as $in => $want ) {
	nx_assert_same( $want, Commission::sanitize( $in ), "Settings: '$in' is saved as $want" );
}
foreach ( array( 'abc', '-1', '100.01', '', '1e1', '5%', array( '5' ), null, '<script>' ) as $bad ) {
	nx_assert_same( '10', Commission::sanitize( $bad ), 'Settings: ' . wp_json_encode( $bad ) . ' is refused and the rate stays at 10' );
}

(new Nexora\Admin\Settings())->register_settings();
$registered = get_registered_settings();
nx_assert( isset( $registered[ Commission::OPTION ] ) && is_callable( $registered[ Commission::OPTION ]['sanitize_callback'] ), 'the setting is registered with its sanitiser' );
nx_assert_same( '12.5', sanitize_option( Commission::OPTION, '12.50' ), 'and WordPress runs it when the option is saved' );
nx_assert_same( '10', sanitize_option( Commission::OPTION, 'nonsense' ), '... also for nonsense' );

wp_set_current_user( nx_test_admin() );
set_current_screen( 'dashboard' );
ob_start();
( new \Nexora\Admin\Settings() )->settings_page();
$html = ob_get_clean();
wp_set_current_user( 0 );
nx_assert( 1 === preg_match( '/<input[^>]*name="nexora_commission_rate"[^>]*value="10"[^>]*>/', $html ) || 1 === preg_match( '/<input[^>]*value="10"[^>]*name="nexora_commission_rate"[^>]*>/', $html ), 'the Settings screen has a commission field showing 10' );
nx_assert( false !== stripos( $html, 'commission' ), 'with a label' );
nx_assert( in_array( Commission::OPTION, \Nexora\Database\Uninstaller::defaults()['options'], true ), 'the option is on the uninstall list' );

/* ---------- the split of one line ---------- */
$cases = array(
	array( '100.00', 1, 10.0, '100.00', '10.00', '90.00' ),
	array( '100.00', 2, 10.0, '200.00', '20.00', '180.00' ),
	array( '33.33', 1, 10.0, '33.33', '3.33', '30.00' ),
	array( '33.35', 1, 10.0, '33.35', '3.34', '30.01' ),
	array( '0.01', 1, 10.0, '0.01', '0.00', '0.01' ),
	array( '0.05', 1, 10.0, '0.05', '0.01', '0.04' ),
	array( '19.99', 3, 12.5, '59.97', '7.50', '52.47' ),
	array( '250.00', 1, 0.0, '250.00', '0.00', '250.00' ),
	array( '250.00', 1, 100.0, '250.00', '250.00', '0.00' ),
	array( '1234567.89', 2, 10.0, '2469135.78', '246913.58', '2222222.20' ),
);
foreach ( $cases as $c ) {
	$s = Commission::split( $c[0], $c[1], $c[2] );
	nx_assert_same( array( 'gross' => $c[3], 'commission' => $c[4], 'earning' => $c[5] ), $s, "price {$c[0]} x {$c[1]} at {$c[2]}%: gross {$c[3]}, commission {$c[4]}, earning {$c[5]}" );
	nx_assert_same( $c[3], number_format( (float) $s['commission'] + (float) $s['earning'], 2, '.', '' ), 'commission + earning always add up to the line total' );
}
nx_test_options( array( Commission::OPTION => '20' ) );
nx_assert_same( '20.00', Commission::split( '100.00', 1 )['commission'], 'with no rate given the saved rate is used (20%)' );

nx_test_finish();
