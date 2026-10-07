<?php

if (!defined('ABSPATH')) exit;

class NEXORA_PROFILE_AJAX {

    public function __construct() {

        // USER INFO
        add_action('wp_ajax_update_personal_info', [$this, 'update_personal_info']);
        add_action('wp_ajax_update_address_info', [$this, 'update_address_info']);
        add_action('wp_ajax_update_work_info', [$this, 'update_work_info']);
        add_action('wp_ajax_update_documents_info', [$this, 'update_documents_info']);
        add_action('wp_ajax_update_profile_password', [$this, 'update_profile_password']);

        // CONNECTION TAB
        add_action('wp_ajax_get_add_new_users', [$this, 'get_add_new_users']);
        add_action('wp_ajax_send_connection_request', [$this, 'send_connection_request']);
        add_action('wp_ajax_get_requests', [$this, 'get_requests']);
        add_action('wp_ajax_update_connection_status', [$this, 'update_connection_status']);
        add_action('wp_ajax_get_history', [$this, 'get_history']);
        add_action('wp_ajax_view_all_connection', [$this, 'view_all_connection']);
        add_action('wp_ajax_view_mutual_connection', [$this, 'view_mutual_connection']);

        // NOTIFICATION
        add_action('wp_ajax_mark_notification_read', [$this, 'mark_notification_read']);

        // USER CONTENT
        add_action('wp_ajax_save_user_content', [$this, 'save_user_content']);
        add_action('wp_ajax_get_user_content_history', [$this, 'get_user_content_history']);
    }


    /* ===============================
       UPDATE USER INFORMATION
    =============================== */
    private function validate_request($require_owner = true) {

        // 1. Nonce
        check_ajax_referer('profile_nonce', 'nonce');

        // 2. Login check
        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized access');
        }

        $user_id = get_current_user_id();

        // 3. Profile check
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        if (!$profile_id) {
            wp_send_json_error('Profile not found');
        }

        // 4. Capability check (basic)
        if (!current_user_can('read')) {
            wp_send_json_error('Permission denied');
        }

