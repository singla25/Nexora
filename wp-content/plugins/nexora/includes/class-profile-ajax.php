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
        $profile_id = (int) get_user_meta($user_id, '_profile_id', true);

        if (!$profile_id || get_post_type($profile_id) !== 'user_profile') {
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

    private function post_value($key) {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    /**
     * An attachment may only be linked to a profile by the user who uploaded it.
     */
    private function user_owns_attachment($attachment_id, $user_id) {

        if (get_post_type($attachment_id) !== 'attachment') {
            return false;
        }

        return (int) get_post_field('post_author', $attachment_id) === (int) $user_id
            || current_user_can('manage_options');
    }

    /**
     * Name shown for a profile (first + last).
     */
    private function profile_full_name($profile_id) {
        return trim(get_post_meta($profile_id, 'first_name', true) . ' ' . get_post_meta($profile_id, 'last_name', true));
    }

    // PERSONAL INFO
    public function update_personal_info() {

        $auth = $this->validate_request();
        $id   = $auth['profile_id'];

        $fields = ['first_name','last_name','phone','gender','birthdate','linkedin_id','bio'];

        foreach ($fields as $field) {
            if (!isset($_POST[$field])) continue;

            $value = ($field === 'bio')
                ? sanitize_textarea_field(wp_unslash($_POST[$field]))
                : $this->post_value($field);

            if ($field === 'gender' && !in_array($value, ['male', 'female', 'other', ''], true)) {
                continue;
            }

            if ($field === 'birthdate' && $value !== '') {
                $dt = DateTime::createFromFormat('Y-m-d', $value);
                if (!$dt || $dt->format('Y-m-d') !== $value || $dt > new DateTime('today')) {
                    wp_send_json_error('Invalid date of birth');
                }
            }

            if ($field === 'linkedin_id') {
                $value = sanitize_text_field($value);
            }

            update_post_meta($id, $field, $value);
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
                update_post_meta($id, $field, $this->post_value($field));
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
            if (!isset($_POST[$field])) continue;

            $value = $this->post_value($field);

            if ($field === 'company_email' && $value !== '') {
                $value = sanitize_email($value);
                if (!is_email($value)) {
                    wp_send_json_error('Invalid company email');
                }
            }

            update_post_meta($id, $field, $value);
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

            $value = trim((string) wp_unslash($_POST[$field]));

            // REMOVE CASE (IMPORTANT)
            if ($value === '') {
                delete_post_meta($id, $field);
                continue;
            }

            $attachment_id = absint($value);

            // Only attachments uploaded by this user can be linked
            if (!$attachment_id || !$this->user_owns_attachment($attachment_id, $auth['user_id'])) {
                wp_send_json_error('Invalid file selected');
            }

            // ID documents are private files; profile / cover images are shown to other members
            $is_private_file = Nexora_Private_Documents::is_private($attachment_id);

            if (in_array($field, Nexora_Private_Documents::PUBLIC_KEYS, true) && $is_private_file) {
                wp_send_json_error('This file is a private document and cannot be used as a public image');
            }

            if (in_array($field, Nexora_Private_Documents::DOC_KEYS, true)
                && !$is_private_file
                && Nexora_Private_Documents::in_public_use($attachment_id)) {
                wp_send_json_error('This file is already used as a public image. Please upload a separate file for your document');
            }

            update_post_meta($id, $field, $attachment_id);
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

        $current_password = wp_unslash($_POST['current_password'] ?? '');
        $new_password     = wp_unslash($_POST['new_password'] ?? '');
        $confirm_password = wp_unslash($_POST['confirm_password'] ?? '');

        $user = get_user_by('id', $user_id);

        if (!$user || !wp_check_password($current_password, $user->user_pass, $user_id)) {
            wp_send_json_error('Current password is incorrect');
        }

        if (strlen($new_password) < 8) {
            wp_send_json_error('Password must be at least 8 characters');
        }

        if ($new_password !== $confirm_password) {
            wp_send_json_error('Passwords do not match');
        }

        if ($current_password === $new_password) {
            wp_send_json_error('New password must be different');
        }

        wp_set_password($new_password, $user_id);

        // wp_set_password() destroys the session; keep this user logged in
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);

        wp_send_json_success('Password updated successfully');
    }

    /* ===============================
       CONNECTION TAB
    =============================== */
    // GET NEW USER
    public function get_add_new_users() {

        $auth       = $this->validate_request();
        $profile_id = $auth['profile_id'];

        // Profiles that already have a pending / accepted connection with me
        $connections = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'sender_profile_id',   'value' => $profile_id],
                ['key' => 'receiver_profile_id', 'value' => $profile_id]
            ]
        ]);

        $blocked_ids = [$profile_id];

        foreach ($connections as $conn_id) {

            $status = get_post_meta($conn_id, 'status', true);

            if (in_array($status, ['pending', 'accepted'], true)) {
                $blocked_ids[] = (int) get_post_meta($conn_id, 'sender_profile_id', true);
                $blocked_ids[] = (int) get_post_meta($conn_id, 'receiver_profile_id', true);
            }
        }

        $users = get_posts([
            'post_type'      => 'user_profile',
            'posts_per_page' => 200,
            'post__not_in'   => array_values(array_unique($blocked_ids))
        ]);

        $data = [];

        foreach ($users as $user) {

            $data[] = [
                'profile_id' => $user->ID,
                'username'   => get_post_meta($user->ID, 'user_name', true),
                'name'       => $this->profile_full_name($user->ID),
                'image'      => NEXORA_PROFILE_HELPER::get_profile_image($user->ID)
            ];
        }

        wp_send_json_success($data);
    }

    // SEND CONNECTION REQUEST
    public function send_connection_request() {

        $auth              = $this->validate_request();
        $sender_user_id    = $auth['user_id'];
        $sender_profile_id = $auth['profile_id'];
        $sender_user_name  = get_post_meta($sender_profile_id, 'user_name', true);

        $receiver_profile_id = absint($_POST['receiver_profile_id'] ?? 0);

        if (!$receiver_profile_id || get_post_type($receiver_profile_id) !== 'user_profile') {
            wp_send_json_error('User not found');
        }

        if ($receiver_profile_id === $sender_profile_id) {
            wp_send_json_error('You cannot connect with yourself');
        }

        // No duplicate pending / accepted connection in either direction
        $existing = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => ['pending', 'accepted'],
                    'compare' => 'IN'
                ],
                [
                    'relation' => 'OR',
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_profile_id',   'value' => $sender_profile_id],
                        ['key' => 'receiver_profile_id', 'value' => $receiver_profile_id]
                    ],
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_profile_id',   'value' => $receiver_profile_id],
                        ['key' => 'receiver_profile_id', 'value' => $sender_profile_id]
                    ]
                ]
            ]
        ]);

        if (!empty($existing)) {
            wp_send_json_error('A connection or request already exists');
        }

        $receiver_user_id   = (int) get_post_meta($receiver_profile_id, '_wp_user_id', true);
        $receiver_user_name = get_post_meta($receiver_profile_id, 'user_name', true);

        $post_id = wp_insert_post([
            'post_type'   => 'user_connections',
            'post_status' => 'publish',
            'post_title'  => $sender_user_name . '->' . $receiver_user_name
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error('Could not send request');
        }

        update_post_meta($post_id, 'sender_user_id', $sender_user_id);
        update_post_meta($post_id, 'sender_profile_id', $sender_profile_id);
        update_post_meta($post_id, 'sender_user_name', $sender_user_name);

        update_post_meta($post_id, 'receiver_user_id', $receiver_user_id);
        update_post_meta($post_id, 'receiver_profile_id', $receiver_profile_id);
        update_post_meta($post_id, 'receiver_user_name', $receiver_user_name);

        update_post_meta($post_id, 'status', 'pending');

        $notification = new NEXORA_Notification();
        $notification->insert([
            'actor_user_id'      => $sender_user_id,
            'actor_user_name'    => $sender_user_name,
            'receiver_user_id'   => $receiver_user_id,
            'receiver_user_name' => $receiver_user_name,
            'type'               => 'request',
            'connection_id'      => $post_id,
            'message'            => "{$sender_user_name} sent a connection request to {$receiver_user_name}"
        ]);

        wp_send_json_success('Request sent');
    }

    // GET REQUESTS
    public function get_requests() {

        $auth       = $this->validate_request();
        $profile_id = $auth['profile_id'];

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
                'name' => $this->profile_full_name($sender),
                'image' => NEXORA_PROFILE_HELPER::get_profile_image($sender)
            ];
        }

        wp_send_json_success($data);
    }

    // REQUEST ACCEPTED / REJECT / REMOVED
    public function update_connection_status() {

        $auth            = $this->validate_request();
        $current_user_id = $auth['user_id'];

        $connection_id = absint($_POST['connection_id'] ?? 0);
        $status        = $this->post_value('status');

        if (!$connection_id || get_post_type($connection_id) !== 'user_connections') {
            wp_send_json_error('Connection not found');
        }

        if (!in_array($status, ['accepted', 'rejected', 'removed'], true)) {
            wp_send_json_error('Invalid status');
        }

        $sender_user_id   = (int) get_post_meta($connection_id, 'sender_user_id', true);
        $receiver_user_id = (int) get_post_meta($connection_id, 'receiver_user_id', true);
        $old_status       = get_post_meta($connection_id, 'status', true);

        // Only the two people on the connection may change it
        if ($current_user_id !== $sender_user_id && $current_user_id !== $receiver_user_id) {
            wp_send_json_error('Unauthorized');
        }

        // Only the receiver may accept / reject, and only a pending request
        if (in_array($status, ['accepted', 'rejected'], true)) {
            if ($current_user_id !== $receiver_user_id || $old_status !== 'pending') {
                wp_send_json_error('Unauthorized');
            }
        }

        // Only an accepted connection can be removed
        if ($status === 'removed' && $old_status !== 'accepted') {
            wp_send_json_error('Connection is not active');
        }

        update_post_meta($connection_id, 'status', $status);

        if ($status === 'removed') {

            $chat_db = new NEXORA_CHAT_DB();
            $chat_db->inactive_threads_by_connection($connection_id);
        }

        // Fetch connection data (here sender and reciever are from user_connection cpt)
        $sender_user_name    = get_post_meta($connection_id, 'sender_user_name', true);
        $receiver_user_name  = get_post_meta($connection_id, 'receiver_user_name', true);

        if ($current_user_id == $sender_user_id) {

            $actor_user_id   = $sender_user_id;
            $actor_user_name = $sender_user_name;

        } else {

            $actor_user_id   = $receiver_user_id;
            $actor_user_name = $receiver_user_name;

            $receiver_user_id   = $sender_user_id;
            $receiver_user_name = $sender_user_name;
        }
        
        if ($status === 'accepted') {
            $message = "{$actor_user_name} accepted {$receiver_user_name} connection request";
        } elseif ($status === 'rejected') {
            $message = "{$actor_user_name} rejected {$receiver_user_name} connection request";
        } elseif ($status === 'removed') {
            $message = "{$actor_user_name} removed connection with {$receiver_user_name}";
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

        $auth       = $this->validate_request();
        $profile_id = $auth['profile_id'];

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
                    $name     = $this->profile_full_name($sender_id);
                    $image    = NEXORA_PROFILE_HELPER::get_profile_image($sender_id);

                    $date = get_the_date('d M Y', $conn->ID);
                    $time = get_the_time('h:i A', $conn->ID);

                    $link = site_url('/profile-page/' . rawurlencode($username));
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
                    $name     = $this->profile_full_name($receiver_id);
                    $image    = NEXORA_PROFILE_HELPER::get_profile_image($receiver_id);

                    $date = get_the_date('d M Y', $conn->ID);
                    $time = get_the_time('h:i A', $conn->ID);

                    $link = site_url('/profile-page/' . rawurlencode($username));
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

        $this->validate_request();

        $profile_id = absint($_POST['profile_id'] ?? 0);

        if (!$profile_id || get_post_type($profile_id) !== 'user_profile') {
            wp_send_json_error('Profile not found');
        }

        $data = [];

        foreach (NEXORA_PROFILE_HELPER::get_user_connection_ids($profile_id) as $other_id) {

            $username = get_post_meta($other_id, 'user_name', true);

            $data[] = [
                'profile_id'   => $other_id,
                'username'     => $username,
                'name'         => $this->profile_full_name($other_id),
                'image'        => NEXORA_PROFILE_HELPER::get_profile_image($other_id),
                'profile_link' => site_url('/profile-page/' . rawurlencode($username))
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

        $auth               = $this->validate_request();
        $other_profile_id   = absint($_POST['profile_id'] ?? 0);
        $current_profile_id = $auth['profile_id'];

        if (!$other_profile_id || get_post_type($other_profile_id) !== 'user_profile') {
            wp_send_json_error('Profile not found');
        }

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
                'name' => $this->profile_full_name($id),
                'image' => NEXORA_PROFILE_HELPER::get_profile_image($id),
                'profile_link' => site_url('/profile-page/' . rawurlencode(get_post_meta($id, 'user_name', true)))
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

    /* ===============================
       NOTIFICATION
    =============================== */
    public function mark_notification_read() {

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $id = absint($_POST['id'] ?? 0);
        $user_id = get_current_user_id();

        $notification = new NEXORA_Notification();

        $row = $notification->get_row($id);

        if (!$row || (int) $row->receiver_user_id !== (int) $user_id) {
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

        $auth       = $this->validate_request();
        $user_id    = $auth['user_id'];
        $profile_id = $auth['profile_id'];

        $title       = $this->post_value('title');
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $image_id    = absint($_POST['image'] ?? 0);

        if ($title === '') {
            wp_send_json_error('Title is required');
        }

        if ($image_id && !$this->user_owns_attachment($image_id, $user_id)) {
            wp_send_json_error('Invalid image selected');
        }

        if ($image_id && Nexora_Private_Documents::is_private($image_id)) {
            wp_send_json_error('Invalid image selected');
        }

        $user_name = get_post_meta($profile_id, 'user_name', true);

        $post_id = wp_insert_post([
            'post_type'    => 'user_content',
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'publish',
            'post_author'  => $user_id
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            wp_send_json_error('Failed to create post');
        }

        if ($image_id) {
            set_post_thumbnail($post_id, $image_id);
        }

        update_post_meta($post_id, 'user_id', $user_id);
        update_post_meta($post_id, 'user_profile_id', $profile_id);
        update_post_meta($post_id, 'user_name', $user_name);

        wp_send_json_success('Post created');
    }

    //  HISTORY
    public function get_user_content_history() {

        $auth       = $this->validate_request();
        $profile_id = $auth['profile_id'];

        // Fetch only current user's content
        $posts = get_posts([
            'post_type' => 'user_content',
            'posts_per_page' => 100,
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