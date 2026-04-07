<?php

class NEXORA_Page {

    public function __construct() {

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_dashboard', [$this, 'render_profile']);

        add_action('init', [$this, 'rewrite_rule']);
        add_filter('query_vars', [$this, 'query_vars']);

        add_filter('ajax_query_attachments_args', [$this, 'image_filter']); 
        add_action('init', [$this, 'allow_user_uploads']);

        add_action('wp_ajax_profile_update', [$this, 'profile_update']);
        add_action('wp_ajax_nopriv_profile_update', [$this, 'profile_update']);

        add_action('wp_ajax_get_add_new_users', [$this, 'get_add_new_users']);
        add_action('wp_ajax_nopriv_add_new_users', [$this, 'add_new_users']);

        add_action('wp_ajax_send_connection_request', [$this, 'send_connection_request']);
        add_action('wp_ajax_nopriv_send_connection_request', [$this, 'send_connection_request']);

        add_action('wp_ajax_get_requests', [$this, 'get_requests']);
        add_action('wp_ajax_nopriv_get_requests', [$this, 'get_requests']);

        add_action('wp_ajax_update_connection_status', [$this, 'update_connection_status']);
        add_action('wp_ajax_nopriv_update_connection_status', [$this, 'update_connection_status']);

        add_action('wp_ajax_view_all_connection', [$this, 'view_all_connection']);
        add_action('wp_ajax_nopriv_view_all_connection', [$this, 'view_all_connection']);

        add_action('wp_ajax_view_mutual_connection', [$this, 'view_mutual_connection']);
        add_action('wp_ajax_nopriv_view_mutual_connection', [$this, 'view_mutual_connection']);

        add_action('wp_ajax_get_history', [$this, 'get_history']);
        add_action('wp_ajax_nopriv_get_history', [$this, 'get_history']);

        add_action('wp_ajax_change_password', [$this, 'change_password']);
        add_action('wp_ajax_nopriv_change_password', [$this, 'change_password']);

        add_action('wp_ajax_mark_notification_read', [$this, 'mark_notification_read']);
        add_action('wp_ajax_nopriv_mark_notification_read', [$this, 'mark_notification_read']);

        add_action('wp_ajax_save_user_content', [$this, 'save_user_content']);
        add_action('wp_ajax_nopriv_save_user_content', [$this, 'save_user_content']);

        add_action('wp_ajax_get_user_content_history', [$this, 'get_user_content_history']);
        add_action('wp_ajax_nopriv_get_user_content_history', [$this, 'get_user_content_history']);
    }

