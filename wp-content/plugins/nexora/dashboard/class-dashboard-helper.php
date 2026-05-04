<?php
/**
 * NEXORA_DASHBOARD_HELPER
 *
 * Pure static utility class — zero WordPress hooks, zero side-effects.
 * Every template and AJAX handler delegates business logic here.
 *
 * ─── Role / Access Matrix ────────────────────────────────────────────────────
 *
 * Actors
 *   guest        = not logged in
 *   user-owner   = logged-in subscriber whose URL username matches their own profile
 *   user-viewer  = logged-in subscriber viewing another subscriber's profile
 *   vendor-owner = logged-in vendor viewing their own profile
 *   vendor-viewer= logged-in vendor viewing someone else's profile
 *
 * Tab visibility
 *   Tab           | guest→user | guest→vendor | user-owner | user-viewer | vendor-owner | vendor-viewer
 *   user-info     |     ✓      |      ✓       |     ✓      |      ✓      |      ✓       |      ✓
 *   connections   |     ✓      |      ✗       |     ✓      |      ✓      |      ✗       |      ✗
 *   notifications |     ✗      |      ✗       |     ✓      |      ✗      |      ✓       |      ✗
 *   content       |     ✗      |      ✗       |     ✓      |      ✗      |      ✗       |      ✗
 *   market        |     ✗      |      ✗       |     ✓      |      ✗      |      ✓       |      ✗
 *
 * ─── Adding a new role ────────────────────────────────────────────────────────
 *   1. Add its WP role slug → CPT to ROLE_MAP
 *   2. Add tab rules to get_visible_tabs()
 *   3. Add info sub-tab config to get_info_subtabs()
 *   Done.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_DASHBOARD_HELPER {

    /* =========================================================================
       CONSTANTS — single source of truth for slugs
    ========================================================================= */

    const ROLE_MAP = [
        'subscriber' => 'user_profile',
        'vendor'     => 'vendor_profile',
    ];

    const PROFILE_POST_TYPES = [ 'user_profile', 'vendor_profile' ];

    const TAB_INFO          = 'user-info';
    const TAB_CONNECTIONS   = 'connections';
    const TAB_CONTENT       = 'content';
    const TAB_MARKET        = 'market';
    const TAB_NOTIFICATIONS = 'notifications';

    /* =========================================================================
       CONTEXT RESOLUTION
    ========================================================================= */

    public static function resolve_context(): array {

        $current_user_id = get_current_user_id();
        $username        = get_query_var( 'username' );

        // ── Which profile is being viewed? ────────────────────────
        if ( $username ) {
            [ $profile_id, $profile_role ] = self::find_profile_by_username( $username );
            $owner_user_id = $profile_id
                ? (int) get_post_meta( $profile_id, '_wp_user_id', true )
                : 0;
        } else {
            // No username in URL: show the current user's own profile.
            // Works for BOTH subscribers (_profile_id) and vendors (_vendor_profile_id).
            $profile_id = 0;
            if ( $current_user_id ) {
                // Try subscriber meta first, then vendor meta
                $profile_id = (int) get_user_meta( $current_user_id, '_profile_id', true );
                if ( ! $profile_id ) {
                    $profile_id = (int) get_user_meta( $current_user_id, '_vendor_profile_id', true );
                }
            }
            $profile_role  = self::get_profile_role_for_user( $current_user_id );
            $owner_user_id = $current_user_id;
        }

        $role_type   = self::resolve_role_type( $current_user_id, $owner_user_id );
        $viewer_role = $current_user_id
            ? self::get_profile_role_for_user( $current_user_id )
            : '';

        return [
            'current_user_id' => $current_user_id,
            'profile_id'      => (int) $profile_id,
            'owner_user_id'   => (int) $owner_user_id,
            'role_type'       => $role_type,
            'profile_role'    => $profile_role,
            'viewer_role'     => $viewer_role,
            'is_owner'        => $role_type === 'owner',
            'is_logged_in'    => $role_type !== 'guest',
        ];
    }

    public static function find_profile_by_username( string $username ): array {

        foreach ( self::PROFILE_POST_TYPES as $cpt ) {

            $q = new WP_Query([
                'post_type'      => $cpt,
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'meta_query'     => [[
                    'key'     => 'user_name',
                    'value'   => sanitize_text_field( $username ),
                    'compare' => '=',
                ]],
            ]);

            if ( $q->have_posts() ) {
                $q->the_post();
                $id = get_the_ID();
                wp_reset_postdata();
                return [ $id, self::cpt_to_role( $cpt ) ];
            }
        }

        return [ 0, '' ];
    }

    public static function resolve_role_type( int $current_user_id, int $owner_user_id ): string {
        if ( ! is_user_logged_in() )               return 'guest';
        if ( $current_user_id === $owner_user_id ) return 'owner';
        return 'viewer';
    }

    public static function get_profile_role_for_user( int $user_id ): string {

        if ( ! $user_id ) return '';

        $user = get_userdata( $user_id );
        if ( ! $user ) return '';

        foreach ( self::ROLE_MAP as $wp_role => $cpt ) {
            if ( in_array( $wp_role, (array) $user->roles, true ) ) {
                return self::cpt_to_role( $cpt );
            }
        }

        return 'user';
    }

    /* =========================================================================
       ACCESS CHECKS
    ========================================================================= */

    public static function is_owner( array $ctx ): bool {
        return $ctx['role_type'] === 'owner';
    }

    public static function is_viewer( array $ctx ): bool {
        return $ctx['role_type'] === 'viewer';
    }

    public static function is_guest( array $ctx ): bool {
        return $ctx['role_type'] === 'guest';
    }

    public static function is_vendor_profile( array $ctx ): bool {
        return $ctx['profile_role'] === 'vendor';
    }

    public static function visitor_is_vendor( array $ctx ): bool {
        return $ctx['viewer_role'] === 'vendor';
    }

    /* =========================================================================
       TAB VISIBILITY
       Fix #3: vendor-owner gets Documents tab (Information + Connections removed for vendors
               because vendor has no subscriber connections).
       Fix #4: vendor-owner sees only Information + Market.
               vendor-viewer sees only Information.
    ========================================================================= */

    public static function get_visible_tabs( array $ctx ): array {

        $role_type    = $ctx['role_type'];    // guest | owner | viewer
        $profile_role = $ctx['profile_role']; // user  | vendor
        $viewer_role  = $ctx['viewer_role'];  // user  | vendor | ''

        $tabs = [];

        // Info tab: always visible
        $tabs[] = self::TAB_INFO;

        // ── Connections tab ────────────────────────────────────
        // Only for: user-owner, user-viewer, guest→user-profile.
        // Hidden for everything involving a vendor (either profile or viewer).
        $show_connections = (
            $profile_role === 'user' &&          // only on user profiles
            $viewer_role  !== 'vendor'           // vendors never see connections tab
        );
        if ( $show_connections ) {
            $tabs[] = self::TAB_CONNECTIONS;
        }

        // ── Owner-only tabs ────────────────────────────────────
        if ( $role_type === 'owner' ) {

            // Content tab: only for user-owners
            if ( $profile_role === 'user' ) {
                $tabs[] = self::TAB_CONTENT;
            }

            // Market tab: both user-owner and vendor-owner
            $tabs[] = self::TAB_MARKET;

            // Notifications: both user-owner and vendor-owner
            $tabs[] = self::TAB_NOTIFICATIONS;
        }

        return $tabs;
    }

    public static function can_access_tab( string $tab, array $ctx ): bool {
        return in_array( $tab, self::get_visible_tabs( $ctx ), true );
    }

    /* =========================================================================
       INFO SUB-TABS
       Fix #2: order is Personal → Address → Work/Business → Documents → Security
               (Security moved to last position)
       Fix #3: vendor-owner now sees Documents sub-tab
    ========================================================================= */

    public static function get_info_subtabs( array $ctx ): array {

        if ( ! $ctx['is_owner'] ) return [];

        if ( $ctx['profile_role'] === 'vendor' ) {
            return [
                'personal-info' => 'Personal',
                'address-info'  => 'Address',
                'work-info'     => 'Business',
                'docs-info'     => 'Documents',
                'security-info' => 'Security',
                'vendor-info'   => 'Vendor Details',
            ];
        }

        // Subscriber / user
        return [
            'personal-info' => 'Personal',
            'address-info'  => 'Address',
            'work-info'     => 'Work',
            'docs-info'     => 'Documents',
            'security-info' => 'Security',
        ];
    }

    /* =========================================================================
       FIELD DEFINITIONS
    ========================================================================= */

    public static function get_personal_fields(): array {
        return [
            'user_name'   => 'Username',
            'email'       => 'Email',
            'first_name'  => 'First Name',
            'last_name'   => 'Last Name',
            'gender'      => 'Gender',
            'birthdate'   => 'Birthdate',
            'phone'       => 'Phone',
            'linkedin_id' => 'LinkedIn',
        ];
    }

    public static function get_address_fields(): array {
        $addr = [
            'address'  => 'Address',
            'city'     => 'City',
            'state'    => 'State',
            'pincode'  => 'Pincode',
        ];

        $build = static function( string $prefix ) use ( $addr ): array {
            $out = [];
            foreach ( $addr as $suffix => $label ) {
                $out[ $prefix . '_' . $suffix ] = $label;
            }
            return $out;
        };

        return [
            'permanent'      => $build( 'perm' ),
            'correspondence' => $build( 'corr' ),
        ];
    }

    /**
     * Fix #1: corrected vendor business meta keys to match registration
     * (business_email, business_phone, business_address instead of
     *  company_email, company_phone, company_address).
     */
    public static function get_work_fields( string $profile_role ): array {

        if ( $profile_role === 'vendor' ) {
            return [
                'heading' => 'Business Information',
                'fields'  => [
                    'business_name'    => 'Business Name',
                    'business_type'    => 'Business Type',
                    'business_email'   => 'Business Email',
                    'business_phone'   => 'Business Phone',
                    'business_address' => 'Business Address',
                    'gst_number'       => 'GST Number',
                ],
            ];
        }

        return [
            'heading' => 'Work Information',
            'fields'  => [
                'company_name'    => 'Company Name',
                'designation'     => 'Designation',
                'company_email'   => 'Company Email',
                'company_phone'   => 'Company Phone',
                'company_address' => 'Company Address',
            ],
        ];
    }

    public static function get_vendor_detail_fields(): array {
        return [
            'vendor_category'    => 'Vendor Category',
            'service_areas'      => 'Service Areas',
            'years_in_business'  => 'Years in Business',
            'website_url'        => 'Website',
        ];
    }

    public static function get_document_fields( string $profile_role ): array {

        $common = [
            'profile_image' => 'Profile Image',
            'cover_image'   => 'Cover Image',
            'aadhaar_card'  => 'Aadhaar Card',
        ];

        $user_docs = [
            'driving_license' => 'Driving License',
            'company_id_card' => 'Company ID Card',
        ];

        $vendor_docs = [
            'company_id_card'  => 'Company ID Card',
            'gst_certificate'  => 'GST Certificate',
            'business_license' => 'Business License',
            'pan_card'         => 'PAN Card',
            'bank_proof'       => 'Bank Proof',
        ];

        $extra = $profile_role === 'vendor' ? $vendor_docs : $user_docs;

        return array_merge( $common, $extra );
    }

    public static function get_personal_save_fields(): array {
        return [ 'first_name', 'last_name', 'phone', 'gender', 'birthdate', 'linkedin_id', 'bio' ];
    }

    public static function get_address_save_fields(): array {
        return [
            'perm_address', 'perm_city', 'perm_state', 'perm_pincode',
            'corr_address', 'corr_city', 'corr_state', 'corr_pincode',
        ];
    }

    /**
     * Fix #1: added business_email, business_phone, business_address to save fields.
     */
    public static function get_work_save_fields(): array {
        return [
            'company_name', 'designation', 'company_email', 'company_phone', 'company_address',
            'business_name', 'business_type', 'business_email', 'business_phone', 'business_address',
            'gst_number', 'vendor_category', 'service_areas', 'years_in_business', 'website_url',
        ];
    }

    public static function get_document_save_fields(): array {
        return [
            'profile_image', 'cover_image', 'aadhaar_card',
            'driving_license', 'company_id_card',
            'gst_certificate', 'business_license', 'pan_card', 'bank_proof',
        ];
    }

    /* =========================================================================
       DATA BUILDERS
    ========================================================================= */

    public static function build_user_data( int $profile_id ): array {

        if ( ! $profile_id ) return [];

        $get = fn( $key ) => get_post_meta( $profile_id, $key, true );

        return [
            'profile_id'      => $profile_id,

            // Identity
            'user_name'       => $get( 'user_name' ),
            'email'           => $get( 'email' ),
            'phone'           => $get( 'phone' ),
            'first_name'      => $get( 'first_name' ),
            'last_name'       => $get( 'last_name' ),
            'gender'          => $get( 'gender' ),
            'birthdate'       => $get( 'birthdate' ),
            'linkedin_id'     => $get( 'linkedin_id' ),
            'bio'             => $get( 'bio' ),

            // Address
            'perm_address'    => $get( 'perm_address' ),
            'perm_city'       => $get( 'perm_city' ),
            'perm_state'      => $get( 'perm_state' ),
            'perm_pincode'    => $get( 'perm_pincode' ),
            'corr_address'    => $get( 'corr_address' ),
            'corr_city'       => $get( 'corr_city' ),
            'corr_state'      => $get( 'corr_state' ),
            'corr_pincode'    => $get( 'corr_pincode' ),

            // Work (subscriber)
            'company_name'    => $get( 'company_name' ),
            'designation'     => $get( 'designation' ),
            'company_email'   => $get( 'company_email' ),
            'company_phone'   => $get( 'company_phone' ),
            'company_address' => $get( 'company_address' ),

            // Business (vendor) — Fix #1: correct meta keys
            'business_name'    => $get( 'business_name' ),
            'business_type'    => $get( 'business_type' ),
            'business_email'   => $get( 'business_email' ),
            'business_phone'   => $get( 'business_phone' ),
            'business_address' => $get( 'business_address' ),
            'gst_number'       => $get( 'gst_number' ),

            // Vendor-specific extras
            'vendor_category'    => $get( 'vendor_category' ),
            'service_areas'      => $get( 'service_areas' ),
            'years_in_business'  => $get( 'years_in_business' ),
            'website_url'        => $get( 'website_url' ),

            // Document IDs
            'profile_image_id'     => $get( 'profile_image' ),
            'cover_image_id'       => $get( 'cover_image' ),
            'aadhaar_card_id'      => $get( 'aadhaar_card' ),
            'driving_license_id'   => $get( 'driving_license' ),
            'company_id_card_id'   => $get( 'company_id_card' ),
            'gst_certificate_id'   => $get( 'gst_certificate' ),
            'business_license_id'  => $get( 'business_license' ),
            'pan_card_id'          => $get( 'pan_card' ),
            'bank_proof_id'        => $get( 'bank_proof' ),

            // Document URLs
            'profile_image'  => self::get_image_url( $profile_id, 'profile_image', 'default_profile_image' ),
            'cover_image'    => self::get_image_url( $profile_id, 'cover_image',   'default_cover_image'   ),
            'aadhaar_card'   => self::get_image_url( $profile_id, 'aadhaar_card',  'default_document_image' ),
            'driving_license'=> self::get_image_url( $profile_id, 'driving_license','default_document_image' ),
            'company_id_card'=> self::get_image_url( $profile_id, 'company_id_card','default_document_image' ),
        ];
    }

    public static function get_profile_header( int $profile_id ): array {

        return [
            'username' => get_post_meta( $profile_id, 'user_name',   true ),
            'name'     => trim(
                get_post_meta( $profile_id, 'first_name', true ) . ' ' .
                get_post_meta( $profile_id, 'last_name',  true )
            ),
            'email'    => get_post_meta( $profile_id, 'email', true ),
            'phone'    => get_post_meta( $profile_id, 'phone', true ),
            'image'    => self::get_image_url( $profile_id, 'profile_image', 'default_profile_image' ),
            'cover'    => self::get_image_url( $profile_id, 'cover_image',   'default_cover_image'   ),
        ];
    }

    /* =========================================================================
       CONNECTIONS
    ========================================================================= */

    public static function get_connections( int $profile_id ): array {

        $posts = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => 'status', 'value' => 'accepted' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'sender_profile_id',   'value' => $profile_id ],
                    [ 'key' => 'receiver_profile_id', 'value' => $profile_id ],
                ],
            ],
        ]);

        $data = [];

        foreach ( $posts as $conn ) {
            $sender   = (int) get_post_meta( $conn->ID, 'sender_profile_id',   true );
            $receiver = (int) get_post_meta( $conn->ID, 'receiver_profile_id', true );
            $other_id = ( $sender === $profile_id ) ? $receiver : $sender;

            $data[] = [
                'connection_id' => $conn->ID,
                'profile_id'    => $other_id,
                'username'      => get_post_meta( $other_id, 'user_name',  true ),
                'name'          => trim(
                    get_post_meta( $other_id, 'first_name', true ) . ' ' .
                    get_post_meta( $other_id, 'last_name',  true )
                ),
                'image'         => self::get_profile_image( $other_id ),
                'profile_link'  => self::get_profile_url( $other_id ),
            ];
        }

        return $data;
    }

    public static function get_user_connection_ids( int $profile_id ): array {

        $posts = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => 'status', 'value' => 'accepted' ],
                [
                    'relation' => 'OR',
                    [ 'key' => 'sender_profile_id',   'value' => $profile_id ],
                    [ 'key' => 'receiver_profile_id', 'value' => $profile_id ],
                ],
            ],
        ]);

        return array_map( function( $conn ) use ( $profile_id ) {
            $sender = (int) get_post_meta( $conn->ID, 'sender_profile_id', true );
            return $sender === $profile_id
                ? (int) get_post_meta( $conn->ID, 'receiver_profile_id', true )
                : $sender;
        }, $posts );
    }

    public static function get_mutual_connection_ids( int $profile_a, int $profile_b ): array {
        return array_values( array_intersect(
            self::get_user_connection_ids( $profile_a ),
            self::get_user_connection_ids( $profile_b )
        ) );
    }

    public static function shape_connection_card( int $profile_id ): array {
        return [
            'profile_id'   => $profile_id,
            'username'     => get_post_meta( $profile_id, 'user_name',  true ),
            'name'         => trim(
                get_post_meta( $profile_id, 'first_name', true ) . ' ' .
                get_post_meta( $profile_id, 'last_name',  true )
            ),
            'image'        => self::get_profile_image( $profile_id ),
            'profile_link' => self::get_profile_url( $profile_id ),
        ];
    }

    /* =========================================================================
       NOTIFICATIONS
    ========================================================================= */

    public static function get_unread_notification_count( int $user_id ): int {
        if ( ! $user_id ) return 0;
        return (int) ( new NEXORA_Notification() )->get_unread_count( $user_id );
    }

    /* =========================================================================
       IMAGE HELPERS
    ========================================================================= */

    public static function get_image_url(
        int    $profile_id,
        string $meta_key,
        string $option_key = 'default_document_image'
    ): string {

        if ( $profile_id && $meta_key ) {
            $image_id = (int) get_post_meta( $profile_id, $meta_key, true );
            if ( $image_id ) {
                $url = wp_get_attachment_url( $image_id );
                if ( $url ) return $url;
            }
        }

        $default_id = (int) get_option( $option_key );
        return $default_id ? (string) wp_get_attachment_url( $default_id ) : '';
    }

    public static function get_profile_image( int $profile_id ): string {
        return self::get_image_url( $profile_id, 'profile_image', 'default_profile_image' );
    }

    /* =========================================================================
       URL HELPERS
    ========================================================================= */

    public static function get_profile_url( int $profile_id ): string {
        $username = get_post_meta( $profile_id, 'user_name', true );
        return $username
            ? site_url( '/dashboard/' . rawurlencode( $username ) )
            : site_url( '/dashboard/' );
    }

    /* =========================================================================
       PRIVATE HELPERS
    ========================================================================= */

    private static function cpt_to_role( string $cpt ): string {
        // Deliberately map to 'user'/'vendor' — NOT the WP role slug ('subscriber').
        // Everything in this class compares against 'user' and 'vendor' only.
        $explicit = [
            'user_profile'   => 'user',
            'vendor_profile' => 'vendor',
        ];
        return $explicit[ $cpt ] ?? 'user';
    }
}
