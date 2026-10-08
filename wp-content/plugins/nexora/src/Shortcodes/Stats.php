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
                . '<a class="nx-btn nx-outline" href="' . esc_url(\Nexora\Core\Urls::login(true)) . '">Log in</a>'
                . '<a class="nx-btn nx-primary" href="' . esc_url(\Nexora\Core\Urls::registration(true)) . '">Sign up</a>'
                . '</span>';
        }

        $user = wp_get_current_user();

        $profile = \Nexora\Core\Urls::profile_for($user, true);

        return '<span class="nx-auth">'
            . '<a class="nx-btn nx-outline" href="' . esc_url($profile) . '">' . esc_html($user->display_name) . '</a>'
            . '<a class="nx-btn nx-primary" href="' . esc_url(wp_logout_url(\Nexora\Core\Urls::login(true))) . '">Log out</a>'
            . '</span>';
    }
}
