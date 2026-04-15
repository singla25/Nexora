<?php

class NEXORA_PROFILE_PAGE {

    public function __construct() {

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('profile_dashboard', [$this, 'render_profile']);

        add_action('init', [$this, 'rewrite_rule']);
        add_filter('query_vars', [$this, 'query_vars']);

        add_filter('ajax_query_attachments_args', [$this, 'image_filter']); 
        add_action('init', [$this, 'allow_user_uploads']);

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

        // FIX STARTS HERE
        $profile_id = 0;
        $email = '';
        $phone = '';

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $profile_id = get_user_meta($user_id, '_profile_id', true);

            $email = get_post_meta($profile_id,'email',true);
            $phone = get_post_meta($profile_id,'phone',true);
        }

        $current_user_id = get_current_user_id();
        $username = get_query_var('username');

        $owner_user_id = 0;

        if ($username) {
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

            if ($query->have_posts()) {
                $query->the_post();
                $profile_id = get_the_ID();
                $owner_user_id = get_post_meta($profile_id, '_wp_user_id', true);
                wp_reset_postdata();
            }
        } else {
            $owner_user_id = $current_user_id;
        }

        // 🔥 ROLE
        $role_type = $this->get_user_role_type($current_user_id, $owner_user_id);

        wp_localize_script('profile-page-js', 'profilePageData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('profile_nonce'),
            'homeUrl' => home_url(),
            'current_user_id' => get_current_user_id(),
            'roleType' => $role_type,

            // USER INFORMATION BLOCK
            'userData' => [
                'profile_id' => $profile_id,
                'user_name'  => get_post_meta($profile_id,'user_name',true),
                'email'      => $email,
                'phone'      => $phone,

                'first_name' => get_post_meta($profile_id,'first_name',true),
                'last_name'  => get_post_meta($profile_id,'last_name',true),
                'gender'     => get_post_meta($profile_id,'gender',true),
                'birthdate'  => get_post_meta($profile_id,'birthdate',true),
                'linkedin_id'=> get_post_meta($profile_id,'linkedin_id',true),
                'bio'        => get_post_meta($profile_id,'bio',true),

                // ADDRESS
                'perm_address'=> get_post_meta($profile_id,'perm_address',true),
                'perm_city'   => get_post_meta($profile_id,'perm_city',true),
                'perm_state'  => get_post_meta($profile_id,'perm_state',true),
                'perm_pincode'=> get_post_meta($profile_id,'perm_pincode',true),

                'corr_address'=> get_post_meta($profile_id,'corr_address',true),
                'corr_city'   => get_post_meta($profile_id,'corr_city',true),
                'corr_state'  => get_post_meta($profile_id,'corr_state',true),
                'corr_pincode'=> get_post_meta($profile_id,'corr_pincode',true),

                // WORK
                'company_name'   => get_post_meta($profile_id,'company_name',true),
                'designation'    => get_post_meta($profile_id,'designation',true),
                'company_email'  => get_post_meta($profile_id,'company_email',true),
                'company_phone'  => get_post_meta($profile_id,'company_phone',true),
                'company_address'=> get_post_meta($profile_id,'company_address',true),

                // DOCUMENTS (IDs)
                'profile_image_id' => get_post_meta($profile_id,'profile_image',true),
                'profile_image'   => wp_get_attachment_url(get_post_meta($profile_id,'profile_image',true)),
                'cover_image_id' => get_post_meta($profile_id,'cover_image',true),
                'cover_image'     => wp_get_attachment_url(get_post_meta($profile_id,'cover_image',true)),
                'aadhaar_card_id' => get_post_meta($profile_id,'aadhaar_card',true),
                'aadhaar_card'    => wp_get_attachment_url(get_post_meta($profile_id,'aadhaar_card',true)),
                'driving_license_id' => get_post_meta($profile_id,'driving_license',true),
                'driving_license' => wp_get_attachment_url(get_post_meta($profile_id,'driving_license',true)),
                'company_id_card_id' => get_post_meta($profile_id,'company_id_card',true),
                'company_id_card' => wp_get_attachment_url(get_post_meta($profile_id,'company_id_card',true)),
            ]
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
       UPDATE USER INFORMATION
    =============================== */
    private function get_profile_id() {

        if (!is_user_logged_in()) {
            wp_send_json_error('User not logged in');
        }

        $user_id = get_current_user_id();
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        if (!$profile_id) {
            wp_send_json_error('Profile not found');
        }

        return $profile_id;
    }

    private function verify_owner() {

        if (!is_user_logged_in()) {
            wp_send_json_error('Unauthorized');
        }
    }

    // PERSONAL INFO
    public function update_personal_info() {

        check_ajax_referer('profile_nonce','nonce');

        $this->verify_owner();

        $id = $this->get_profile_id();

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

        check_ajax_referer('profile_nonce','nonce');

        $this->verify_owner();

        $id = $this->get_profile_id();

        $fields = ['perm_address','perm_city','perm_state','perm_pincode','corr_address','corr_city','corr_state','corr_pincode'];

        foreach ($fields as $field) {
            update_post_meta($id, $field, sanitize_text_field($_POST[$field]));
        }

        wp_send_json_success('Address Info Updated');
    }

    // WORK INFO
    public function update_work_info() {

        check_ajax_referer('profile_nonce','nonce');

        $this->verify_owner();

        $id = $this->get_profile_id();

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

        check_ajax_referer('profile_nonce','nonce');

        $this->verify_owner();

        $id = $this->get_profile_id();

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
                'image' => $this->get_profile_image($user->ID)
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
            'sender_user_id'      => $sender_user_id,
            'sender_profile_id'   => $sender_profile_id,
            'sender_user_name'    => $sender_user_name,

            'receiver_user_id'    => $receiver_user_id,
            'receiver_profile_id' => $receiver_profile_id,
            'receiver_user_name'  => $receiver_user_name,

            'type' => 'request',
            'actor_user_id' => $sender_user_id,
            'reference_id' => $post_id,

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
                'image' => $this->get_profile_image($sender)
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

        // Fetch connection data
        $sender_user_id      = get_post_meta($connection_id, 'sender_user_id', true);
        $sender_profile_id   = get_post_meta($connection_id, 'sender_profile_id', true);
        $sender_user_name    = get_post_meta($connection_id, 'sender_user_name', true);

        $receiver_user_id    = get_post_meta($connection_id, 'receiver_user_id', true);
        $receiver_profile_id = get_post_meta($connection_id, 'receiver_profile_id', true);
        $receiver_user_name  = get_post_meta($connection_id, 'receiver_user_name', true);

        $is_sender_actor = ($current_user_id == $sender_user_id);

        // MESSAGE BASED ON STATUS
        if ($status === 'accepted') {
            $message = "{$receiver_user_name} accepted {$sender_user_name} connection request";
        } elseif ($status === 'rejected') {
            $message = "{$receiver_user_name} rejected {$sender_user_name} connection request";
        } elseif ($status === 'removed') {
            if ($is_sender_actor) {
                $message = "{$sender_user_name} removed connection with {$receiver_user_name}";
            } else {
                $message = "{$receiver_user_name} removed connection with {$sender_user_name}";
            }
        } else {
            $message = "Connection status updated";
        }

        if ($is_sender_actor) {
            $actor_user_id = $sender_user_id;
        } else {
            $actor_user_id = $receiver_user_id;
        }

        $data = [
            'sender_user_id'      => $sender_user_id,
            'sender_profile_id'   => $sender_profile_id,
            'sender_user_name'    => $sender_user_name,

            'receiver_user_id'    => $receiver_user_id,
            'receiver_profile_id' => $receiver_profile_id,
            'receiver_user_name'  => $receiver_user_name,

            'type' => $status,
            'actor_user_id' => $actor_user_id,
            'reference_id' => $connection_id,

            'message' => $message
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

                    $sender_id = get_post_meta($conn->ID, 'sender_profile_id', true);
                    $status = get_post_meta($conn->ID, 'status', true);

                    $username = get_post_meta($sender_id,'user_name',true);
                    $name = get_post_meta($sender_id,'first_name',true) . ' ' . get_post_meta($sender_id,'last_name',true);
                    $image = $this->get_profile_image($sender_id);

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

                    $receiver_id = get_post_meta($conn->ID, 'receiver_profile_id', true);
                    $status = get_post_meta($conn->ID, 'status', true);

                    $username = get_post_meta($receiver_id,'user_name',true);
                    $name = get_post_meta($receiver_id,'first_name',true) . ' ' . get_post_meta($receiver_id,'last_name',true);
                    $image = $this->get_profile_image($receiver_id);

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
                    'image' => $this->get_profile_image($other_id),
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
                'image' => $this->get_profile_image($id),
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

        check_ajax_referer('profile_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $id = intval($_POST['id']);
        $user_id = get_current_user_id();

        // echo $id;
        // echo "<br>";
        // echo $user_id;
        // echo "<br>";

        global $wpdb;
        $table = $wpdb->prefix . 'nexora_notifications';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id)
        );

        // echo $row->actor_user_id;
        // echo "<br>";
        // echo $row->receiver_user_id;

        
        if (
            !$row ||
            ($row->sender_user_id != $user_id && $row->receiver_user_id != $user_id)
        ) {
            wp_send_json_error('Unauthorized');
        }

        $notification = new NEXORA_Notification();
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

        if (is_user_logged_in() && current_user_can('manage_options')) {

            $current_user = wp_get_current_user();

            return '
                <div style="
                    max-width:520px;
                    margin:120px auto;
                    text-align:center;
                    padding:50px 40px;
                    background:#ffffff;
                    border-radius:16px;
                    box-shadow:0 20px 50px rgba(0,0,0,0.08);
                    font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;
                ">
                    
                    <!-- ICON -->
                    <div style="font-size:40px; margin-bottom:10px;">⚙️</div>

                    <!-- TITLE -->
                    <h2 style="
                        margin-bottom:8px;
                        font-size:22px;
                        font-weight:600;
                        color:#111827;
                    ">
                        Welcome back, ' . esc_html($current_user->display_name) . ' 👋
                    </h2>

                    <!-- SUB TEXT (DULL) -->
                    <p style="
                        color:#9ca3af;
                        font-size:14px;
                        margin-bottom:6px;
                    ">
                        You are currently in admin mode
                    </p>

                    <!-- MAIN MESSAGE -->
                    <p style="
                        color:#4b5563;
                        font-size:15px;
                        margin-bottom:25px;
                    ">
                        Manage users, content and system settings from your dashboard.
                    </p>

                    <!-- BUTTON -->
                    <a href="' . esc_url(admin_url() . '?admin_access=true') . '"
                    style="
                        display:inline-block;
                        padding:12px 24px;
                        background:linear-gradient(135deg,#2563eb,#4f46e5);
                        color:#fff;
                        border-radius:10px;
                        text-decoration:none;
                        font-size:14px;
                        font-weight:500;
                        box-shadow:0 8px 20px rgba(37,99,235,0.3);
                        transition:all 0.2s ease;
                    ">
                        Go to Dashboard →
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
                return '
                    <div style="
                        max-width:520px;
                        margin:120px auto;
                        text-align:center;
                        padding:50px 40px;
                        background:#ffffff;
                        border-radius:16px;
                        box-shadow:0 20px 50px rgba(0,0,0,0.08);
                        font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;
                    ">
                        
                        <!-- ICON -->
                        <div style="font-size:42px; margin-bottom:12px;">🔍</div>

                        <!-- TITLE -->
                        <h2 style="
                            margin-bottom:8px;
                            font-size:22px;
                            font-weight:600;
                            color:#111827;
                        ">
                            User not found
                        </h2>

                        <!-- SUB TEXT -->
                        <p style="
                            color:#9ca3af;
                            font-size:14px;
                            margin-bottom:6px;
                        ">
                            We couldn’t find the profile you’re looking for
                        </p>

                        <!-- MAIN MESSAGE -->
                        <p style="
                            color:#4b5563;
                            font-size:15px;
                            margin-bottom:25px;
                        ">
                            The username might be incorrect, or the user may have removed their profile.
                        </p>

                        <!-- BUTTON -->
                        <a href="' . esc_url(home_url()) . '"
                        style="
                            display:inline-block;
                            padding:12px 24px;
                            background:linear-gradient(135deg,#2563eb,#4f46e5);
                            color:#fff;
                            border-radius:10px;
                            text-decoration:none;
                            font-size:14px;
                            font-weight:500;
                            box-shadow:0 8px 20px rgba(37,99,235,0.3);
                            transition:all 0.2s ease;
                        ">
                            Go to Home →
                        </a>

                    </div>
                ';
            }

            $query->the_post();
            $profile_id = get_the_ID();
            wp_reset_postdata();

            $owner_user_id = get_post_meta($profile_id, '_wp_user_id', true);
        }

        // Role of User
        $role_type = $this->get_user_role_type($current_user_id, $owner_user_id);

        $is_owner = ($role_type === 'owner');
        $is_logged_in = ($role_type !== 'guest');

        $name     = get_the_title($profile_id);
        $username = get_post_meta($profile_id, 'user_name', true);
        $email    = get_post_meta($profile_id, 'email', true);
        $phone    = get_post_meta($profile_id, 'phone', true);

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
                    <button class="tab-btn active" data-tab="user-info">User Information</button>
                    <button class="tab-btn" data-tab="connections">Connections</button>
                    <?php if ($is_owner): ?>
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

                                    <div class="info-item">
                                        <span class="info-label">Email</span>
                                        <span class="info-value"><?php echo esc_html($email); ?></span>
                                    </div>

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

                                    <div class="info-item">
                                        <span class="info-label">Birthdate</span>
                                        <span class="info-value"><?php echo esc_html(get_post_meta($profile_id,'birthdate',true)); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Phone</span>
                                        <span class="info-value"><?php echo esc_html($phone); ?></span>
                                    </div>
                                    
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
                                            <span class="doc-title"><?php echo $label; ?></span>

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
                                    <button class="conn-tab" data-type="view-all-conn" data-profile="<?php echo $profile_id; ?>">
                                        All Connections
                                    </button>

                                    <button class="conn-tab" data-type="view-common-conn" data-profile="<?php echo $profile_id; ?>">
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
                                        'image' => $this->get_profile_image($other_id),
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
                                                    <img src="<?php echo $user['image']; ?>" alt="">
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

                        <!-- CHAT -->
                        <!-- <div id="connection-chat" style="display:none;">
                            <?php // echo do_shortcode('[better_messages]'); ?>
                        </div> -->
                    </div>

                    <!-- NOTIFICATION -->
                    <div class="tab-content" id="notifications">

                        <?php if ($is_owner): ?>

                        <?php
                        $notification = new NEXORA_Notification();

                        $all_notifications = $notification->get_notifications($current_user_id);

                        $received = [];
                        $sent = [];

                        foreach ($all_notifications as $n) {
                            if ($n->actor_user_id == $current_user_id) {
                                $sent[] = $n;
                            } else {
                                $received[] = $n;
                            }
                        }
                        ?>

                        <div class="notification-wrapper">

                            <!-- 🔵 RECEIVED -->
                            <h3>📥 Received Notifications</h3>

                            <div class="notification-list">

                                <?php if ($received): foreach ($received as $n): ?>

                                    <?php
                                    $is_actor = ($n->actor_user_id == $current_user_id);

                                    $other_user_id = ($n->sender_user_id == $current_user_id)
                                        ? $n->receiver_user_id
                                        : $n->sender_user_id;

                                    $other_user_name = ($n->sender_user_id == $current_user_id)
                                        ? $n->receiver_user_name
                                        : $n->sender_user_name;
                                    ?>

                                    <div class="notification-item <?php echo $n->is_read ? 'read' : 'unread'; ?>">

                                        <div class="noti-content">
                                            <?php
                                            if ($n->type == 'request') {
                                                echo "<b>{$other_user_name}</b> sent you a request";
                                            }

                                            elseif ($n->type == 'accepted') {
                                                echo "<b>{$other_user_name}</b> accepted your request";
                                            }

                                            elseif ($n->type == 'rejected') {
                                                echo "<b>{$other_user_name}</b> rejected your request";
                                            }

                                            elseif ($n->type == 'removed') {
                                                echo "<b>{$other_user_name}</b> removed connection with you";
                                            }
                                            ?>
                                        </div>

                                        <div class="noti-meta">
                                            <button class="notification-view" data-id="<?php echo $n->id; ?>" data-type="received">
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

                                    <?php
                                    $is_actor = ($n->actor_user_id == $current_user_id);

                                    $other_user_name = ($n->sender_user_id == $current_user_id)
                                        ? $n->receiver_user_name
                                        : $n->sender_user_name;
                                    ?>

                                    <div class="notification-item read">

                                        <div class="noti-content">
                                            <?php
                                            if ($n->type == 'request') {
                                                echo "You sent a request to <b>{$other_user_name}</b>";
                                            }

                                            elseif ($n->type == 'accepted') {
                                                echo "You accepted <b>{$other_user_name}</b>'s request";
                                            }

                                            elseif ($n->type == 'rejected') {
                                                echo "You rejected <b>{$other_user_name}</b>'s request";
                                            }

                                            elseif ($n->type == 'removed') {
                                                echo "You removed connection with <b>{$other_user_name}</b>";
                                            }
                                            ?>
                                        </div>

                                        <div class="noti-meta">
                                            <button class="notification-view" 
                                                    data-id="<?php echo $n->id; ?>">
                                                View
                                            </button>
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
                    <a class="logout-btn" href="<?php echo wp_logout_url(home_url('/login-page')); ?>" 
                    style="display:inline-block; padding:12px 25px; background:#ef4444; color:#fff; border-radius:10px; text-decoration:none;">
                        Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    private function get_profile_image($profile_id) {

        $image_id = get_post_meta($profile_id, 'profile_image', true);

        // default from options
        $default_id = get_option('default_profile_image');
        $default_url = $default_id ? wp_get_attachment_url($default_id) : '';

        return $image_id 
            ? wp_get_attachment_url($image_id) 
            : $default_url;
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
            $role->add_cap('upload_files'); // THIS FIXES IT
        }
    }
}