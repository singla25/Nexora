<?php

namespace Nexora\Admin;

use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nexora System > Settings: the options (registered through the Settings API) and the page that edits them.
 */
class Settings {

	/**
	 * Default-image options: option name, label, preview width.
	 *
	 * @return array[]
	 */
	private function images() {

		return array(
			array( 'default_profile_image', __( 'Default Profile Image', 'nexora' ), 100 ),
			array( 'default_cover_image', __( 'Default Cover Image', 'nexora' ), 150 ),
			array( 'default_document_image', __( 'Default Document Image', 'nexora' ), 150 ),
			array( 'default_home_cover_image', __( 'Default Home Cover Image', 'nexora' ), 150 ),
			array( 'default_feed_experience_image', __( 'Default Feed Experience Image', 'nexora' ), 150 ),
			array( 'default_real_time_chat_image', __( 'Default Real-Time Chat Image', 'nexora' ), 150 ),
			array( 'default_smart_connections_image', __( 'Default Smart Connections Image', 'nexora' ), 150 ),
		);
	}

	/**
	 * Hooks the settings registration.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers the options of the settings page with their sanitisers.
	 */
	public function register_settings() {
		register_setting( 'profile_settings_group', 'default_profile_image' );
		register_setting( 'profile_settings_group', 'default_cover_image' );
		register_setting( 'profile_settings_group', 'default_document_image' );
		register_setting( 'profile_settings_group', 'default_home_cover_image' );
		register_setting( 'profile_settings_group', 'default_feed_experience_image' );
		register_setting( 'profile_settings_group', 'default_real_time_chat_image' );
		register_setting( 'profile_settings_group', 'default_smart_connections_image' );
		register_setting( 'profile_settings_group', 'default_admin_mail', array( 'sanitize_callback' => 'sanitize_email' ) );

		// Home page content (all optional - sensible defaults are used when empty)
		register_setting( 'profile_settings_group', 'nexora_home_eyebrow', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'profile_settings_group', 'nexora_home_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'profile_settings_group', 'nexora_home_subtitle', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'profile_settings_group', 'nexora_home_features', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'profile_settings_group', 'nexora_home_testimonials', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );

		register_setting( 'profile_settings_group', 'recaptcha_site_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'profile_settings_group',
			'recaptcha_secret_key',
			array(
				'sanitize_callback' => function ( $value ) {
					$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

					// The field shows a mask, never the real secret: keep the stored key
					// unless the admin typed a new one.
					if ( '' === $value || preg_match( '/^\*+$/', $value ) ) {
						return get_option( 'recaptcha_secret_key' );
					}

					return sanitize_text_field( $value );
				},
			)
		);
		register_setting(
			'profile_settings_group',
			'recaptcha_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => function ( $value ) {
					return $value ? 1 : 0;
				},
			)
		);
	}

	/**
	 * Prints Nexora System > Settings.
	 */
	public function settings_page() {

		$images = array();

		foreach ( $this->images() as [ $option, $label, $width ] ) {

			$id = get_option( $option );

			$images[] = array(
				'option' => $option,
				'label'  => $label,
				'width'  => $width,
				'id'     => $id,
				'url'    => $id ? wp_get_attachment_url( $id ) : '',
			);
		}

		View::output(
			'admin/settings',
			array(
				'images' => $images,
				'v'      => array(
					'eyebrow'      => get_option( 'nexora_home_eyebrow' ),
					'title'        => get_option( 'nexora_home_title' ),
					'subtitle'     => get_option( 'nexora_home_subtitle' ),
					'features'     => get_option( 'nexora_home_features' ),
					'testimonials' => get_option( 'nexora_home_testimonials' ),
					'admin_email'  => get_option( 'default_admin_mail' ),
					'site_key'     => get_option( 'recaptcha_site_key' ),
					'has_secret'   => (bool) get_option( 'recaptcha_secret_key' ),
					'enabled'      => get_option( 'recaptcha_enabled' ),
				),
			)
		);
	}
}
