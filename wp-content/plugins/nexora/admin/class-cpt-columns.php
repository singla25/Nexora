<?php
/**
 * admin/class-cpt-columns.php
 *
 * Adds custom columns to WP admin list tables.
 *
 * ── Adding columns for a new CPT ──────────────────────────────────────────
 *   1. Add a register_*() method following the pattern below.
 *   2. Call it from the constructor.
 *   Done — no other file needs to change.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_CPT_Columns {

    public function __construct() {
        $this->register_user_profile_columns();
        $this->register_connections_columns();
        $this->register_user_content_columns();
    }

    /* =========================================================================
       REGISTRATION
    ========================================================================= */

    private function register_user_profile_columns(): void {
        add_filter( 'manage_user_profile_posts_columns',        [ $this, 'add_name_column' ] );
        add_action( 'manage_user_profile_posts_custom_column',  [ $this, 'render_name_column' ], 10, 2 );
    }

    private function register_connections_columns(): void {
        add_filter( 'manage_user_connections_posts_columns',        [ $this, 'add_status_column' ] );
        add_action( 'manage_user_connections_posts_custom_column',  [ $this, 'render_status_column' ], 10, 2 );
    }

    private function register_user_content_columns(): void {
        add_filter( 'manage_user_content_posts_columns',        [ $this, 'add_username_column' ] );
        add_action( 'manage_user_content_posts_custom_column',  [ $this, 'render_username_column' ], 10, 2 );
    }

    /* =========================================================================
       USER PROFILE — Name column
    ========================================================================= */

    public function add_name_column( array $columns ): array {
        return $this->insert_after( $columns, 'title', 'user_full_name', 'Name' );
    }

    public function render_name_column( string $column, int $post_id ): void {
        if ( $column !== 'user_full_name' ) return;

        echo esc_html(
            trim(
                get_post_meta( $post_id, 'first_name', true ) . ' ' .
                get_post_meta( $post_id, 'last_name',  true )
            )
        );
    }

    /* =========================================================================
       USER CONNECTIONS — Status column
    ========================================================================= */

    public function add_status_column( array $columns ): array {
        return $this->insert_after( $columns, 'title', 'connection_status', 'Status' );
    }

    public function render_status_column( string $column, int $post_id ): void {
        if ( $column !== 'connection_status' ) return;

        $status = get_post_meta( $post_id, 'status', true ) ?: 'pending';

        $map = [
            'accepted' => [ 'color' => 'green',   'label' => 'Accepted' ],
            'rejected' => [ 'color' => 'red',     'label' => 'Rejected' ],
            'removed'  => [ 'color' => '#374151', 'label' => 'Removed'  ],
            'pending'  => [ 'color' => 'orange',  'label' => 'Pending'  ],
        ];

        $style = $map[ $status ] ?? $map['pending'];

        printf(
            '<span style="color:%s;font-weight:600;">%s</span>',
            esc_attr( $style['color'] ),
            esc_html( $style['label'] )
        );
    }

    /* =========================================================================
       USER CONTENT — Username column
    ========================================================================= */

    public function add_username_column( array $columns ): array {
        return $this->insert_after( $columns, 'title', 'content_user_name', 'Author Profile' );
    }

    public function render_username_column( string $column, int $post_id ): void {
        if ( $column !== 'content_user_name' ) return;

        $profile_id = (int) get_post_meta( $post_id, 'user_profile_id', true );

        echo esc_html(
            trim(
                get_post_meta( $profile_id, 'first_name', true ) . ' ' .
                get_post_meta( $profile_id, 'last_name',  true )
            )
        );
    }

    /* =========================================================================
       PRIVATE HELPERS
    ========================================================================= */

    /**
     * Insert a new column immediately after a given column key.
     *
     * @param array  $columns   Existing columns array
     * @param string $after     Key of the column to insert after
     * @param string $new_key   Key for the new column
     * @param string $new_label Label for the new column
     * @return array
     */
    private function insert_after( array $columns, string $after, string $new_key, string $new_label ): array {

        $result = [];

        foreach ( $columns as $key => $value ) {
            $result[ $key ] = $value;
            if ( $key === $after ) {
                $result[ $new_key ] = $new_label;
            }
        }

        return $result;
    }
}
