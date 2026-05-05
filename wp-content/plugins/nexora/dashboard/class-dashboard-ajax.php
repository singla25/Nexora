<?php
/**
 * NEXORA_DASHBOARD_AJAX
 *
 * HTTP layer only.  Pattern for every handler:
 *   1. auth()         — verify nonce + login + profile ownership
 *   2. validate       — sanitise / validate POST inputs
 *   3. delegate       — call NEXORA_DASHBOARD_HELPER for data
 *   4. wp_send_json_* — respond
 *
 * No business logic here.  Add helpers to NEXORA_DASHBOARD_HELPER.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_DASHBOARD_AJAX {

    public function __construct() {

        // ── User info ──────────────────────────────────────────
        $this->add( 'update_personal_info' );
        $this->add( 'update_address_info' );
        $this->add( 'update_work_info' );
        $this->add( 'update_documents_info' );
        $this->add( 'update_profile_password' );

        // ── Connections ────────────────────────────────────────
        $this->add( 'get_add_new_users' );
        $this->add( 'send_connection_request' );
        $this->add( 'get_requests' );
        $this->add( 'update_connection_status' );
        $this->add( 'get_history' );
        $this->add( 'view_all_connection' );
        $this->add( 'view_mutual_connection' );
        $this->add( 'remove_connection' );

        // ── Notifications ──────────────────────────────────────
        $this->add( 'mark_notification_read' );

        // ── Content ────────────────────────────────────────────
        $this->add( 'save_user_content' );
        $this->add( 'get_user_content_history' );
    }

    /** Register an authenticated AJAX action. */
    private function add( string $action ): void {
        add_action( 'wp_ajax_' . $action, [ $this, $action ] );
    }

    /* =========================================================================
       AUTH / VALIDATION UTILITIES
    ========================================================================= */

    /**
     * Verify nonce + login + profile.
     * Calls wp_send_json_error and dies on failure.
     *
     * @return array{ user_id: int, profile_id: int }
     * 
     * compact() creates an array from variables
     * 
     * compact( 'user_id', 'profile_id' )
     * Output: 
     * [
     *      'user_id'    => $user_id,
     *      'profile_id' => $profile_id,
     * ]
     * 
     */
    private function auth(): array {

        check_ajax_referer( 'profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 401 );
        }

        $user_id    = get_current_user_id();
        $profile_id = (int) get_user_meta( $user_id, '_profile_id', true );

        if ( ! $profile_id ) {
            wp_send_json_error( [ 'message' => 'Profile not found' ], 404 );
        }
        
        return compact( 'user_id', 'profile_id' );
    }

    /**
     * Save a set of text/textarea meta fields from $_POST to a CPT.
     *
     * @param int      $profile_id
     * @param string[] $fields         list of meta keys
     * @param string[] $textarea_keys  keys that need sanitize_textarea_field instead
     */
    private function save_fields(
        int   $profile_id,
        array $fields,
        array $textarea_keys = []
    ): void {

        foreach ( $fields as $field ) {
            if ( ! isset( $_POST[ $field ] ) ) continue;

            $value = in_array( $field, $textarea_keys, true )
                ? sanitize_textarea_field( $_POST[ $field ] )
                : sanitize_text_field( $_POST[ $field ] );

            update_post_meta( $profile_id, $field, $value );
        }
    }

    /**
     * Verify that the current user is the owner of a profile.
     * Dies with 403 if not.
     */
    private function assert_owner( int $profile_id ): void {
        $user_id   = get_current_user_id();
        $owner_uid = (int) get_post_meta( $profile_id, '_wp_user_id', true );

        if ( $user_id !== $owner_uid ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }
    }

    /* =========================================================================
       USER INFO HANDLERS
    ========================================================================= */

    public function update_personal_info(): void {

        $auth = $this->auth();
        $this->save_fields(
            $auth['profile_id'],
            NEXORA_DASHBOARD_HELPER::get_personal_save_fields(),
            [ 'bio' ]
        );
        wp_send_json_success( 'Personal info updated.' );
    }

    public function update_address_info(): void {

        $auth = $this->auth();
        $this->save_fields(
            $auth['profile_id'],
            NEXORA_DASHBOARD_HELPER::get_address_save_fields()
        );
        wp_send_json_success( 'Address updated.' );
    }

    public function update_work_info(): void {

        $auth = $this->auth();
        $this->save_fields(
            $auth['profile_id'],
            NEXORA_DASHBOARD_HELPER::get_work_save_fields()
        );
        wp_send_json_success( 'Work info updated.' );
    }

    public function update_documents_info(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];

        foreach ( NEXORA_DASHBOARD_HELPER::get_document_save_fields() as $field ) {
            if ( ! isset( $_POST[ $field ] ) ) continue;

            $value = $_POST[ $field ];

            if ( $value === '' || $value === '0' ) {
                delete_post_meta( $profile_id, $field );
            } else {
                update_post_meta( $profile_id, $field, absint( $value ) );
            }
        }

        wp_send_json_success( 'Documents updated.' );
    }

    public function update_profile_password(): void {

        check_ajax_referer( 'profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        $user_id          = get_current_user_id();
        $current_password = wp_unslash( $_POST['current_password'] ?? '' );
        $new_password     = wp_unslash( $_POST['new_password']     ?? '' );
        $confirm_password = wp_unslash( $_POST['confirm_password'] ?? '' );

        $user = get_user_by( 'id', $user_id );

        if ( ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
            wp_send_json_error( 'Current password is incorrect.' );
        }

        if ( $new_password !== $confirm_password ) {
            wp_send_json_error( 'Passwords do not match.' );
        }

        if ( $current_password === $new_password ) {
            wp_send_json_error( 'New password must differ from current.' );
        }

        wp_set_password( $new_password, $user_id );
        wp_send_json_success( 'Password updated.' );
    }

    /* =========================================================================
       CONNECTION HANDLERS
    ========================================================================= */

    public function get_add_new_users(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];

        // Collect IDs already connected (pending or accepted)
        $existing = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => 'sender_profile_id',   'value' => $profile_id ],
                [ 'key' => 'receiver_profile_id', 'value' => $profile_id ],
            ],
        ]);

        $excluded = [ $profile_id ];

        foreach ( $existing as $conn ) {
            if ( in_array( get_post_meta( $conn->ID, 'status', true ), [ 'pending', 'accepted' ], true ) ) {
                $excluded[] = (int) get_post_meta( $conn->ID, 'sender_profile_id',   true );
                $excluded[] = (int) get_post_meta( $conn->ID, 'receiver_profile_id', true );
            }
        }

        // Only show user_profiles (connections are subscriber ↔ subscriber)
        $users = get_posts([
            'post_type'      => 'user_profile',
            'posts_per_page' => -1,
            'post__not_in'   => array_unique( $excluded ),
        ]);

        $data = array_map(
            fn( $u ) => NEXORA_DASHBOARD_HELPER::shape_connection_card( $u->ID ),
            $users
        );

        wp_send_json_success( $data );
    }

    public function send_connection_request(): void {

        $auth = $this->auth();

        $sender_profile_id   = $auth['profile_id'];
        $sender_user_id      = $auth['user_id'];
        $sender_user_name    = get_post_meta( $sender_profile_id, 'user_name', true );

        $receiver_profile_id = absint( $_POST['receiver_profile_id'] ?? 0 );
        if ( ! $receiver_profile_id ) {
            wp_send_json_error( 'Invalid receiver.' );
        }

        $receiver_user_id    = (int) get_post_meta( $receiver_profile_id, '_wp_user_id', true );
        $receiver_user_name  = get_post_meta( $receiver_profile_id, 'user_name', true );

        $post_id = wp_insert_post([
            'post_type'   => 'user_connections',
            'post_status' => 'publish',
            'post_title'  => "{$sender_user_name}->{$receiver_user_name}",
        ]);

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( 'Failed to create connection.' );
        }

        foreach ( compact(
            'sender_user_id', 'sender_profile_id', 'sender_user_name',
            'receiver_user_id', 'receiver_profile_id', 'receiver_user_name'
        ) as $key => $val ) {
            update_post_meta( $post_id, $key, $val );
        }

        update_post_meta( $post_id, 'status', 'pending' );

        ( new NEXORA_Notification() )->insert([
            'actor_user_id'      => $sender_user_id,
            'actor_user_name'    => $sender_user_name,
            'receiver_user_id'   => $receiver_user_id,
            'receiver_user_name' => $receiver_user_name,
            'type'               => 'request',
            'connection_id'      => $post_id,
            'message'            => "{$sender_user_name} sent a connection request to {$receiver_user_name}",
        ]);

        wp_send_json_success( 'Request sent.' );
    }

    public function get_requests(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];

        $requests = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => 'receiver_profile_id', 'value' => $profile_id ],
                [ 'key' => 'status',              'value' => 'pending'   ],
            ],
        ]);

        $data = array_map( function( $conn ) {
            $sender_id = (int) get_post_meta( $conn->ID, 'sender_profile_id', true );
            $card      = NEXORA_DASHBOARD_HELPER::shape_connection_card( $sender_id );
            $card['connection_id'] = $conn->ID;
            return $card;
        }, $requests );

        wp_send_json_success( $data );
    }

    public function update_connection_status(): void {

        $auth            = $this->auth();
        $current_user_id = $auth['user_id'];
        $connection_id   = absint( $_POST['connection_id'] ?? 0 );
        $status          = sanitize_key( $_POST['status'] ?? '' );

        if ( ! in_array( $status, [ 'accepted', 'rejected', 'removed' ], true ) ) {
            wp_send_json_error( 'Invalid status.' );
        }

        update_post_meta( $connection_id, 'status', $status );

        if ( $status === 'removed' ) {
            ( new NEXORA_CHAT_DB() )->inactive_threads_by_connection( $connection_id );
        }

        $sender_user_id      = (int) get_post_meta( $connection_id, 'sender_user_id',    true );
        $sender_user_name    =       get_post_meta( $connection_id, 'sender_user_name',   true );
        $receiver_user_id    = (int) get_post_meta( $connection_id, 'receiver_user_id',   true );
        $receiver_user_name  =       get_post_meta( $connection_id, 'receiver_user_name', true );

        if ( $current_user_id === $sender_user_id ) {
            [ $actor_id, $actor_name, $recv_id, $recv_name ] =
                [ $sender_user_id, $sender_user_name, $receiver_user_id, $receiver_user_name ];
        } else {
            [ $actor_id, $actor_name, $recv_id, $recv_name ] =
                [ $receiver_user_id, $receiver_user_name, $sender_user_id, $sender_user_name ];
        }

        ( new NEXORA_Notification() )->insert([
            'actor_user_id'      => $actor_id,
            'actor_user_name'    => $actor_name,
            'receiver_user_id'   => $recv_id,
            'receiver_user_name' => $recv_name,
            'type'               => $status,
            'connection_id'      => $connection_id,
            'message'            => "{$actor_name} {$status} connection with {$recv_name}",
        ]);

        wp_send_json_success();
    }

    public function get_history(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];

        $received = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'receiver_profile_id', 'value' => $profile_id ]],
        ]);

        $sent = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'sender_profile_id', 'value' => $profile_id ]],
        ]);

        ob_start();
        include NEXORA_DASHBOARD_TEMPLATES . 'partials/connections-history.php';
        wp_send_json_success( ob_get_clean() );
    }

    public function view_all_connection(): void {

        check_ajax_referer( 'profile_nonce', 'nonce' );

        $profile_id  = absint( $_POST['profile_id'] ?? 0 );
        $connections = NEXORA_DASHBOARD_HELPER::get_connections( $profile_id );
        $is_owner    = false;

        ob_start();
        include NEXORA_DASHBOARD_TEMPLATES . 'partials/connection-cards.php';
        wp_send_json_success( ob_get_clean() );
    }

    public function view_mutual_connection(): void {

        $auth = $this->auth();

        $other_id   = absint( $_POST['profile_id'] ?? 0 );
        $my_id      = $auth['profile_id'];

        $mutual_ids  = NEXORA_DASHBOARD_HELPER::get_mutual_connection_ids( $my_id, $other_id );
        $connections = array_map(
            fn( $id ) => NEXORA_DASHBOARD_HELPER::shape_connection_card( $id ),
            $mutual_ids
        );

        $show_mutual_badge = true;
        $is_owner          = false;

        ob_start();
        include NEXORA_DASHBOARD_TEMPLATES . 'partials/connection-cards.php';
        wp_send_json_success( ob_get_clean() );
    }

    public function remove_connection(): void {

        $auth          = $this->auth();
        $connection_id = absint( $_POST['connection_id'] ?? 0 );

        // Confirm the caller is a party to this connection
        $sender   = (int) get_post_meta( $connection_id, 'sender_profile_id',   true );
        $receiver = (int) get_post_meta( $connection_id, 'receiver_profile_id', true );

        if ( $sender !== $auth['profile_id'] && $receiver !== $auth['profile_id'] ) {
            wp_send_json_error( 'Forbidden.' );
        }

        update_post_meta( $connection_id, 'status', 'removed' );
        ( new NEXORA_CHAT_DB() )->inactive_threads_by_connection( $connection_id );

        wp_send_json_success( 'Connection removed.' );
    }

    /* =========================================================================
       NOTIFICATION HANDLERS
    ========================================================================= */

    public function mark_notification_read(): void {

        check_ajax_referer( 'profile_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        $id      = absint( $_POST['id'] ?? 0 );
        $user_id = get_current_user_id();

        $notification = new NEXORA_Notification();
        $row          = $notification->get_row( $id );

        if ( ! $row || (int) $row->receiver_user_id !== $user_id ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $notification->mark_as_read( $id );
        wp_send_json_success( [ 'message' => $row->message ] );
    }

    /* =========================================================================
       CONTENT HANDLERS
    ========================================================================= */

    public function save_user_content(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];
        $user_id    = $auth['user_id'];

        // Content is only for subscriber (user) owners — deny vendors
        if ( get_post_type( $profile_id ) !== 'user_profile' ) {
            wp_send_json_error( 'Content posting is not available for this account type.' );
        }

        $title       = sanitize_text_field( $_POST['title'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $image_id    = absint( $_POST['image'] ?? 0 );
        $user_name   = get_post_meta( $profile_id, 'user_name', true );

        if ( ! $title ) {
            wp_send_json_error( 'Title is required.' );
        }

        $post_id = wp_insert_post([
            'post_type'    => 'user_content',
            'post_title'   => $title,
            'post_content' => $description,
            'post_status'  => 'publish',
        ]);

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( 'Failed to save content.' );
        }

        if ( $image_id ) {
            set_post_thumbnail( $post_id, $image_id );
        }

        update_post_meta( $post_id, 'user_id',         $user_id );
        update_post_meta( $post_id, 'user_profile_id', $profile_id );
        update_post_meta( $post_id, 'user_name',       $user_name );

        wp_send_json_success( 'Content saved.' );
    }

    public function get_user_content_history(): void {

        $auth       = $this->auth();
        $profile_id = $auth['profile_id'];

        $posts = get_posts([
            'post_type'      => 'user_content',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'user_profile_id', 'value' => $profile_id ]],
        ]);

        ob_start();
        include NEXORA_DASHBOARD_TEMPLATES . 'partials/content-history-table.php';
        wp_send_json_success( ob_get_clean() );
    }
}
