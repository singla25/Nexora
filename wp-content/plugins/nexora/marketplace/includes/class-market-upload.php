<?php
/**
 * includes/class-market-upload.php
 *
 * Handles all product image / media operations:
 *
 *   1. wp.media uploader integration
 *      - Enqueues the WP media library on the marketplace page
 *      - Scopes the media frame to the current user's own uploads
 *        (via the 'ajax_query_attachments_args' filter)
 *      - REST endpoint that the JS calls to confirm a selected
 *        attachment and return its URL
 *
 *   2. Feature image  — single attachment ID stored as image_url on the product
 *   3. Product gallery — JSON array of attachment URLs stored in the gallery column
 *
 *   4. Remote URL sideloading
 *      - Used by the API sync path (attach_from_url)
 *      - Not exposed to end-users
 *
 * JS side:  see assets/market.js  →  NexoraMarketUpload  namespace
 * PHP side: this class is statically bootstrapped by NEXORA_MARKET_CORE
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_UPLOAD {

    /* =========================================================
       BOOTSTRAP — called once from NEXORA_MARKET_CORE
    ========================================================= */

    public static function init(): void {

        // Only enqueue / filter when we're on a front-end page that has
        // the marketplace shell (detected by our own shortcode / filter).
        add_action( 'wp_enqueue_scripts',       [ __CLASS__, 'enqueue_media'       ] );
        add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'scope_to_current_user' ] );

        // REST route: JS → PHP confirm selected attachment
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
    }

    /* =========================================================
       ENQUEUE WP MEDIA UPLOADER
    ========================================================= */

    /**
     * Enqueue the WP media library scripts on the front-end.
     * Only runs if the current user can upload files.
     */
    public static function enqueue_media(): void {
        if ( ! is_user_logged_in() ) return;
        if ( ! current_user_can( 'upload_files' ) ) return;

        wp_enqueue_media();
    }

    /* =========================================================
       SCOPE MEDIA LIBRARY TO CURRENT USER
    ========================================================= */

    /**
     * When a marketplace user opens the media library uploader,
     * they should only see their OWN uploads — not other users'.
     *
     * This filter fires on every 'query-attachments' AJAX call.
     * We gate it to non-admin users only; admins keep the full library.
     *
     * @param  array $query  WP_Query args built by media-upload.php
     * @return array
     */
    public static function scope_to_current_user( array $query ): array {
        if ( current_user_can( 'manage_options' ) ) return $query;

        // Only restrict when the request originates from our uploader
        // (signalled by a custom X-NX-Context header set in market.js).
        $context = sanitize_text_field( $_SERVER['HTTP_X_NX_CONTEXT'] ?? '' );
        if ( $context !== 'marketplace' ) return $query;

        $query['author'] = get_current_user_id();
        return $query;
    }

    /* =========================================================
       REST ROUTES
    ========================================================= */

    /**
     * Register:
     *   POST /wp-json/nexora/v1/media/confirm
     *     Accepts attachment_id(s), returns URL(s).
     *     Used by JS after user picks image(s) in the media frame.
     */
    public static function register_rest_routes(): void {

        register_rest_route( 'nexora/v1', '/media/confirm', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rest_confirm_media' ],
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'args' => [
                'attachment_ids' => [
                    'required'          => true,
                    'type'              => 'array',
                    'items'             => [ 'type' => 'integer', 'minimum' => 1 ],
                    'sanitize_callback' => function( $ids ) {
                        return array_map( 'intval', (array) $ids );
                    },
                ],
                'context' => [
                    'type'              => 'string',
                    'default'           => 'feature',
                    'enum'              => [ 'feature', 'gallery' ],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    /**
     * REST callback: validate attachment ownership and return URL(s).
     *
     * Non-admin users can only confirm attachments they uploaded themselves.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function rest_confirm_media( WP_REST_Request $request ): WP_REST_Response {

        $ids     = $request->get_param( 'attachment_ids' );
        $context = $request->get_param( 'context' );
        $user_id = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );

        $confirmed = [];
        $rejected  = [];

        foreach ( $ids as $attachment_id ) {
            $post = get_post( $attachment_id );

            if ( ! $post || $post->post_type !== 'attachment' ) {
                $rejected[] = $attachment_id;
                continue;
            }

            // Non-admins: only allow own uploads
            if ( ! $is_admin && (int) $post->post_author !== $user_id ) {
                $rejected[] = $attachment_id;
                continue;
            }

            $url = wp_get_attachment_url( $attachment_id );
            if ( ! $url ) {
                $rejected[] = $attachment_id;
                continue;
            }

            $confirmed[] = [
                'id'  => $attachment_id,
                'url' => $url,
            ];
        }

        return new WP_REST_Response( [
            'context'   => $context,
            'confirmed' => $confirmed,
            'rejected'  => $rejected,
        ], 200 );
    }

    /* =========================================================
       FEATURE IMAGE HELPER
    ========================================================= */

    /**
     * Get the feature image URL for a product.
     * Returns image_url from the nx_products row (which is set when
     * the user picks an attachment via wp.media).
     *
     * @param  array  $product  nx_products row
     * @return string           Image URL or empty string
     */
    public static function get_feature_image( array $product ): string {
        return esc_url( $product['image_url'] ?? '' );
    }

    /* =========================================================
       GALLERY HELPER
    ========================================================= */

    /**
     * Decode the gallery JSON column into an array of URLs.
     *
     * @param  array $product  nx_products row
     * @return string[]
     */
    public static function get_gallery_urls( array $product ): array {
        if ( empty( $product['gallery'] ) ) return [];
        $decoded = json_decode( $product['gallery'], true );
        return is_array( $decoded ) ? array_values( array_filter( $decoded ) ) : [];
    }

    /**
     * Encode an array of attachment IDs into a JSON gallery string
     * suitable for storage in nx_products.gallery.
     *
     * @param  int[]   $attachment_ids
     * @return string  JSON array of URLs
     */
    public static function encode_gallery( array $attachment_ids ): string {
        $urls = [];
        foreach ( $attachment_ids as $id ) {
            $url = wp_get_attachment_url( (int) $id );
            if ( $url ) $urls[] = $url;
        }
        return wp_json_encode( $urls );
    }

    /* =========================================================
       REMOTE URL SIDELOADING (API / CSV path)
    ========================================================= */

    /**
     * Sideload a remote image URL into the WP media library and set it
     * as the featured image on a WC product post.
     *
     * Only called internally by NEXORA_MARKET_WOOCOMMERCE — not exposed
     * to end-users (they use wp.media instead).
     *
     * @param  int    $wc_post_id  WooCommerce product post ID.
     * @param  string $url         Remote image URL.
     * @param  string $title       Used as attachment title.
     * @return int|false           Attachment ID or false on failure.
     */
    public static function attach_from_url( int $wc_post_id, string $url, string $title = '' ) {

        if ( empty( $url ) ) return false;

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( esc_url_raw( $url ) );

        if ( is_wp_error( $tmp ) ) return false;

        $file_array = [
            'name'     => sanitize_file_name( wp_basename( $url ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $wc_post_id, sanitize_text_field( $title ) );

        @unlink( $file_array['tmp_name'] );

        if ( is_wp_error( $attachment_id ) ) return false;

        set_post_thumbnail( $wc_post_id, $attachment_id );

        return $attachment_id;
    }
}
