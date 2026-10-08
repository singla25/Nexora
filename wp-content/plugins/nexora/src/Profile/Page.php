<?php

namespace Nexora\Profile;

if (!defined('ABSPATH')) exit;

class Page {

    public function __construct() {

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_dashboard', [$this, 'render_profile']);

        add_action('init', [$this, 'rewrite_rule']);
        add_filter('query_vars', [$this, 'query_vars']);

        add_filter('ajax_query_attachments_args', [$this, 'image_filter']); 
        add_action('init', [$this, 'allow_user_uploads']);
    }

    /* ===============================
       ASSETS
    =============================== */
    public function enqueue_assets() {

        // Profile scripts, the media library and the owner's private data are only for the profile page
        if (!\Nexora\Core\Assets::is_page_for('profile-page', 'profile_dashboard')) {
            return;
        }

        \Nexora\Core\Assets::enqueue_tokens();
        wp_enqueue_style('profile-page-style', NEXORA_URL . 'assets/css/profile-page.css', ['nexora-tokens'], NEXORA_VERSION);

        \Nexora\Core\Assets::enqueue_sweetalert();

        wp_enqueue_script('profile-page-js', NEXORA_URL . 'assets/js/profile-page.js', ['jquery','sweetalert2'], NEXORA_VERSION, true);

        // wp.media() is only needed by logged-in members (uploads)
        if (is_user_logged_in()) {
            wp_enqueue_media();
        }

        $current_user_id = get_current_user_id();
        $profile_id      = 0;
        $owner_user_id   = 0;

        $username = get_query_var('username');

        if ($username) {
            $profile_id = $this->get_profile_id_by_username($username);

            if ($profile_id) {
                $owner_user_id = (int) get_post_meta($profile_id, '_wp_user_id', true);
            }
        } elseif ($current_user_id) {
            $profile_id    = (int) get_user_meta($current_user_id, '_profile_id', true);
            $owner_user_id = $current_user_id;
        }

        $role_type = $this->get_user_role_type($current_user_id, $owner_user_id);

        // Public data: safe for any visitor
        $user_data = [
            'profile_id'    => $profile_id,
            'user_name'     => get_post_meta($profile_id, 'user_name', true),
            'first_name'    => get_post_meta($profile_id, 'first_name', true),
            'last_name'     => get_post_meta($profile_id, 'last_name', true),
            'bio'           => get_post_meta($profile_id, 'bio', true),
            'profile_image' => wp_get_attachment_url((int) get_post_meta($profile_id, 'profile_image', true)),
            'cover_image'   => wp_get_attachment_url((int) get_post_meta($profile_id, 'cover_image', true)),
        ];

        // Private data (contact details, address, ID documents) is only ever sent to the owner
        if ($role_type === 'owner' && $profile_id) {

            $private_fields = [
                'email', 'phone', 'gender', 'birthdate', 'linkedin_id',
                'perm_address', 'perm_city', 'perm_state', 'perm_pincode',
                'corr_address', 'corr_city', 'corr_state', 'corr_pincode',
                'company_name', 'designation', 'company_email', 'company_phone', 'company_address'
            ];

            foreach ($private_fields as $field) {
                $user_data[$field] = get_post_meta($profile_id, $field, true);
            }

            foreach (['profile_image', 'cover_image', 'aadhaar_card', 'driving_license', 'company_id_card'] as $doc) {
                $doc_id = (int) get_post_meta($profile_id, $doc, true);

                $user_data[$doc . '_id'] = $doc_id ?: '';
                $user_data[$doc]         = $doc_id ? wp_get_attachment_url($doc_id) : '';
            }
        }

        wp_localize_script('profile-page-js', 'profilePageData', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('profile_nonce'),
            'homeUrl'         => home_url(),
            'current_user_id' => $current_user_id,
            'roleType'        => $role_type,
            'userData'        => $user_data
        ]);
    }

    /**
     * Resolve a profile post ID from the username in the URL.
     */
    private function get_profile_id_by_username($username) {

        $username = sanitize_user($username, true);

        if ($username === '') {
            return 0;
        }

        $ids = get_posts([
            'post_type'      => 'user_profile',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'user_name',
                    'value'   => $username,
                    'compare' => '='
                ]
            ]
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /* ===============================
       ROUTING RULES
    =============================== */
    function rewrite_rule() {
        add_rewrite_rule(
            '^profile-page/([^/]+)/?$',
            'index.php?pagename=profile-page&username=$matches[1]',
            'top'
        );
    }

    function query_vars($vars) {
        $vars[] = 'username';
        return $vars;
    }

    /* ===============================
       RENDER PROFILE
    =============================== */
    private function get_user_role_type($current_user_id, $owner_user_id) {

        if (!is_user_logged_in()) {
            return 'guest';
        }

        if ($current_user_id == $owner_user_id) {
            return 'owner';
        }

        return 'viewer'; // logged in but not owner
    }
    
    /**
     * Text shown for a notification row (UI transformation of the stored type).
     */
    private function format_notification_message($noti) {

        $actor = esc_html($noti->actor_user_name);

        switch ($noti->type) {

            case 'request':
                return "{$actor} sent you a connection request";

            case 'accepted':
                return "{$actor} accepted your connection request";

            case 'rejected':
                return "{$actor} rejected your connection request";

            case 'removed':
                return "{$actor} removed the connection with you";

            case 'content':
                return "{$actor} uploaded new content";

            default:
                return esc_html($noti->message); // fallback
        }
    }

    public function render_profile() {

        // Only run on profile page
        if (!is_page('profile-page')) {
            return '';
        }

        $username = get_query_var('username');  // From URL

        $current_user_id = get_current_user_id();
        $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);
        $current_user_name = get_post_meta($current_profile_id, 'user_name', true);

        // CASE 1: Guest User
        if (!$current_user_id) {
            return '
                <div style="max-width:500px;margin:100px auto;text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.1);">
                    <h2 style="margin-bottom:10px;">🔒 Access Restricted</h2>
                    <p style="color:#6b7280; margin-bottom:20px;">
                        Please login or sign up to access your profile
                    </p>

                    <a href="' . esc_url(home_url('/login-page')) . '" 
                    style="display:inline-block; padding:10px 20px; background:#14275c; color:#fff; border-radius:8px; text-decoration:none; margin-right:10px;">
                    Login
                    </a>

                    <a href="' . esc_url(home_url('/registration-page')) . '" 
                    style="display:inline-block; padding:10px 20px; background:#16a34a; color:#fff; border-radius:8px; text-decoration:none;">
                    Sign Up
                    </a>
                </div>
            ';
        }

        // CASE 2: Admin User
        if (is_user_logged_in() && current_user_can('manage_options')) {

            $current_user = wp_get_current_user();

            return '
                <div style="max-width:520px;margin:120px auto;text-align:center;padding:50px 40px;background:#ffffff;
                border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,0.08);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
                    
                    <div style="font-size:40px; margin-bottom:10px;">⚙️</div>

                    <h2 style="margin-bottom:8px;font-size:22px;font-weight:600;color:#111827;">
                        Welcome back, ' . esc_html($current_user->display_name) . ' 👋
                    </h2>

                    <p style="color:#9ca3af;font-size:14px;margin-bottom:6px;">
                        You are currently in admin mode
                    </p>

                    <p style="color:#4b5563;font-size:15px;margin-bottom:25px;">
                        Manage users, content and system settings from your dashboard.
                    </p>

                    <a href="' . esc_url(admin_url() . '?admin_access=true') . '"
                    style="display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#14275c,#2c8fd1);color:#fff;
                    border-radius:10px;text-decoration:none;font-size:14px;font-weight:500;box-shadow:0 8px 20px rgba(20,39,92,0.3);transition:all 0.2s ease;">
                        Go to Dashboard →
                    </a>

                </div>
            ';
        }

        // CASE 3: Own profile (/profile-page)
        if (!$username) {

            if (!$current_user_id) {
                return "<p>Please login</p>";
            }

            $profile_id = (int) get_user_meta($current_user_id, '_profile_id', true);
            $owner_user_id = $current_user_id;

            if (!$profile_id) {
                return '<p>Profile not found.</p>';
            }
        }

        // CASE 4: Other user's profile (/profile-page/username)
        else {

            $profile_id = $this->get_profile_id_by_username($username);

            if (!$profile_id) {
                return '
                    <div style="max-width:520px;margin:120px auto;text-align:center;padding:50px 40px;background:#ffffff;border-radius:16px;
                        box-shadow:0 20px 50px rgba(0,0,0,0.08);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
                        
                        <div style="font-size:42px; margin-bottom:12px;">🔍</div>

                        <h2 style="margin-bottom:8px;font-size:22px;font-weight:600;color:#111827;">
                            User not found
                        </h2>

                        <p style="color:#9ca3af;font-size:14px;margin-bottom:6px;">
                            We couldn’t find the profile you’re looking for
                        </p>

                        <p style="color:#4b5563;font-size:15px;margin-bottom:25px;">
                            The username might be incorrect, or the user may have removed their profile.
                        </p>

                        <a href="' . esc_url(home_url()) . '"
                        style="display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#14275c,#2c8fd1);
                            color:#fff;border-radius:10px;text-decoration:none;font-size:14px;font-weight:500;
                            box-shadow:0 8px 20px rgba(20,39,92,0.3);transition:all 0.2s ease;">
                            Go to Home →
                        </a>

                    </div>
                ';
            }

            $owner_user_id = get_post_meta($profile_id, '_wp_user_id', true);
        }

        // Role of User
        $role_type = $this->get_user_role_type($current_user_id, $owner_user_id);

        $is_owner = ($role_type === 'owner');
        $is_logged_in = ($role_type !== 'guest');

        $name     = get_the_title($profile_id);
        $username = get_post_meta($profile_id, 'user_name', true);
        // Contact details are private to the owner
        $email    = $is_owner ? get_post_meta($profile_id, 'email', true) : '';
        $phone    = $is_owner ? get_post_meta($profile_id, 'phone', true) : '';

        $profile_image_id  = get_post_meta($profile_id, 'profile_image', true);
        $cover_image_id  = get_post_meta($profile_id, 'cover_image', true);
        
        $default_profile_id = get_option('default_profile_image');
        $default_cover_id   = get_option('default_cover_image');
        $default_doc_id     = get_option('default_document_image');

        $default_profile = $default_profile_id ? wp_get_attachment_url($default_profile_id) : '';
        $default_cover   = $default_cover_id ? wp_get_attachment_url($default_cover_id) : '';
        $default_doc     = $default_doc_id ? wp_get_attachment_url($default_doc_id) : '';

        $profile_image = $profile_image_id ? wp_get_attachment_url($profile_image_id) : $default_profile;
        $cover_image = $cover_image_id ? wp_get_attachment_url($cover_image_id) : $default_cover;

        $notification = new \Nexora\Notifications\Repository();
        $unread_count = $notification->get_unread_count($current_user_id);

        ob_start();
        ?>
        <div class="profile-container">
            <div class="profile-wrapper">

                <!-- COVER -->
                <div class="profile-cover" style="background-image:url('<?php echo esc_url($cover_image); ?>')"></div>

                <!-- HEADER -->
                <div class="profile-header">
                    <img src="<?php echo esc_url($profile_image); ?>" class="profile-avatar">
                    <h2><?php echo esc_html($username); ?></h2>
                    <h4><?php echo esc_html($name); ?></h4>
                    <?php if ($is_owner): ?>
                        <p><?php echo esc_html($email); ?> | <?php echo esc_html($phone); ?></p>
                    <?php endif; ?>
                </div>

                <!-- TABS -->
                <div class="profile-tabs">
                    <button class="tab-btn active" data-tab="user-info">User Information</button>
                    <button class="tab-btn" data-tab="connections">Connections</button>
                    <?php if ($is_owner): ?>
                        <button class="tab-btn" data-tab="content">Content</button>
                        <button class="tab-btn" data-tab="notifications">
                            Notifications
                            <?php if ($unread_count > 0): ?>
                                <span class="noti-badge">
                                    <?php echo (int) $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- MAIN CONTENT -->
                <div class="profile-content">
                    
                    <!-- USER INFORMATION -->
                    <div class="tab-content active" id="user-info">
                        <div class="user-info-header">
                            <?php if ($is_owner): ?>
                                
                                <div class="user-info-left">
                                    <h3>Your Information</h3>
                                    <span class="user-info-sub">Manage your Informations</span>
                                </div>

                                <div class="user-info-right">
                                    <button class="user-edit-info active" data-type="personal-info">Personal</button>
                                    <button class="user-edit-info" data-type="address-info">Address</button>
                                    <button class="user-edit-info" data-type="work-info">Work</button>
                                    <button class="user-edit-info" data-type="docs-info">Documents</button>
                                    <button class="user-edit-info" data-type="security-info">Security</button>
                                </div>

                            <?php else: ?>
                                <div class="user-info-center">
                                    <h3>User Information</h3>
                                    <span class="user-info-sub">Login to explore more</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div id="user-info-content">
                            <!-- PERSONAL INFO -->
                            <div class="info-card">
                                <h3>Personal Information</h3>

                                <div class="info-grid">

                                    <div class="info-item">
                                        <span class="info-label">Username</span>
                                        <span class="info-value"><?php echo esc_html($username); ?></span>
                                    </div>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Email</span>
                                        <span class="info-value"><?php echo esc_html($email); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <div class="info-item">
                                        <span class="info-label">First Name</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'first_name',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Last Name</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'last_name',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'gender',true)); ?></span>
                                    </div>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Birthdate</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'birthdate',true)); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Phone</span>
                                        <span class="info-value"><?php echo esc_html($phone); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-item">
                                        <span class="info-label">LinkedIn</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'linkedin_id',true)); ?></span>
                                    </div>

                                </div>

                                <div class="info-full">
                                    <span class="info-label">Bio</span>
                                    <p class="info-value"><?php echo esc_html(get_post_meta($profile_id,'bio',true)); ?></p>
                                </div>
                            </div>

                            <!-- ADDRESS INFO -->
                            <?php if ($is_owner): ?>
                            <div class="info-card">
                                <h3>Address Information</h3>

                                <!-- PERMANENT -->
                                <div class="info-section">
                                    <h4>Permanent Address</h4>

                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Address</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'perm_address',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">City</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'perm_city',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">State</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'perm_state',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">Pincode</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'perm_pincode',true)); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- CORRESPONDENCE -->
                                <div class="info-section">
                                    <h4>Correspondence Address</h4>

                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Address</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'corr_address',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">City</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'corr_city',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">State</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'corr_state',true)); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">Pincode</span>
                                            <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'corr_pincode',true)); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- WORK INFO -->
                            <div class="info-card">
                                <h3>Work Information</h3>

                                <div class="info-grid">

                                    <div class="info-item">
                                        <span class="info-label">Company Name</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'company_name',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Designation</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'designation',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Email</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'company_email',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Phone</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'company_phone',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Address</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'company_address',true)); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- DOCUMENTS -->
                            <div class="info-card">
                                <h3>Documents</h3>

                                <div class="doc-grid">

                                    <?php
                                    $docs = [
                                        'profile_image'   => 'Profile Image',
                                        'cover_image'     => 'Cover Image',
                                        'aadhaar_card'    => 'Aadhaar Card',
                                        'driving_license' => 'Driving License',
                                        'company_id_card' => 'Company ID Card'
                                    ];

                                    foreach ($docs as $key => $label):

                                        // ID documents are private to the owner
                                        if (!$is_owner && !in_array($key, ['profile_image', 'cover_image'], true)) {
                                            continue;
                                        }

                                        $id  = get_post_meta($profile_id,$key,true);
                                        $url = $id ? wp_get_attachment_url($id) : '';

                                        // ✅ Fallback logic
                                        if (!$url) {
                                            if ($key === 'profile_image') {
                                                $url = $default_profile;
                                            } elseif ($key === 'cover_image') {
                                                $url = $default_cover;
                                            } else {
                                                $url = $default_doc;
                                            }
                                        }
                                    ?>

                                        <div class="doc-card">
                                            <span class="doc-title"><?php echo esc_html($label); ?></span>

                                            <?php if ($url): ?>
                                                <a href="<?php echo esc_url($url); ?>" target="_blank">
                                                    <img src="<?php echo esc_url($url); ?>" class="doc-img">
                                                </a>
                                            <?php else: ?>
                                                <div class="doc-empty-box">No File</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CONNECTIONS -->
                    <div class="tab-content" id="connections">
                        <div class="connection-header">

                            <?php if (!$is_logged_in): ?>
                                <!-- CASE 1: GUEST -->
                                <div class="conn-center">
                                    <h3>Connections</h3>
                                    <span class="conn-sub">Login to explore connections</span>
                                </div>

                            <?php elseif ($is_owner): ?>
                                <!-- CASE 2: OWNER -->
                                <div class="conn-left">
                                    <h3 id="conn-heading">Connections</h3>
                                    <span class="conn-sub">Manage your network</span>
                                </div>

                                <div class="conn-right">
                                    <button class="conn-tab" data-type="add">Add New</button>
                                    <button class="conn-tab" data-type="requests">Requests</button>
                                    <button class="conn-tab" data-type="history">History</button>
                                    <button class="conn-tab" data-type="chat">Chat</button>
                                </div>

                            <?php else: ?>
                                <!-- CASE 3: OTHER USER -->
                                <div class="conn-left">
                                    <h3>Connections</h3>
                                    <span class="conn-sub">View their network</span>
                                </div>

                                <div class="conn-right"> 
                                    <button class="conn-tab" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
                                        All Connections
                                    </button>

                                    <button class="conn-tab" data-type="view-common-conn" data-profile="<?php echo (int) $profile_id; ?>">
                                        Mutual
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- CONNECTION ESTABLISHED -->
                        <div id="connection-established">
                            <?php

                            $connections = get_posts([
                                'post_type' => 'user_connections',
                                'posts_per_page' => -1,
                                'meta_query' => [
                                    [
                                        'key' => 'status',
                                        'value' => 'accepted'
                                    ]
                                ]
                            ]);

                            $data = [];

                            foreach ($connections as $conn) {

                                $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
                                $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

                                // Check if current user involved
                                if ($sender == $profile_id || $receiver == $profile_id) {

                                    $other_id = ($sender == $profile_id) ? $receiver : $sender;

                                    $data[] = [
                                        'connection_id' => $conn->ID,
                                        'profile_id' => $other_id,
                                        'username' => get_post_meta($other_id, 'user_name', true),
                                        'name' => get_post_meta($other_id, 'first_name', true) . ' ' . get_post_meta($other_id, 'last_name', true),
                                        'image' => \Nexora\Profile\Repository::get_profile_image($other_id),
                                        'profile_link' => site_url('/profile-page/' . get_post_meta($other_id, 'user_name', true))
                                    ];
                                }
                            }
                            ?>
                            
                            <?php if ($is_owner): ?>
                                <div class="establish-connection-cards">
                                    <?php if (!empty($data)) : ?>
                                        <?php foreach ($data as $user) : ?>
                                            <div class="establish-connection-card">

                                                <!-- COVER -->
                                                <div class="conn-cover"></div>

                                                <!-- AVATAR -->
                                                <div class="conn-avatar">
                                                    <img src="<?php echo esc_url($user['image']); ?>" alt="">
                                                </div>

                                                <!-- INFO -->
                                                <div class="conn-body">

                                                    <a href="<?php echo esc_url($user['profile_link']); ?>" class="conn-username">
                                                        <?php echo esc_html($user['username']); ?>
                                                    </a>

                                                    <p class="conn-name">
                                                        <?php echo esc_html($user['name']); ?>
                                                    </p>

                                                    <?php if ($is_owner): ?>
                                                        <button class="remove-connection-btn" data-id="<?php echo (int) $user['connection_id']; ?>">
                                                            Remove
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <div class="empty-content">
                                            <div class="empty-icon">🤝</div>
                                            <h3>No Connections Yet</h3>
                                            <p>
                                                You haven’t connected with anyone yet.<br>
                                                Start building your network by sending connection requests 🚀
                                            </p>
                                            <button class="conn-tab" data-type="add">
                                                + Find People
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php
                                    $total_connections = count($data);
                                    $mutual_count = 0;

                                    if ($is_logged_in) {

                                        $current_user_id = get_current_user_id();
                                        $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);

                                        $current_connections = \Nexora\Connections\Repository::accepted_profile_ids($current_profile_id);
                                        $other_connections   = \Nexora\Connections\Repository::accepted_profile_ids($profile_id);

                                        $mutual_ids = array_intersect($current_connections, $other_connections);

                                        $mutual_count = count($mutual_ids);
                                    }
                                ?>

                                <div class="connection-summary-wrapper">
                                    <div class="connection-summary-card">
                                        <h2><?php echo esc_html($total_connections); ?></h2>
                                        <p>Connections</p>

                                        <?php if ($is_logged_in): ?>
                                            <p class="mutual-count">
                                                <?php echo esc_html($mutual_count); ?> Mutual Connections
                                            </p>
                                        <?php endif; ?>

                                        <div class="connection-preview">
                                            <?php
                                            $preview = array_slice($data, 0, 3);
                                            foreach ($preview as $user):
                                            ?>
                                                <img src="<?php echo esc_url($user['image']); ?>" alt="">
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if ($is_logged_in): ?>
                                            <button class="view-all-btn" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
                                                View All Connections
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- CHAT -->
                        <!-- <div id="connection-chat" style="display:none;">
                            <?php // echo do_shortcode('[better_messages]'); ?>
                        </div> -->
                    </div>

                    <!-- NOTIFICATION -->
                    <div class="tab-content" id="notifications">

                        <?php if ($is_owner): ?>

                        <?php
                        $notification = new \Nexora\Notifications\Repository();
                        $notifications = $notification->get_notifications($current_user_id);

                        ?>

                        <div class="notification-wrapper">

                            <div class="notification-header">
                                <h3>🔔 Notifications</h3>
                            </div>

                            <div class="notification-list">

                                <?php if ($notifications): foreach ($notifications as $noti): ?>

                                    <?php
                                        $formatted_message = $this->format_notification_message($noti);
                                        $is_unread = !$noti->is_read;
                                    ?>

                                    <div class="notification-item <?php echo !$noti->is_read ? 'unread' : ''; ?>">

                                        <!-- LEFT AVATAR -->
                                        <?php 
                                        $actor_profile_id = get_user_meta($noti->actor_user_id, '_profile_id', true);
                                        ?>

                                        <div class="noti-avatar">
                                            <img src="<?php echo esc_url(
                                                \Nexora\Profile\Repository::get_profile_image($actor_profile_id)
                                            ); ?>">
                                        </div>

                                        <!-- CONTENT -->
                                        <div class="noti-content">

                                            <div class="noti-top">
                                                <span class="noti-message">
                                                    <?php echo esc_html($formatted_message); ?>
                                                </span>

                                                <span class="noti-status <?php echo !$noti->is_read ? 'new' : 'read'; ?>">
                                                    <?php echo !$noti->is_read ? 'New' : 'Read'; ?>
                                                </span>
                                            </div>

                                            <div class="noti-time">
                                                <?php echo esc_html(date('d M Y • h:i A', strtotime($noti->created_at))); ?>
                                            </div>

                                        </div>

                                        <!-- ACTION -->
                                        <button 
                                            class="notification-view"
                                            data-id="<?php echo (int) $noti->id; ?>"
                                            data-type="received"
                                        >
                                            View
                                        </button>

                                    </div>

                                <?php endforeach; else: ?>

                                    <div class="empty-notification empty-content">

                                        <div class="empty-icon">🔔</div>

                                        <h3>No Notifications Yet</h3>

                                        <p>
                                            You're all caught up 🎉 <br>
                                            Notifications will appear here when you get updates
                                        </p>
                                    </div>

                                <?php endif; ?>
                            </div>
                        </div>

                        <?php else: ?>
                            <p>Access restricted</p>
                        <?php endif; ?>
                    </div>

                    <!-- CONTENT -->
                    <div class="tab-content" id="content">
                        <div class="content-header">
                            <div class="content-left">
                                <h3>Content</h3>
                                <span class="content-sub">See Content of Other Users</span>
                            </div>

                            <div class="content-right">
                                <button class="content-tab" data-type="add">Add New</button>
                                <button class="content-tab" data-type="history">History</button>
                            </div>
                        </div>

                        <div class="content-box">

                            <?php
                            $current_user_id = get_current_user_id();
                            $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);

                            $posts = get_posts([
                                'post_type' => 'user_content',
                                'posts_per_page' => -1
                            ]);

                            $has_other_posts = false;

                            if ($posts):

                                foreach ($posts as $post):

                                    $author_profile_id = get_post_meta($post->ID, 'user_profile_id', true);
                                    if ($author_profile_id == $current_profile_id) continue;
                                    $has_other_posts = true;

                                    $image   = get_the_post_thumbnail_url($post->ID, 'medium');
                                    $title   = $post->post_title;
                                    $content = $post->post_content;

                                    $user_name  = get_post_meta($post->ID, 'user_name', true);
                                    $first_name = get_post_meta($author_profile_id, 'first_name', true);
                                    $last_name  = get_post_meta($author_profile_id, 'last_name', true);

                                    $full_name = $first_name . ' ' . $last_name;
                                    $date = get_the_date('Y-m-d H:i', $post->ID);

                                    $profile_link = site_url('/profile-page/' . $user_name);
                            ?>

                            <div class="content-card"
                                data-title="<?php echo esc_attr($title); ?>"
                                data-content="<?php echo esc_attr($content); ?>"
                                data-image="<?php echo esc_url($image); ?>"
                                data-username="<?php echo esc_attr($user_name); ?>"
                                data-fullname="<?php echo esc_attr($full_name); ?>"
                                data-date="<?php echo esc_attr($date); ?>"
                                data-profile="<?php echo esc_url($profile_link); ?>"
                            >

                                <img src="<?php echo esc_url($image); ?>" class="content-img">

                                <div class="content-body">
                                    <a href="<?php echo esc_url($profile_link); ?>" 
                                        class="content-user" target="_blank"
                                        onclick="event.stopPropagation();">
                                        <?php echo esc_html($user_name); ?>
                                    </a>

                                    <h4 class="content-title view-post"><?php echo esc_html($title); ?></h4>
                                </div>

                            </div>

                            <?php endforeach; ?>

                            <?php if (!$has_other_posts): ?>

                                <!-- EMPTY STATE -->
                                <div class="empty-content">
                                    <div class="empty-icon">📭</div>
                                    <h3>No Content Yet</h3>
                                    <p>No one else has posted anything yet.</p>
                                </div>

                            <?php endif; ?>

                            <?php else: ?>

                                <!-- OPTIONAL: if literally no posts exist at all -->
                                <div class="empty-content">
                                    <div class="empty-icon">📭</div>
                                    <h3>No Content Yet</h3>
                                    <p>No one else has posted anything yet.</p>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LOG OUT -->
            <?php if ($is_owner): ?>                    
                <div style="text-align:center; margin-top:30px;">
                    <a class="logout-btn" href="<?php echo esc_url(wp_logout_url(home_url('/login-page'))); ?>" 
                    style="display:inline-block; padding:12px 25px; background:#ef4444; color:#fff; border-radius:10px; text-decoration:none;">
                        Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    /* ===============================
       Image Filter
    =============================== */
    function image_filter($query) {

        if (!current_user_can('manage_options')) {
            $query['author'] = get_current_user_id();
        }

        return $query;
    }

    function allow_user_uploads() {

        // Upload rights are granted per request by \Nexora\Profile\Upload_Policy (no stored role capability)
        // Members may only upload images and PDFs
        add_filter('upload_mimes', [$this, 'restrict_member_mimes']);
    }

    function restrict_member_mimes($mimes) {

        if (current_user_can('manage_options')) {
            return $mimes;
        }

        return [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'pdf'          => 'application/pdf',
        ];
    }
}