<?php
/**
 * includes/class-market-helper.php
 *
 * Pure static utility methods — no direct DB calls (use NEXORA_MARKET_DB),
 * no hooks, no side-effects.
 *
 * Covers:
 *   - Encryption / decryption for API keys
 *   - Webhook HMAC verification
 *   - WP-Cron scheduling helpers
 *   - Price formatter
 *   - Owner role resolver
 *   - Convenience proxy methods that delegate to NEXORA_MARKET_DB
 *     (so templates don't need to know about the DB class)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_HELPER {

    /* =========================================================
       OWNER / ROLE
    ========================================================= */

    /**
     * Resolve the marketplace role for the current user.
     *
     * @return string  'admin' | 'vendor' | 'user'
     */
    public static function current_user_role(): string {
        $user = wp_get_current_user();
        if ( current_user_can( 'manage_options' ) ) return 'admin';
        if ( in_array( 'vendor', (array) $user->roles, true ) ) return 'vendor';
        return 'user';
    }

    /**
     * Resolve owner_role for a given WP user.
     *
     * @param  int    $user_id  0 = current user
     * @return string
     */
    public static function resolve_owner_role( int $user_id = 0 ): string {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        if ( ! $user ) return 'user';
        if ( user_can( $user, 'manage_options' ) ) return 'admin';
        if ( in_array( 'vendor', (array) $user->roles, true ) ) return 'vendor';
        return 'user';
    }

    /* =========================================================
       ENCRYPTION  (API keys stored in DB)
    ========================================================= */

    /**
     * Encrypt a string using WordPress AUTH_KEY as the passphrase.
     * Returns base64-encoded ciphertext.
     * Falls back to base64 obfuscation when OpenSSL is unavailable.
     *
     * @param  string $plaintext
     * @return string
     */
    public static function encrypt( string $plaintext ): string {
        if ( empty( $plaintext ) ) return '';

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $plaintext ); // fallback — not true encryption
        }

        $key    = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );

        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a string previously encrypted with self::encrypt().
     *
     * @param  string $ciphertext
     * @return string
     */
    public static function decrypt( string $ciphertext ): string {
        if ( empty( $ciphertext ) ) return '';

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $ciphertext );
        }

        $decoded = base64_decode( $ciphertext );
        $iv      = substr( $decoded, 0, 16 );
        $data    = substr( $decoded, 16 );
        $key     = substr( hash( 'sha256', AUTH_KEY, true ), 0, 32 );
        $result  = openssl_decrypt( $data, 'AES-256-CBC', $key, 0, $iv );

        return $result !== false ? $result : '';
    }

    /* =========================================================
       WEBHOOK VERIFICATION
    ========================================================= */

    /**
     * Verify an incoming webhook payload against its HMAC signature.
     *
     * Vendors should send:  X-Nexora-Signature: sha256=<hex_digest>
     * (same convention as GitHub / Shopify webhooks)
     *
     * @param  string $raw_body
     * @param  string $received_sig  Value of the X-Nexora-Signature header.
     * @param  string $secret        Stored webhook_secret for this source.
     * @return bool
     */
    public static function verify_webhook_signature( string $raw_body, string $received_sig, string $secret ): bool {
        if ( empty( $secret ) || empty( $received_sig ) ) return false;

        // Strip "sha256=" prefix if present
        // $hash     = ltrim( $received_sig, 'sha256=' );
        $hash = str_starts_with( $received_sig, 'sha256=' ) ? substr( $received_sig, 7 ) : $received_sig;
        $expected = hash_hmac( 'sha256', $raw_body, $secret );

        return hash_equals( $expected, $hash );
    }

    /* =========================================================
       WP-CRON SCHEDULING
    ========================================================= */

    /**
     * Schedule a recurring sync for one API source.
     * Hook name: nexora_market_cron_sync_{$source_id}
     *
     * @param int    $source_id
     * @param string $interval  WP cron recurrence slug (hourly, twicedaily, daily)
     */
    public static function schedule_api_sync( int $source_id, string $interval = 'hourly' ): void {
        $hook = "nexora_market_cron_sync_{$source_id}";

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), $interval, $hook );
        }

        add_action( $hook, function() use ( $source_id ) {
            NEXORA_MARKET_API::sync_source( $source_id );
        } );
    }

    /**
     * Unschedule a cron job for a source (called on source deletion).
     *
     * @param int $source_id
     */
    public static function unschedule_api_sync( int $source_id ): void {
        $hook      = "nexora_market_cron_sync_{$source_id}";
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }

    /**
     * Re-register all active cron hooks on every init.
     * Called from NEXORA_MARKET_CORE::boot_crons().
     */
    public static function register_cron_hooks(): void {
        $table = $GLOBALS['wpdb']->prefix . 'nx_api_sources';
        if ( ! NEXORA_MARKET_DB::table_exists( $table ) ) return;

        foreach ( NEXORA_MARKET_DB::get_active_cron_sources() as $source ) {
            self::schedule_api_sync( (int) $source['id'], $source['sync_interval'] ?? 'hourly' );
        }
    }

    /* =========================================================
       FORMATTING
    ========================================================= */

    /**
     * Format a price for display.
     *
     * @param  float  $amount
     * @param  string $symbol  Currency symbol (default ₹)
     * @return string          e.g. "₹1,299.00"
     */
    public static function format_price( float $amount, string $symbol = '₹' ): string {
        return $symbol . number_format( $amount, 2 );
    }

    /* =========================================================
       CONVENIENCE PROXIES → NEXORA_MARKET_DB
       Templates and other classes can call these without importing
       the DB class directly, keeping the API surface consistent.
    ========================================================= */

    public static function get_product( int $id ): ?array {
        return NEXORA_MARKET_DB::get_product( $id );
    }

    public static function get_products( int $exclude_user_id = 0, int $limit = 40, int $offset = 0 ): array {
        return NEXORA_MARKET_DB::get_products( $exclude_user_id, $limit, $offset );
    }

    public static function get_my_products( int $user_id ): array {
        return NEXORA_MARKET_DB::get_my_products( $user_id );
    }

    public static function get_purchases( int $user_id, int $limit = 50 ): array {
        return NEXORA_MARKET_DB::get_purchases( $user_id, $limit );
    }

    public static function get_sales( int $user_id, int $limit = 50 ): array {
        return NEXORA_MARKET_DB::get_sales( $user_id, $limit );
    }

    public static function get_earnings( int $vendor_id, int $limit = 24 ): array {
        return NEXORA_MARKET_DB::get_earnings( $vendor_id, $limit );
    }

    public static function get_activity( int $user_id, int $limit = 40 ): array {
        return NEXORA_MARKET_DB::get_activity( $user_id, $limit );
    }

    public static function log_activity( int $user_id, string $action_type, array $meta = [] ): void {
        NEXORA_MARKET_DB::log_activity( $user_id, $action_type, $meta );
    }

    public static function upsert_earnings( int $vendor_id, string $period, float $gross, float $fee_pct = 10.0 ): void {
        NEXORA_MARKET_DB::upsert_earnings( $vendor_id, $period, $gross, $fee_pct );
    }

    public static function table_exists( string $table_name ): bool {
        return NEXORA_MARKET_DB::table_exists( $table_name );
    }
}
