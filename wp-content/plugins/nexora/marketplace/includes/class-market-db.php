<?php
/**
 * includes/class-market-db.php
 *
 * Centralised database layer.
 *
 * Two responsibilities:
 *   1. Schema management (install / uninstall via dbDelta)
 *   2. Reusable CRUD helpers called by Product, Ajax, Helper, WooCommerce
 *
 * RULE: raw $wpdb calls belong here; all other classes call these methods.
 *
 * Tables managed:
 *   nx_products        — master product catalogue
 *   nx_api_sources     — vendor API connection records
 *   nx_orders          — lightweight WooCommerce order mirror
 *   nx_earnings        — monthly earnings per vendor
 *   nx_activity_log    — append-only audit trail
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_DB {

    const DB_VERSION     = '2.0.0';
    const DB_VERSION_KEY = 'nexora_market_db_version';

    /* =========================================================
       SCHEMA MANAGEMENT
    ========================================================= */

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

    public static function uninstall(): void {
        global $wpdb;
        foreach ( [ 'nx_activity_log', 'nx_earnings', 'nx_orders', 'nx_api_sources', 'nx_products' ] as $t ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`" );
        }
        delete_option( self::DB_VERSION_KEY );
    }

    public static function table_exists( string $table_name ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }

    /* =========================================================
       PRODUCTS — INSERT / UPDATE / SOFT-DELETE / SELECT
    ========================================================= */

    /**
     * Insert one product row. Returns new nx_products.id or false.
     *
     * @param array $data  See NEXORA_MARKET_PRODUCT::create() for full spec.
     * @return int|false
     */
    public static function insert_product( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'nx_products';

        $row = [
            'owner_user_id'     => (int) $data['owner_user_id'],
            'owner_profile_id'  => (int) ( $data['owner_profile_id'] ?? 0 ),
            'owner_role'        => sanitize_text_field( $data['owner_role']        ?? 'user' ),
            'title'             => sanitize_text_field( $data['title'] ),
            'slug'              => sanitize_title( $data['title'] ) . '-' . uniqid(),
            'description'       => wp_kses_post( $data['description']              ?? '' ),
            'short_description' => sanitize_textarea_field( $data['short_description'] ?? '' ),
            'price'             => (float) $data['price'],
            'sale_price'        => isset( $data['sale_price'] ) && $data['sale_price'] !== null
                                        ? (float) $data['sale_price'] : null,
            'stock_qty'         => (int) ( $data['stock_qty']  ?? 0 ),
            'sku'               => sanitize_text_field( $data['sku']               ?? '' ),
            'category'          => sanitize_text_field( $data['category']          ?? '' ),
            'tags'              => sanitize_text_field( $data['tags']              ?? '' ),
            'product_type'      => sanitize_text_field( $data['product_type']      ?? 'simple' ),
            'source_type'       => sanitize_text_field( $data['source_type']       ?? 'manual' ),
            'api_source_id'     => ! empty( $data['api_source_id'] ) ? (int) $data['api_source_id'] : null,
            'external_id'       => sanitize_text_field( $data['external_id']       ?? '' ),
            'image_url'         => esc_url_raw( $data['image_url']                 ?? '' ),
            'gallery'           => $data['gallery']                                ?? '',
            'status'            => sanitize_text_field( $data['status']            ?? 'active' ),
        ];

        $inserted = $wpdb->insert( $table, $row );
        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing product row.
     *
     * @param int   $nx_id
     * @param array $data  Only changed fields needed.
     * @return bool
     */
    public static function update_product( int $nx_id, array $data ): bool {
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'nx_products',
            $data,
            [ 'id' => $nx_id ]
        );
        return $result !== false;
    }

    /**
     * Set wc_product_id on a product row.
     *
     * @param int $nx_id
     * @param int $wc_id
     */
    public static function link_wc_product( int $nx_id, int $wc_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nx_products',
            [ 'wc_product_id' => $wc_id ],
            [ 'id' => $nx_id ]
        );
    }

    /**
     * Soft-delete: set status = 'inactive'.
     *
     * @param int $nx_id
     */
    public static function soft_delete_product( int $nx_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nx_products',
            [ 'status' => 'inactive' ],
            [ 'id' => $nx_id ]
        );
    }

    /**
     * Fetch one product by ID.
     *
     * @param  int        $nx_id
     * @return array|null
     */
    public static function get_product( int $nx_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}nx_products WHERE id = %d",
                $nx_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Fetch all active products not owned by $exclude_user_id (browse view).
     *
     * @param  int   $exclude_user_id
     * @param  int   $limit
     * @param  int   $offset
     * @return array
     */
    public static function get_products( int $exclude_user_id = 0, int $limit = 40, int $offset = 0 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name AS owner_name
                   FROM {$wpdb->prefix}nx_products p
              LEFT JOIN {$wpdb->users} u ON u.ID = p.owner_user_id
                  WHERE p.status = 'active'
                    AND p.owner_user_id != %d
                  ORDER BY p.id DESC
                  LIMIT %d OFFSET %d",
                $exclude_user_id, $limit, $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Fetch all products belonging to one user.
     *
     * @param  int   $user_id
     * @return array
     */
    public static function get_my_products( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}nx_products WHERE owner_user_id = %d ORDER BY id DESC",
                $user_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get wc_product_id for a given nx product.
     *
     * @param  int $nx_id
     * @return int
     */
    public static function get_wc_id( int $nx_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT wc_product_id FROM {$wpdb->prefix}nx_products WHERE id = %d",
                $nx_id
            )
        );
    }

    /**
     * Check if an API-synced product already exists (for upsert logic).
     *
     * @param  int    $user_id
     * @param  int    $source_id
     * @param  string $external_id
     * @return int|null  nx_products.id or null
     */
    public static function find_api_product( int $user_id, int $source_id, string $external_id ): ?int {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}nx_products
                  WHERE owner_user_id = %d AND api_source_id = %d AND external_id = %s",
                $user_id, $source_id, $external_id
            )
        );
        return $id ? (int) $id : null;
    }

    /* =========================================================
       API SOURCES
    ========================================================= */

    /**
     * Insert a new API source record.
     *
     * @param  array    $data
     * @return int|false
     */
    public static function insert_api_source( array $data ) {
        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'nx_api_sources',
            [
                'user_id'        => (int) $data['user_id'],
                'label'          => sanitize_text_field( $data['label']          ?? '' ),
                'endpoint_url'   => esc_url_raw( $data['endpoint_url'] ),
                'api_key'        => $data['api_key']        ?? '',
                'api_secret'     => $data['api_secret']     ?? '',
                'webhook_secret' => $data['webhook_secret'] ?? '',
                'sync_method'    => sanitize_text_field( $data['sync_method']    ?? 'cron' ),
                'sync_interval'  => sanitize_text_field( $data['sync_interval']  ?? 'hourly' ),
                'status'         => 'active',
            ]
        );
        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Fetch one active API source.
     *
     * @param  int        $source_id
     * @return array|null
     */
    public static function get_api_source( int $source_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}nx_api_sources WHERE id = %d AND status = 'active'",
                $source_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Fetch all active cron-sync sources.
     *
     * @return array
     */
    public static function get_active_cron_sources(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, sync_interval FROM {$wpdb->prefix}nx_api_sources
              WHERE status = 'active' AND sync_method IN ('cron','both')",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Update sync status on an API source after a sync attempt.
     *
     * @param int    $source_id
     * @param string $status      'ok' | 'error'
     * @param string $error_msg
     */
    public static function update_api_source_status( int $source_id, string $status, string $error_msg = '' ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nx_api_sources',
            [
                'last_status'    => $status,
                'last_error'     => $error_msg ?: null,
                'last_synced_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $source_id ]
        );
    }

    /* =========================================================
       ORDERS
    ========================================================= */

    /**
     * Insert or update an order row (idempotent upsert).
     *
     * @param array $data
     * @return int|false
     */
    public static function upsert_order( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'nx_orders';

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE wc_order_id = %d AND product_id = %d",
            (int) $data['wc_order_id'], (int) $data['product_id']
        ) );

        if ( $existing_id ) {
            $wpdb->update(
                $table,
                [
                    'order_status'   => sanitize_text_field( $data['order_status'] ),
                    'payment_status' => 'paid',
                ],
                [ 'id' => (int) $existing_id ]
            );
            return (int) $existing_id;
        }

        $inserted = $wpdb->insert( $table, [
            'wc_order_id'    => (int)   $data['wc_order_id'],
            'buyer_id'       => (int)   $data['buyer_id'],
            'seller_id'      => (int)   $data['seller_id'],
            'product_id'     => (int)   $data['product_id'],
            'quantity'       => (int)   $data['quantity'],
            'total'          => (float) $data['total'],
            'platform_fee'   => (float) $data['platform_fee'],
            'seller_net'     => (float) $data['seller_net'],
            'order_status'   => sanitize_text_field( $data['order_status'] ),
            'payment_status' => 'paid',
        ] );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Soft-cancel an order (refund / cancellation).
     *
     * @param int $wc_order_id
     * @param int $nx_product_id
     */
    public static function cancel_order( int $wc_order_id, int $nx_product_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nx_orders',
            [ 'order_status' => 'cancelled', 'payment_status' => 'refunded' ],
            [ 'wc_order_id' => $wc_order_id, 'product_id' => $nx_product_id ]
        );
    }

    /**
     * Purchases made by a buyer.
     *
     * @param  int   $user_id
     * @param  int   $limit
     * @return array
     */
    public static function get_purchases( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.*, p.title AS product_title, p.image_url,
                        u.display_name AS seller_name
                   FROM {$wpdb->prefix}nx_orders o
              LEFT JOIN {$wpdb->prefix}nx_products p ON p.id = o.product_id
              LEFT JOIN {$wpdb->users} u ON u.ID = o.seller_id
                  WHERE o.buyer_id = %d ORDER BY o.id DESC LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Sales made by a seller.
     *
     * @param  int   $user_id
     * @param  int   $limit
     * @return array
     */
    public static function get_sales( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.*, p.title AS product_title, p.image_url,
                        u.display_name AS buyer_name
                   FROM {$wpdb->prefix}nx_orders o
              LEFT JOIN {$wpdb->prefix}nx_products p ON p.id = o.product_id
              LEFT JOIN {$wpdb->users} u ON u.ID = o.buyer_id
                  WHERE o.seller_id = %d ORDER BY o.id DESC LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /* =========================================================
       EARNINGS
    ========================================================= */

    /**
     * All earnings rows for a vendor, newest first.
     *
     * @param  int   $vendor_id
     * @param  int   $limit
     * @return array
     */
    public static function get_earnings( int $vendor_id, int $limit = 24 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}nx_earnings
                  WHERE vendor_id = %d ORDER BY period DESC LIMIT %d",
                $vendor_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Single earnings row for a specific period.
     *
     * @param  int        $vendor_id
     * @param  string     $period    'YYYY-MM'
     * @return array|null
     */
    public static function get_earnings_for_period( int $vendor_id, string $period ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}nx_earnings WHERE vendor_id = %d AND period = %s",
                $vendor_id, $period
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Upsert an earnings row for (vendor, period).
     *
     * @param int    $vendor_id
     * @param string $period    'YYYY-MM'
     * @param float  $gross     Can be negative for refund reversals.
     * @param float  $fee_pct
     */
    public static function upsert_earnings( int $vendor_id, string $period, float $gross, float $fee_pct = 10.0 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'nx_earnings';

        $fee = round( abs( $gross ) * ( $fee_pct / 100 ), 2 ) * ( $gross < 0 ? -1 : 1 );
        $net = round( $gross - $fee, 2 );

        $existing = self::get_earnings_for_period( $vendor_id, $period );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'order_count'  => max( 0, (int) $existing['order_count'] + ( $gross >= 0 ? 1 : -1 ) ),
                    'gross'        => round( (float) $existing['gross']        + $gross, 2 ),
                    'platform_fee' => round( (float) $existing['platform_fee'] + $fee,   2 ),
                    'net'          => round( (float) $existing['net']          + $net,    2 ),
                ],
                [ 'id' => (int) $existing['id'] ]
            );
        } else {
            $wpdb->insert( $table, [
                'vendor_id'     => $vendor_id,
                'period'        => $period,
                'order_count'   => $gross >= 0 ? 1 : 0,
                'gross'         => $gross,
                'platform_fee'  => $fee,
                'net'           => $net,
                'payout_status' => 'pending',
            ] );
        }
    }

    /* =========================================================
       ACTIVITY LOG
    ========================================================= */

    /**
     * Append one activity record.
     *
     * @param int    $user_id
     * @param string $action_type
     * @param array  $meta
     */
    public static function log_activity( int $user_id, string $action_type, array $meta = [] ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'nx_activity_log',
            [
                'user_id'     => $user_id,
                'action_type' => sanitize_text_field( $action_type ),
                'meta'        => wp_json_encode( $meta ),
                'ip_address'  => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            ]
        );
    }

    /**
     * Recent activity rows for a user.
     *
     * @param  int   $user_id
     * @param  int   $limit
     * @return array
     */
    public static function get_activity( int $user_id, int $limit = 40 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT action_type, meta, created_at
                   FROM {$wpdb->prefix}nx_activity_log
                  WHERE user_id = %d ORDER BY id DESC LIMIT %d",
                $user_id, $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /* =========================================================
       TABLE DEFINITIONS (private)
    ========================================================= */

    private static function create_products_table(): void {
        global $wpdb;
        $t  = $wpdb->prefix . 'nx_products';
        $ch = $wpdb->get_charset_collate();
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
