<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The marketplace commission. One setting (Nexora System > Settings > Commission, 10% until changed), validated on every read and on
 * every save; the split of a line is worked out in whole cents so nothing drifts through floating point. An order line keeps the rate
 * it was ordered at (Orders\Lines), so changing the setting later never changes an old order.
 */
class Commission {

	const OPTION       = 'nexora_commission_rate';
	const DEFAULT_RATE = '10';

	/**
	 * The commission rate in percent: 0 to 100, at most two decimals; anything else in the database reads as 10.
	 *
	 * @return float
	 */
	public static function rate() {

		$stored = get_option( self::OPTION, self::DEFAULT_RATE );
		$clean  = self::parse( $stored );

		return null === $clean ? (float) self::DEFAULT_RATE : $clean;
	}

	/**
	 * What the Settings form may save: plain digits with an optional decimal part, 0 to 100, rounded to two decimals, written without
	 * trailing zeros ("12.5"). Anything else is refused and the current rate stays.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize( $value ) {

		$clean = self::parse( $value );

		if ( null === $clean ) {

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, self::OPTION, __( 'The commission must be a number from 0 to 100, for example 10 or 12.5. The previous value was kept.', 'nexora' ) );
			}

			return self::format( self::rate() );
		}

		return self::format( $clean );
	}

	/**
	 * A rate as the text that is saved and shown ("10", "12.5").
	 *
	 * @param float $rate Percent.
	 * @return string
	 */
	public static function format( $rate ) {
		return rtrim( rtrim( number_format( (float) $rate, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * The split of one order line, from the stored unit price.
	 *
	 * @param mixed      $price Unit price (text such as "19.99").
	 * @param int        $qty   Quantity.
	 * @param float|null $rate  Percent; null = the saved rate.
	 * @return array gross, commission, earning (each text with two decimals; commission + earning = gross).
	 */
	public static function split( $price, $qty, $rate = null ) {

		$rate  = null === $rate ? self::rate() : (float) $rate;
		$gross = self::cents( $price ) * max( 0, (int) $qty );
		$bp    = (int) round( $rate * 100 ); // hundredths of a percent
		$comm  = intdiv( ( $gross * $bp * 2 ) + 10000, 20000 ); // half up, in cents

		return array(
			'gross'      => self::money( $gross ),
			'commission' => self::money( $comm ),
			'earning'    => self::money( $gross - $comm ),
		);
	}

	/**
	 * Text such as "19.99" in whole cents.
	 *
	 * @param mixed $amount Amount.
	 * @return int
	 */
	public static function cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Whole cents as text with two decimals.
	 *
	 * @param int $cents Cents.
	 * @return string
	 */
	public static function money( $cents ) {
		return number_format( (int) $cents / 100, 2, '.', '' );
	}

	/**
	 * Text to a rate between 0 and 100 (two decimals), or null when it is not one.
	 *
	 * @param mixed $value Text.
	 * @return float|null
	 */
	private static function parse( $value ) {

		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( ! preg_match( '/^\d{1,3}(\.\d+)?$/', $value ) ) {
			return null;
		}

		$rate = round( (float) $value, 2 );

		return ( $rate >= 0 && $rate <= 100 ) ? $rate : null;
	}
}
