<?php

namespace Nexora\Imports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The only file this class touches is the upload PHP just received (read-only) and an in-memory stream; WP_Filesystem does not apply.
// phpcs:disable WordPress.WP.AlternativeFunctions
/**
 * Reads a vendor's product CSV. The file is read from where PHP put the upload and is never stored anywhere; every
 * check happens here: extension, size, readable text, UTF-8, header, row count. Cells that a spreadsheet would run as
 * formulas (starting with = + - @ tab or carriage return) get a leading apostrophe.
 */
class Csv_Parser {

	const MAX_BYTES = 2 * MB_IN_BYTES;
	const MAX_ROWS  = 500;
	const REQUIRED  = array( 'sku', 'name', 'price', 'stock', 'category' );
	const OPTIONAL  = array( 'description', 'image_url' );

	/**
	 * Parses a file.
	 *
	 * @param string $path          Where the upload is.
	 * @param string $original_name The name the browser gave it (only its extension is used).
	 * @return array|\WP_Error array( rows => [ line, cells ], errors => [ line, message ] )
	 */
	public static function parse( $path, $original_name ) {

		if ( 'csv' !== strtolower( (string) pathinfo( (string) $original_name, PATHINFO_EXTENSION ) ) ) {
			return self::fail( __( 'Only .csv files are accepted.', 'nexora' ) );
		}

		if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return self::fail( __( 'The file could not be read.', 'nexora' ) );
		}

		$size = (int) filesize( $path );

		if ( $size > self::MAX_BYTES ) {
			return self::fail( __( 'The file is larger than 2 MB.', 'nexora' ) );
		}

		$content = (string) file_get_contents( $path, false, null, 0, self::MAX_BYTES + 1 );

		if ( strlen( $content ) > self::MAX_BYTES ) {
			return self::fail( __( 'The file is larger than 2 MB.', 'nexora' ) );
		}

		if ( '' === trim( $content ) ) {
			return self::fail( __( 'The file is empty.', 'nexora' ) );
		}

		if ( false !== strpos( $content, "\0" ) || ! self::looks_like_text( $content ) ) {
			return self::fail( __( 'This is not a text CSV file.', 'nexora' ) );
		}

		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}

		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return self::fail( __( 'The file must be saved as UTF-8.', 'nexora' ) );
		}

		return self::read( $content );
	}

	/**
	 * The text check: an allowed MIME type from the content itself (never the browser's claim).
	 *
	 * @param string $content File content.
	 * @return bool
	 */
	private static function looks_like_text( $content ) {

		if ( ! function_exists( 'finfo_open' ) ) {
			return true;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = $finfo ? (string) finfo_buffer( $finfo, $content ) : 'text/plain';

		return 0 === strpos( $mime, 'text/' ) || in_array( $mime, array( 'application/csv', 'application/vnd.ms-excel', 'inode/x-empty' ), true );
	}

	/**
	 * Splits the text into a header and rows, keeping each row's physical line number.
	 *
	 * @param string $content UTF-8 text.
	 * @return array|\WP_Error
	 */
	private static function read( $content ) {

		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, $content );
		rewind( $stream );

		$header = null;
		$rows   = array();
		$errors = array();
		$seen   = 0;

		while ( true ) {

			$offset = ftell( $stream );
			$record = fgetcsv( $stream, 0, ',', '"', '' );

			if ( false === $record ) {
				break;
			}

			if ( array( null ) === $record ) {
				continue;
			}

			$line = 1 + substr_count( $content, "\n", 0, $offset );

			if ( null === $header ) {

				$header = self::header( $record );

				if ( is_wp_error( $header ) ) {
					fclose( $stream );
					return $header;
				}

				continue;
			}

			++$seen;

			if ( $seen > self::MAX_ROWS ) {
				fclose( $stream );
				/* translators: %d: the row limit. */
				return self::fail( sprintf( __( 'The file has more than %d rows.', 'nexora' ), self::MAX_ROWS ) );
			}

			if ( count( $record ) > count( $header ) ) {
				$errors[] = array(
					'line'    => $line,
					'message' => __( 'This row has more cells than the header.', 'nexora' ),
				);
				continue;
			}

			$cells = array();

			foreach ( $header as $i => $name ) {
				$cells[ $name ] = self::cell( isset( $record[ $i ] ) ? (string) $record[ $i ] : '' );
			}

			$rows[] = array(
				'line'  => $line,
				'cells' => $cells,
			);
		}

		fclose( $stream );

		if ( 0 === $seen ) {
			return self::fail( __( 'The file has a header but no products.', 'nexora' ) );
		}

		return array(
			'rows'   => $rows,
			'errors' => $errors,
		);
	}

	/**
	 * Checks the header row.
	 *
	 * @param array $record The first record.
	 * @return string[]|\WP_Error Column names, in order.
	 */
	private static function header( array $record ) {

		$names = array_map(
			function ( $name ) {
				return strtolower( trim( (string) $name ) );
			},
			$record
		);

		if ( count( array_unique( $names ) ) !== count( $names ) ) {
			return self::fail( __( 'The header repeats a column.', 'nexora' ) );
		}

		$unknown = array_diff( $names, self::REQUIRED, self::OPTIONAL );

		if ( $unknown ) {
			/* translators: %s: the unknown column names. */
			return self::fail( sprintf( __( 'Unknown column(s): %s', 'nexora' ), implode( ', ', array_map( 'sanitize_text_field', $unknown ) ) ) );
		}

		$missing = array_diff( self::REQUIRED, $names );

		if ( $missing ) {
			/* translators: %s: the missing column names. */
			return self::fail( sprintf( __( 'Missing required column(s): %s', 'nexora' ), implode( ', ', $missing ) ) );
		}

		return $names;
	}

	/**
	 * One cell: trimmed, and neutralised when a spreadsheet could run it as a formula.
	 *
	 * @param string $value Raw cell.
	 * @return string
	 */
	private static function cell( $value ) {

		$value = trim( $value );

		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * A file-level error.
	 *
	 * @param string $message What is wrong.
	 * @return \WP_Error
	 */
	private static function fail( $message ) {
		return new \WP_Error( 'nexora_csv', $message );
	}
}
// phpcs:enable WordPress.WP.AlternativeFunctions
