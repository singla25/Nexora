<?php

namespace Nexora\Auth;

use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [profile_registration] page: creates a member (WP user + linked profile) from the sign-up form.
 */
class Registration {

	/**
	 * Registers the [profile_registration] shortcode and the sign-up AJAX action.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'profile_registration', array( $this, 'registration_form' ) );

		\Nexora\Http\Ajax::register( 'profile_register', array( $this, 'registration_form_handle' ), true );
	}

	/**
	 * Loads the registration scripts, styles and captcha on the registration page only.
	 */
	public function enqueue_assets() {

		// Only the registration page needs these scripts (and the guest nonce)
		if ( ! \Nexora\Core\Assets::is_page_for( 'registration-page', 'profile_registration' ) ) {
			return;
		}

		\Nexora\Core\Assets::enqueue_tokens();
		wp_enqueue_style( 'profile-style', NEXORA_URL . 'assets/css/profile-registration.css', array( 'nexora-tokens' ), NEXORA_VERSION );

		\Nexora\Core\Assets::enqueue_sweetalert();

		wp_enqueue_script(
			'profile-registration',
			NEXORA_URL . 'assets/js/profile-registration.js',
			array( 'jquery', 'sweetalert2' ),
			NEXORA_VERSION,
			true
		);

		wp_localize_script(
			'profile-registration',
			'profileData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'profile_nonce' ),
			)
		);

