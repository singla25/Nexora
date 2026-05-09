<?php
/**
 * class-market-db.php
 *
 * Schema definitions for all marketplace custom tables.
 * Uses dbDelta() so it is safe to call on every load —
 * actual SQL is only run when the stored version is behind.
 *
 * Tables:
 *   nx_products        — master product catalogue
 *   nx_api_sources     — vendor API connection records
 *   nx_orders          — lightweight WooCommerce order mirror
 *   nx_earnings        — monthly earnings per vendor
 *   nx_activity_log    — append-only audit trail
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_DB {

    const DB_VERSION     = '1.1.0';
    const DB_VERSION_KEY = 'nexora_market_db_version';

    /* =========================================================
       PUBLIC API
    ========================================================= */

    /**
     * Run install only when the stored version is behind.
     * Called on plugins_loaded (priority 5) and on activation.
     */
    public static function install(): void {

        if ( version_compare( get_option( self::DB_VERSION_KEY, '0' ), self::DB_VERSION, '>=' ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::create_products_table();
        self::create_api_sources_table();
        self::create_orders_table();
        self::create_earnings_table();
        self::create_activity_log_table();

        update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
    }

    /**
     * Drop all tables — only call from uninstall.php.
     */
    public static function uninstall(): void {

        global $wpdb;

        $tables = [
            'nx_activity_log',
            'nx_earnings',
            'nx_orders',
            'nx_api_sources',
            'nx_products',
        ];

        foreach ( $tables as $t ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`" );
        }

        delete_option( self::DB_VERSION_KEY );
    }

    /* =========================================================
       TABLE DEFINITIONS
    ========================================================= */

    private static function create_products_table(): void {

        global $wpdb;
        $t  = $wpdb->prefix . 'nx_products';
        $ch = $wpdb->get_charset_collate();

        // NOTE: dbDelta requires two spaces before column constraints,
        // and a blank line between column definitions and indexes.
        $sql = "CREATE TABLE {$t} (
            id                 BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
            wc_product_id      BIGINT UNSIGNED              DEFAULT NULL,
            owner_user_id      BIGINT UNSIGNED     NOT NULL,
            owner_profile_id   BIGINT UNSIGNED              DEFAULT NULL,
            owner_role         VARCHAR(50)         NOT NULL DEFAULT 'user',
            title              VARCHAR(255)        NOT NULL,
            slug               VARCHAR(255)                 DEFAULT NULL,
            description        LONGTEXT                     DEFAULT NULL,
            short_description  TEXT                         DEFAULT NULL,
            price              DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
            sale_price         DECIMAL(12,2)                DEFAULT NULL,
            stock_qty          INT(11)             NOT NULL DEFAULT 0,
            sku                VARCHAR(150)                 DEFAULT NULL,
            category           VARCHAR(150)                 DEFAULT NULL,
            tags               TEXT                         DEFAULT NULL,
            product_type       VARCHAR(50)         NOT NULL DEFAULT 'simple',
            source_type        VARCHAR(50)         NOT NULL DEFAULT 'manual',
            api_source_id      BIGINT UNSIGNED              DEFAULT NULL,
            external_id        VARCHAR(255)                 DEFAULT NULL,
            image_url          TEXT                         DEFAULT NULL,
            gallery            TEXT                         DEFAULT NULL,
            status             VARCHAR(50)         NOT NULL DEFAULT 'active',
            last_synced_at     DATETIME                     DEFAULT NULL,
            created_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY owner_user_id  (owner_user_id),
            KEY wc_product_id  (wc_product_id),
            KEY api_source_id  (api_source_id),
            KEY external_id    (external_id(40)),
            KEY status         (status)
        ) {$ch};";

        dbDelta( $sql );
    }

    private static function create_api_sources_table(): void {

        global $wpdb;
        $t  = $wpdb->prefix . 'nx_api_sources';
        $ch = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$t} (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id          BIGINT UNSIGNED  NOT NULL,
            label            VARCHAR(255)              DEFAULT NULL,
            endpoint_url     TEXT             NOT NULL,
            api_key          LONGTEXT                  DEFAULT NULL,
            api_secret       LONGTEXT                  DEFAULT NULL,
            webhook_secret   LONGTEXT                  DEFAULT NULL,
            sync_method      VARCHAR(50)      NOT NULL DEFAULT 'cron',
            sync_interval    VARCHAR(40)               DEFAULT 'hourly',
            last_synced_at   DATETIME                  DEFAULT NULL,
            last_status      VARCHAR(40)               DEFAULT NULL,
            last_error       TEXT                      DEFAULT NULL,
            status           VARCHAR(50)      NOT NULL DEFAULT 'active',
            created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id      (user_id),
            KEY sync_method  (sync_method),
            KEY status       (status)
        ) {$ch};";

        dbDelta( $sql );
    }

    private static function create_orders_table(): void {

        global $wpdb;
        $t  = $wpdb->prefix . 'nx_orders';
        $ch = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$t} (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            wc_order_id      BIGINT UNSIGNED  NOT NULL,
            buyer_id         BIGINT UNSIGNED  NOT NULL,
            seller_id        BIGINT UNSIGNED  NOT NULL,
            product_id       BIGINT UNSIGNED  NOT NULL,
            quantity         INT(11)          NOT NULL DEFAULT 1,
            total            DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
            platform_fee     DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
            seller_net       DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
            order_status     VARCHAR(50)      NOT NULL DEFAULT 'pending',
            payment_status   VARCHAR(50)      NOT NULL DEFAULT 'pending',
            created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   ux_wc_product (wc_order_id, product_id),
            KEY buyer_id    (buyer_id),
            KEY seller_id   (seller_id),
            KEY product_id  (product_id),
            KEY order_status (order_status)
        ) {$ch};";

        dbDelta( $sql );
    }

    private static function create_earnings_table(): void {

        global $wpdb;
        $t  = $wpdb->prefix . 'nx_earnings';
        $ch = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$t} (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            vendor_id        BIGINT UNSIGNED  NOT NULL,
            period           VARCHAR(7)       NOT NULL,
            order_count      INT(11)          NOT NULL DEFAULT 0,
            gross            DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
            platform_fee     DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
            net              DECIMAL(14,2)    NOT NULL DEFAULT 0.00,
            payout_status    VARCHAR(50)      NOT NULL DEFAULT 'pending',
            payout_ref       VARCHAR(120)              DEFAULT NULL,
            payout_date      DATE                      DEFAULT NULL,
            created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   ux_vendor_period (vendor_id, period),
            KEY vendor_id    (vendor_id),
            KEY period       (period),
            KEY payout_status (payout_status)
        ) {$ch};";

        dbDelta( $sql );
    }

    private static function create_activity_log_table(): void {

        global $wpdb;
        $t  = $wpdb->prefix . 'nx_activity_log';
        $ch = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$t} (
            id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED  NOT NULL,
            action_type  VARCHAR(60)      NOT NULL,
            label        VARCHAR(255)              DEFAULT NULL,
            meta         LONGTEXT                  DEFAULT NULL,
            ip_address   VARCHAR(45)               DEFAULT NULL,
            created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id      (user_id),
            KEY action_type  (action_type),
            KEY created_at   (created_at)
        ) {$ch};";

        dbDelta( $sql );
    }
}

