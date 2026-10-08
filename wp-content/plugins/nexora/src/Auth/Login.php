<?php

namespace Nexora\Auth;

use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [profile_login] page: password login, and the email one-time-code flow for resetting a password.
 */
class Login {

	/**
	 * Registers the [profile_login] shortcode and the login / OTP / reset AJAX actions.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'login_enqueue_assets' ) );
		add_shortcode( 'profile_login', array( $this, 'login_form' ) );

		\Nexora\Http\Ajax::register( 'profile_login', array( $this, 'handle_login' ), true );

		\Nexora\Http\Ajax::register( 'send_otp', array( $this, 'send_otp' ), true );

		\Nexora\Http\Ajax::register( 'verify_otp', array( $this, 'verify_otp' ), true );

		\Nexora\Http\Ajax::register( 'reset_password', array( $this, 'reset_password' ), true );
	}

	/**
	 * Loads the login scripts, styles and captcha on the login page only.
	 */
	public function login_enqueue_assets() {

		// Only the login page needs these scripts (and the guest nonce)
		if ( ! \Nexora\Core\Assets::is_page_for( 'login-page', 'profile_login' ) ) {
			return;
		}

		\Nexora\Core\Assets::enqueue_tokens();
		wp_enqueue_style( 'profile-login-style', NEXORA_URL . 'assets/css/profile-login.css', array( 'nexora-tokens' ), NEXORA_VERSION );

		\Nexora\Core\Assets::enqueue_sweetalert();

		wp_enqueue_script(
			'profile-login',
			NEXORA_URL . 'assets/js/profile-login.js',
			array( 'jquery', 'sweetalert2' ),
			NEXORA_VERSION,
			true
		);

		wp_localize_script(
			'profile-login',
			'profileData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'profile_nonce' ),
			)
		);

		$captcha = new \Nexora\Auth\Recaptcha();
		$captcha->enqueue_script();
	}

	/**
	 * Emails a one-time code to a member who asks to reset their password. The reply is identical for unknown accounts.
	 */
	public function send_otp() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'otp_send' ) ) {
			wp_send_json_error( __( 'Too many requests. Please try again later.', 'nexora' ) );
		}

		$username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		$reply = function ( $user_id = 0 ) {
			return array(
				'user_id' => Otp::issue_ref( $user_id ),
				'message' => __( 'If the details are correct, an OTP has been sent to your email.', 'nexora' ),
			);
		};

		$user = $username ? get_user_by( 'login', $username ) : false;

		// Same response for unknown user / wrong email (no account enumeration)
		if ( ! $user || ! $email || strcasecmp( $user->user_email, $email ) !== 0 ) {
			wp_send_json_success( $reply() );
		}

		// Do not issue a new OTP while a valid one exists (prevents mail flooding)
		if ( Otp::has_active_otp( $user->ID ) ) {
			wp_send_json_success( $reply( $user->ID ) );
		}

		$otp = Otp::issue( $user->ID );

		$subject = __( 'Reset Password OTP - Nexora', 'nexora' );
		/* translators: %s: six-digit one-time code. */
		$message = sprintf( __( "Your OTP is: %s\n\nThis OTP is valid for 10 minutes. If you did not request it, ignore this email.", 'nexora' ), $otp );

		wp_mail( $user->user_email, $subject, $message );

		wp_send_json_success( $reply( $user->ID ) );
	}

	/**
	 * Checks the one-time code and, if right, returns a short-lived reset token.
	 */
	public function verify_otp() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'otp_verify' ) ) {
			wp_send_json_error( __( 'Too many attempts. Please try again later.', 'nexora' ) );
		}

		$user_id = Otp::resolve_ref( sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) ) );
		$otp     = sanitize_text_field( wp_unslash( $_POST['otp'] ?? '' ) );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid or expired OTP', 'nexora' ) );
		}

		$result = Otp::verify( $user_id, $otp );

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['message'] );
		}

		wp_send_json_success(
			array(
				'message' => __( 'OTP verified', 'nexora' ),
				'token'   => $result['token'],
			)
		);
	}

	/**
	 * Sets a new password for a member who holds a valid reset token, then logs them in.
	 */
	public function reset_password() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'reset_password' ) ) {
			wp_send_json_error( __( 'Too many requests. Please try again later.', 'nexora' ) );
		}

		$ref     = sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) );
		$user_id = Otp::resolve_ref( $ref );
		$token   = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		// Passwords are stored hashed as typed: sanitizing would alter them.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = wp_unslash( $_POST['password'] ?? '' );

		if ( ! $user_id || '' === $token ) {
			wp_send_json_error( __( 'Invalid request', 'nexora' ) );
		}

		// The OTP must have been verified first (proves ownership of the email)
		if ( ! Otp::token_valid( $user_id, $token ) ) {
			wp_send_json_error( __( 'Reset session expired. Please verify OTP again.', 'nexora' ) );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( __( 'Password must be at least 8 characters', 'nexora' ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( __( 'Invalid request', 'nexora' ) );
		}

		wp_set_password( $password, $user_id );

		// Token / OTP are single use, and so is the reference the browser held
		Otp::clear( $user_id );
		Otp::forget_ref( $ref );

		wp_mail(
			$user->user_email,
			__( 'Password Reset Successful - Nexora', 'nexora' ),
			View::render( 'mail/password-reset-success', array( 'user' => $user ) ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		// Auto login
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_send_json_success(
			array(
				'redirect' => Urls::profile_for( $user ),
			)
		);
	}

	/**
	 * Renders the login form, or an "already logged in" card.
	 *
	 * @return string HTML.
	 */
	public function login_form() {

		// Already logged in
		if ( is_user_logged_in() ) {

			$current_user = wp_get_current_user();

			return View::render(
				'auth/login-state',
				array(
					'user'        => $current_user,
					'profile_url' => Urls::profile( $current_user->user_login ),
					'logout_url'  => wp_logout_url( Urls::login() ),
				)
			);
		}

		$captcha = new Recaptcha();

		return View::render(
			'auth/login-form',
			array(
				'captcha_html' => $captcha->render(),
				'register_url' => Urls::registration(),
			)
		);
	}

	/**
	 * Logs a member in by username or email after the captcha and rate-limit checks.
	 */
	public function handle_login() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'login' ) ) {
			wp_send_json_error( __( 'Too many login attempts. Please try again later.', 'nexora' ) );
		}

		$captcha = new Recaptcha();

		$result = $captcha->verify( sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ?? '' ) ) );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['message'] );
		}

		$login_input = sanitize_text_field( wp_unslash( $_POST['user_name'] ?? '' ) );
		// Passwords are checked as typed: sanitizing would alter them.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = wp_unslash( $_POST['password'] ?? '' );

		if ( '' === $login_input || '' === $password ) {
			wp_send_json_error( __( 'Invalid username or password', 'nexora' ) );
		}

		// Allow login with email
		if ( is_email( $login_input ) ) {

			$by_email = get_user_by( 'email', $login_input );

			// Same error as a wrong password (no account enumeration)
			if ( ! $by_email ) {
				wp_send_json_error( __( 'Invalid username or password', 'nexora' ) );
			}

			$login_input = $by_email->user_login;
		}

		$user = wp_signon(
			array(
				'user_login'    => $login_input,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( __( 'Invalid username or password', 'nexora' ) );
		}

		wp_send_json_success(
			array(
				'redirect' => Urls::profile_for( $user ),
			)
		);
	}
}