		// The form renders a captcha, so its script must load here too
		$captcha = new \Nexora\Auth\Recaptcha();
		$captcha->enqueue_script();
	}

	/**
	 * Renders the sign-up form, or an "already logged in" card.
	 *
	 * @return string HTML.
	 */
	public function registration_form() {

		// Already logged in
		if ( is_user_logged_in() ) {

			$current_user = wp_get_current_user();

			return View::render(
				'auth/registration-state',
				array(
					'user'        => $current_user,
					'profile_url' => Urls::profile( $current_user->user_login ),
				)
			);
		}

		$captcha = new Recaptcha();

		return View::render(
			'auth/registration-form',
			array(
				'captcha_html' => $captcha->render(),
				'login_url'    => Urls::login(),
			)
		);
	}

	/**
	 * Creates a member account from the sign-up form (captcha, rate limit, validation, profile, auto login).
	 */
	public function registration_form_handle() {

		check_ajax_referer( 'profile_nonce', 'nonce' );

		// Basic throttling: max 5 sign-ups per IP per hour
		if ( Rate_Limiter::blocked( 'register' ) ) {
			wp_send_json_error( __( 'Too many registrations. Please try again later.', 'nexora' ) );
		}

		$captcha = new Recaptcha();

		$result = $captcha->verify( sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ?? '' ) ) );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['message'] );
		}

		$input = $this->read_input();

		$this->validate( $input );

		Rate_Limiter::hit( 'register' );

		$this->create_member( $input );

		wp_send_json_success(
			array(
				'message'  => __( 'Registration successful', 'nexora' ),
				'redirect' => Urls::profile( $input['user_name'] ),
			)
		);
	}

	/**
	 * Sanitised copy of the submitted form.
	 *
	 * @return array Sanitised form fields.
	 */
	private function read_input() {

		return array(
			'email'        => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'user_name'    => sanitize_user( wp_unslash( $_POST['user_name'] ?? '' ), true ),
			// Passwords are hashed as typed: sanitizing would alter them.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'password'     => wp_unslash( $_POST['password'] ?? '' ),
			'confirm_pass' => wp_unslash( $_POST['confirm_password'] ?? '' ),
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'first_name'   => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'phone'        => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'gender'       => sanitize_text_field( wp_unslash( $_POST['gender'] ?? '' ) ),
			'birthdate'    => sanitize_text_field( wp_unslash( $_POST['birthdate'] ?? '' ) ),
		);
	}

	/**
	 * Ends the request with a JSON error for the first problem found.
	 * An unknown gender is not an error: it is dropped.
	 *
	 * @param array $input Sanitised sign-up fields.
	 */
	private function validate( array &$input ) {

		if ( empty( $input['email'] ) || empty( $input['user_name'] ) || empty( $input['password'] ) || empty( $input['confirm_pass'] ) ) {
			wp_send_json_error( __( 'Required fields missing', 'nexora' ) );
		}

		if ( ! is_email( $input['email'] ) ) {
			wp_send_json_error( __( 'Invalid email address', 'nexora' ) );
		}

		// The username is used in profile URLs and as a lookup key
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{3,30}$/', $input['user_name'] ) ) {
			wp_send_json_error( __( 'Username must be 3-30 characters (letters, numbers, . _ -)', 'nexora' ) );
		}

		if ( strlen( $input['password'] ) < 8 ) {
			wp_send_json_error( __( 'Password must be at least 8 characters', 'nexora' ) );
		}

		if ( $input['password'] !== $input['confirm_pass'] ) {
			wp_send_json_error( __( 'Passwords do not match', 'nexora' ) );
		}

		if ( ! in_array( $input['gender'], array( 'male', 'female', 'other', '' ), true ) ) {
			$input['gender'] = '';
		}

		if ( '' !== $input['birthdate'] ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d', $input['birthdate'] );

			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $input['birthdate'] || $dt > new \DateTime( 'today' ) ) {
				wp_send_json_error( __( 'Invalid date of birth', 'nexora' ) );
			}
		}

		if ( username_exists( $input['user_name'] ) || email_exists( $input['email'] ) ) {
			wp_send_json_error( __( 'User already exists', 'nexora' ) );
		}
	}

	/**
	 * Creates the WP user and the linked user_profile post, notifies the admin and logs the
	 * new member in. Rolls the user back (and ends the request) if the profile cannot be created.
	 *
	 * @param array $input Sanitised sign-up fields.
	 */
	private function create_member( array $input ) {

		// Create WP User
		$wp_user_id = wp_create_user( $input['user_name'], $input['password'], $input['email'] );

		if ( is_wp_error( $wp_user_id ) ) {
			wp_send_json_error( __( 'User creation failed', 'nexora' ) );
		}

		wp_update_user(
			array(
				'ID'            => $wp_user_id,
				'user_nicename' => sanitize_title( $input['user_name'] ),
				'first_name'    => $input['first_name'],
				'last_name'     => $input['last_name'],
			)
		);

		// Create Profile CPT
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'user_profile',
				'post_title'  => $input['user_name'],
				'post_name'   => sanitize_title( $input['user_name'] ),
				'post_status' => 'publish',
				'post_author' => $wp_user_id,
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			// Roll back so the user can try again
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $wp_user_id );
			wp_send_json_error( __( 'Profile creation failed', 'nexora' ) );
		}

		update_post_meta( $post_id, '_wp_user_id', $wp_user_id );

		// Save Meta
		update_post_meta( $post_id, 'user_name', $input['user_name'] );
		update_post_meta( $post_id, 'first_name', $input['first_name'] );
		update_post_meta( $post_id, 'last_name', $input['last_name'] );
		update_post_meta( $post_id, 'email', $input['email'] );
		update_post_meta( $post_id, 'phone', $input['phone'] );
		update_post_meta( $post_id, 'gender', $input['gender'] );
		update_post_meta( $post_id, 'birthdate', $input['birthdate'] );

		// Link profile
		update_user_meta( $wp_user_id, '_profile_id', $post_id );

		$this->send_admin_notification( $input['user_name'], $input['email'], trim( $input['first_name'] . ' ' . $input['last_name'] ) );

		// Auto Login
		wp_set_current_user( $wp_user_id );
		wp_set_auth_cookie( $wp_user_id );
	}

	/**
	 * Emails the site admin that a new member signed up.
	 *
	 * @param string $user_name Username.
	 * @param string $email Email address.
	 * @param string $full_name First and last name.
	 */
	private function send_admin_notification( $user_name, $email, $full_name ) {

		$admin_email = get_option( 'default_admin_mail' );

		// fallback (safety)
		if ( empty( $admin_email ) ) {
			$admin_email = get_option( 'admin_email' );
		}

		wp_mail(
			$admin_email,
			__( '🚀 New User Registered on Nexora', 'nexora' ),
			View::render(
				'mail/admin-new-member',
				array(
					'user_name' => $user_name,
					'full_name' => $full_name,
					'email'     => $email,
					'time'      => current_time( 'mysql' ),
				)
			),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
