<?php

namespace Nexora\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recaptcha {

	private $site_key;
	private $secret_key;
	private $enabled;

	public function __construct() {
		$this->site_key   = get_option( 'recaptcha_site_key' );
		$this->secret_key = get_option( 'recaptcha_secret_key' );
		$this->enabled    = get_option( 'recaptcha_enabled' );
	}

	/**
	 * The captcha is skipped only on a genuinely local site:
	 *   - the 'nexora_skip_captcha' filter says so (explicit opt-in for CI / staging), or
	 *   - WordPress reports environment type "local" AND the site's own hostname
	 *     (from the stored home URL, never the request's Host header) resolves to a
	 *     private / loopback address.
	 * A live site that ships with WP_ENVIRONMENT_TYPE=local still gets the captcha.
	 */
	public function is_local() {

		if ( apply_filters( 'nexora_skip_captcha', false ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_get_environment_type' ) || wp_get_environment_type() !== 'local' ) {
			return false;
		}

		$host = apply_filters( 'nexora_captcha_site_host', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		$ip = apply_filters( 'nexora_captcha_host_ip', filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host ), $host );

		// gethostbyname() returns the input unchanged when it cannot resolve: not an IP, so treated as public
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	// 🔹 Check if captcha is enabled
	public function is_enabled() {
		return ! empty( $this->enabled ) && ! empty( $this->site_key ) && ! empty( $this->secret_key );
	}

	// 🔹 Render captcha HTML (Frontend)
	public function render() {

		if ( $this->is_local() ) {
			return ''; // ❌ hide captcha on local
		}

		if ( ! $this->is_enabled() ) {
			return '';
		}

		return '<div class="g-recaptcha" style="margin: 15px 0;display: flex;justify-content: center;" 
                data-sitekey="' . esc_attr( $this->site_key ) . '"></div>';
	}

	// 🔹 Enqueue script (call once globally)
	public function enqueue_script() {

		if ( $this->is_local() ) {
			return;
		}

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Google's own script: versioned by Google.
		// phpcs:disable WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script(
			'google-recaptcha',
			'https://www.google.com/recaptcha/api.js',
			array(),
			null,
			true
		);
		// phpcs:enable WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	// 🔹 Verify captcha (Backend)
	public function verify( $captcha_response ) {

		// 🔥 BYPASS ON LOCAL
		if ( $this->is_local() ) {
			return array( 'success' => true );
		}

		// If disabled → skip validation
		if ( ! $this->is_enabled() ) {
			return array(
				'success' => true,
			);
		}

		if ( empty( $captcha_response ) ) {
			return array(
				'success' => false,
				'message' => 'Captcha is required',
			);
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'body'    => array(
					'secret'   => $this->secret_key,
					'response' => $captcha_response,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => 'Captcha request failed',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['success'] ) ) {
			return array(
				'success' => true,
			);
		}

		return array(
			'success' => false,
			'message' => 'Captcha verification failed',
		);
	}
}