        return [
            'user_id' => $user_id,
            'profile_id' => $profile_id
        ];
    }

    // PERSONAL INFO
    public function update_personal_info() {

        $auth = $this->validate_request();
        $id   = $auth['profile_id'];

        $fields = ['first_name','last_name','phone','gender','birthdate','linkedin_id','bio'];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($id, $field, sanitize_text_field($_POST[$field]));
            }
        }

        wp_send_json_success('Personal Info Updated');
    }

    // ADDRESS INFO
    public function update_address_info() {

        $auth = $this->validate_request();
        $id   = $auth['profile_id'];

        $fields = ['perm_address','perm_city','perm_state','perm_pincode','corr_address','corr_city','corr_state','corr_pincode'];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($id, $field, sanitize_text_field($_POST[$field]));
            }   
        }

        wp_send_json_success('Address Info Updated');
    }

    // WORK INFO
    public function update_work_info() {

        $auth = $this->validate_request();
        $id   = $auth['profile_id'];

        $fields = ['company_name','designation','company_email','company_phone','company_address'];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($id, $field, sanitize_text_field($_POST[$field]));
            }
        }

        wp_send_json_success('Work Info Updated');
    }

    // DOCUMENTS 
    public function update_documents_info() {

        $auth = $this->validate_request();
        $id   = $auth['profile_id'];

        $fields = ['profile_image','cover_image','aadhaar_card','driving_license','company_id_card'];

        foreach ($fields as $field) {

            if (!isset($_POST[$field])) continue;

            $value = $_POST[$field];

            // REMOVE CASE (IMPORTANT)
            if ($value === '') {
                delete_post_meta($id, $field);
            }

            // UPDATE CASE
            else {
                update_post_meta($id, $field, intval($value));
            }
        }

        wp_send_json_success('Documents updated');
    }

    // CHANGE PASSWORD
    public function update_profile_password() {

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
       CONNECTION TAB
    =============================== */
    // GET NEW USER
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
                'image' => NEXORA_PROFILE_HELPER::get_profile_image($user->ID)
            ];
        }

        wp_send_json_success($data);
    }

    // SEND CONNECTION REQUEST
    public function send_connection_request() {

        check_ajax_referer('profile_nonce', 'nonce');

        $sender_user_id = get_current_user_id();
        $sender_profile_id = get_user_meta($sender_user_id, '_profile_id', true);
        $sender_user_name = get_post_meta($sender_profile_id, 'user_name', true);

        $receiver_profile_id = intval($_POST['receiver_profile_id']);
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

        $data = [
            'actor_user_id'     => $sender_user_id,
            'actor_user_name'   => $sender_user_name,

            'receiver_user_id'    => $receiver_user_id,
            'receiver_user_name'  => $receiver_user_name,

            'type' => 'request',
            'connection_id' => $post_id,

            'message' => "{$sender_user_name} sent a connection request to {$receiver_user_name}"
        ];
        
        $notification = new NEXORA_Notification();
        $notifications = $notification->insert($data);

        wp_send_json_success('Request sent');
    }

    // GET REQUESTS
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
                'image' => NEXORA_PROFILE_HELPER::get_profile_image($sender)
            ];
        }

        wp_send_json_success($data);
    }

    // REQUEST ACCEPTED / REJECT / REMOVED
    public function update_connection_status() {

        check_ajax_referer('profile_nonce', 'nonce');

        $current_user_id = get_current_user_id();

        $connection_id = intval($_POST['connection_id']);
        $status = sanitize_text_field($_POST['status']);

        update_post_meta($connection_id, 'status', $status);

        if ($status === 'removed') {

            $chat_db = new NEXORA_CHAT_DB();
            $chat_db->inactive_threads_by_connection($connection_id);
        }

        // Fetch connection data (here sender and reciever are from user_connection cpt)
        $sender_user_id      = get_post_meta($connection_id, 'sender_user_id', true);
        $sender_user_name    = get_post_meta($connection_id, 'sender_user_name', true);

        $receiver_user_id    = get_post_meta($connection_id, 'receiver_user_id', true);
        $receiver_user_name  = get_post_meta($connection_id, 'receiver_user_name', true);

        if ($current_user_id == $sender_user_id) {

            $actor_user_id = $sender_user_id;
            $actor_user_name = $sender_user_name;

            $receiver_user_id = $receiver_user_id;
            $receiver_user_name = $receiver_user_name;

        } else {

            $actor_user_id = $receiver_user_id;
            $actor_user_name = $receiver_user_name;

            $receiver_user_id = $sender_user_id;
            $receiver_user_name = $sender_user_name;
        }
        
        if ($status === 'accepted') {
            $message = "{$actor_user_name} accepted {$receiver_user_name} connection request";
        } elseif ($status === 'rejected') {
            $message = "{$actor_user_name} rejected {$receiver_user_name} connection request";
        } elseif ($status === 'removed') {
            if ($sender_user_id === $current_user_id) {
                $message = "{$receiver_user_name} removed connection with {$actor_user_name}";
            } else {
                $message = "{$actor_user_name} removed connection with {$receiver_user_name}";
            }
        } else {
            $message = "Connection status updated";
        }

        $data = [
            'actor_user_id'     => $actor_user_id,
            'actor_user_name'   => $actor_user_name,

            'receiver_user_id'    => $receiver_user_id,
            'receiver_user_name'  => $receiver_user_name,

            'type' => $status,
            'connection_id' => $connection_id,

            'message' => $message,
        ];
        
        $notification = new NEXORA_Notification();
        $notifications = $notification->insert($data);

        wp_send_json_success();
    }

    // HISTORY
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

        <div class="history-wrapper">

            <!-- ===============================
                RECEIVED
            =============================== -->
            <div class="history-section">
                <h3>📥 Received Requests</h3>

                <?php if ($received): foreach ($received as $conn):

                    $status = get_post_meta($conn->ID, 'status', true);
                    $sender_id = get_post_meta($conn->ID, 'sender_profile_id', true);
                    
                    $username = get_post_meta($sender_id,'user_name',true);
                    $name     = get_post_meta($sender_id,'first_name',true) . ' ' . get_post_meta($sender_id,'last_name',true);
                    $image    = NEXORA_PROFILE_HELPER::get_profile_image($sender_id);

                    $date = get_the_date('d M Y', $conn->ID);
                    $time = get_the_time('h:i A', $conn->ID);

                    $link = site_url('/profile-page/' . $username);
                ?>

                <div class="history-card">

                    <img src="<?php echo esc_url($image); ?>" class="history-avatar">

                    <a href="<?php echo esc_url($link); ?>" target="_blank" class="history-username">
                        <?php echo esc_html($username); ?>
                    </a>

                    <div class="history-meta">
                        <div class="history-name">
                            <?php echo esc_html($name); ?>
                        </div>

                        <div class="history-time">
                            <?php echo esc_html($date . ' • ' . $time); ?>
                        </div>
                    </div>

                    <span class="history-status <?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucfirst($status)); ?>
                    </span>

                </div>

                <?php endforeach; else: ?>
                    <p class="history-empty">No received requests</p>
                <?php endif; ?>

            </div>

            <!-- ===============================
                SENT
            =============================== -->
            <div class="history-section">
                <h3>📤 Sent Requests</h3>

                <?php if ($sent): foreach ($sent as $conn):

                    $status = get_post_meta($conn->ID, 'status', true);
                    $receiver_id = get_post_meta($conn->ID, 'receiver_profile_id', true);
                   
                    $username = get_post_meta($receiver_id,'user_name',true);
                    $name     = get_post_meta($receiver_id,'first_name',true) . ' ' . get_post_meta($receiver_id,'last_name',true);
                    $image    = NEXORA_PROFILE_HELPER::get_profile_image($receiver_id);

                    $date = get_the_date('d M Y', $conn->ID);
                    $time = get_the_time('h:i A', $conn->ID);

                    $link = site_url('/profile-page/' . $username);
                ?>

                <div class="history-card">

                    <img src="<?php echo esc_url($image); ?>" class="history-avatar">

                    <a href="<?php echo esc_url($link); ?>" target="_blank" class="history-username">
                        <?php echo esc_html($username); ?>
                    </a>

                    <div class="history-meta">
                        <div class="history-name">
                            <?php echo esc_html($name); ?>
                        </div>

                        <div class="history-time">
                            <?php echo esc_html($date . ' • ' . $time); ?>
                        </div>
                    </div>

                    <span class="history-status <?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucfirst($status)); ?>
                    </span>

                </div>

                <?php endforeach; else: ?>
                    <p class="history-empty">No sent requests</p>
                <?php endif; ?>
            </div>
        </div>

        <?php

        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    // VIEW ALL CONNECTIONS
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
                    'image' => NEXORA_PROFILE_HELPER::get_profile_image($other_id),
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

    // VIEW MUTUAL CONNECTIONS
    public function view_mutual_connection() {

        check_ajax_referer('profile_nonce', 'nonce');

        $other_profile_id = intval($_POST['profile_id']);

        $current_user_id = get_current_user_id();
        $current_profile_id = get_user_meta($current_user_id, '_profile_id', true);

        // 1. Get connections of both
        $current_connections = NEXORA_PROFILE_HELPER::get_user_connection_ids($current_profile_id);
        $other_connections   = NEXORA_PROFILE_HELPER::get_user_connection_ids($other_profile_id);

        // 2. Find mutual
        $mutual_ids = array_intersect($current_connections, $other_connections);

        $data = [];

        foreach ($mutual_ids as $id) {

            $data[] = [
                'profile_id' => $id,
                'username' => get_post_meta($id, 'user_name', true),
                'name' => get_post_meta($id, 'first_name', true) . ' ' . get_post_meta($id, 'last_name', true),
                'image' => NEXORA_PROFILE_HELPER::get_profile_image($id),
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
                        <img src="<?php echo $user['image']; ?>">
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
       NOTIFICATION
    =============================== */
    public function mark_notification_read() {

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $id = intval($_POST['id']);
        $user_id = get_current_user_id();

        $notification = new NEXORA_Notification();

        $row = $notification->get_row($id);

        if (!$row || $row->receiver_user_id != $user_id) {
            wp_send_json_error('Unauthorized');
        }

        $notification->mark_as_read($id);

        wp_send_json_success([
            'message' => $row->message
        ]);
    }

    /* ===============================
       USER CONTENT
    =============================== */
    // ADD NEW CONTENT
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

    //  HISTORY
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
                    <th style="padding:8px;">Title</th>
                    <th style="padding:8px;">Date</th>
                    <th style="padding:8px;">Action</th>
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
                            data-date="<?php echo esc_attr($date); ?>"
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
}