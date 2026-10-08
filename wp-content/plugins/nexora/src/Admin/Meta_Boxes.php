<?php

namespace Nexora\Admin;

use Nexora\Chat\Repository as Chat;
use Nexora\Connections\Repository as Connections;
use Nexora\Content\Repository as Content;
use Nexora\Core\View;
use Nexora\Profile\Fields;

if (!defined('ABSPATH')) exit;

/**
 * The edit-screen panels for user profiles, connections and content, and saving them.
 * Markup lives in templates/admin/metabox-*.php.
 */
class Meta_Boxes {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);
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

    /** Meta values of a post for the given keys. */
    private function meta_of($post_id, array $keys) {

        $meta = [];

        foreach ($keys as $key) {
            $meta[$key] = get_post_meta($post_id, $key, true);
        }

        return $meta;
    }

    /* ===============================
       USER PROFILE
    =============================== */
    public function user_personal_details($post) {
        View::output('admin/metabox-personal', ['meta' => $this->meta_of($post->ID, ['user_name', 'first_name', 'last_name', 'email', 'phone', 'linkedin_id', 'gender', 'birthdate', 'bio'])]);
    }

    public function user_address_details($post) {
        View::output('admin/metabox-address', ['meta' => $this->meta_of($post->ID, Fields::ADDRESS)]);
    }

    public function user_work_details($post) {
        View::output('admin/metabox-work', ['meta' => $this->meta_of($post->ID, Fields::WORK)]);
    }

    /* DOCUMENTS (MEDIA UPLOAD) */
    public function user_document_details($post) {

        $labels = [
            'profile_image'   => 'Profile Image',
            'cover_image'     => 'Cover Image',
            'aadhaar_card'    => 'Aadhar Card',
            'driving_license' => 'Driving License',
            'company_id_card' => 'Company ID Card'
        ];

        $docs = [];

        foreach ($labels as $key => $label) {

            $image_id = get_post_meta($post->ID, $key, true);

            $docs[] = [
                'key'   => $key,
                'label' => $label,
                'id'    => $image_id,
                'url'   => $image_id ? wp_get_attachment_url($image_id) : '',
            ];
        }

        View::output('admin/metabox-documents', ['docs' => $docs]);
    }

    /* USER CONNECTIONS */
    public function user_connection_details($post) {

        $profile_id = $post->ID;

        View::output('admin/metabox-connections', [
            'received' => $this->connection_rows(Connections::received_by($profile_id), 'sender'),
            'sent'     => $this->connection_rows(Connections::sent_by($profile_id), 'receiver'),
        ]);
    }

    /** Rows for a connection list: the other side's profile id and user name, and the status. */
    private function connection_rows(array $connections, $other_side) {

        $rows = [];

        foreach ($connections as $conn) {
            $rows[] = [
                'profile_id' => get_post_meta($conn->ID, $other_side . '_profile_id', true),
                'user_name'  => get_post_meta($conn->ID, $other_side . '_user_name', true),
                'status'     => get_post_meta($conn->ID, 'status', true),
            ];
        }

        return $rows;
    }

    /* USER CONTENT */
    public function user_content_details($post) {

        $contents = [];

        foreach (Content::for_profile($post->ID, -1) as $content) {
            $contents[] = [
                'title'    => $content->post_title,
                'date'     => get_the_date('Y-m-d H:i:s', $content->ID),
                'edit_url' => admin_url('post.php?post=' . $content->ID . '&action=edit'),
            ];
        }

        View::output('admin/metabox-contents', ['contents' => $contents]);
    }

    /* USER CHAT OVERVIEW */
    public function user_chat_details($post) {

        $user_id = get_post_meta($post->ID, '_wp_user_id', true);

        if (!$user_id) {
            View::output('admin/metabox-user-chat', ['state' => 'no_user', 'rows' => []]);
            return;
        }

        $connections = Connections::for_user($user_id);

        if (!$connections) {
            View::output('admin/metabox-user-chat', ['state' => 'no_connections', 'rows' => []]);
            return;
        }

        $chat_db = new Chat();
        $rows    = [];

        foreach ($connections as $conn) {

            $conn_id = $conn->ID;

            $sender_id   = get_post_meta($conn_id, 'sender_user_id', true);
            $receiver_id = get_post_meta($conn_id, 'receiver_user_id', true);
            $status      = get_post_meta($conn_id, 'status', true);

            // Other user
            $other_user_id = ($sender_id == $user_id) ? $receiver_id : $sender_id;
            $other_user    = get_userdata($other_user_id);

            $threads = [];

            foreach ($chat_db->get_threads_by_connection($conn_id) as $t) {
                $threads[] = [
                    'subject' => $t->subject ?: 'No Subject',
                    'status'  => $t->status,
                    'color'   => $t->status === 'active' ? '#16a34a' : '#dc2626',
                ];
            }

            $rows[] = [
                'name'         => $other_user ? $other_user->display_name : '-',
                'conn_id'      => $conn_id,
                'status'       => $status,
                'status_color' => match($status) {
                    'accepted' => '#16a34a',
                    'pending'  => '#f59e0b',
                    'removed'  => '#6b7280',
                    default    => '#dc2626'
                },
                'time'         => get_the_date('d M Y, H:i', $conn_id),
                'threads'      => $threads,
            ];
        }

        View::output('admin/metabox-user-chat', ['state' => 'ok', 'rows' => $rows]);
    }

    /* ===============================
       USER CONNECTIONS
    =============================== */
    public function user_connection_meta_box($post) {

        View::output('admin/metabox-connection', ['m' => $this->meta_of($post->ID, [
            'sender_user_id', 'sender_profile_id', 'sender_user_name',
            'receiver_user_id', 'receiver_profile_id', 'receiver_user_name',
            'status',
        ])]);
    }

    public function user_connection_chat_box($post) {

        $threads = [];

        foreach ((new Chat())->get_threads_by_connection($post->ID) as $thread) {

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

            $threads[] = [
                'id'         => $thread->id,
                'users'      => $user1 . ' & ' . $user2,
                'subject'    => $thread->subject ?: 'No Subject',
                'status'     => $thread->status,
                'color'      => $thread->status === 'active' ? '#16a34a' : '#dc2626',
                // Admin screen: never use the logged-in admin as the "other" user
                'other_user' => $user_ids[1] ?? $user_ids[0] ?? 0,
                'name'       => $user1 . ' and ' . $user2,
            ];
        }

        View::output('admin/metabox-connection-chat', ['threads' => $threads]);
    }

    /* ===============================
       USER CONTENT
    =============================== */
    public function render_user_content_meta_box($post) {

        View::output('admin/metabox-content', ['m' => $this->meta_of($post->ID, ['user_id', 'user_profile_id', 'user_name'])]);
    }

    /* ===============================
       SAVE DATA
    =============================== */
    public function save_meta_boxes($post_id) {

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if (wp_is_post_revision($post_id)) return;

        // Only react to the real admin edit screen. Front-end AJAX handlers also
        // call wp_insert_post() and must never be overwritten with raw $_POST data.
        if (!is_admin() || wp_doing_ajax()) return;

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'update-post_' . $post_id)) return;

        if (!current_user_can('edit_post', $post_id) || !current_user_can('manage_options')) return;

        $post_type = get_post_type($post_id);

        // ===============================
        // USER PROFILE SAVE
        // ===============================
        if ($post_type === 'user_profile') {

            $fields = Fields::admin_editable();

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {

                    if (in_array($field, Fields::DOCUMENTS, true)) {
                        update_post_meta($post_id, $field, absint($_POST[$field]));
                    } else {
                        update_post_meta($post_id, $field, sanitize_text_field(wp_unslash($_POST[$field])));
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
                    update_post_meta($post_id, $field, sanitize_text_field(wp_unslash($_POST[$field])));
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
                    update_post_meta($post_id, $field, sanitize_text_field(wp_unslash($_POST[$field])));
                }
            }
        }
    }
}
