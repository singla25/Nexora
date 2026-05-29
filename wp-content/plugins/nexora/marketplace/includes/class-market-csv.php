<?php
/**
 * includes/class-market-csv.php
 *
 * Handles everything CSV-related:
 *   - Parsing an uploaded CSV file into an array of associative rows
 *   - Validating required columns
 *   - Bulk-importing rows into nx_products + WooCommerce
 *   - Streaming a sample CSV template for download
 *
 * Called from: NEXORA_MARKET_AJAX::csv_import()
 * Depends on:  NEXORA_MARKET_DB, NEXORA_MARKET_PRODUCT, NEXORA_MARKET_HELPER
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_MARKET_CSV {

    /** Columns that MUST be present in any imported CSV. */
    const REQUIRED_COLUMNS = [ 'title', 'price' ];

    /** Full list of columns the template ships with (in order). */
    const TEMPLATE_COLUMNS = [
        'title', 'price', 'sale_price', 'stock_qty',
        'sku', 'category', 'tags', 'product_type',
        'description', 'short_desc', 'image_url',
    ];

    /* =========================================================
       PUBLIC: IMPORT
    ========================================================= */

    /**
     * Import products from an uploaded CSV file.
     *
     * Validates the file, parses it, and passes each row through
     * NEXORA_MARKET_PRODUCT::create() so the full create-pipeline
     * (nx_products + WooCommerce) runs for every row.
     *
     * @param  array $file        $_FILES['csv_file'] element.
     * @param  int   $user_id     Owner user ID.
     * @return array {
     *   imported int,
     *   skipped  int,
     *   message  string,
     *   error?   string
     * }
     */
    public static function import( array $file, int $user_id ): array {

        /* ── File validation ──────────────────────────────── */
        if ( empty( $file['tmp_name'] ) ) {
            return [ 'imported' => 0, 'skipped' => 0, 'error' => 'No CSV file uploaded.' ];
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' ) {
            return [ 'imported' => 0, 'skipped' => 0, 'error' => 'Only .csv files are accepted.' ];
        }

        /* ── Parse ────────────────────────────────────────── */
        $rows = self::parse( $file['tmp_name'] );

        if ( empty( $rows ) ) {
            return [ 'imported' => 0, 'skipped' => 0, 'error' => 'CSV is empty or could not be parsed.' ];
        }

        /* ── Column validation ────────────────────────────── */
        $validation_error = self::validate_columns( array_keys( $rows[0] ) );
        if ( $validation_error ) {
            return [ 'imported' => 0, 'skipped' => 0, 'error' => $validation_error ];
        }

        /* ── Resolve owner role once ──────────────────────── */
        $owner_role = NEXORA_MARKET_HELPER::resolve_owner_role( $user_id );

        $imported = 0;
        $skipped  = 0;

        foreach ( $rows as $row ) {
            $result = self::import_row( $row, $user_id, $owner_role );
            $result ? $imported++ : $skipped++;
        }

        NEXORA_MARKET_DB::log_activity( $user_id, 'csv_import', [
            'imported' => $imported,
            'skipped'  => $skipped,
            'file'     => sanitize_text_field( $file['name'] ),
        ] );

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'message'  => "{$imported} product(s) imported" . ( $skipped ? ", {$skipped} row(s) skipped." : '.' ),
        ];
    }

    /* =========================================================
       PUBLIC: TEMPLATE DOWNLOAD
    ========================================================= */

    /**
     * Stream a sample CSV template to the browser.
     * Called from the wp_ajax_nexora_market_csv_template action.
     */
    public static function output_template(): void {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="nexora-products-template.csv"' );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, self::TEMPLATE_COLUMNS );

        // One sample row so the user knows the expected format
        fputcsv( $out, [
            'Sample Product', '999.00', '799.00', '50',
            'SKU-001', 'Electronics', 'gadget,tech', 'simple',
            'Full product description here.', 'Short description.', 'https://example.com/image.jpg',
        ] );

        fclose( $out );
        exit;
    }

    /* =========================================================
       PRIVATE HELPERS
    ========================================================= */

    /**
     * Parse a CSV file into an array of associative rows.
     * First row treated as headers.
     *
     * @param  string $file_path  Absolute path to the uploaded temp file.
     * @return array
     */
    private static function parse( string $file_path ): array {
        $rows   = [];
        $handle = @fopen( $file_path, 'r' );

        if ( ! $handle ) return $rows;

        $headers = fgetcsv( $handle );
        if ( ! $headers || ! is_array( $headers ) ) {
            fclose( $handle );
            return $rows;
        }

        $headers = array_map( 'trim', $headers );

        while ( ( $line = fgetcsv( $handle ) ) !== false ) {
            if ( count( $line ) === count( $headers ) ) {
                $rows[] = array_combine( $headers, $line );
            }
        }

        fclose( $handle );
        return $rows;
    }

    /**
     * Validate that required columns exist in the CSV header.
     *
     * @param  string[] $columns  Parsed header names.
     * @return string|null        Error message or null if valid.
     */
    private static function validate_columns( array $columns ): ?string {
        foreach ( self::REQUIRED_COLUMNS as $required ) {
            if ( ! in_array( $required, $columns, true ) ) {
                return "CSV must have a \"{$required}\" column.";
            }
        }
        return null;
    }

    /**
     * Import a single CSV row.
     *
     * @param  array  $row
     * @param  int    $user_id
     * @param  string $owner_role
     * @return bool   True if the row was imported successfully.
     */
    private static function import_row( array $row, int $user_id, string $owner_role ): bool {
        $title = sanitize_text_field( $row['title'] ?? '' );
        $price = (float) ( $row['price'] ?? 0 );

        // Skip rows missing required fields
        if ( empty( $title ) || $price <= 0 ) return false;

        $sale_raw   = $row['sale_price'] ?? '';
        $sale_price = strlen( trim( $sale_raw ) ) ? (float) $sale_raw : null;

        $nx_id = NEXORA_MARKET_PRODUCT::create( [
            'owner_user_id'     => $user_id,
            'owner_role'        => $owner_role,
            'title'             => $title,
            'price'             => $price,
            'sale_price'        => $sale_price,
            'stock_qty'         => (int) ( $row['stock_qty']    ?? 0 ),
            'sku'               => sanitize_text_field( $row['sku']          ?? '' ),
            'category'          => sanitize_text_field( $row['category']     ?? '' ),
            'tags'              => sanitize_text_field( $row['tags']         ?? '' ),
            'product_type'      => sanitize_text_field( $row['product_type'] ?? 'simple' ),
            'description'       => sanitize_textarea_field( $row['description']   ?? '' ),
            'short_description' => sanitize_textarea_field( $row['short_desc']    ?? '' ),
            'image_url'         => esc_url_raw( $row['image_url']                 ?? '' ),
            'source_type'       => 'csv',
        ] );

        return (bool) $nx_id;
    }
}
