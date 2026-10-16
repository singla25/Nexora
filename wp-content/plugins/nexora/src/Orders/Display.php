<?php

namespace Nexora\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How money is shown on the order screens: plain text with the shop currency (the template escapes it).
 */
class Display {

	/**
	 * An amount such as "180.00" as "₹180.00" (the shop's currency symbol and format).
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
