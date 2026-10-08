<?php

namespace Nexora\Profile;

if (!defined('ABSPATH')) exit;

/**
 * Who may upload, where from, and how much.
 *
 * Members do not hold a permanent upload_files capability. They receive it only
 * while the profile page is rendered or while the media uploader's own AJAX
 * requests run, so REST /wp/v2/media and wp-admin uploads stay closed to them.
 * Members are also limited in file size and in total number of files.
 */
class Upload_Policy {

    const CLEANED_OPTION = 'nexora_upload_cap_cleaned';

    /** Media uploader AJAX actions a member needs (own library only, see ajax_query_attachments_args). */
    const UPLOAD_ACTIONS = ['upload-attachment', 'query-attachments', 'get-attachment'];

    const DEFAULT_MAX_BYTES = 8 * MB_IN_BYTES;
    const DEFAULT_MAX_FILES = 100;

    public function __construct() {

        add_action('init', [$this, 'maybe_cleanup_role_cap']);
        add_filter('user_has_cap', [$this, 'grant_upload_in_context'], 10, 4);
        add_filter('upload_size_limit', [$this, 'limit_size']);
        add_filter('wp_handle_upload_prefilter', [$this, 'check_upload']);

        // Members see only their own files in the media library and may only upload images and PDFs
        add_filter('ajax_query_attachments_args', [$this, 'own_files_only']);
        add_filter('upload_mimes', [$this, 'restrict_member_mimes']);
    }

    public function own_files_only($query) {

        if (!current_user_can('manage_options')) {
            $query['author'] = get_current_user_id();
        }

        return $query;
    }

    public function restrict_member_mimes($mimes) {

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

    /* ===============================
       CAPABILITY
    =============================== */

    /**
     * Earlier versions stored upload_files on the subscriber role for everyone.
     * Remove it once; the context rule below replaces it.
     */
    public function maybe_cleanup_role_cap() {

        if (get_option(self::CLEANED_OPTION)) {
            return;
        }

        self::cleanup_role_cap();
        update_option(self::CLEANED_OPTION, 1, false);
    }

    public static function cleanup_role_cap() {

        $role = get_role('subscriber');

        if ($role && $role->has_cap('upload_files')) {
            $role->remove_cap('upload_files');
        }
    }

    private function upload_context() {

        if (wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
            return in_array($action, self::UPLOAD_ACTIONS, true);
        }

        if (is_admin() || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return false;
        }

        return isset($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof \WP_Query
            && \Nexora\Core\Assets::is_page_for('profile-page', 'profile_dashboard');
    }

    public function grant_upload_in_context($allcaps, $caps, $args, $user) {

        if (!in_array('upload_files', (array) $caps, true) || !empty($allcaps['upload_files'])) {
            return $allcaps;
        }

        if (!$user instanceof \WP_User || !$user->exists() || !get_user_meta($user->ID, '_profile_id', true)) {
            return $allcaps;
        }

        if ($this->upload_context()) {
            $allcaps['upload_files'] = true;
        }

        return $allcaps;
    }

    /* ===============================
       LIMITS
    =============================== */
    private function is_limited_member() {
        return is_user_logged_in() && !current_user_can('manage_options');
    }

    private function max_bytes() {
        return (int) apply_filters('nexora_member_max_upload_bytes', self::DEFAULT_MAX_BYTES);
    }

    public function limit_size($size) {
        return $this->is_limited_member() ? min((int) $size, $this->max_bytes()) : $size;
    }

    public function check_upload($file) {

        if (!$this->is_limited_member() || !is_array($file)) {
            return $file;
        }

        $max = $this->max_bytes();

        if (!empty($file['size']) && (int) $file['size'] > $max) {
            $file['error'] = sprintf('File is too large. The maximum size is %s.', size_format($max));
            return $file;
        }

        global $wpdb;

        $limit = (int) apply_filters('nexora_member_max_files', self::DEFAULT_MAX_FILES);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_author = %d",
            get_current_user_id()
        ));

        if ($count >= $limit) {
            $file['error'] = 'You have reached the maximum number of uploaded files. Please remove some before uploading more.';
        }

        return $file;
    }
}
