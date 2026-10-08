<?php

namespace Nexora\Core;

if (!defined('ABSPATH')) exit;

/**
 * Assets every Nexora page shares (design tokens, global stylesheet) plus the helpers the
 * feature modules use to load their own scripts only where they are needed.
 */
class Assets {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {

        self::enqueue_tokens();

        wp_enqueue_style(
            'profile-global-style',
            NEXORA_URL . 'assets/css/style.css',
            ['nexora-tokens'],
            NEXORA_VERSION
        );
    }

    /**
     * Shared design tokens (+ the brand font when the Nexora theme, which
     * already loads it, is not active). Safe to call repeatedly.
     */
    public static function enqueue_tokens() {

        $deps = [];

        if (get_template() !== 'nexora-theme') {
            wp_enqueue_style(
                'nexora-font',
                'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
                [],
                null
            );
            $deps[] = 'nexora-font';
        }

        wp_enqueue_style('nexora-tokens', NEXORA_URL . 'assets/css/tokens.css', $deps, NEXORA_VERSION);
    }

    /**
     * SweetAlert2 is shipped with the plugin (pinned version) instead of a floating CDN tag.
     * Safe to call repeatedly from several modules.
     */
    public static function enqueue_sweetalert() {

        if (!wp_script_is('sweetalert2', 'registered')) {
            wp_register_script(
                'sweetalert2',
                NEXORA_URL . 'assets/lib/sweetalert2/sweetalert2.all.min.js',
                [],
                '11.14.5',
                true
            );
        }

        wp_enqueue_script('sweetalert2');
    }

    /**
     * True when the current front-end page is the given page slug, or contains the
     * shortcode (in the post content or in Elementor data, where a Shortcode widget
     * stores it).
     */
    public static function is_page_for($slug, $shortcode) {

        if (is_admin() || !is_singular()) {
            return false;
        }

        if (is_page($slug)) {
            return true;
        }

        $post = get_post();

        if (!$post) {
            return false;
        }

        if (has_shortcode($post->post_content, $shortcode)) {
            return true;
        }

        $elementor = get_post_meta($post->ID, '_elementor_data', true);

        return is_string($elementor) && strpos($elementor, '[' . $shortcode) !== false;
    }
}
