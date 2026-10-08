<?php

namespace Nexora\Shortcodes;

use Nexora\Core\Urls;
use Nexora\Core\View;

if (!defined('ABSPATH')) exit;

/**
 * [nexora_home] shortcode.
 *
 * Nothing on this page is hard-coded any more:
 *  - the numbers come from the database (members, connections, posts, chats)
 *  - hero text, features and testimonials come from Nexora > Settings
 *  - buttons follow the visitor's login state
 *  - images come from the media chosen in Settings (section skipped if none)
 */
class Home {

    const STATS_TRANSIENT = 'nexora_home_stats';

    public function __construct() {
        add_shortcode('nexora_home', [$this, 'render_home_page']);

        // Keep the numbers fresh without querying on every page view
        add_action('user_register', [$this, 'flush_stats']);
        add_action('deleted_user', [$this, 'flush_stats']);
        add_action('save_post_user_connections', [$this, 'flush_stats']);
        add_action('save_post_user_content', [$this, 'flush_stats']);
        add_action('save_post_user_profile', [$this, 'flush_stats']);
    }

    public function flush_stats() {
        delete_transient(self::STATS_TRANSIENT);
    }

    /* ===============================
       DYNAMIC DATA
    =============================== */

    /**
     * Live platform numbers (cached for an hour).
     */
    public static function get_stats() {

        $stats = get_transient(self::STATS_TRANSIENT);

        if (is_array($stats)) {
            return $stats;
        }

        global $wpdb;

        $profiles = wp_count_posts('user_profile');
        $content  = wp_count_posts('user_content');

        $connections = new \WP_Query([
            'post_type'      => 'user_connections',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [['key' => 'status', 'value' => 'accepted']]
        ]);

        $threads_table = $wpdb->prefix . 'nexora_threads';
        $threads       = 0;

        // The chat table only exists after the plugin was activated
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $threads_table)) === $threads_table) {
            $threads = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$threads_table}");
        }

        $stats = [
            'members'     => (int) ($profiles->publish ?? 0),
            'connections' => (int) $connections->found_posts,
            'posts'       => (int) ($content->publish ?? 0),
            'chats'       => $threads
        ];

        set_transient(self::STATS_TRANSIENT, $stats, HOUR_IN_SECONDS);

        return $stats;
    }

    /**
     * Compact number: 1,250 -> 1.2K
     */
    public static function short_number($n) {

        $n = (int) $n;

        if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
        if ($n >= 1000)    return round($n / 1000, 1) . 'K';

        return number_format_i18n($n);
    }

    /**
     * Parse "a | b | c" lines from a settings textarea.
     */
    private function parse_lines($option, $columns) {

        $rows  = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) get_option($option, ''));

        foreach ($lines as $line) {

            $line = trim($line);

            if ($line === '') continue;

            $cells = array_map('trim', explode('|', $line, $columns));
            $cells = array_pad($cells, $columns, '');

            $rows[] = $cells;
        }

        return $rows;
    }

    public static function default_features() {
        return [
            ['Real-time chat',    'Instant conversations with subject-based threads.'],
            ['Smart connections', 'Build a meaningful network, not random followers.'],
            ['Share content',     'Post updates with images and keep a personal history.'],
            ['Notifications',     'Stay up to date on requests and responses.'],
            ['Your profile',      'Showcase your identity, work and documents securely.'],
        ];
    }

    /* ===============================
       RENDER
    =============================== */
    public function render_home_page() {

        $logged_in = is_user_logged_in();

        $cover_id = (int) get_option('default_home_cover_image');

        $stats = self::get_stats();

        $features = $this->parse_lines('nexora_home_features', 2);
        if (!$features) {
            $features = self::default_features();
        }

        // Preview images: only the ones set in Settings
        $shots = [];
        foreach ([
            ['default_feed_experience_image',    'Feed experience'],
            ['default_real_time_chat_image',     'Real-time chat'],
            ['default_smart_connections_image',  'Smart connections'],
        ] as $item) {
            $id  = (int) get_option($item[0]);
            $url = $id ? wp_get_attachment_url($id) : '';
            if ($url) {
                $shots[] = [$url, $item[1]];
            }
        }

        $vars = [
            'logged_in'    => $logged_in,
            'login_url'    => Urls::login(true),
            'reg_url'      => Urls::registration(true),
            'eyebrow'      => get_option('nexora_home_eyebrow')  ?: 'Your professional network',
            'title'        => get_option('nexora_home_title')    ?: 'Connect. Grow. Discover.',
            'subtitle'     => get_option('nexora_home_subtitle') ?: 'Nexora helps you connect, share, and grow your network in real-time.',
            'cover'        => $cover_id ? wp_get_attachment_url($cover_id) : '',
            'stats'        => array_map([self::class, 'short_number'], $stats),
            'features'     => $features,
            'shots'        => $shots,
            'testimonials' => $this->parse_lines('nexora_home_testimonials', 3),
        ];

        if ($logged_in) {
            $vars['user']        = wp_get_current_user();
            $vars['primary_url'] = Urls::profile_for($vars['user'], true);
        }

        return View::render('shortcodes/home', $vars);
    }
}
