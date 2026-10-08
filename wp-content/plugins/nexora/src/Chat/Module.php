<?php

namespace Nexora\Chat;

if (!defined('ABSPATH')) exit;

class Module {

    public function __construct() {

        // INIT CHAT MODULES
        new \Nexora\Chat\Repository();
        new \Nexora\Chat\Ajax();

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Load chat popup globally
        add_action('wp_footer', [$this, 'load_chat_template']);
        add_action('admin_footer', [$this, 'load_chat_template']);
    }

    public function enqueue_assets() {

        // Chat is for logged-in members only
        if (!is_user_logged_in()) return;

        \Nexora\Core\Assets::enqueue_tokens();

        wp_enqueue_style(
            'nexora-chat-css',
            NEXORA_URL . 'chat/assets/css/chat.css',
            ['nexora-tokens'],
            NEXORA_VERSION
        );

        wp_enqueue_script(
            'nexora-chat-js',
            NEXORA_URL . 'chat/assets/js/chat.js',
            ['jquery'],
            NEXORA_VERSION,
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