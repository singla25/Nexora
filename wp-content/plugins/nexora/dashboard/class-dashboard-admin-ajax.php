<?php
/**
 * NEXORA_ADMIN_PROFILE_AJAX
 *
 * Handles the three admin-profile AJAX actions
 * Data is stored in WP usermeta — no CPT involved.
 *
 * Actions registered:
 *   nx_admin_update_personal  — first_name, last_name, nexora_admin_phone
 *   nx_admin_update_password  — current/new/confirm password
 *   nx_admin_update_avatar    — nexora_admin_avatar / nexora_admin_cover (attachment IDs)
 *
 * Include this file from dashboard.php (or your main plugin file) AFTER
 * class-dashboard-helper.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_ADMIN_PROFILE_AJAX {

    public function __construct() {
        add_action( 'wp_ajax_nx_admin_update_personal', [ $this, 'update_personal' ] );
        add_action( 'wp_ajax_nx_admin_update_password', [ $this, 'update_password' ] );
        add_action( 'wp_ajax_nx_admin_update_avatar',   [ $this, 'update_avatar'   ] );
    }

    /* =========================================================================
       AUTH
    ========================================================================= */

    /**
     * Verify nonce + admin capability.
     * Dies with 403 on failure.
     */
    private function auth(): int {

        check_ajax_referer( 'profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        return get_current_user_id();
    }

    /* =========================================================================
       PERSONAL INFO
    ========================================================================= */

    public function update_personal(): void {

        $admin_id = $this->auth();

        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone']      ?? '' ) );

        // WP core user fields
        wp_update_user( [
            'ID'           => $admin_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ) ?: wp_get_current_user()->user_login,
        ] );

        // Custom meta
        update_user_meta( $admin_id, 'nexora_admin_phone', $phone );

        wp_send_json_success( 'Personal info Updated.' );
    }

    /* =========================================================================
       PASSWORD
    ========================================================================= */

    public function update_password(): void {

        $admin_id        = $this->auth();
        $current_password = wp_unslash( $_POST['current_password'] ?? '' );
        $new_password     = wp_unslash( $_POST['new_password']     ?? '' );
        $confirm_password = wp_unslash( $_POST['confirm_password'] ?? '' );

        $user = get_user_by( 'id', $admin_id );

        if ( ! wp_check_password( $current_password, $user->user_pass, $admin_id ) ) {
            wp_send_json_error( [ 'message' => 'Current password is incorrect.' ] );
        }

        if ( strlen( $new_password ) < 8 ) {
            wp_send_json_error( [ 'message' => 'New password must be at least 8 characters.' ] );
        }

        if ( $new_password !== $confirm_password ) {
            wp_send_json_error( [ 'message' => 'Passwords do not match.' ] );
        }

        if ( $current_password === $new_password ) {
            wp_send_json_error( [ 'message' => 'New password must differ from current.' ] );
        }

        wp_set_password( $new_password, $admin_id );

        wp_send_json_success( 'Password Updated.' );
    }

    /* =========================================================================
       AVATAR + COVER
    ========================================================================= */

    public function update_avatar(): void {

        $admin_id = $this->auth();
 
        // buildDocCard() sends hidden inputs named 'profile_image' and 'cover_image'
        // (attachment IDs — same convention as update_documents_info for users/vendors)
        $avatar_id = absint( $_POST['profile_image'] ?? $_POST['avatar_id'] ?? 0 );
        $cover_id  = absint( $_POST['cover_image']   ?? $_POST['cover_id']  ?? 0 );

        $response = [];

        if ( $avatar_id ) {
            update_user_meta( $admin_id, 'nexora_admin_avatar', $avatar_id );
            $url = wp_get_attachment_url( $avatar_id );
            if ( $url ) $response['avatar_url'] = $url;
        }

        if ( $cover_id ) {
            update_user_meta( $admin_id, 'nexora_admin_cover', $cover_id );
            $url = wp_get_attachment_url( $cover_id );
            if ( $url ) $response['cover_url'] = $url;
        }

        if ( empty( $response ) ) {
            wp_send_json_error( [ 'message' => 'No valid attachment IDs provided.' ] );
        }

        wp_send_json_success( 'Image Updated.' );
    }
}
