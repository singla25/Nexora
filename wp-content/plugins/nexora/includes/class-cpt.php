<?php

class NEXORA_CPT {

    public function __construct() {

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        add_action('admin_menu', [$this, 'register_main_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);

        // manage_{post_type}_posts_columns
        // manage_{post_type}_posts_custom_column

        add_filter('manage_user_profile_posts_columns', [$this, 'add_name_column']);
        add_action('manage_user_profile_posts_custom_column', [$this, 'manage_name_column'], 10, 2);

        add_filter('manage_user_connections_posts_columns', [$this, 'add_status_column']);
        add_action('manage_user_connections_posts_custom_column', [$this, 'manage_status_column'], 10, 2);

        add_filter('manage_user_content_posts_columns', [$this, 'add_user_name_column']);
        add_action('manage_user_content_posts_custom_column', [$this, 'manage_user_name_column'], 10, 2);
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_media();
        wp_enqueue_script(
            'profile-admin-js',
            NEXORA_URL . 'assets/js/profile-admin.js',
            ['jquery'],
            null,
            true
        );
    }

    public function register_main_menu() {

        add_menu_page(
            'Nexora System',
            'Nexora System',
            'manage_options',
            'nexora-system',
            [$this, 'settings_page'],
            'dashicons-groups',
            5
        );

        add_submenu_page(
            'nexora-system',
            'Notifications',
            'Notifications',
            'manage_options',
            'nexora-notifications',
            [$this, 'notifications_page']
        );

        add_submenu_page(
            'nexora-system',
            'Nexora Chat',
            'Nexora Chat',
            'manage_options',
            'nexora-chat',
            [$this, 'nexora_user_chat']
        );

        add_submenu_page(
            'nexora-system',
            'Settings',
            'Settings',
            'manage_options',
            'nexora-system',
            [$this, 'settings_page']
        );
    }

    public function register_cpt() {

        register_post_type('user_profile', [
            'label' => 'User Profiles',
            'public' => true,
            'show_ui' => true,
            'supports' => ['title', 'thumbnail'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);

        register_post_type('user_connections', [
            'label' => 'User Connections',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);

        register_post_type('user_content', [
            'label' => 'User Content',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
            'show_in_menu' => 'nexora-system',
            'menu_icon' => 'dashicons-groups',
        ]);
    }

    public function add_meta_boxes() {

        add_meta_box('user_personal_details', 'User Personal Details', [$this, 'user_personal_details'], 'user_profile');
        add_meta_box('user_address_details', 'User Address Details', [$this, 'user_address_details'], 'user_profile');
        add_meta_box('user_work_details', 'User Work Details', [$this, 'user_work_details'], 'user_profile');
        add_meta_box('user_document_details', 'User Document Details', [$this, 'user_document_details'], 'user_profile');
        add_meta_box('user_connection_details', 'User Connection Details', [$this, 'user_connection_details'], 'user_profile');
        add_meta_box('user_content_details', 'User Content Details', [$this, 'user_content_details'], 'user_profile');
        add_meta_box('user_chat_details', 'User Chat Details', [$this, 'user_chat_details'], 'user_profile');

        add_meta_box('user_connection_meta_box', 'User Connection Details', [$this, 'user_connection_meta_box'], 'user_connections');
        add_meta_box('user_connection_chat_box', 'User Connection Chat Details', [$this, 'user_connection_chat_box'], 'user_connections');

        add_meta_box('user_content_meta_box', 'User Content Info', [$this, 'render_user_content_meta_box'], 'user_content');
    }

    public function register_settings() {
        register_setting('profile_settings_group', 'default_profile_image');
        register_setting('profile_settings_group', 'default_cover_image');
        register_setting('profile_settings_group', 'default_document_image');
        register_setting('profile_settings_group', 'default_home_cover_image');
        register_setting('profile_settings_group', 'default_feed_experience_image');
        register_setting('profile_settings_group', 'default_real_time_chat_image');
        register_setting('profile_settings_group', 'default_smart_connections_image');
        register_setting('profile_settings_group', 'default_admin_mail');

        register_setting('profile_settings_group', 'recaptcha_site_key');
        register_setting('profile_settings_group', 'recaptcha_secret_key');
        register_setting('profile_settings_group', 'recaptcha_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function($value) {
                return $value ? 1 : 0;
            }
        ]);
    }

    public function notifications_page() {

        $notification = new NEXORA_Notification();
        $notifications = $notification->get_all();

        ?>
        <div class="wrap">
            <h1>🔔 Notifications</h1>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Actor</th>
                        <th>Receiver</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ($notifications): foreach ($notifications as $n): ?>

                    <tr>
                        <td><?php echo esc_html($n->id); ?></td>
                        <td><?php echo esc_html($n->actor_user_name); ?></td>
                        <td><?php echo esc_html($n->receiver_user_name); ?></td>
                        <td><?php echo esc_html($n->type); ?></td>
                        <td><?php echo esc_html($n->message); ?></td>
                        <td>
                            <?php if ($n->is_read): ?>
                                <span style="color: grey; font-weight: 600;">Read</span>
                            <?php else: ?>
                                <span style="color: green; font-weight: 600;">Unread</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($n->created_at); ?></td>
                    </tr>

                <?php endforeach; else: ?>

                    <tr><td colspan="7" style="text-align: center;">No notifications found</td></tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
        <?php
    }

    /* ===============================
       CHAT
    =============================== */
    public function nexora_user_chat() {

        $chat_db = new NEXORA_CHAT_DB();
        $threads = $chat_db->get_all_threads_with_last_message();
        ?>

        <div class="wrap">
            <h1>💬 Nexora Chat (Admin)</h1>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Thread ID</th>
                        <th>Connection ID</th> <!-- ✅ NEW -->
                        <th>Status</th>        <!-- ✅ NEW -->
                        <th>User 1</th>
                        <th>User 2</th>
                        <th>Subject</th>
                        <th>Last Message</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ($threads): foreach ($threads as $thread): 

                    // PARTICIPANTS (NAMES)
                    $user_ids = explode(',', $thread->participants);

                    $user1 = '-';
                    $user2 = '-';

                    if (isset($user_ids[0])) {
                        $u1 = get_userdata($user_ids[0]);
                        $user1 = $u1 ? $u1->display_name : '-';
                    }

                    if (isset($user_ids[1])) {
                        $u2 = get_userdata($user_ids[1]);
                        $user2 = $u2 ? $u2->display_name : '-';
                    }

                    // LAST MESSAGE
                    $last_message = $thread->last_message ? wp_trim_words($thread->last_message, 10) : '-';

                    // Find other user
                    $other_user = null; 
                    
                    foreach ($user_ids as $uid) { 
                        if ($uid != get_current_user_id()) { 
                            $other_user = $uid; 
                            break; 
                        } 
                    }

                    ?>

                    <tr>
                        <td><?php echo esc_html($thread->id); ?></td>
                        <td><?php echo esc_html($thread->connection_id ?: '-'); ?></td>
                        <td>
                            <?php if ($thread->status === 'active'): ?>
                                <span style="color: green; font-weight: 600;">Active</span>
                            <?php else: ?>
                                <span style="color: red; font-weight: 600;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($user1); ?></td>
                        <td><?php echo esc_html($user2); ?></td>
                        <td><?php echo esc_html($thread->subject ?: '-'); ?></td>
                        <td><?php echo esc_html($last_message); ?></td>

                        <td>
                            <!-- <button class="button button-primary nexora-open-chat" data-thread="<?php echo $thread->id; ?>">
                                View Chat
                            </button> -->
                            <!-- <button class="button button-primary nexora-open-chat" 
                                    data-thread="<?php echo esc_attr($thread->id); ?>" 
                                    data-user="<?php echo esc_attr($other_user); ?>" > 
                                View Chat 
                            </button> -->
                            <button 
                                class="button button-primary nexora-open-chat"
                                data-thread="<?php echo esc_attr($thread->id); ?>"
                                data-user="<?php echo esc_attr($other_user); ?>"
                                data-name="<?php echo esc_attr($user1 . ' and ' . $user2); ?>"
                            >
                                View Chat
                            </button>
                        </td>
                    </tr>

                <?php endforeach; else: ?>

                    <tr>
                        <td colspan="5" style="text-align:center;">No chats found</td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <?php
    }

    public function get_user_names($user_ids = []) {
        $names = [];

        foreach ($user_ids as $id) {
            $user = get_userdata($id);
            if ($user) {
                $names[] = $user->display_name;
            }
        }

        return implode(', ', $names);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Profile System Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('profile_settings_group'); ?>
                <?php do_settings_sections('profile_settings_group'); ?>

                <table class="form-table">
                    <tr>
                        <th>Default Profile Image</th>
                        <td>
                            <?php $profile_id = get_option('default_profile_image'); ?>
                            <img src="<?php echo $profile_id ? wp_get_attachment_url($profile_id) : ''; ?>" 
                                style="max-width:100px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_profile_image" value="<?php echo esc_attr($profile_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Cover Image</th>
                        <td>
                            <?php $cover_id = get_option('default_cover_image'); ?>
                            <img src="<?php echo $cover_id ? wp_get_attachment_url($cover_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_cover_image" value="<?php echo esc_attr($cover_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Document Image</th>
                        <td>
                            <?php $document_id = get_option('default_document_image'); ?>
                            <img src="<?php echo $document_id ? wp_get_attachment_url($document_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_document_image" value="<?php echo esc_attr($document_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Home Cover Image</th>
                        <td>
                            <?php $home_cover_id = get_option('default_home_cover_image'); ?>
                            <img src="<?php echo $home_cover_id ? wp_get_attachment_url($home_cover_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_home_cover_image" value="<?php echo esc_attr($home_cover_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Feed Experience Image</th>
                        <td>
                            <?php $feed_id = get_option('default_feed_experience_image'); ?>
                            <img src="<?php echo $feed_id ? wp_get_attachment_url($feed_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_feed_experience_image" value="<?php echo esc_attr($feed_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Real-Time Chat Image</th>
                        <td>
                            <?php $chat_id = get_option('default_real_time_chat_image'); ?>
                            <img src="<?php echo $chat_id ? wp_get_attachment_url($chat_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_real_time_chat_image" value="<?php echo esc_attr($chat_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Default Smart Connections Image</th>
                        <td>
                            <?php $conn_id = get_option('default_smart_connections_image'); ?>
                            <img src="<?php echo $conn_id ? wp_get_attachment_url($conn_id) : ''; ?>" 
                                style="max-width:150px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="default_smart_connections_image" value="<?php echo esc_attr($conn_id); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

                    <tr>
                        <th>Admin Notification Email</th>
                        <td>
                            <?php $admin_email = get_option('default_admin_mail'); ?>

                            <input 
                                type="email" 
                                name="default_admin_mail" 
                                value="<?php echo esc_attr($admin_email); ?>" 
                                class="regular-text"
                                placeholder="Enter admin email"
                            >

                            <p class="description">
                                All registration notifications will be sent to this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Google reCAPTCHA Site Key</th>
                        <td>
                            <?php $site_key = get_option('recaptcha_site_key'); ?>

                            <input 
                                type="text" 
                                name="recaptcha_site_key" 
                                value="<?php echo esc_attr($site_key); ?>" 
                                class="regular-text"
                                placeholder="Enter Site Key"
                            >

                            <p class="description">
                                Used on frontend (forms).
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>Google reCAPTCHA Secret Key</th>
                        <td>
                            <?php $secret_key = get_option('recaptcha_secret_key'); ?>

                            <input 
                                type="password" 
                                name="recaptcha_secret_key" 
                                value="<?php echo esc_attr($secret_key ? '************' : ''); ?>" 
                                class="regular-text"
                                placeholder="Enter Secret Key"
                            >

                            <p class="description">
                                Used for backend verification. Keep it secure.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Enable reCAPTCHA</th>
                        <td>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="recaptcha_enabled" 
                                    value="1" 
                                    <?php checked(get_option('recaptcha_enabled'), 1); ?>
                                >
                                Enable Google reCAPTCHA
                            </label>

                            <p class="description">
                                Enable captcha protection on login, registration and forms.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ===============================
       USER PROFILE METABOXES
    =============================== */
    /* PERSONAL */
    public function user_personal_details($post) {
        ?>

        <input type="text" name="user_name" placeholder="User Name" value="<?php echo esc_attr(get_post_meta($post->ID, 'user_name', true)); ?>" class="widefat"><br><br>
        <input type="text" name="first_name" placeholder="First Name" value="<?php echo esc_attr(get_post_meta($post->ID, 'first_name', true)); ?>" class="widefat"><br><br>
        <input type="text" name="last_name" placeholder="Last Name" value="<?php echo esc_attr(get_post_meta($post->ID, 'last_name', true)); ?>" class="widefat"><br><br>
        <input type="email" name="email" placeholder="Email" value="<?php echo esc_attr(get_post_meta($post->ID, 'email', true)); ?>" class="widefat"><br><br>
        <input type="text" name="phone" placeholder="Phone" value="<?php echo esc_attr(get_post_meta($post->ID, 'phone', true)); ?>" class="widefat"><br><br>
        <input type="text" name="linkedin_id" placeholder="LinkedIn" value="<?php echo esc_attr(get_post_meta($post->ID, 'linkedin_id', true)); ?>" class="widefat"><br><br>

        <label>Gender</label>
        <select name="gender" class="widefat">
            <option value="">Select Gender</option>
            <option value="male" <?php selected(get_post_meta($post->ID, 'gender', true), 'male'); ?>>Male</option>
            <option value="female" <?php selected(get_post_meta($post->ID, 'gender', true), 'female'); ?>>Female</option>
            <option value="other" <?php selected(get_post_meta($post->ID, 'gender', true), 'other'); ?>>Other</option>
        </select><br><br>

        <label>Birthdate</label>
        <input type="date" name="birthdate"
            value="<?php echo esc_attr(get_post_meta($post->ID, 'birthdate', true)); ?>"
            class="widefat"><br><br>

        <textarea name="bio" placeholder="Bio" class="widefat"><?php echo esc_textarea(get_post_meta($post->ID, 'bio', true)); ?></textarea>

        <?php
    }

    /* ADDRESS*/
    public function user_address_details($post) {
        ?>

        <h3>Permanent Address</h3>

        <input type="text" name="perm_address" placeholder="Address" value="<?php echo esc_attr(get_post_meta($post->ID, 'perm_address', true)); ?>" class="widefat"><br><br>
        <input type="text" name="perm_city" placeholder="City" value="<?php echo esc_attr(get_post_meta($post->ID, 'perm_city', true)); ?>" class="widefat"><br><br>
        <input type="text" name="perm_state" placeholder="State" value="<?php echo esc_attr(get_post_meta($post->ID, 'perm_state', true)); ?>" class="widefat"><br><br>
        <input type="text" name="perm_pincode" placeholder="Pincode" value="<?php echo esc_attr(get_post_meta($post->ID, 'perm_pincode', true)); ?>" class="widefat"><br><br>

        <h3>Correspondence Address</h3>

        <input type="text" name="corr_address" placeholder="Address" value="<?php echo esc_attr(get_post_meta($post->ID, 'corr_address', true)); ?>" class="widefat"><br><br>
        <input type="text" name="corr_city" placeholder="City" value="<?php echo esc_attr(get_post_meta($post->ID, 'corr_city', true)); ?>" class="widefat"><br><br>
        <input type="text" name="corr_state" placeholder="State" value="<?php echo esc_attr(get_post_meta($post->ID, 'corr_state', true)); ?>" class="widefat"><br><br>
        <input type="text" name="corr_pincode" placeholder="Pincode" value="<?php echo esc_attr(get_post_meta($post->ID, 'corr_pincode', true)); ?>" class="widefat"><br><br>

        <?php
    }

    /* WORK */
    public function user_work_details($post) {
        ?>

        <input type="text" name="company_name" placeholder="Company Name" value="<?php echo esc_attr(get_post_meta($post->ID, 'company_name', true)); ?>" class="widefat"><br><br>
        <input type="text" name="designation" placeholder="Designation" value="<?php echo esc_attr(get_post_meta($post->ID, 'designation', true)); ?>" class="widefat"><br><br>
        <input type="email" name="company_email" placeholder="Company Email" value="<?php echo esc_attr(get_post_meta($post->ID, 'company_email', true)); ?>" class="widefat"><br><br>
        <input type="text" name="company_phone" placeholder="Company Phone" value="<?php echo esc_attr(get_post_meta($post->ID, 'company_phone', true)); ?>" class="widefat"><br><br>
        <input type="text" name="company_address" placeholder="Company Address" value="<?php echo esc_attr(get_post_meta($post->ID, 'company_address', true)); ?>" class="widefat"><br><br>

        <?php
    }

    /* DOCUMENTS (MEDIA UPLOAD) */
    public function user_document_details($post) {

        $fields = [
            'profile_image'   => 'Profile Image',
            'cover_image'     => 'Cover Image',
            'aadhaar_card'    => 'Aadhar Card',
            'driving_license' => 'Driving License',
            'company_id_card' => 'Company ID Card'
        ];

        foreach ($fields as $key => $label) {

            $image_id = get_post_meta($post->ID, $key, true);
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
            ?>

            <div class="profile-upload-box">
                <label><strong><?php echo $label; ?></strong></label><br>

                <img src="<?php echo esc_url($image_url); ?>"
                    class="profile-preview"
                    style="max-width:150px; display:<?php echo $image_url ? 'block' : 'none'; ?>; margin-bottom:10px;">

                <input type="hidden" name="<?php echo $key; ?>" value="<?php echo esc_attr($image_id); ?>">

                <button type="button" class="button upload-btn">Upload</button>
                <button type="button" class="button remove-btn" style="<?php echo $image_url ? '' : 'display:none;'; ?>">Remove</button>
            </div>
            <hr>
            <?php
        }
    }

    /* USER CONNECTIONS */
    public function user_connection_details($post) {

        $profile_id = $post->ID;

        // ===============================
        // RECEIVED REQUESTS
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
        // SENT REQUESTS
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

        ?>

        <h2>📥 Received Requests</h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Sender Profile ID</th>
                    <th>Sender Username</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($received): foreach ($received as $conn): 

                $sender_id   = get_post_meta($conn->ID, 'sender_profile_id', true);
                $sender_name = get_post_meta($conn->ID, 'sender_user_name', true);
                $status      = get_post_meta($conn->ID, 'status', true);

            ?>

                <tr>
                    <td><?php echo esc_html($sender_id); ?></td>
                    <td><?php echo esc_html($sender_name); ?></td>
                    <td><?php echo esc_html($status); ?></td>
                </tr>

            <?php endforeach; else: ?>

                <tr><td colspan="3">No received requests</td></tr>

            <?php endif; ?>

            </tbody>
        </table>


        <br><br>

        <h2>📤 Sent Requests</h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Receiver Profile ID</th>
                    <th>Receiver Username</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($sent): foreach ($sent as $conn): 

                $receiver_id   = get_post_meta($conn->ID, 'receiver_profile_id', true);
                $receiver_name = get_post_meta($conn->ID, 'receiver_user_name', true);
                $status        = get_post_meta($conn->ID, 'status', true);

            ?>

                <tr>
                    <td><?php echo esc_html($receiver_id); ?></td>
                    <td><?php echo esc_html($receiver_name); ?></td>
                    <td><?php echo esc_html($status); ?></td>
                </tr>

            <?php endforeach; else: ?>

                <tr><td colspan="3">No sent requests</td></tr>

            <?php endif; ?>

            </tbody>
        </table>

        <?php
    }

    /* USER CONTENT DETAIL */
    public function user_content_details($post) {

        $profile_id = $post->ID;

        // Fetch user content
        $args = [
            'post_type'      => 'user_content',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'user_profile_id',
                    'value' => $profile_id,
                    'compare' => '='
                ]
            ]
        ];

        $contents = get_posts($args);
        ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($contents): foreach ($contents as $content): ?>

                <tr>
                    <td><?php echo esc_html($content->post_title); ?></td>

                    <td><?php echo esc_html(get_the_date('Y-m-d H:i:s', $content->ID)); ?></td>

                    <td>
                        <a href="<?php echo admin_url('post.php?post=' . $content->ID . '&action=edit'); ?>" 
                        class="button button-primary">
                        View
                        </a>
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
    }

    /* USER CHAT DETAIL */
    public function user_chat_details($post) {

        $chat_db = new NEXORA_CHAT_DB();

        $user_id = get_post_meta($post->ID, '_wp_user_id', true);

        if (!$user_id) {
            echo "<p>No user linked.</p>";
            return;
        }

        $connections = $this->get_connections_by_user($user_id);

        if (!$connections) {
            echo "<p>No connections found.</p>";
            return;
        }

        echo '<h3>💬 User Chat Overview</h3>';

        echo '<table class="widefat striped" style="font-size:13px;">';
        echo '<thead>
                <tr>
                    <th>User</th>
                    <th>Connection ID</th>
                    <th>Status</th>
                    <th>Connection Time</th>
                    <th>Threads</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($connections as $conn) {

            $conn_id = $conn->ID;

            $sender_id   = get_post_meta($conn_id, 'sender_user_id', true);
            $receiver_id = get_post_meta($conn_id, 'receiver_user_id', true);
            $status      = get_post_meta($conn_id, 'status', true);

            // Other user
            $other_user_id = ($sender_id == $user_id) ? $receiver_id : $sender_id;
            $other_user    = get_userdata($other_user_id);
            $other_name    = $other_user ? $other_user->display_name : '-';

            // Connection time
            $connection_time = get_the_date('d M Y, H:i', $conn_id);

            // Status badge color
            $status_color = match($status) {
                'accepted' => '#16a34a',
                'pending'  => '#f59e0b',
                'removed'  => '#6b7280',
                default    => '#dc2626'
            };

            // Threads
            $threads = $chat_db->get_threads_by_connection($conn_id);

            echo "<tr>";

            echo "<td><strong>{$other_name}</strong></td>";

            echo "<td>#{$conn_id}</td>";

            echo "<td>
                    <span style='color:white; background:{$status_color}; padding:3px 8px; border-radius:4px; font-size:12px;'>
                        {$status}
                    </span>
                </td>";

            echo "<td>{$connection_time}</td>";

            echo "<td>";

            if ($threads) {

                echo "<ul style='margin:0;'>";

                foreach ($threads as $t) {

                    $thread_color = $t->status === 'active' ? '#16a34a' : '#dc2626';
                    $subject = $t->subject ?: 'No Subject';

                    echo "<li style='margin-bottom:5px;'>
                            <strong>{$subject}</strong>
                            <span style='color:{$thread_color}; font-weight:600; margin-left:6px;'>
                                ● {$t->status}
                            </span>
                        </li>";
                }

                echo "</ul>";

            } else {
                echo "<span style='color:#6b7280;'>No conversations</span>";
            }

            echo "</td>";

            echo "</tr>";
        }

        echo '</tbody></table>';
    }

    /* ===============================
       USER CONNECTIONS META BOX
    =============================== */
    /* USER CONNECTIONS DETAIL */
    public function user_connection_meta_box($post) {

        $sender_user_id     = get_post_meta($post->ID, 'sender_user_id', true);
        $sender_profile_id  = get_post_meta($post->ID, 'sender_profile_id', true);
        $sender_user_name   = get_post_meta($post->ID, 'sender_user_name', true);

        $receiver_user_id    = get_post_meta($post->ID, 'receiver_user_id', true);
        $receiver_profile_id = get_post_meta($post->ID, 'receiver_profile_id', true);
        $receiver_user_name  = get_post_meta($post->ID, 'receiver_user_name', true);

        $status = get_post_meta($post->ID, 'status', true);
        ?>

        <table class="form-table">

            <tr>
                <th>Sender User ID</th>
                <td><input type="number" name="sender_user_id" value="<?php echo esc_attr($sender_user_id); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Sender Profile ID</th>
                <td><input type="number" name="sender_profile_id" value="<?php echo esc_attr($sender_profile_id); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Sender User Name</th>
                <td><input type="text" name="sender_user_name" value="<?php echo esc_attr($sender_user_name); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Receiver User ID</th>
                <td><input type="number" name="receiver_user_id" value="<?php echo esc_attr($receiver_user_id); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Receiver Profile ID</th>
                <td><input type="number" name="receiver_profile_id" value="<?php echo esc_attr($receiver_profile_id); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Receiver User Name</th>
                <td><input type="text" name="receiver_user_name" value="<?php echo esc_attr($receiver_user_name); ?>" class="widefat"></td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <select name="status" class="widefat">
                        <option value="pending" <?php selected($status, 'pending'); ?>>Pending</option>
                        <option value="accepted" <?php selected($status, 'accepted'); ?>>Accepted</option>
                        <option value="rejected" <?php selected($status, 'rejected'); ?>>Rejected</option>
                        <option value="removed" <?php selected($status, 'removed'); ?>>Removed</option>
                    </select>
                </td>
            </tr>

        </table>

        <?php
    }

     /* USER CONNECTIONS CHAT DETAIL */
    public function user_connection_chat_box($post) {

        $chat_db = new NEXORA_CHAT_DB();
        $threads = $chat_db->get_threads_by_connection($post->ID);

        echo '<h3>💬 Connection Chat Threads</h3>';

        if (!$threads) {
            echo "<p>No threads found.</p>";
            return;
        }

        echo '<table class="widefat striped" style="font-size:13px;">';
        echo '<thead>
                <tr>
                    <th>Thread ID</th>
                    <th>Users</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead><tbody>';

        foreach ($threads as $thread) {

            $user_ids = explode(',', $thread->participants);

            // Get user names safely
            $user1 = '-';
            $user2 = '-';

            if (!empty($user_ids[0])) {
                $u1 = get_userdata($user_ids[0]);
                $user1 = $u1 ? $u1->display_name : '-';
            }

            if (!empty($user_ids[1])) {
                $u2 = get_userdata($user_ids[1]);
                $user2 = $u2 ? $u2->display_name : '-';
            }

            // ✅ FIXED: DO NOT use get_current_user_id() in admin
            $other_user = $user_ids[1] ?? $user_ids[0] ?? 0;

            // Status color
            $status = $thread->status;
            $color = $status === 'active' ? '#16a34a' : '#dc2626';

            // Subject fallback
            $subject = $thread->subject ?: 'No Subject';

            echo '<tr>';

            echo '<td>#' . esc_html($thread->id) . '</td>';

            echo '<td>' . esc_html($user1 . ' & ' . $user2) . '</td>';

            echo '<td>' . esc_html($subject) . '</td>';

            echo '<td>
                    <span style="
                        color:white;
                        background:' . $color . ';
                        padding:3px 8px;
                        border-radius:4px;
                        font-size:12px;
                    ">
                        ' . esc_html($status) . '
                    </span>
                </td>';

            echo '<td>
                    <button 
                        type="button"
                        class="button button-primary nexora-open-chat"
                        data-thread="' . esc_attr($thread->id) . '"
                        data-user="' . esc_attr($other_user) . '"
                        data-name="' . esc_attr($user1 . ' and ' . $user2) . '"
                    >
                        View Chat
                    </button>
                </td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /* ===============================
       USER CONNECTIONS META BOX
    =============================== */
    public function render_user_content_meta_box($post) {

        $user_id          = get_post_meta($post->ID, 'user_id', true);
        $user_profile_id  = get_post_meta($post->ID, 'user_profile_id', true);
        $user_name        = get_post_meta($post->ID, 'user_name', true);
        ?>

        <table class="form-table">

            <tr>
                <th>User ID</th>
                <td><input type="number" name="user_id" value="<?php echo esc_attr($user_id); ?>" class="regular-text"></td>
            </tr>

            <tr>
                <th>User Profile ID</th>
                <td><input type="number" name="user_profile_id" value="<?php echo esc_attr($user_profile_id); ?>" class="regular-text"></td>
            </tr>

            <tr>
                <th>User Name</th>
                <td><input type="text" name="user_name" value="<?php echo esc_attr($user_name); ?>" class="regular-text"></td>
            </tr>

        </table>

        <?php
    }

    /* ===============================
       SAVE DATA
    =============================== */
    public function save_meta_boxes($post_id) {

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        $post_type = get_post_type($post_id);

        // ===============================
        // USER PROFILE SAVE
        // ===============================
        if ($post_type === 'user_profile') {

            $fields = [
                'user_name','first_name','last_name','email','phone','linkedin_id','bio','gender','birthdate',
                'perm_address','perm_city','perm_state','perm_pincode',
                'corr_address','corr_city','corr_state','corr_pincode',
                'company_name','designation','company_email','company_phone','company_address',
                'profile_image','cover_image','aadhaar_card','driving_license','company_id_card'
            ];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {

                    if (in_array($field, ['profile_image','cover_image','aadhaar_card','driving_license','company_id_card'])) {
                        update_post_meta($post_id, $field, intval($_POST[$field]));
                    } else {
                        update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                    }
                }
            }
        }

        // ===============================
        // USER CONTENT SAVE
        // ===============================
        if ($post_type === 'user_content') {

            $fields = ['user_id', 'user_profile_id', 'user_name'];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
        }

        // ===============================
        // USER CONNECTION SAVE
        // ===============================
        if ($post_type === 'user_connections') {

            $fields = [
                'sender_user_id','sender_profile_id','sender_user_name',
                'receiver_user_id','receiver_profile_id','receiver_user_name','status'
            ];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
        }
    }

    /* ===============================
       ADD and MANAGE COLUMN
    =============================== */
    function add_name_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['user_full_name'] = 'Name';
            }
        }

        return $new_columns;
    }

