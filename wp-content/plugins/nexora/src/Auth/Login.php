<?php

namespace Nexora\Auth;

use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Login {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'login_enqueue_assets' ) );
		add_shortcode( 'profile_login', array( $this, 'login_form' ) );

		\Nexora\Http\Ajax::register( 'profile_login', array( $this, 'handle_login' ), true );

		\Nexora\Http\Ajax::register( 'send_otp', array( $this, 'send_otp' ), true );

		\Nexora\Http\Ajax::register( 'verify_otp', array( $this, 'verify_otp' ), true );

		\Nexora\Http\Ajax::register( 'reset_password', array( $this, 'reset_password' ), true );
	}

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

	// ---------------------------
	// SEND OTP
	// ---------------------------
	public function send_otp() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'otp_send' ) ) {
			wp_send_json_error( 'Too many requests. Please try again later.' );
		}

		$username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		$reply = function ( $user_id = 0 ) {
			return array(
				'user_id' => Otp::issue_ref( $user_id ),
				'message' => 'If the details are correct, an OTP has been sent to your email.',
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

		$subject = 'Reset Password OTP - Nexora';
		$message = "Your OTP is: $otp\n\nThis OTP is valid for 10 minutes. If you did not request it, ignore this email.";

		wp_mail( $user->user_email, $subject, $message );

		wp_send_json_success( $reply( $user->ID ) );
	}

	// ---------------------------
	// VERIFY OTP
	// ---------------------------
	public function verify_otp() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'otp_verify' ) ) {
			wp_send_json_error( 'Too many attempts. Please try again later.' );
		}

		$user_id = Otp::resolve_ref( sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) ) );
		$otp     = sanitize_text_field( wp_unslash( $_POST['otp'] ?? '' ) );

		if ( ! $user_id ) {
			wp_send_json_error( 'Invalid or expired OTP' );
		}

		$result = Otp::verify( $user_id, $otp );

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['message'] );
		}

		wp_send_json_success(
			array(
				'message' => 'OTP verified',
				'token'   => $result['token'],
			)
		);
	}

	// ---------------------------
	// RESET Password
	// ---------------------------
	public function reset_password() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'reset_password' ) ) {
			wp_send_json_error( 'Too many requests. Please try again later.' );
		}

		$ref      = sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) );
		$user_id  = Otp::resolve_ref( $ref );
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$password = wp_unslash( $_POST['password'] ?? '' );

		if ( ! $user_id || $token === '' ) {
			wp_send_json_error( 'Invalid request' );
		}

		// The OTP must have been verified first (proves ownership of the email)
		if ( ! Otp::token_valid( $user_id, $token ) ) {
			wp_send_json_error( 'Reset session expired. Please verify OTP again.' );
		}

		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( 'Password must be at least 8 characters' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( 'Invalid request' );
		}

		wp_set_password( $password, $user_id );

		// Token / OTP are single use, and so is the reference the browser held
		Otp::clear( $user_id );
		Otp::forget_ref( $ref );

		wp_mail(
			$user->user_email,
			'Password Reset Successful - Nexora',
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

	// ---------------------------
	// LogIn Form
	// ---------------------------
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

	public function handle_login() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		if ( Rate_Limiter::hit( 'login' ) ) {
			wp_send_json_error( 'Too many login attempts. Please try again later.' );
		}

		$captcha = new Recaptcha();

		$result = $captcha->verify( sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ?? '' ) ) );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['message'] );
		}

		$login_input = sanitize_text_field( wp_unslash( $_POST['user_name'] ?? '' ) );
		$password    = wp_unslash( $_POST['password'] ?? '' );

		if ( $login_input === '' || $password === '' ) {
			wp_send_json_error( 'Invalid username or password' );
		}

		// Allow login with email
		if ( is_email( $login_input ) ) {

			$by_email = get_user_by( 'email', $login_input );

			// Same error as a wrong password (no account enumeration)
			if ( ! $by_email ) {
				wp_send_json_error( 'Invalid username or password' );
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
			wp_send_json_error( 'Invalid username or password' );
		}

		wp_send_json_success(
			array(
				'redirect' => Urls::profile_for( $user ),
			)
		);
	}
}
