<?php

namespace Nexora\PostTypes;

if (!defined('ABSPATH')) exit;

/**
 * The three private post types the social data lives in. They are never public and never in REST;
 * they appear only under the "Nexora System" admin menu.
 */
class Registrar {

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
    }

    public function register_cpt() {

        register_post_type('user_profile', [
            'label' => 'User Profiles',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);

        register_post_type('user_connections', [
            'label' => 'User Connections',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);

        register_post_type('user_content', [
            'label' => 'User Content',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);
    }
}
