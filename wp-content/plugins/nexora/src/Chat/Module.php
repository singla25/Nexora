<?php

namespace Nexora\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat module: loads the chat assets and prints the chat popup for logged-in members.
 */
class Module {

	/**
	 * Hooks the chat assets and popup.
	 */
	public function __construct() {

		// INIT CHAT MODULES
		new \Nexora\Chat\Repository();
		new \Nexora\Chat\Ajax();

		// Assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Load chat popup globally
		add_action( 'wp_footer', array( $this, 'load_chat_template' ) );
		add_action( 'admin_footer', array( $this, 'load_chat_template' ) );
	}

	/**
	 * Loads the chat styles and script for logged-in members.
	 */
	public function enqueue_assets() {

		// Chat is for logged-in members only
		if ( ! is_user_logged_in() ) {
			return;
		}

		\Nexora\Core\Assets::enqueue_tokens();

		wp_enqueue_style(
			'nexora-chat-css',
			NEXORA_URL . 'assets/css/chat.css',
			array( 'nexora-tokens' ),
			NEXORA_VERSION
		);

		wp_enqueue_script(
			'nexora-chat-js',
			NEXORA_URL . 'assets/js/chat.js',
			array( 'jquery' ),
			NEXORA_VERSION,
			true
		);

		wp_localize_script(
			'nexora-chat-js',
			'nexoraChat',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'user_id'  => get_current_user_id(),
				'nonce'    => wp_create_nonce( 'nexora_chat_nonce' ),
			)
		);
	}

	/**
	 * Prints the chat popup in the footer for logged-in members.
	 */
	public function load_chat_template() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		\Nexora\Core\View::output( 'chat/layout' );
	}
}
