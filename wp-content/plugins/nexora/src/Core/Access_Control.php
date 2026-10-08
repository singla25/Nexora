<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps members out of wp-admin and wp-login.php and sends everyone to the custom
 * login / profile pages. Administrators are untouched; AJAX, REST, cron and
 * admin-post.php stay open.
 */
class Access_Control {

	public function __construct() {
		add_action( 'after_setup_theme', array( $this, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( $this, 'block_wp_admin' ) );
		add_action( 'init', array( $this, 'block_wp_login' ) );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
	}

	public function hide_admin_bar() {

		if ( ! current_user_can( 'manage_options' ) ) {
			show_admin_bar( false );
		}
	}

	public function block_wp_admin() {

		// Allow AJAX
		if ( wp_doing_ajax() ) {
			return;
		}

		// Allow REST
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Allow WP-Cron and form handlers that must work for visitors
		if ( wp_doing_cron() ) {
			return;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( $_SERVER['SCRIPT_NAME'] ) : '';

		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}

		// Not logged in → redirect to login page
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( \Nexora\Core\Urls::login() );
			exit;
		}

		// Logged in but NOT admin → block wp-admin
		if ( ! current_user_can( 'manage_options' ) ) {
			$username = wp_get_current_user()->user_login;
			wp_safe_redirect( \Nexora\Core\Urls::profile( $username ) );
			exit;
		}
	}

	public function block_wp_login() {

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		// Only act on wp-login.php
		if ( strpos( $request_uri, 'wp-login.php' ) === false ) {
			return;
		}

		// Allow logout, password reset links and post-password forms
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( $action, array( 'logout', 'postpass' ), true ) ) {
			return;
		}

		// Admins may use wp-login.php
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		// Anyone else is sent to the custom pages
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( \Nexora\Core\Urls::login() );
			exit;
		}

		$username = wp_get_current_user()->user_login;
		wp_safe_redirect( \Nexora\Core\Urls::profile( $username ) );
		exit;
	}

	public function login_redirect( $redirect_to, $request, $user ) {

		// Failed login / no user yet: leave WordPress' default untouched
		if ( ! ( $user instanceof \WP_User ) ) {
			return $redirect_to;
		}

		return \Nexora\Core\Urls::profile_for( $user );
	}
}
