<?php

namespace Nexora\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small dynamic shortcodes meant to be dropped into Elementor (Shortcode
 * widget) so templates never hard-code numbers or login-dependent buttons.
 *
 *  [nexora_stat type="members|connections|posts|chats"]
 *  [nexora_auth_buttons]
 */
class Stats {

	/**
	 * Registers [nexora_stat] and [nexora_auth_buttons].
	 */
	public function __construct() {
		add_shortcode( 'nexora_stat', array( $this, 'stat' ) );
		add_shortcode( 'nexora_auth_buttons', array( $this, 'auth_buttons' ) );
	}

	/**
	 * Shortcode: one live platform number.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function stat( $atts ) {

		$atts = shortcode_atts( array( 'type' => 'members' ), $atts, 'nexora_stat' );
		$type = sanitize_key( $atts['type'] );

		$stats = \Nexora\Shortcodes\Home::get_stats();

		if ( ! isset( $stats[ $type ] ) ) {
			return '';
		}

		return esc_html( \Nexora\Shortcodes\Home::short_number( $stats[ $type ] ) );
	}

	/**
	 * Shortcode: Log in / Sign up, or the member's profile link and Log out.
	 *
	 * @return string HTML.
	 */
	public function auth_buttons() {

		if ( ! is_user_logged_in() ) {
			return '<span class="nx-auth">'
				. '<a class="nx-btn nx-outline" href="' . esc_url( \Nexora\Core\Urls::login( true ) ) . '">Log in</a>'
				. '<a class="nx-btn nx-primary" href="' . esc_url( \Nexora\Core\Urls::registration( true ) ) . '">Sign up</a>'
				. '</span>';
		}

		$user = wp_get_current_user();

		$profile = \Nexora\Core\Urls::profile_for( $user, true );

		return '<span class="nx-auth">'
			. '<a class="nx-btn nx-outline" href="' . esc_url( $profile ) . '">' . esc_html( $user->display_name ) . '</a>'
			. '<a class="nx-btn nx-primary" href="' . esc_url( wp_logout_url( \Nexora\Core\Urls::login( true ) ) ) . '">Log out</a>'
			. '</span>';
	}
}