    /* ===============================
       ASSETS
    =============================== */
    public function enqueue_assets() {

        wp_enqueue_style('profile-page-style', NEXORA_URL . 'assets/css/profile-page.css');

        wp_enqueue_script('sweetalert2','https://cdn.jsdelivr.net/npm/sweetalert2@11',[],null,true);

        wp_enqueue_script(
            'profile-page-js',
            NEXORA_URL . 'assets/js/profile-page.js',
            ['jquery','sweetalert2'],
            null,
            true
        );

        wp_enqueue_media(); // To upload Media by Using wp.media()

        wp_localize_script('profile-page-js', 'profilePageData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('profile_nonce'),
            'homeUrl' => home_url()
        ]);
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
       GET NEW USER
    =============================== */
    public function get_add_new_users() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        // Get all connections of current user
        $connections = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'sender_profile_id',
                    'value' => $profile_id
                ],
                [
                    'key' => 'receiver_profile_id',
                    'value' => $profile_id
                ]
            ]
        ]);

        $blocked_ids = [$profile_id];

        foreach ($connections as $conn) {

            $status = get_post_meta($conn->ID, 'status', true);

            if (in_array($status, ['pending', 'accepted'])) {

                $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
                $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

                $blocked_ids[] = $sender;
                $blocked_ids[] = $receiver;
            }
        }

        // Get users excluding blocked
        $users = get_posts([
            'post_type' => 'user_profile',
            'posts_per_page' => -1,
            'post__not_in' => $blocked_ids
        ]);

        $data = [];

        foreach ($users as $user) {

            $data[] = [
                'profile_id' => $user->ID,
                'username' => get_post_meta($user->ID, 'user_name', true),
                'name' => get_post_meta($user->ID, 'first_name', true) . ' ' . get_post_meta($user->ID, 'last_name', true),
                'image' => wp_get_attachment_url(get_post_meta($user->ID, 'profile_image', true))
            ];
        }

        wp_send_json_success($data);
    }

    /* ===============================
       SEND CONNECTION REQUEST
    =============================== */
    public function send_connection_request() {

        check_ajax_referer('profile_nonce', 'nonce');

        $receiver_profile_id = intval($_POST['receiver_profile_id']);

        $sender_user_id = get_current_user_id();
        $sender_profile_id = get_user_meta($sender_user_id, '_profile_id', true);
        $sender_user_name = get_post_meta($sender_profile_id, 'user_name', true);

        $receiver_user_id   = get_post_meta($receiver_profile_id, '_wp_user_id', true);
        $receiver_user_name = get_post_meta($receiver_profile_id, 'user_name', true);

        $post_id = wp_insert_post([
            'post_type' => 'user_connections',
            'post_status' => 'publish',
            'post_title' => $sender_user_name . '->' . $receiver_user_name
        ]);

        update_post_meta($post_id, 'sender_user_id', $sender_user_id);
        update_post_meta($post_id, 'sender_profile_id', $sender_profile_id);
        update_post_meta($post_id, 'sender_user_name', $sender_user_name);

        update_post_meta($post_id, 'receiver_user_id', $receiver_user_id);
        update_post_meta($post_id, 'receiver_profile_id', $receiver_profile_id);
        update_post_meta($post_id, 'receiver_user_name', $receiver_user_name);

        update_post_meta($post_id, 'status', 'pending');

        // 🔔 Notification insert
        $notification = new NEXORA_Notification();

        $notification->insert([
            'sender_user_id'      => $sender_user_id,
            'sender_profile_id'   => $sender_profile_id,
            'sender_user_name'    => $sender_user_name,

            'receiver_user_id'    => $receiver_user_id,
            'receiver_profile_id' => $receiver_profile_id,
            'receiver_user_name'  => $receiver_user_name,

            'type' => 'request',
            'reference_id' => $post_id,
            'message' => "{$sender_user_name} sent a connection request to {$receiver_user_name}"
        ]);

        wp_send_json_success('Request sent');
    }

    /* ===============================
       GET REQUESTS
    =============================== */
    public function get_requests() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        $requests = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'receiver_profile_id',
                    'value' => $profile_id
                ],
                [
                    'key' => 'status',
                    'value' => 'pending'
                ]
            ]
        ]);

        $data = [];

        foreach ($requests as $conn) {

            $sender = get_post_meta($conn->ID, 'sender_profile_id', true);

            $data[] = [
                'connection_id' => $conn->ID,
                'profile_id' => $sender,
                'username' => get_post_meta($sender, 'user_name', true),
                'name' => get_post_meta($sender, 'first_name', true) . ' ' . get_post_meta($sender, 'last_name', true),
                'image' => wp_get_attachment_url(get_post_meta($sender, 'profile_image', true))
            ];
        }

        wp_send_json_success($data);
    }

    /* ===============================
       REQUEST ACCEPTED Or Rejected
    =============================== */
    public function update_connection_status() {

        check_ajax_referer('profile_nonce', 'nonce');

        $connection_id = intval($_POST['connection_id']);
        $status = sanitize_text_field($_POST['status']);

        update_post_meta($connection_id, 'status', $status);

        // 🔔 Fetch connection data
        $sender_user_id      = get_post_meta($connection_id, 'sender_user_id', true);
        $sender_profile_id   = get_post_meta($connection_id, 'sender_profile_id', true);
        $sender_user_name    = get_post_meta($connection_id, 'sender_user_name', true);

        $receiver_user_id    = get_post_meta($connection_id, 'receiver_user_id', true);
        $receiver_profile_id = get_post_meta($connection_id, 'receiver_profile_id', true);
        $receiver_user_name  = get_post_meta($connection_id, 'receiver_user_name', true);

        $notification = new NEXORA_Notification();

        // 🎯 MESSAGE BASED ON STATUS
        if ($status === 'accepted') {
            $message = "{$receiver_user_name} accepted your connection request";
        } elseif ($status === 'rejected') {
            $message = "{$receiver_user_name} rejected your connection request";
        } elseif ($status === 'removed') {
            $message = "{$receiver_user_name} removed the connection";
        } else {
            $message = "Connection status updated";
        }

        // 🔔 Insert notification (for sender)
        $notification->insert([
            'sender_user_id'      => $receiver_user_id, // actor
            'sender_profile_id'   => $receiver_profile_id,
            'sender_user_name'    => $receiver_user_name,

            'receiver_user_id'    => $sender_user_id, // notify sender
            'receiver_profile_id' => $sender_profile_id,
            'receiver_user_name'  => $sender_user_name,

            'type' => $status,
            'reference_id' => $connection_id,
            'message' => $message
        ]);

        wp_send_json_success();
    }

    /* ===============================
       HISTORY
    =============================== */
    public function get_history() {

        check_ajax_referer('profile_nonce', 'nonce');

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        // ===============================
        // RECEIVED
        // ===============================
        $received = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'receiver_profile_id',
                    'value' => $profile_id
                ]
            ]
        ]);

        // ===============================
        // SENT
        // ===============================
        $sent = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'sender_profile_id',
                    'value' => $profile_id
                ]
            ]
        ]);

        ob_start();
        ?>

        <h3>📥 Received Requests</h3>

        <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Status</th>
            </tr>

            <?php if ($received): foreach ($received as $conn):
                $sender_id = get_post_meta($conn->ID, 'sender_profile_id', true);
                $status = get_post_meta($conn->ID, 'status', true);
                $username = get_post_meta($sender_id,'user_name',true);
                $first_name = get_post_meta($sender_id,'first_name',true);
                $last_name = get_post_meta($sender_id,'last_name',true);
                $link = site_url('/profile-page/' . $username);
            ?>

            <tr>
                <td>
                    <a href="<?php echo esc_url($link); ?>" class="history-user-link">
                        <?php echo esc_html($username); ?>
                    </a>
                </td>
                <td><?php echo esc_html($first_name . ' ' . $last_name); ?></td>
                <td><?php echo esc_html($status); ?></td>
            </tr>

            <?php endforeach; else: ?>
                <tr><td colspan="3">No received requests</td></tr>
            <?php endif; ?>
        </table>


        <h3>📤 Sent Requests</h3>

        <table style="width:100%; border-collapse:collapse;">
            <tr>
                <th>Username</th>
                <th>Name</th>
                <th>Status</th>
            </tr>

            <?php if ($sent): foreach ($sent as $conn):

                $receiver_id = get_post_meta($conn->ID, 'receiver_profile_id', true);
                $status = get_post_meta($conn->ID, 'status', true);
                $username = get_post_meta($receiver_id,'user_name',true);
                $first_name = get_post_meta($receiver_id,'first_name',true);
                $last_name = get_post_meta($receiver_id,'last_name',true);
                $link = site_url('/profile-page/' . $username);

            ?>

            <tr>
                <td>
                    <a href="<?php echo esc_url($link); ?>" class="history-user-link">
                        <?php echo esc_html($username); ?>
                    </a>
                </td>
                <td><?php echo esc_html($first_name . ' ' . $last_name); ?></td>
                <td><?php echo esc_html($status); ?></td>
            </tr>

            <?php endforeach; else: ?>
                <tr><td colspan="3">No sent requests</td></tr>
            <?php endif; ?>
        </table>

        <?php

        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /* ===============================
       VIEW ALL CONNECTIONS
    =============================== */
    public function view_all_connection() {

        check_ajax_referer('profile_nonce', 'nonce');

        $profile_id = intval($_POST['profile_id']);

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

            if ($sender == $profile_id || $receiver == $profile_id) {

                $other_id = ($sender == $profile_id) ? $receiver : $sender;

                $data[] = [
                    'profile_id' => $other_id,
                    'username' => get_post_meta($other_id, 'user_name', true),
                    'name' => get_post_meta($other_id, 'first_name', true) . ' ' . get_post_meta($other_id, 'last_name', true),
                    'image' => wp_get_attachment_url(get_post_meta($other_id, 'profile_image', true)),
                    'profile_link' => site_url('/profile-page/' . get_post_meta($other_id, 'user_name', true))
                ];
            }
        }

        ob_start();

        if (!empty($data)) {
            foreach ($data as $user) {
                ?>
                <div class="connection-card">

                    <div class="conn-cover"></div>

                    <div class="conn-avatar">
                        <img src="<?php echo esc_url($user['image']); ?>">
                    </div>

                    <div class="conn-body">

                        <a href="<?php echo esc_url($user['profile_link']); ?>" class="conn-username" target="_blank">
                            <?php echo esc_html($user['username']); ?>
                        </a>

                        <p class="conn-name">
                            <?php echo esc_html($user['name']); ?>
                        </p>

                    </div>
                </div>
                <?php
            }
        } else {
            echo "<p>No connections found</p>";
        }

        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /* ===============================
       VIEW MUTUAL CONNECTIONS
    =============================== */
    public function view_mutual_connection() {

        check_ajax_referer('profile_nonce', 'nonce');

        $other_profile_id = intval($_POST['profile_id']);

        $current_user_id = get_current_user_id();
        $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);

        // 1. Get connections of both
        $current_connections = $this->get_user_connection_ids($current_profile_id);
        $other_connections   = $this->get_user_connection_ids($other_profile_id);

        // 2. Find mutual
        $mutual_ids = array_intersect($current_connections, $other_connections);

        $data = [];

        foreach ($mutual_ids as $id) {

            $data[] = [
                'profile_id' => $id,
                'username' => get_post_meta($id, 'user_name', true),
                'name' => get_post_meta($id, 'first_name', true) . ' ' . get_post_meta($id, 'last_name', true),
                'image' => wp_get_attachment_url(get_post_meta($id, 'profile_image', true)),
                'profile_link' => site_url('/profile-page/' . get_post_meta($id, 'user_name', true))
            ];
        }

        ob_start();

        if (!empty($data)) {
            foreach ($data as $user) {
                ?>
                <div class="connection-card">

                    <div class="conn-cover"></div>

                    <div class="conn-avatar">
                        <img src="<?php echo esc_url($user['image']); ?>">
                    </div>

                    <div class="conn-body">

                        <a href="<?php echo esc_url($user['profile_link']); ?>" class="conn-username" target="_blank">
                            <?php echo esc_html($user['username']); ?>
                        </a>

                        <p class="conn-name">
                            <?php echo esc_html($user['name']); ?>
                        </p>

                        <span class="mutual-badge">Mutual</span>

                    </div>
                </div>
                <?php
            }
        } else {
            echo "<p>No mutual connections found</p>";
        }

        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    private function get_user_connection_ids($profile_id) {

        $connections = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'status',
                    'value' => 'accepted'
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => 'sender_profile_id',
                        'value' => $profile_id
                    ],
                    [
                        'key' => 'receiver_profile_id',
                        'value' => $profile_id
                    ]
                ]
            ]
        ]);

        $ids = [];

        foreach ($connections as $conn) {

            $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
            $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

            if ($sender == $profile_id) {
                $ids[] = $receiver;
            } else {
                $ids[] = $sender;
            }
        }

        return $ids;
    }

    /* ===============================
       CHANGE PASSWORD
    =============================== */
    public function change_password() {

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $user_id = get_current_user_id();

        $current_password = $_POST['current_password'];
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // 🔐 Check current password
        $user = get_user_by('id', $user_id);

        if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
            wp_send_json_error('Current password is incorrect');
        }

        // ❌ match check
        if ($new_password !== $confirm_password) {
            wp_send_json_error('Passwords do not match');
        }

        // ❌ prevent same password
        if ($current_password === $new_password) {
            wp_send_json_error('New password must be different');
        }

        // ✅ Update password
        wp_set_password($new_password, $user_id);

        wp_send_json_success('Password updated successfully');
    }

    /* ===============================
       NOTIFICATION
    =============================== */
    public function mark_notification_read() {

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $id = intval($_POST['id']);
        $user_id = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'nexora_notifications';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)
        );

        if (!$row || $row->receiver_user_id != $user_id) {
            wp_send_json_error('Unauthorized');
        }

        $notification = new NEXORA_Notification();
        $notification->mark_as_read($id);

        wp_send_json_success([
            'message' => $row->message
        ]);
    }

    /* ===============================
       ADD NEW CONTENT
    =============================== */
    public function save_user_content() {

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        $title       = sanitize_text_field($_POST['title']);
        $description = sanitize_textarea_field($_POST['description']);
        $image_id    = intval($_POST['image']);

        // Get user name from profile
        $user_name = get_post_meta($profile_id, 'user_name', true);

        // Create post
        $post_id = wp_insert_post([
            'post_type'   => 'user_content',
            'post_title'  => $title,
            'post_content'=> $description,
            'post_status' => 'publish'
        ]);

        if (!$post_id) {
            wp_send_json_error('Failed to create post');
        }

        // Set featured image
        if ($image_id) {
            set_post_thumbnail($post_id, $image_id);
        }

        // Save meta
        update_post_meta($post_id, 'user_id', $user_id);
        update_post_meta($post_id, 'user_profile_id', $profile_id);
        update_post_meta($post_id, 'user_name', $user_name);

        wp_send_json_success('Post created');
    }

    /* ===============================
       CONTENT HISTORY
    =============================== */
    public function get_user_content_history() {

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        // Fetch only current user's content
        $posts = get_posts([
            'post_type' => 'user_content',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'user_profile_id',
                    'value' => $profile_id
                ]
            ]
        ]);

        ob_start();
        ?>

        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:8px;">Title</th>
                    <th style="text-align:left; padding:8px;">Date</th>
                    <th style="text-align:left; padding:8px;">Action</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($posts): foreach ($posts as $post):

                $title = $post->post_title;
                $content = $post->post_content;
                $image = get_the_post_thumbnail_url($post->ID, 'medium');
                $date = get_the_date('Y-m-d H:i', $post->ID);
            ?>

                <tr>
                    <td style="padding:8px;"><?php echo esc_html($title); ?></td>
                    <td style="padding:8px;"><?php echo esc_html($date); ?></td>
                    <td style="padding:8px;">
                        
                        <button 
                            class="view-content-btn"
                            data-title="<?php echo esc_attr($title); ?>"
                            data-content="<?php echo esc_attr($content); ?>"
                            data-image="<?php echo esc_url($image); ?>"
                        >
                            View
                        </button>

                    </td>
                </tr>

            <?php endforeach; else: ?>

                <tr>
                    <td colspan="3" style="text-align:center;">No content found</td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>

        <?php

        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /* ===============================
       RENDER PROFILE
    =============================== */
    public function render_profile() {

        // Only run on profile page
        if (!is_page('profile-page')) {
            return '';
        }

        $username = get_query_var('username');
        $current_user_id = get_current_user_id();

        if (!$current_user_id && !$username) {
            return '
                <div style="
                    max-width:500px;
                    margin:100px auto;
                    text-align:center;
                    padding:40px;
                    background:#fff;
                    border-radius:12px;
                    box-shadow:0 10px 30px rgba(0,0,0,0.1);
                ">
                    <h2 style="margin-bottom:10px;">🔒 Access Restricted</h2>
                    <p style="color:#6b7280; margin-bottom:20px;">
                        Please login or sign up to access your profile
                    </p>

                    <a href="' . esc_url(home_url('/login-page')) . '" 
                    style="display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; margin-right:10px;">
                    Login
                    </a>

                    <a href="' . esc_url(home_url('/registration-page')) . '" 
                    style="display:inline-block; padding:10px 20px; background:#16a34a; color:#fff; border-radius:8px; text-decoration:none;">
                    Sign Up
                    </a>
                </div>
            ';
        }

        if (is_user_logged_in() && current_user_can('manage_options') && !$username) {

            $current_user = wp_get_current_user();

            return '
                <div style="
                    max-width:500px;
                    margin:100px auto;
                    text-align:center;
                    padding:40px;
                    background:#fff;
                    border-radius:12px;
                    box-shadow:0 10px 30px rgba(0,0,0,0.1);
                ">
                    <h2 style="margin-bottom:10px;">👋 Hi ' . esc_html($current_user->display_name) . '</h2>

                    <p style="color:#6b7280; margin-bottom:20px;">
                        You are logged in as admin. Go back to dashboard to manage the system.
                    </p>

                    <a href="' . esc_url(admin_url()) . '" 
                    style="display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none;">
                    Go to Dashboard
                    </a>
                </div>
            ';
        }

        // CASE 1: Own profile (/profile-page)
        if (!$username) {

            if (!$current_user_id) {
                return "<p>Please login</p>";
            }

            $profile_id = get_user_meta($current_user_id, '_profile_id', true);

            if (!$profile_id) {
                return "<p>No profile found</p>";
            }

            $owner_user_id = $current_user_id;
        }

        // CASE 2: Other user's profile (/profile-page/username)
        else {

            $query = new WP_Query([
                'post_type' => 'user_profile',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => 'user_name',
                        'value' => $username,
                        'compare' => '='
                    ]
                ]
            ]);

            if (!$query->have_posts()) {
                return "<p>User not found</p>";
            }

            $query->the_post();
            $profile_id = get_the_ID();
            wp_reset_postdata();

            $owner_user_id = get_post_meta($profile_id, '_wp_user_id', true);
        }

        // OWNER CHECK
        $is_owner = ($current_user_id == $owner_user_id);

        // LoggedIn CHECK
        $is_logged_in = is_user_logged_in();

        $name     = get_the_title($profile_id);
        $username = get_post_meta($profile_id, 'user_name', true);
        $email    = get_post_meta($profile_id, 'email', true);
        $phone    = get_post_meta($profile_id, 'phone', true);

        $profile_image_id  = get_post_meta($profile_id, 'profile_image', true);
        $cover_image_id  = get_post_meta($profile_id, 'cover_image', true);
        
        $default_profile_id = get_option('default_profile_image');
        $default_cover_id   = get_option('default_cover_image');

        $default_profile = $default_profile_id ? wp_get_attachment_url($default_profile_id) : '';
        $default_cover   = $default_cover_id ? wp_get_attachment_url($default_cover_id) : '';

        $profile_image = $profile_image_id ? wp_get_attachment_url($profile_image_id) : $default_profile;
        $cover_image = $cover_image_id ? wp_get_attachment_url($cover_image_id) : $default_cover;

        $notification = new NEXORA_Notification();
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
                    <p><?php echo esc_html($email); ?> | <?php echo esc_html($phone); ?></p>
                </div>

                <!-- TABS -->
                <div class="profile-tabs">
                    <button class="tab-btn active" data-tab="personal">Personal</button>
                    <button class="tab-btn" data-tab="address">Address</button>
                    <button class="tab-btn" data-tab="work">Work</button>
                    <button class="tab-btn" data-tab="docs">Documents</button>
                    <button class="tab-btn" data-tab="connections">Connections</button>
                    <?php if ($is_owner): ?>
                        <button class="tab-btn" data-tab="security">Security</button>

                        <button class="tab-btn" data-tab="content">Content</button>

                        <button class="tab-btn" data-tab="notifications">
                            Notifications

                            <?php if ($unread_count > 0): ?>
                                <span class="noti-badge">
                                    <?php echo $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- CONTENT -->
                <div class="profile-content">
                    <!-- PERSONAL -->
                    <div class="tab-content active" id="personal">
                        <form class="profile-page-form <?php echo !$is_owner ? 'view-only' : ''; ?>">

                            <input type="hidden" name="id" value="<?php echo $profile_id; ?>">

                            <input type="text" value="<?php echo esc_attr(get_post_meta($profile_id,'user_name',true)); ?>" disabled placeholder="User Name">

                            <input type="email" value="<?php echo esc_attr($email); ?>" disabled placeholder="Email">

                            <input type="text" name="first_name" value="<?php echo esc_attr(get_post_meta($profile_id,'first_name',true)); ?>" placeholder="First Name">

                            <input type="text" name="last_name" value="<?php echo esc_attr(get_post_meta($profile_id,'last_name',true)); ?>" placeholder="Last Name">

                            <input type="text" name="phone" value="<?php echo esc_attr($phone); ?>" placeholder="Phone">

                            <select name="gender">
                                <option value="">Select Gender</option>
                                <option value="male" <?php selected(get_post_meta($profile_id,'gender',true),'male'); ?>>Male</option>
                                <option value="female" <?php selected(get_post_meta($profile_id,'gender',true),'female'); ?>>Female</option>
                                <option value="other" <?php selected(get_post_meta($profile_id,'gender',true),'other'); ?>>Other</option>
                            </select>

                            <input type="date" name="birthdate" value="<?php echo esc_attr(get_post_meta($profile_id,'birthdate',true)); ?>">

                            <input type="text" name="linkedin_id" value="<?php echo esc_attr(get_post_meta($profile_id,'linkedin_id',true)); ?>" placeholder="Linked In URL">

                            <textarea name="bio" placeholder="Enter your bio here..."><?php echo esc_textarea(get_post_meta($profile_id,'bio',true)); ?></textarea>

                            <?php if ($is_owner): ?>
                                <button type="submit">Save</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- ADDRESS -->
                    <div class="tab-content" id="address">
                        <form class="profile-page-form <?php echo !$is_owner ? 'view-only' : ''; ?>">

                            <input type="hidden" name="id" value="<?php echo $profile_id; ?>">

                            <div class="address-section">
                                <h4>Permanent Address</h4>
                                
                                <input type="text" name="perm_address" value="<?php echo esc_attr(get_post_meta($profile_id,'perm_address',true)); ?>" placeholder="Address">
                                <input type="text" name="perm_city" value="<?php echo esc_attr(get_post_meta($profile_id,'perm_city',true)); ?>" placeholder="City">
                                <input type="text" name="perm_state" value="<?php echo esc_attr(get_post_meta($profile_id,'perm_state',true)); ?>" placeholder="State">
                                <input type="text" name="perm_pincode" value="<?php echo esc_attr(get_post_meta($profile_id,'perm_pincode',true)); ?>" placeholder="Pincode">
                            </div>

                            <div class="address-section">
                                <h4>Correspondence Address</h4>
                                
                                <input type="text" name="corr_address" value="<?php echo esc_attr(get_post_meta($profile_id,'corr_address',true)); ?>" placeholder="Address">
                                <input type="text" name="corr_city" value="<?php echo esc_attr(get_post_meta($profile_id,'corr_city',true)); ?>" placeholder="City">
                                <input type="text" name="corr_state" value="<?php echo esc_attr(get_post_meta($profile_id,'corr_state',true)); ?>" placeholder="State">
                                <input type="text" name="corr_pincode" value="<?php echo esc_attr(get_post_meta($profile_id,'corr_pincode',true)); ?>" placeholder="Pincode">
                            </div>

                            <?php if ($is_owner): ?>
                                <button type="submit">Save</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- WORK -->
                    <div class="tab-content" id="work">
                        <form class="profile-page-form <?php echo !$is_owner ? 'view-only' : ''; ?>">

                            <input type="hidden" name="id" value="<?php echo $profile_id; ?>">

                            <input type="text" name="company_name" value="<?php echo esc_attr(get_post_meta($profile_id,'company_name',true)); ?>" placeholder="Company Name">

                            <input type="text" name="designation" value="<?php echo esc_attr(get_post_meta($profile_id,'designation',true)); ?>" placeholder="Designation">

                            <input type="email" name="company_email" value="<?php echo esc_attr(get_post_meta($profile_id,'company_email',true)); ?>" placeholder="Company Email">

                            <input type="text" name="company_phone" value="<?php echo esc_attr(get_post_meta($profile_id,'company_phone',true)); ?>" placeholder="Company Phone">

                            <input type="text" name="company_address" value="<?php echo esc_attr(get_post_meta($profile_id,'company_address',true)); ?>" placeholder="Company Address">

                            <?php if ($is_owner): ?>
                                <button type="submit">Save</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- DOCS -->
                    <div class="tab-content" id="docs">
                        <form class="profile-page-form <?php echo !$is_owner ? 'view-only' : ''; ?>" enctype="multipart/form-data">

                            <input type="hidden" name="id" value="<?php echo $profile_id; ?>">

                            <?php
                            $fields = [
                                'profile_image'   => 'Profile Image',
                                'cover_image'     => 'Cover Image',
                                'aadhaar_card'    => 'Aadhar Card',
                                'driving_license' => 'Driving License',
                                'company_id_card' => 'Company ID Card'
                            ];

                            foreach ($fields as $key => $label) {

                                $image_id  = get_post_meta($profile_id, $key, true);
                                $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
                            ?>

                                <div class="profile-upload-box">

                                    <label><?php echo esc_html($label); ?></label>

                                    <img 
                                        src="<?php echo esc_url($image_url); ?>" 
                                        class="profile-preview"
                                        style="display:<?php echo $image_url ? 'block' : 'none'; ?>; max-width:120px;"
                                    >

                                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($image_id); ?>">

                                    <?php if ($is_owner): ?>
                                        <button type="button" class="upload-btn">Upload</button>
                                        <button type="button" class="remove-btn" style="<?php echo $image_url ? '' : 'display:none;'; ?>">Remove</button>
                                    <?php endif; ?>
                                </div>

                            <?php } ?>

                            <?php if ($is_owner): ?>
                                <button type="submit">Upload</button>
                            <?php endif; ?>

                        </form>
                    </div>

                    <!-- Security -->
                    <div class="tab-content" id="security">

                        <?php if ($is_owner): ?>

                        <form id="change-password-form" class="profile-page-form">

                            <div class="password-field">
                                <input type="password" name="current_password" placeholder="Current Password" required>
                                <span class="toggle-pass">
                                    <svg viewBox="0 0 24 24" width="18" height="18">
                                        <path d="M12 5C6 5 2 12 2 12s4 7 10 7 10-7 10-7-4-7-10-7z"
                                            fill="none" stroke="black" stroke-width="2"/>
                                        <circle cx="12" cy="12" r="3"
                                                fill="none" stroke="black" stroke-width="2"/>
                                    </svg>
                                </span>
                            </div>

                            <div class="password-field">
                                <input type="password" name="new_password" placeholder="New Password" required>
                                <span class="toggle-pass">
                                    <svg viewBox="0 0 24 24" width="18" height="18">
                                        <path d="M12 5C6 5 2 12 2 12s4 7 10 7 10-7 10-7-4-7-10-7z"
                                            fill="none" stroke="black" stroke-width="2"/>
                                        <circle cx="12" cy="12" r="3"
                                                fill="none" stroke="black" stroke-width="2"/>
                                    </svg>
                                </span>
                            </div>

                            <div class="password-field">
                                <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                                <span class="toggle-pass">
                                    <svg viewBox="0 0 24 24" width="18" height="18">
                                        <path d="M12 5C6 5 2 12 2 12s4 7 10 7 10-7 10-7-4-7-10-7z"
                                            fill="none" stroke="black" stroke-width="2"/>
                                        <circle cx="12" cy="12" r="3"
                                                fill="none" stroke="black" stroke-width="2"/>
                                    </svg>
                                </span>
                            </div>

                            <button type="submit">Update Password</button>

                        </form>

                        <?php else: ?>
                            <div class="security-error-box">

                                <div class="security-error-icon">
                                    🔒
                                </div>

                                <h3>Access Restricted</h3>

                                <p>You can only change your own password.</p>

                            </div>
                        <?php endif; ?>

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
                                    <h3>Connections</h3>
                                    <span class="conn-sub">Manage your network</span>
                                </div>

                                <div class="conn-right">
                                    <button class="conn-tab" data-type="add">Add New</button>
                                    <button class="conn-tab" data-type="requests">Requests</button>
                                    <button class="conn-tab" data-type="history">History</button>
                                </div>

                            <?php else: ?>
                                <!-- CASE 3: OTHER USER -->
                                <div class="conn-left">
                                    <h3>Connections</h3>
                                    <span class="conn-sub">View their network</span>
                                </div>

                                <div class="conn-right"> 
                                    <button class="conn-tab" data-type="view-all-conn" data-profile="<?php echo $profile_id; ?>">
                                        All Connections
                                    </button>

                                    <button class="conn-tab" data-type="view-common-conn" data-profile="<?php echo $profile_id; ?>">
                                        Mutual
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

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
                                        'image' => wp_get_attachment_url(get_post_meta($other_id, 'profile_image', true)),
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
                                                        <button class="remove-connection-btn" data-id="<?php echo $user['connection_id']; ?>">
                                                            Remove
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <p>No connections found</p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php
                                    $total_connections = count($data);
                                    $mutual_count = 0;

                                    if ($is_logged_in) {

                                        $current_user_id = get_current_user_id();
                                        $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);

                                        $current_connections = $this->get_user_connection_ids($current_profile_id);
                                        $other_connections   = $this->get_user_connection_ids($profile_id);

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
                                            <button class="view-all-btn" data-type="view-all-conn" data-profile="<?php echo $profile_id; ?>">
                                                View All Connections
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- NOTIFICATION -->
                    <div class="tab-content" id="notifications">

                        <?php if ($is_owner): ?>

                            <?php
                            $notification = new NEXORA_Notification();

                            $received = $notification->get_received($current_user_id);
                            $sent     = $notification->get_sent($current_user_id);
                            ?>

                            <div class="notification-wrapper">

                                <!-- 🔵 RECEIVED -->
                                <h3>📥 Received Notifications</h3>

                                <div class="notification-list">

                                    <?php if ($received): foreach ($received as $n): ?>

                                        <div class="notification-item <?php echo $n->is_read ? 'read' : 'unread'; ?>">

                                            <div class="noti-content">

                                                <?php
                                                if ($n->type == 'request') {
                                                    echo "You received a request from <b>{$n->sender_user_name}</b>";
                                                } elseif ($n->type == 'accepted') {
                                                    echo "<b>{$n->sender_user_name}</b> accepted your request";
                                                } elseif ($n->type == 'rejected') {
                                                    echo "<b>{$n->sender_user_name}</b> rejected your request";
                                                } elseif ($n->type == 'removed') {
                                                    echo "<b>{$n->sender_user_name}</b> removed connection";
                                                }
                                                ?>

                                            </div>

                                            <div class="noti-meta">
                                                <button class="notification-view" data-type="view-receive-noti" data-id="<?php echo $n->id; ?>">
                                                    View
                                                </button>
                                                <small><?php echo esc_html($n->created_at); ?></small>
                                            </div>

                                        </div>

                                    <?php endforeach; else: ?>
                                        <p>No received notifications</p>
                                    <?php endif; ?>

                                </div>


                                <!-- 🟢 SENT -->
                                <h3 style="margin-top:30px;">📤 Sent Notifications</h3>

                                <div class="notification-list">

                                    <?php if ($sent): foreach ($sent as $n): ?>

                                        <div class="notification-item read">

                                            <div class="noti-content">

                                                <?php
                                                if ($n->type == 'request') {
                                                    echo "You sent a request to <b>{$n->receiver_user_name}</b>";
                                                } elseif ($n->type == 'accepted') {
                                                    echo "You accepted the request of <b>{$n->receiver_user_name}</b>";
                                                } elseif ($n->type == 'rejected') {
                                                    echo "You rejected the request of <b>{$n->receiver_user_name}</b>";
                                                } elseif ($n->type == 'removed') {
                                                    echo "You removed connection with <b>{$n->receiver_user_name}</b>";
                                                }
                                                ?>

                                            </div>

                                            <div class="noti-meta">
                                                <button class="notification-view" data-type="view-sending-noti" data-id="<?php echo $n->id; ?>"> View </button>
                                                <small><?php echo esc_html($n->created_at); ?></small>
                                            </div>

                                        </div>

                                    <?php endforeach; else: ?>
                                        <p>No sent notifications</p>
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

                            if ($posts):

                                foreach ($posts as $post):

                                    $author_profile_id = get_post_meta($post->ID, 'user_profile_id', true);
                                    if ($author_profile_id == $current_profile_id) continue;

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

                            <?php endforeach; else: ?>
                                <p>No content found</p>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

            <!-- LOG OUT -->
            <?php if ($is_owner): ?>                    
                <div style="text-align:center; margin-top:30px;">
                    <a href="<?php echo wp_logout_url(home_url('/login-page')); ?>" 
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
       Profile Data UPDATE
    =============================== */
    public function profile_update() {

        check_ajax_referer('profile_nonce','nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $id = intval($_POST['id']);

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        if ($profile_id != $id) {
            wp_send_json_error('Unauthorized');
        }

        // TEXT FIELDS
        $fields = [
            'first_name',
            'last_name',
            'phone',
            'gender',
            'birthdate',
            'linkedin_id',
            'bio',

            // Address
            'perm_address',
            'perm_city',
            'perm_state',
            'perm_pincode',

            'corr_address',
            'corr_city',
            'corr_state',
            'corr_pincode',

            // Work
            'company_name',
            'designation',
            'company_email',
            'company_phone',
            'company_address'
        ];

        // Save text fields
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($id, $field, sanitize_text_field($_POST[$field]));
            }
        }

        // Save images
        $image_fields = [
            'profile_image',
            'cover_image',
            'aadhaar_card',
            'driving_license',
            'company_id_card'
        ];

        foreach ($image_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($id, $field, intval($_POST[$field]));
            }
        }

        wp_send_json_success('Saved successfully');
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

        $role = get_role('subscriber'); // or your custom role

        if ($role) {
            $role->add_cap('upload_files'); // 🔥 THIS FIXES IT
        }
    }
}