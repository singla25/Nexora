<?php
/**
 * database/class-market-install.php
 *
 * Thin wrapper called on plugin activation.
 * The real schema logic lives in includes/class-market-db.php.
 *
 * Usage (in your main plugin file):
 *   register_activation_hook( __FILE__, [ 'NEXORA_MARKET_INSTALL', 'activate' ] );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_INSTALL {

    /**
     * Run on plugin activation.
     * Flushes rewrite rules so the webhook REST route is immediately available.
     */
    public static function activate(): void {
        NEXORA_MARKET_DB::install();
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     * Does NOT drop tables — data must survive deactivate/reactivate.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
        /* Unschedule all marketplace cron hooks */
        self::clear_crons();
    }

    /**
     * Run on full uninstall (called from uninstall.php, not deactivation).
     */
    public static function uninstall(): void {
        self::clear_crons();
        NEXORA_MARKET_DB::uninstall();
        delete_option( 'nexora_market_db_version' );
    }

    /* ── Private ──────────────────────────────────────────── */

    private static function clear_crons(): void {

        global $wpdb;
        $table = $wpdb->prefix . 'nx_api_sources';

        // Guard if table doesn't exist
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $source_ids = $wpdb->get_col( "SELECT id FROM {$table}" );

        foreach ( (array) $source_ids as $id ) {
            NEXORA_MARKET_HELPER::unschedule_api_sync( (int) $id );
        }
    }
}
