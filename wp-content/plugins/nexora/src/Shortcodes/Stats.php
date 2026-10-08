<?php

namespace Nexora\Shortcodes;

if (!defined('ABSPATH')) exit;

/**
 * Small dynamic shortcodes meant to be dropped into Elementor (Shortcode
 * widget) so templates never hard-code numbers or login-dependent buttons.
 *
 *  [nexora_stat type="members|connections|posts|chats"]
 *  [nexora_auth_buttons]
 */
class Stats {

    public function __construct() {
        add_shortcode('nexora_stat', [$this, 'stat']);
        add_shortcode('nexora_auth_buttons', [$this, 'auth_buttons']);
    }

    public function stat($atts) {

        $atts = shortcode_atts(['type' => 'members'], $atts, 'nexora_stat');
        $type = sanitize_key($atts['type']);

        $stats = \Nexora\Shortcodes\Home::get_stats();

        if (!isset($stats[$type])) {
            return '';
        }

        return esc_html(\Nexora\Shortcodes\Home::short_number($stats[$type]));
    }

    public function auth_buttons() {

        if (!is_user_logged_in()) {
            return '<span class="nx-auth">'
                . '<a class="nx-btn nx-outline" href="' . esc_url(home_url('/login-page/')) . '">Log in</a>'
                . '<a class="nx-btn nx-primary" href="' . esc_url(home_url('/registration-page/')) . '">Sign up</a>'
                . '</span>';
        }

        $user = wp_get_current_user();

        $profile = user_can($user, 'manage_options')
            ? home_url('/profile-page/')
            : home_url('/profile-page/' . rawurlencode($user->user_login));

        return '<span class="nx-auth">'
            . '<a class="nx-btn nx-outline" href="' . esc_url($profile) . '">' . esc_html($user->display_name) . '</a>'
            . '<a class="nx-btn nx-primary" href="' . esc_url(wp_logout_url(home_url('/login-page/'))) . '">Log out</a>'
            . '</span>';
    }
}
