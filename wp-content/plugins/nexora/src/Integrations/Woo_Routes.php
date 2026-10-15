<?php

namespace Nexora\Integrations;

use Nexora\Core\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps WooCommerce's own account pages from being a second front door. Members sign up, log in and see their
 * account through Nexora; Woo's /my-account/ login and registration forms would skip Nexora's captcha, rate
 * limits and generic errors, and checkout sign-up would create users with no Nexora profile.
 *
 * The cart and checkout routes (guests, forged items, order lookups) are closed in the checkout phase.
 */
class Woo_Routes {

	/**
	 * Woo options that must stay off whatever the settings screen says.
	 *
	 * @return string[]
	 */
	public static function forced_off_options() {
		return array(
			'woocommerce_enable_signup_and_login_from_checkout',
			'woocommerce_enable_checkout_login_reminder',
			'woocommerce_enable_myaccount_registration',
		);
	}

	/**
	 * Forces the sign-up / login options off and hooks the account-page redirect.
	 */
	public function __construct() {

		foreach ( self::forced_off_options() as $option ) {
			add_filter( 'pre_option_' . $option, array( __CLASS__, 'off' ) );
		}

		add_filter( 'woocommerce_checkout_registration_enabled', '__return_false' );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_ajax_requests' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_account_page' ), 1 );
	}

	/**
	 * The forced value of a Woo yes/no option.
	 *
	 * @return string
	 */
	public static function off() {
		return 'no';
	}

	/**
	 * WooCommerce keeps members out of wp-admin and exempts only admin-ajax.php and admin-post.php by file name. The media
	 * modal uploads through wp-admin/async-upload.php (an AJAX request that WordPress marks with DOING_AJAX), so every member's
	 * upload, product images included, was redirected to /my-account/ and failed. AJAX requests are let through here; Nexora's
	 * own Access_Control still bounces members from every real admin page.
	 *
	 * @param bool $prevent Whether WooCommerce is about to block the request.
	 * @return bool
	 */
	public static function allow_ajax_requests( $prevent ) {
		return wp_doing_ajax() ? false : $prevent;
	}

	/**
	 * Sends visitors of Woo's My account page (and its endpoints) to Nexora: guests to the login, members to
	 * their own profile. Administrators are left alone.
	 */
	public static function redirect_account_page() {

		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( is_user_logged_in() ) {

			if ( current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_safe_redirect( Urls::profile_for( wp_get_current_user() ) );
			exit;
		}

		wp_safe_redirect( Urls::login() );
		exit;
	}
}
