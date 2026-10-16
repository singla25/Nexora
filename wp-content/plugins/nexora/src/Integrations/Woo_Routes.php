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
 * It also closes WooCommerce's cart, checkout and order doors to guests (Store API, wc-ajax, ?add-to-cart=, the cart and
 * checkout pages) and keeps guest checkout off: only a signed-in member can buy, and only through Nexora.
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
			'woocommerce_enable_guest_checkout',
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
		add_action( 'template_redirect', array( __CLASS__, 'redirect_shop_pages' ), 2 );
		add_action( 'init', array( __CLASS__, 'guard_wc_ajax' ), 1 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_store_api' ), 10, 3 );
		add_action( 'wp_loaded', array( __CLASS__, 'remove_woo_handlers' ), 0 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'login_address' ), 99 );
		add_filter( 'woocommerce_lostpassword_url', array( __CLASS__, 'login_address' ), 99 );
	}

	/**
	 * Woo's login, registration and password-reset form handlers are not used: Nexora's own forms (captcha, rate limits,
	 * OTP) are the only way in. The add-to-cart handler stays; Cart_Bridge gates it.
	 */
	public static function remove_woo_handlers() {
		foreach ( array( 'process_login', 'process_registration', 'process_lost_password', 'process_reset_password' ) as $handler ) {
			remove_action( 'wp_loaded', array( 'WC_Form_Handler', $handler ), 20 );
		}
	}

	/**
	 * Any lost-password address leads to the Nexora login (its OTP reset is the only reset).
	 *
	 * @return string
	 */
	public static function login_address() {
		return Urls::login();
	}

	/**
	 * The answer a guest gets from a cart door.
	 *
	 * @return array
	 */
	private static function sign_in_answer() {
		return array(
			'code'      => 'nexora_sign_in',
			'message'   => __( 'Please sign in to continue.', 'nexora' ),
			'login_url' => Urls::login(),
		);
	}

	/**
	 * Store API cart, checkout, order, batch and extension routes answer 401 to guests, and say nothing else. The public
	 * product list stays open.
	 *
	 * @param mixed            $result  Result so far.
	 * @param \WP_REST_Server  $server  Server (unused).
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_store_api( $result, $server, $request ) {

		unset( $server );

		if ( is_user_logged_in() || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( preg_match( '#^/wc/store(/v\d+)?/(cart|checkout|order|batch|cart-extensions)(/|$)#', $request->get_route() ) ) {
			$answer = self::sign_in_answer();
			return new \WP_Error( $answer['code'], $answer['message'], array( 'status' => 401 ) );
		}

		return $result;
	}

	/**
	 * Cart and checkout wc-ajax actions answer a guest with "sign in" and the login address.
	 */
	public static function guard_wc_ajax() {

		if ( is_user_logged_in() || empty( $_GET['wc-ajax'] ) || ! is_string( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$guarded = array( 'add_to_cart', 'remove_from_cart', 'checkout', 'update_order_review', 'apply_coupon', 'remove_coupon', 'update_shipping_method', 'get_cart_totals', 'update_cart' );

		if ( in_array( $action, $guarded, true ) ) {
			wp_send_json( self::sign_in_answer(), 401 );
		}
	}

	/**
	 * Guests on the cart or checkout page go to the login (and come back); guests on an order-received page go to the login.
	 */
	public static function redirect_shop_pages() {

		if ( is_user_logged_in() || ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}

		$cart     = (int) wc_get_page_id( 'cart' );
		$checkout = (int) wc_get_page_id( 'checkout' );

		// Not is_cart()/is_checkout(): WooCommerce caches those answers for the whole request.
		if ( $checkout > 0 && is_page( $checkout ) && '' !== (string) get_query_var( 'order-received' ) ) {
			wp_safe_redirect( Urls::login() );
			exit;
		}

		if ( ( $cart > 0 && is_page( $cart ) ) || ( $checkout > 0 && is_page( $checkout ) ) ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( get_permalink() ), Urls::login() ) );
			exit;
		}
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
