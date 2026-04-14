<?php

if (!defined('ABSPATH')) exit;

class Nexora_Home_Page {

    public function __construct() {
        add_shortcode('nexora_home', [$this, 'render_home_page']);
    }

    function render_home_page() {

        $home_cover_id = get_option('default_home_cover_image');
        $feed_experience_id = get_option('default_feed_experience_image');
        $real_time_chat_id = get_option('default_real_time_chat_image');
        $smart_connections_id = get_option('default_smart_connections_image');

        ob_start();
        ?>

        <div class="nexora">

            <!-- HERO -->
            <section class="nx-hero">
                <div class="nx-container nx-hero-inner">

                    <div class="nx-hero-content">
                        <h1 class="nx-title">
                            Connect. <span>Grow.</span> Discover.
                        </h1>

                        <p class="nx-subtitle">
                            Nexora helps you connect, share, and grow your network in real-time.
                        </p>

                        <div class="nx-cta">
                            <a href="<?php echo site_url('/registration-page'); ?>" class="nx-btn nx-primary">Get Started</a>
                            <a href="<?php echo site_url('/login-page'); ?>" class="nx-btn nx-outline">Login</a>
                        </div>
                    </div>

                    <div class="nx-hero-preview">
                        <div class="nx-glass-card">
                            <img src="<?php echo $home_cover_id ? wp_get_attachment_url($home_cover_id) : ''; ?>" alt="Preview">
                        </div>
                    </div>

                </div>
            </section>

            <!-- Why Nexora -->
            <section class="nx-section">
                <div class="nx-container">
                    <h2 class="nx-section-title">Why Nexora?</h2>

                    <div class="nx-grid">
                        <div class="nx-card">
                            <h4>⚡ Real-time Chat</h4>
                            <p>Instant conversations with subject-based threads.</p>
                        </div>
                        <div class="nx-card">
                            <h4>🔗 Smart Connections</h4>
                            <p>Build meaningful network, not random followers.</p>
                        </div>
                        <div class="nx-card">
                            <h4>🧠 Organized Conversations</h4>
                            <p>Email-style chat threads with subjects.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- STATS -->
            <section class="nx-section nx-stats">
                <div class="nx-container">
                    <div class="nx-stats-grid">
                        <div class="nx-stat-box">
                            <h3>10K+</h3>
                            <p>Users</p>
                        </div>
                        <div class="nx-stat-box">
                            <h3>5K+</h3>
                            <p>Connections</p>
                        </div>
                        <div class="nx-stat-box">
                            <h3>1K+</h3>
                            <p>Daily Posts</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- FEATURES -->
            <section class="nx-section nx-features">
                <div class="nx-container">
                    <h2 class="nx-section-title center">Powerful Features</h2>

                    <div class="nx-grid">
                        <div class="nx-card"><span>👤</span><h4>Create Profile</h4><p>Showcase your identity.</p></div>
                        <div class="nx-card"><span>🤝</span><h4>Connections</h4><p>Build meaningful network.</p></div>
                        <div class="nx-card"><span>📝</span><h4>Share Content</h4><p>Post and engage.</p></div>
                        <div class="nx-card"><span>🔔</span><h4>Notifications</h4><p>Stay updated instantly.</p></div>
                        <div class="nx-card"><span>💬</span><h4>Chat</h4><p>Real-time messaging.</p></div>
                    </div>
                </div>
            </section>

            <!-- HOW IT WORKS -->
            <section class="nx-section nx-steps">
                <div class="nx-container">
                    <h2 class="nx-section-title center">How It Works</h2>

                    <div class="nx-steps-grid">
                        <div class="nx-step"><span>1</span><p>Sign Up</p></div>
                        <div class="nx-step"><span>2</span><p>Build Profile</p></div>
                        <div class="nx-step"><span>3</span><p>Connect</p></div>
                        <div class="nx-step"><span>4</span><p>Share & Chat</p></div>
                    </div>
                </div>
            </section>

            <!-- DEMO -->
            <section class="nx-section nx-demo">
                <div class="nx-container">
                    <h2 class="nx-section-title center">Live Preview</h2>

                    <div class="nx-demo-grid">
                        <div class="nx-demo-card">
                            <img src="<?php echo $feed_experience_id ? wp_get_attachment_url($feed_experience_id) : ''; ?>" alt="Preview">
                            <p>Feed Experience</p>
                        </div>

                        <div class="nx-demo-card">
                            <img src="<?php echo $real_time_chat_id ? wp_get_attachment_url($real_time_chat_id) : ''; ?>" alt="Preview">
                            <p>Real-time Chat</p>
                        </div>

                        <div class="nx-demo-card">
                            <img src="<?php echo $smart_connections_id ? wp_get_attachment_url($smart_connections_id) : ''; ?>" alt="Preview">
                            <p>Smart Connections</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- TESTIMONIALS -->
            <section class="nx-section nx-testimonials">
                <div class="nx-container">

                    <h2 class="nx-section-title center">Loved by Users</h2>

                    <div class="nx-test-grid">

                        <div class="nx-test-card">
                            <div class="nx-test-top">
                                <img src="https://i.pravatar.cc/50?img=1">
                                <div>
                                    <strong>Rahul Sharma</strong>
                                    <span>Entrepreneur</span>
                                </div>
                            </div>
                            <p>“Nexora helped me grow my network faster than ever.”</p>
                            <p>⭐⭐⭐⭐⭐</p>
                        </div>

                        <div class="nx-test-card">
                            <div class="nx-test-top">
                                <img src="https://i.pravatar.cc/50?img=2">
                                <div>
                                    <strong>Sahil</strong>
                                    <span>Developer</span>
                                </div>
                            </div>
                            <p>“Clean UI and super smooth experience.”</p>
                            <p>⭐⭐⭐⭐⭐</p>
                        </div>

                    </div>

                </div>
            </section>

            <!-- CTA -->
            <section class="nx-final-cta">
                <h2>Join Nexora Today</h2>
                <a href="<?php echo site_url('/registration-page'); ?>" class="nx-btn nx-primary">Get Started</a>
            </section>

        </div>

        <?php
        return ob_get_clean();
    }
}