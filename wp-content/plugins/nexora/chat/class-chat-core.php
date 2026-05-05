<?php

if ( ! defined( 'ABSPATH' ) ) exit;

// Load internal chat modules.
require_once NEXORA_PATH . 'chat/class-chat-db.php';
require_once NEXORA_PATH . 'chat/class-chat-ajax.php';

class NEXORA_CHAT_CORE {

    /** Script/style version — bump to bust cache after deploys. */
    const ASSET_VERSION = '1.1';

    public function __construct() {

        new NEXORA_CHAT_AJAX();

        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Output the chat popup in the footer of every page (template handles auth check).
        add_action( 'wp_footer',    [ $this, 'load_chat_template' ] );
        add_action( 'admin_footer', [ $this, 'load_chat_template' ] );
    }

    public function enqueue_assets() {

        wp_enqueue_style(
            'nexora-chat-css',
            NEXORA_URL . 'chat/assets/css/chat.css',
            [],
            self::ASSET_VERSION
        );

        wp_enqueue_script(
            'nexora-chat-js',
            NEXORA_URL . 'chat/assets/js/chat.js',
            [ 'jquery' ],
            self::ASSET_VERSION,
            true   // load in footer
        );

        // Expose only what JS actually needs; never expose secrets or capabilities.
        wp_localize_script( 'nexora-chat-js', 'nexoraChat', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'user_id'  => get_current_user_id(),
            'nonce'    => wp_create_nonce( 'nexora_chat_nonce' ),
            'is_admin' => current_user_can( 'manage_options' ) ? '1' : '0',
        ] );
    }

    public function load_chat_template() {
        if ( ! is_user_logged_in() ) return;
        include NEXORA_PATH . 'chat/templates/chat-layout.php';
    }
}
