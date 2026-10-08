<?php

namespace Nexora\Shortcodes;

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

        $eyebrow  = get_option('nexora_home_eyebrow')  ?: 'Your professional network';
        $title    = get_option('nexora_home_title')    ?: 'Connect. Grow. Discover.';
        $subtitle = get_option('nexora_home_subtitle') ?: 'Nexora helps you connect, share, and grow your network in real-time.';

        $cover_id = (int) get_option('default_home_cover_image');
        $cover    = $cover_id ? wp_get_attachment_url($cover_id) : '';

        $stats = self::get_stats();

        $features = $this->parse_lines('nexora_home_features', 2);
        if (!$features) {
            $features = self::default_features();
        }

        $testimonials = $this->parse_lines('nexora_home_testimonials', 3);

        $showcase = [
            ['default_feed_experience_image',    'Feed experience'],
            ['default_real_time_chat_image',     'Real-time chat'],
            ['default_smart_connections_image',  'Smart connections'],
        ];

        $login_url = home_url('/login-page/');
        $reg_url   = home_url('/registration-page/');

        if ($logged_in) {
            $user        = wp_get_current_user();
            $primary_url = user_can($user, 'manage_options')
                ? home_url('/profile-page/')
                : home_url('/profile-page/' . rawurlencode($user->user_login));
        }

        ob_start();
        ?>

        <div class="nexora">

            <!-- HERO -->
            <section class="nx-hero">
                <div class="nx-container nx-hero-inner">

                    <div class="nx-hero-content">
                        <p class="nx-eyebrow"><?php echo esc_html($eyebrow); ?></p>

                        <h1 class="nx-title"><?php echo esc_html($title); ?></h1>

                        <p class="nx-subtitle"><?php echo esc_html($subtitle); ?></p>

                        <div class="nx-cta">
                            <?php if ($logged_in): ?>
                                <a href="<?php echo esc_url($primary_url); ?>" class="nx-btn nx-primary">
                                    <?php echo esc_html(sprintf('Welcome back, %s', $user->display_name)); ?> &rarr;
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url($reg_url); ?>" class="nx-btn nx-primary">Get Started</a>
                                <a href="<?php echo esc_url($login_url); ?>" class="nx-btn nx-outline">Login</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($cover): ?>
                        <div class="nx-hero-preview">
                            <div class="nx-glass-card">
                                <img src="<?php echo esc_url($cover); ?>" alt="">
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </section>

            <!-- LIVE STATS -->
            <section class="nx-section nx-stats">
                <div class="nx-container">
                    <div class="nx-stats-grid">
                        <div class="nx-stat-box">
                            <h3><?php echo esc_html(self::short_number($stats['members'])); ?></h3>
                            <p>Members</p>
                        </div>
                        <div class="nx-stat-box">
                            <h3><?php echo esc_html(self::short_number($stats['connections'])); ?></h3>
                            <p>Connections</p>
                        </div>
                        <div class="nx-stat-box">
                            <h3><?php echo esc_html(self::short_number($stats['posts'])); ?></h3>
                            <p>Posts shared</p>
                        </div>
                        <div class="nx-stat-box">
                            <h3><?php echo esc_html(self::short_number($stats['chats'])); ?></h3>
                            <p>Conversations</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- FEATURES (editable in Nexora > Settings) -->
            <section class="nx-section">
                <div class="nx-container">
                    <h2 class="nx-section-title">Why Nexora?</h2>

                    <div class="nx-grid">
                        <?php foreach ($features as $feature): ?>
                            <div class="nx-card">
                                <h4><?php echo esc_html($feature[0]); ?></h4>
                                <p><?php echo esc_html($feature[1]); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- HOW IT WORKS -->
            <section class="nx-section nx-steps">
                <div class="nx-container">
                    <h2 class="nx-section-title">How it works</h2>

                    <div class="nx-steps-grid">
                        <div class="nx-step"><span>1</span><p>Sign up</p></div>
                        <div class="nx-step"><span>2</span><p>Build your profile</p></div>
                        <div class="nx-step"><span>3</span><p>Connect</p></div>
                        <div class="nx-step"><span>4</span><p>Share &amp; chat</p></div>
                    </div>
                </div>
            </section>

            <?php
            $shots = [];
            foreach ($showcase as $item) {
                $id  = (int) get_option($item[0]);
                $url = $id ? wp_get_attachment_url($id) : '';
                if ($url) {
                    $shots[] = [$url, $item[1]];
                }
            }
            ?>

            <?php if ($shots): ?>
                <!-- PREVIEW (only the images set in Settings) -->
                <section class="nx-section">
                    <div class="nx-container">
                        <h2 class="nx-section-title">A look inside</h2>

                        <div class="nx-demo-grid">
                            <?php foreach ($shots as $shot): ?>
                                <div class="nx-demo-card">
                                    <img src="<?php echo esc_url($shot[0]); ?>" alt="<?php echo esc_attr($shot[1]); ?>">
                                    <p><?php echo esc_html($shot[1]); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($testimonials): ?>
                <!-- TESTIMONIALS (editable in Nexora > Settings; hidden when empty) -->
                <section class="nx-section">
                    <div class="nx-container">
                        <h2 class="nx-section-title">Loved by members</h2>

                        <div class="nx-test-grid">
                            <?php foreach ($testimonials as $t): ?>
                                <div class="nx-test-card">
                                    <p class="nx-quote">&ldquo;<?php echo esc_html($t[2]); ?>&rdquo;</p>
                                    <div class="nx-test-top">
                                        <strong><?php echo esc_html($t[0]); ?></strong>
                                        <?php if ($t[1] !== ''): ?>
                                            <span><?php echo esc_html($t[1]); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!$logged_in): ?>
                <!-- CTA -->
                <section class="nx-final-cta">
                    <h2>Join Nexora today</h2>
                    <a href="<?php echo esc_url($reg_url); ?>" class="nx-btn nx-btn--inverse">Get Started</a>
                </section>
            <?php endif; ?>

        </div>

        <?php
        return ob_get_clean();
    }
}
