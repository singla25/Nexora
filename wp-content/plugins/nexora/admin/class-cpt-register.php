<?php
/**
 * admin/class-cpt-register.php
 *
 * Orchestrator — owns ALL WordPress hooks for the admin module.
 * No HTML rendering lives here. Delegates to:
 *   NEXORA_Metabox_User, NEXORA_Metabox_Vendor  — metabox HTML
 *   NEXORA_Admin_Pages                           — page HTML
 *   NEXORA_CPT_Columns                           — list table columns
 *
 * ── Adding a new CPT ──────────────────────────────────────────────────────
 *   1. add_action( 'init' ) calls register_cpt() — add post type there.
 *   2. add_meta_boxes()     — register the new metaboxes.
 *   3. SAVE_FIELDS          — add the new CPT slug + its field keys.
 *   4. INT_FIELDS           — add any attachment/integer fields.
 *   Done. Everything else is automatic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_CPT_Register {

    /* =========================================================================
       FIELD REGISTRY
       Single source of truth for which fields belong to which CPT.
       Used by save_meta_boxes() — no more giant if/else chains.
    ========================================================================= */

    /**
     * Maps CPT post_type → array of meta keys to save.
     * Add a new CPT here and its fields save automatically.
     */
    private const SAVE_FIELDS = [

        'user_profile' => [
            // Personal
            'first_name', 'last_name', 'phone', 'linkedin_id', 'bio', 'gender', 'birthdate',
            // Address
            'perm_address', 'perm_city', 'perm_state', 'perm_pincode',
            'corr_address', 'corr_city', 'corr_state', 'corr_pincode',
            // Work
            'company_name', 'designation', 'company_email', 'company_phone', 'company_address',
            // Documents
            'profile_image', 'cover_image', 'aadhaar_card', 'driving_license', 'company_id_card',
        ],

        'vendor_profile' => [
            // Personal
            'first_name', 'last_name', 'phone', 'linkedin_id', 'bio', 'gender', 'birthdate',
            // Address
            'perm_address', 'perm_city', 'perm_state', 'perm_pincode',
            'corr_address', 'corr_city', 'corr_state', 'corr_pincode',
            // Business
            'business_name', 'business_type', 'business_email', 'business_phone',
            'business_address', 'gst_number', 'business_category',
            'service_areas', 'years_in_business', 'website_url',
            // Documents
            'profile_image', 'cover_image', 'aadhaar_card', 'company_id_card',
            'gst_certificate', 'business_license', 'pan_card', 'bank_proof',
        ],

        'user_connections' => [
            'sender_user_id', 'sender_profile_id', 'sender_user_name',
            'receiver_user_id', 'receiver_profile_id', 'receiver_user_name',
            'status',
        ],

        'user_content' => [
            'user_id', 'user_profile_id', 'user_name',
        ],
    ];

    /**
     * Fields that store integer IDs (attachment IDs, user IDs).
     * These are saved with absint() instead of sanitize_text_field().
     */
    private const INT_FIELDS = [
        'profile_image', 'cover_image', 'aadhaar_card',
        'driving_license', 'company_id_card',
        'gst_certificate', 'business_license', 'pan_card', 'bank_proof',
        'years_in_business',
        'user_id', 'user_profile_id',
        'sender_user_id', 'sender_profile_id',
        'receiver_user_id', 'receiver_profile_id',
    ];

    /* =========================================================================
       DEPENDENCIES
    ========================================================================= */

    private NEXORA_Metabox_User   $meta_user;
    private NEXORA_Metabox_Vendor $meta_vendor;
    private NEXORA_Admin_Pages    $pages;

    public function __construct() {

        $this->meta_user   = new NEXORA_Metabox_User();
        $this->meta_vendor = new NEXORA_Metabox_Vendor();
        $this->pages       = new NEXORA_Admin_Pages();

        // Columns class boots itself in its own constructor
        new NEXORA_CPT_Columns();

        // Hooks
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_menu',            [ $this, 'register_main_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'init',                  [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',        [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',             [ $this, 'save_meta_boxes' ] );
    }

    /* =========================================================================
       ASSETS
    ========================================================================= */

    public function enqueue_assets(): void {

        wp_enqueue_media();

        wp_enqueue_script(
            'nexora-admin-js',
            NEXORA_URL . 'admin/assets/js/nexora-admin.js',
            [ 'jquery' ],
            null,
            true
        );
    }

    /* =========================================================================
       MENU
    ========================================================================= */

    public function register_main_menu(): void {

        add_menu_page(
            'Nexora System',
            'Nexora System',
            'manage_options',
            'nexora-system',
            [ $this->pages, 'settings_page' ],
            'dashicons-groups',
            5
        );

        add_submenu_page(
            'nexora-system',
            'Notifications',
            'Notifications',
            'manage_options',
            'nexora-notifications',
            [ $this->pages, 'notifications_page' ]
        );

        add_submenu_page(
            'nexora-system',
            'Nexora Chat',
            'Nexora Chat',
            'manage_options',
            'nexora-chat',
            [ $this->pages, 'chat_page' ]
        );

        add_submenu_page(
            'nexora-system',
            'Settings',
            'Settings',
            'manage_options',
            'nexora-system',
            [ $this->pages, 'settings_page' ]
        );
    }

    /* =========================================================================
       SETTINGS
    ========================================================================= */

    public function register_settings(): void {

        $text_options = [
            'default_profile_image',
            'default_cover_image',
            'default_document_image',
            'default_home_cover_image',
            'default_feed_experience_image',
            'default_real_time_chat_image',
            'default_smart_connections_image',
            'default_admin_mail',
            'recaptcha_site_key',
            'recaptcha_secret_key',
        ];

        foreach ( $text_options as $option ) {
            register_setting( 'profile_settings_group', $option );
        }

        register_setting( 'profile_settings_group', 'recaptcha_enabled', [
            'type'              => 'boolean',
            'sanitize_callback' => fn( $v ) => $v ? 1 : 0,
        ]);
    }

    /* =========================================================================
       CPT REGISTRATION
    ========================================================================= */

    public function register_cpt(): void {

        register_post_type( 'user_profile', [
            'label'        => 'User Profiles',
            'public'       => true,
            'show_ui'      => true,
            'supports'     => [ 'title', 'thumbnail' ],
            'show_in_menu' => 'nexora-system',
            'menu_icon'    => 'dashicons-groups',
        ]);

        register_post_type( 'vendor_profile', [
            'label'        => 'Vendor Profiles',
            'public'       => false,
            'show_ui'      => true,
            'supports'     => [ 'title', 'thumbnail' ],
            'show_in_menu' => 'nexora-system',
            'menu_icon'    => 'dashicons-store',
        ]);

        register_post_type( 'user_connections', [
            'label'        => 'User Connections',
            'public'       => false,
            'show_ui'      => true,
            'supports'     => [ 'title' ],
            'show_in_menu' => 'nexora-system',
            'menu_icon'    => 'dashicons-networking',
        ]);

        register_post_type( 'user_content', [
            'label'        => 'User Content',
            'public'       => false,
            'show_ui'      => true,
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'show_in_menu' => 'nexora-system',
            'menu_icon'    => 'dashicons-media-document',
        ]);
    }

    /* =========================================================================
       METABOX REGISTRATION
    ========================================================================= */

    public function add_meta_boxes(): void {

        // ── user_profile ──────────────────────────────────────
        $u = $this->meta_user;
        $user_boxes = [
            [ 'user_personal_details',  'Personal Details',    [ $u, 'personal_details'  ] ],
            [ 'user_address_details',   'Address Details',     [ $u, 'address_details'   ] ],
            [ 'user_work_details',      'Work Details',        [ $u, 'work_details'      ] ],
            [ 'user_document_details',  'Document Details',    [ $u, 'document_details'  ] ],
            [ 'user_connection_details','Connection Overview', [ $u, 'connection_details'] ],
            [ 'user_content_details',   'Content Overview',   [ $u, 'content_details'   ] ],
            [ 'user_chat_details',      'Chat Overview',       [ $u, 'chat_details'      ] ],
        ];

        foreach ( $user_boxes as [ $id, $title, $cb ] ) {
            add_meta_box( $id, $title, $cb, 'user_profile' );
        }

        // ── user_connections ──────────────────────────────────
        add_meta_box(
            'user_connection_meta_box',
            'Connection Details',
            [ $this, 'render_connection_meta_box' ],
            'user_connections'
        );

        add_meta_box(
            'user_connection_chat_box',
            'Connection Chat Threads',
            [ $this, 'render_connection_chat_box' ],
            'user_connections'
        );

        // ── user_content ──────────────────────────────────────
        add_meta_box(
            'user_content_meta_box',
            'Content Info',
            [ $this, 'render_content_meta_box' ],
            'user_content'
        );

        // ── vendor_profile ────────────────────────────────────
        $v = $this->meta_vendor;
        $vendor_boxes = [
            [ 'vendor_personal_details', 'Personal Details', [ $v, 'personal_details' ] ],
            [ 'vendor_address_details',  'Address Details',  [ $v, 'address_details'  ] ],
            [ 'vendor_business_details', 'Business Details', [ $v, 'business_details' ] ],
            [ 'vendor_document_details', 'Document Details', [ $v, 'document_details' ] ],
        ];

        foreach ( $vendor_boxes as [ $id, $title, $cb ] ) {
            add_meta_box( $id, $title, $cb, 'vendor_profile' );
        }
    }

    /* =========================================================================
       INLINE METABOX RENDERERS
       Small, self-contained boxes that don't warrant their own class method.
    ========================================================================= */

    /** Connection CPT detail fields (editable). */
    public function render_connection_meta_box( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );

        $rows = [
            'sender_user_id'     => [ 'number', 'Sender User ID' ],
            'sender_profile_id'  => [ 'number', 'Sender Profile ID' ],
            'sender_user_name'   => [ 'text',   'Sender Username' ],
            'receiver_user_id'   => [ 'number', 'Receiver User ID' ],
            'receiver_profile_id'=> [ 'number', 'Receiver Profile ID' ],
            'receiver_user_name' => [ 'text',   'Receiver Username' ],
        ];
        ?>

        <table class="form-table">

            <?php foreach ( $rows as $key => [ $type, $label ] ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <input type="<?php echo esc_attr( $type ); ?>"
                               name="<?php echo esc_attr( $key ); ?>"
                               value="<?php echo $m( $key ); ?>"
                               class="widefat">
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <th>Status</th>
                <td>
                    <select name="status" class="widefat">
                        <?php
                        $current = get_post_meta( $post->ID, 'status', true );
                        foreach ( [ 'pending', 'accepted', 'rejected', 'removed' ] as $s ) {
                            printf(
                                '<option value="%s" %s>%s</option>',
                                esc_attr( $s ),
                                selected( $current, $s, false ),
                                esc_html( ucfirst( $s ) )
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>

        </table>

        <?php
    }

    /** Connection CPT — chat threads overview (read-only). */
    public function render_connection_chat_box( WP_Post $post ): void {

        $threads = ( new NEXORA_CHAT_DB() )->get_threads_by_connection( $post->ID );

        echo '<h3>Connection Chat Threads</h3>';

        if ( ! $threads ) {
            echo '<p>No threads found.</p>';
            return;
        }

        echo '<table class="widefat striped" style="font-size:13px;">
              <thead><tr>
                <th>Thread ID</th><th>Users</th><th>Subject</th><th>Status</th><th>Action</th>
              </tr></thead><tbody>';

        foreach ( $threads as $thread ) {

            $user_ids = explode( ',', $thread->participants );
            $u1 = ! empty( $user_ids[0] ) ? get_userdata( $user_ids[0] ) : null;
            $u2 = ! empty( $user_ids[1] ) ? get_userdata( $user_ids[1] ) : null;

            $name1      = $u1 ? $u1->display_name : '-';
            $name2      = $u2 ? $u2->display_name : '-';
            $other_user = $user_ids[1] ?? $user_ids[0] ?? 0;

            $color   = $thread->status === 'active' ? '#16a34a' : '#dc2626';
            $subject = $thread->subject ?: 'No Subject';

            printf(
                '<tr>
                    <td>#%s</td>
                    <td>%s &amp; %s</td>
                    <td>%s</td>
                    <td><span style="color:white;background:%s;padding:3px 8px;border-radius:4px;font-size:12px;">%s</span></td>
                    <td>
                        <button type="button" class="button button-primary nexora-open-chat"
                                data-thread="%s" data-user="%s" data-name="%s">
                            View Chat
                        </button>
                    </td>
                </tr>',
                esc_html( $thread->id ),
                esc_html( $name1 ),
                esc_html( $name2 ),
                esc_html( $subject ),
                esc_attr( $color ),
                esc_html( $thread->status ),
                esc_attr( $thread->id ),
                esc_attr( $other_user ),
                esc_attr( $name1 . ' and ' . $name2 )
            );
        }

        echo '</tbody></table>';
    }

    /** User content CPT — meta fields (editable). */
    public function render_content_meta_box( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
        ?>

        <table class="form-table">
            <tr>
                <th>User ID</th>
                <td><input type="number" name="user_id" value="<?php echo $m( 'user_id' ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th>User Profile ID</th>
                <td><input type="number" name="user_profile_id" value="<?php echo $m( 'user_profile_id' ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th>Username</th>
                <td><input type="text" name="user_name" value="<?php echo $m( 'user_name' ); ?>" class="regular-text"></td>
            </tr>
        </table>

        <?php
    }

    /* =========================================================================
       SAVE  —  dispatch table replaces the original if/else chain
    ========================================================================= */

    public function save_meta_boxes( int $post_id ): void {

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $post_type = get_post_type( $post_id );
        $fields    = self::SAVE_FIELDS[ $post_type ] ?? [];

        if ( empty( $fields ) ) return;

        foreach ( $fields as $field ) {

            if ( ! isset( $_POST[ $field ] ) ) continue;

            $value = in_array( $field, self::INT_FIELDS, true )
                ? absint( $_POST[ $field ] )
                : sanitize_text_field( wp_unslash( $_POST[ $field ] ) );

            update_post_meta( $post_id, $field, $value );
        }
    }
}
