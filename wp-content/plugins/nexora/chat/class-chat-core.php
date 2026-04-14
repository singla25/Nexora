<?php

if (!defined('ABSPATH')) exit;

// Load internal chat modules
require_once NEXORA_PATH . 'chat/class-chat-db.php';
require_once NEXORA_PATH . 'chat/class-chat-ajax.php';

class NEXORA_CHAT_CORE {

    public function __construct() {

        // INIT CHAT MODULES
        new NEXORA_CHAT_DB();
        new NEXORA_CHAT_AJAX();

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Load chat popup globally
        add_action('wp_footer', [$this, 'load_chat_template']);
        add_action('admin_footer', [$this, 'load_chat_template']);
    }

    public function enqueue_assets() {

        wp_enqueue_style(
            'nexora-chat-css',
            NEXORA_URL . 'chat/assets/css/chat.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'nexora-chat-js',
            NEXORA_URL . 'chat/assets/js/chat.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('nexora-chat-js', 'nexoraChat', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'user_id'  => get_current_user_id(),
            'nonce'    => wp_create_nonce('nexora_chat_nonce')
        ]);
    }

    // LOAD CHAT TEMPLATE
    public function load_chat_template() {
        if (!is_user_logged_in()) return;
        include NEXORA_PATH . 'chat/templates/chat-layout.php';
    }
}