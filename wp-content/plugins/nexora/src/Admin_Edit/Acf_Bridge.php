<?php

namespace Nexora\Admin_Edit;

use Nexora\Core\Admin_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects the edit screens to ACF. ACF only DRAWS the form (and shows the messages); persisting is done here, by the Saver:
 *
 *  - `acf/form-post/skip_save` tells ACF not to save these record types itself (ACF validates only published posts, and writes whatever
 *    it is given); our `save_post` handler does it instead, always after validation, and only for a genuine administrator request;
 *  - `acf/validate_save_post` flags bad values on their own fields before the form is submitted;
 *  - `acf/pre_load_value` shows each field's value from where it is really kept (a product's name is the post title, a seller's status
 *    is the account status).
 *
 * When ACF is not active a notice says so, the old boxes stay as read-only views and nothing can be edited.
 */
class Acf_Bridge {

	const NOTICE_META = '_nexora_admin_notice';

	/**
	 * Guards against the Saver's own post updates calling this handler again.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Hooks everything.
	 */
	public function __construct() {
		add_action( 'acf/init', array( Groups::class, 'register' ) );
		add_filter( 'acf/form-post/skip_save', array( $this, 'skip_save' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'acf/validate_save_post', array( $this, 'validate' ) );
		add_filter( 'acf/pre_load_value', array( $this, 'pre_load' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Whether ACF is there to draw the forms.
	 *
	 * @return bool
	 */
	public static function active() {
		return (bool) apply_filters( 'nexora_acf_active', function_exists( 'acf_add_local_field_group' ) );
	}

	/**
	 * ACF must leave saving these record types to us.
	 *
	 * @param bool     $skip    Whether to skip.
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 * @return bool
	 */
	public function skip_save( $skip, $post_id = 0, $post = null ) {

		unset( $post_id );

		return $skip || ( $post instanceof \WP_Post && in_array( $post->post_type, Fields::types(), true ) );
	}

	/**
	 * Saves the ACF values of an edit screen through the Saver (only for a genuine administrator request; anything else is ignored).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 */
	public function on_save_post( $post_id, $post ) {

		if ( self::$running || ! $post instanceof \WP_Post || ! in_array( $post->post_type, Fields::types(), true ) || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( ! Admin_Gate::open( $post_id, 'edit' ) ) {
			return;
		}

		self::$running = true;

		try {
			$result = Saver::from_request( $post_id );
		} finally {
			self::$running = false;
		}

		if ( is_wp_error( $result ) ) {
			self::push_notice( get_current_user_id(), $result );
		}
	}

	/**
	 * Flags invalid values on their own fields while the form is validated.
	 */
	public function validate() {

		// ACF's own validation request: it carries the form, its nonce is checked by ACF; nothing is written here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$post_id = isset( $_POST['_acf_post_id'] ) && is_numeric( $_POST['_acf_post_id'] ) ? (int) $_POST['_acf_post_id'] : ( isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0 );
		// phpcs:enable
		$type = $post_id ? get_post_type( $post_id ) : '';

		if ( ! $type || ! in_array( $type, Fields::types(), true ) || ! current_user_can( 'manage_options' ) || ! function_exists( 'acf_add_validation_error' ) ) {
			return;
		}

		$input  = Saver::input_from_post( $type );
		$checks = Saver::validate( $post_id, $input );

		foreach ( $checks['errors'] as $name => $message ) {
			acf_add_validation_error( 'acf[' . Fields::field_key( $type, $name ) . ']', $message );
		}
	}

	/**
	 * Gives ACF a field's value from where it is kept.
	 *
	 * @param mixed $value   Short-circuit value (null to let ACF read it).
	 * @param mixed $post_id ACF's post ID.
	 * @param array $field   The field.
	 * @return mixed
	 */
	public function pre_load( $value, $post_id, $field ) {

		if ( null !== $value || ! is_numeric( $post_id ) || empty( $field['key'] ) || 0 !== strpos( $field['key'], 'field_nx_' ) ) {
			return $value;
		}

		$current = Saver::current( (int) $post_id );

		return isset( $current[ $field['name'] ] ) ? $current[ $field['name'] ] : $value;
	}

	/**
	 * Keeps a message for the administrator to see after the page reloads.
	 *
	 * @param int       $user_id The administrator.
	 * @param \WP_Error $error   What was refused.
	 */
	public static function push_notice( $user_id, \WP_Error $error ) {

		$data  = $error->get_error_data();
		$lines = array( $error->get_error_message() );

		if ( is_array( $data ) && ! empty( $data['fields'] ) ) {
			foreach ( $data['fields'] as $name => $message ) {
				$lines[] = $name . ': ' . $message;
			}
		}

		update_user_meta( (int) $user_id, self::NOTICE_META, implode( "\n", $lines ) );
	}

	/**
	 * Returns the waiting message of a user, once.
	 *
	 * @param int $user_id The administrator.
	 * @return string
	 */
	public static function take_notice( $user_id ) {

		$message = (string) get_user_meta( (int) $user_id, self::NOTICE_META, true );

		if ( '' !== $message ) {
			delete_user_meta( (int) $user_id, self::NOTICE_META );
		}

		return $message;
	}

	/**
	 * The text shown when ACF is not active.
	 *
	 * @return string HTML.
	 */
	public static function notice_html() {

		return '<div class="notice notice-warning"><p>' . esc_html__( 'Editing Nexora records needs the Advanced Custom Fields (ACF) plugin. It is not active, so these screens are read-only until it is.', 'nexora' ) . '</p></div>';
	}

	/**
	 * Prints the waiting refusal message, and the "ACF not active" notice on the Nexora edit screens.
	 */
	public function notices() {

		$message = self::take_notice( get_current_user_id() );

		if ( '' !== $message ) {
			echo '<div class="notice notice-error"><p>' . nl2br( esc_html( $message ) ) . '</p></div>';
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! self::active() && current_user_can( 'manage_options' ) && $screen && in_array( $screen->post_type, Fields::types(), true ) ) {
			echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html__() above.
		}
	}
}
