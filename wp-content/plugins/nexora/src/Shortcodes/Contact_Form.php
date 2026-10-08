<?php

namespace Nexora\Shortcodes;

use Nexora\Core\View;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [nexora_contact_form]
 *
 * A small, spam-resistant contact form that mails the site admin (or the
 * "Admin Notification Email" from Nexora settings). Protections: nonce,
 * honeypot field, per-IP rate limit, header-injection safe Reply-To.
 */
class Contact_Form {

	const ACTION = 'nexora_contact';

	public function __construct() {
		add_shortcode( 'nexora_contact_form', array( $this, 'render' ) );

		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/** Message shown after the form was submitted, keyed by the nx_contact query argument. */
	const NOTICES = array(
		'sent'    => array( 'ok', 'status', 'Thank you! Your message has been sent. We will get back to you soon.' ),
		'invalid' => array( 'error', 'alert', 'Please fill in your name, a valid email and a message.' ),
		'limit'   => array( 'error', 'alert', 'Too many messages sent. Please try again in a few minutes.' ),
		'error'   => array( 'error', 'alert', 'Sorry, the message could not be sent. Please try again later.' ),
	);

	public function render() {

		// Display-only status flag set by our own redirect; nothing is changed by it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['nx_contact'] ) ? sanitize_key( wp_unslash( $_GET['nx_contact'] ) ) : '';

		$user = wp_get_current_user();

		$notice = null;

		if ( isset( self::NOTICES[ $status ] ) ) {
			[$type, $role, $text] = self::NOTICES[ $status ];
			$notice               = array(
				'type' => $type,
				'role' => $role,
				'text' => $text,
			);
		}

		return View::render(
			'shortcodes/contact-form',
			array(
				'notice'     => $notice,
				'action'     => self::ACTION,
				'action_url' => admin_url( 'admin-post.php' ),
				'redirect'   => is_singular() ? get_permalink() : home_url( '/' ),
				'name'       => $user->exists() ? $user->display_name : '',
				'email'      => $user->exists() ? $user->user_email : '',
			)
		);
	}

	public function handle() {

		$redirect = isset( $_POST['redirect_to'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), home_url( '/' ) )
			: home_url( '/' );

		$back = function ( $status ) use ( $redirect ) {
			wp_safe_redirect( add_query_arg( 'nx_contact', $status, remove_query_arg( 'nx_contact', $redirect ) ) );
			exit;
		};

		if ( ! isset( $_POST['nx_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nx_contact_nonce'] ) ), self::ACTION ) ) {
			$back( 'error' );
		}

		// Honeypot filled: pretend success so bots learn nothing
		if ( ! empty( $_POST['nx_website'] ) ) {
			$back( 'sent' );
		}

		if ( Rate_Limiter::blocked( 'contact' ) ) {
			$back( 'limit' );
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['nx_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['nx_email'] ?? '' ) );
		$subject = sanitize_text_field( wp_unslash( $_POST['nx_subject'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['nx_message'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) || '' === $message ) {
			$back( 'invalid' );
		}

		$to = get_option( 'default_admin_mail' );
		if ( ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$subject = '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] '
			. ( '' !== $subject ? $subject : 'New contact message' );

		$body = "Name: {$name}\nEmail: {$email}\n\n{$message}\n";

		// name / email were sanitised (no line breaks), so they cannot inject headers
		$headers = array( 'Reply-To: ' . $name . ' <' . $email . '>' );

		Rate_Limiter::hit( 'contact' );

		$sent = wp_mail( $to, $subject, $body, $headers );

		$back( $sent ? 'sent' : 'error' );
	}
}