    function manage_name_column($column, $post_id) {

        if ($column === 'user_full_name') {

            $first_name = get_post_meta($post_id, 'first_name', true);
            $last_name  = get_post_meta($post_id, 'last_name', true);
            $full_name  = $first_name . ' ' . $last_name;

            echo $full_name;
        }

    }

    function add_status_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['connection_status'] = 'Status';
            }
        }

        return $new_columns;
    }

    function manage_status_column($column, $post_id) {

        if ($column === 'connection_status') {

            $status = get_post_meta($post_id, 'status', true);

            if (!$status) {
                $status = 'pending';
            }

            if ($status === 'accepted') {
                echo '<span style="color: green; font-weight: 600;">Accepted</span>';
            } elseif ($status === 'rejected') {
                echo '<span style="color: red; font-weight: 600;">Rejected</span>';
            } elseif ($status === 'removed') {
                echo '<span style="color: #374151; font-weight: 600;">Removed</span>';
            } else {
                echo '<span style="color: orange; font-weight: 600;">Pending</span>';
            }
        }
    }

    function add_user_name_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['user_name'] = 'Name';
            }
        }

        return $new_columns;
    }

    function manage_user_name_column($column, $post_id) {

        if ($column === 'user_name') {

            $user_profile_id = get_post_meta($post_id, 'user_profile_id', true);
            $first_name = get_post_meta($user_profile_id, 'first_name', true);
            $last_name  = get_post_meta($user_profile_id, 'last_name', true);
            $full_name  = $first_name . ' ' . $last_name;

            echo $full_name;
        }
    }

    public function get_connections_by_user($user_id) {

        return get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'sender_user_id',
                    'value' => $user_id
                ],
                [
                    'key' => 'receiver_user_id',
                    'value' => $user_id
                ]
            ]
        ]);
    }
}