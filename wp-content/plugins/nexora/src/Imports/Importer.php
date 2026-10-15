<?php

namespace Nexora\Imports;

use Nexora\Accounts\Account_Types;
use Nexora\Products\Fields;
use Nexora\Products\Repository as Products;
use Nexora\Products\Service as Product_Service;
use Nexora\Vendors\Repository as Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns parsed CSV rows or fetched API items into a plan (a dry run that writes nothing), and applies a plan.
 * Every row goes through Products\Service::import_row(), the same rules as the manual form, so a vendor can only ever
 * create or update their own products: the upsert key is the vendor plus the SKU (CSV) or the vendor plus the
 * external id (API).
 */
class Importer {

	const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;
	const SHOW_ROWS = 100;

	/**
	 * Plans a CSV import.
	 *
	 * @param int    $user_id The vendor's user ID.
	 * @param array  $rows    Rows from Csv_Parser::parse(): [ line, cells ].
	 * @param string $label   The file name, for the history.
	 * @return array|\WP_Error
	 */
	public static function plan_csv( $user_id, array $rows, $label = '' ) {

		if ( ! Product_Service::vendor_for( (int) $user_id ) ) {
			return self::forbidden();
		}

		$entries = array();
		$first   = array();

		foreach ( $rows as $row ) {

			$cells = $row['cells'];
			$raw   = array(
				'name'        => $cells['name'] ?? '',
				'description' => $cells['description'] ?? '',
				'price'       => $cells['price'] ?? '',
				'stock'       => $cells['stock'] ?? '',
				'sku'         => $cells['sku'] ?? '',
				'category'    => self::category_slug( $cells['category'] ?? '' ),
				'image_id'    => '0',
			);
			$pre   = array();
			$norm  = strtoupper( trim( $raw['sku'] ) );

			if ( '' !== $norm && isset( $first[ $norm ] ) ) {
				/* translators: %d: line number of the first row with this SKU. */
				$pre['sku'] = sprintf( __( 'Repeats the SKU of line %d.', 'nexora' ), $first[ $norm ] );
			} elseif ( '' !== $norm ) {
				$first[ $norm ] = (int) $row['line'];
			}

			if ( ! empty( $cells['image_url'] ) ) {
				$image = self::image_from_url( (int) $user_id, $cells['image_url'] );

				if ( $image ) {
					$raw['image_id'] = (string) $image;
				} else {
					$pre['image_url'] = __( 'Use the address of an image in your own Media Library.', 'nexora' );
				}
			}

			$entries[] = array(
				'line'        => (int) $row['line'],
				'ref'         => (string) $row['line'],
				'raw'         => $raw,
				'external_id' => '',
				'image_url'   => '',
				'pre'         => $pre,
			);
		}

		return self::plan( (int) $user_id, 'csv', $entries, $label, '' );
	}

	/**
	 * Plans an API import.
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $fetched Api_Source::fetch() result: items, errors, fetch_error.
	 * @return array|\WP_Error
	 */
	public static function plan_api( $user_id, array $fetched ) {

		if ( ! Product_Service::vendor_for( (int) $user_id ) ) {
			return self::forbidden();
		}

		$entries = array();

		foreach ( (array) ( $fetched['items'] ?? array() ) as $item ) {

			$entries[] = array(
				'line'        => 0,
				'ref'         => (string) ( $item['external_id'] ?? '' ),
				'raw'         => (array) ( $item['raw'] ?? array() ),
				'external_id' => (string) ( $item['external_id'] ?? '' ),
				'image_url'   => (string) ( $item['image_url'] ?? '' ),
				'pre'         => array(),
			);
		}

		$plan = self::plan( (int) $user_id, 'api', $entries, 'DummyJSON', (string) ( $fetched['fetch_error'] ?? '' ) );

		foreach ( (array) ( $fetched['errors'] ?? array() ) as $error ) {
			$plan['rows'][] = array(
				'line'        => 0,
				'ref'         => (string) $error['ref'],
				'sku'         => '',
				'action'      => 'error',
				'errors'      => array( 'item' => (string) $error['message'] ),
				'raw'         => array(),
				'external_id' => '',
				'image_url'   => '',
			);
			++$plan['summary']['error'];
			++$plan['summary']['total'];
		}

		return $plan;
	}

	/**
	 * Applies a plan: every valid row is created, updated or left alone; invalid rows are skipped. Writes one history entry.
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $plan    From plan_csv() / plan_api().
	 * @return array|\WP_Error summary [ created, updated, unchanged, skipped ], log_id
	 */
	public static function apply( $user_id, array $plan ) {

		$user_id   = (int) $user_id;
		$vendor_id = Product_Service::vendor_for( $user_id );

		if ( ! $vendor_id ) {
			return self::forbidden();
		}

		$summary = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
		);
		$errors  = array();

		foreach ( $plan['rows'] as $row ) {

			if ( 'error' === $row['action'] ) {
				++$summary['skipped'];
				$errors[] = self::log_error( $row, $row['errors'] );
				continue;
			}

			$result = Product_Service::import_row( $user_id, $row['raw'], $plan['source'], $row['external_id'], true, 0, $row['image_url'] );

			if ( is_wp_error( $result ) ) {
				++$summary['skipped'];
				$errors[] = self::log_error( $row, self::fields_of( $result ) );
				continue;
			}

			$key = 'create' === $result['action'] ? 'created' : ( 'update' === $result['action'] ? 'updated' : 'unchanged' );
			++$summary[ $key ];
		}

		$log = Log_Repository::record(
			$user_id,
			$vendor_id,
			$summary + array(
				'source' => $plan['source'],
				'label'  => $plan['label'],
				'errors' => $errors,
			)
		);

		return array(
			'summary' => $summary,
			'log_id'  => $log,
		);
	}

	/**
	 * Keeps a plan under a one-time token for the confirm step (bound to the member and the source, 15 minutes).
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $plan    The plan.
	 * @return string The token (32 hex characters).
	 */
	public static function stash( $user_id, array $plan ) {

		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			'nexora_import_' . $token,
			array(
				'user_id' => (int) $user_id,
				'source'  => $plan['source'],
				'plan'    => $plan,
			),
			self::TOKEN_TTL
		);

		return $token;
	}

	/**
	 * Returns the plan behind a token and uses the token up. A token for another member or another source is refused
	 * (and stays usable by its owner).
	 *
	 * @param mixed  $token   Token from the request.
	 * @param int    $user_id The member using it.
	 * @param string $source  csv | api.
	 * @return array|\WP_Error
	 */
	public static function take( $token, $user_id, $source ) {

		$stored = is_string( $token ) && preg_match( '/^[a-f0-9]{32}$/', $token ) ? get_transient( 'nexora_import_' . $token ) : false;

		if ( ! is_array( $stored ) || (int) $stored['user_id'] !== (int) $user_id || $stored['source'] !== $source ) {
			return new \WP_Error( 'nexora_token', __( 'This preview has expired. Please preview again.', 'nexora' ) );
		}

		delete_transient( 'nexora_import_' . $token );

		return $stored['plan'];
	}

	/**
	 * What the browser may see of a plan: counts and up to 100 rows, without the validated data.
	 *
	 * @param array $plan A plan.
	 * @return array
	 */
	public static function report( array $plan ) {

		$rows = array();

		foreach ( array_slice( $plan['rows'], 0, self::SHOW_ROWS ) as $row ) {
			$rows[] = array(
				'line'   => $row['line'],
				'ref'    => $row['ref'],
				'sku'    => $row['sku'],
				'action' => $row['action'],
				'errors' => $row['errors'],
			);
		}

		return array(
			'summary'     => $plan['summary'],
			'rows'        => $rows,
			'truncated'   => count( $plan['rows'] ) > self::SHOW_ROWS,
			'fetch_error' => $plan['fetch_error'],
			'label'       => $plan['label'],
		);
	}

	/**
	 * Runs every entry through the dry run and totals the result.
	 *
	 * @param int    $user_id     The vendor's user ID.
	 * @param string $source      csv | api.
	 * @param array  $entries     Prepared entries.
	 * @param string $label       Label for the history.
	 * @param string $fetch_error Upstream error (API).
	 * @return array
	 */
	private static function plan( $user_id, $source, array $entries, $label, $fetch_error ) {

		$summary  = array(
			'create'    => 0,
			'update'    => 0,
			'unchanged' => 0,
			'error'     => 0,
			'total'     => 0,
		);
		$rows     = array();
		$reserved = 0;

		foreach ( $entries as $entry ) {

			$result = empty( $entry['pre'] )
				? Product_Service::import_row( $user_id, $entry['raw'], $source, $entry['external_id'], false, $reserved, $entry['image_url'] )
				: new \WP_Error( 'nexora_invalid', '', array( 'fields' => $entry['pre'] ) );
			$row    = array(
				'line'        => $entry['line'],
				'ref'         => $entry['ref'],
				'sku'         => strtoupper( trim( (string) ( $entry['raw']['sku'] ?? '' ) ) ),
				'raw'         => $entry['raw'],
				'external_id' => $entry['external_id'],
				'image_url'   => $entry['image_url'],
			);

			if ( is_wp_error( $result ) ) {
				$row['action'] = 'error';
				$row['errors'] = self::fields_of( $result );
				++$summary['error'];
			} else {
				$row['action'] = $result['action'];
				$row['errors'] = array();
				++$summary[ $result['action'] ];

				if ( 'create' === $result['action'] ) {
					++$reserved;
				}
			}

			++$summary['total'];
			$rows[] = $row;
		}

		return array(
			'source'      => $source,
			'label'       => $label,
			'summary'     => $summary,
			'rows'        => $rows,
			'fetch_error' => $fetch_error,
		);
	}

	/**
	 * A category cell: the slug, or the label ("Home & Kitchen"), whichever the vendor typed.
	 *
	 * @param string $value Cell.
	 * @return string The slug when it matches the list, else the cell as given (the validator rejects it).
	 */
	private static function category_slug( $value ) {

		$value = trim( (string) $value );

		foreach ( Fields::categories() as $slug => $label ) {

			if ( 0 === strcasecmp( $value, $slug ) || 0 === strcasecmp( $value, $label ) ) {
				return $slug;
			}
		}

		return $value;
	}

	/**
	 * The attachment behind a Media Library URL, only if it is the vendor's own image.
	 *
	 * @param int    $user_id The vendor's user ID.
	 * @param string $url     Address from the file.
	 * @return int Attachment ID, or 0.
	 */
	private static function image_from_url( $user_id, $url ) {

		$id = (int) attachment_url_to_postid( esc_url_raw( $url ) );

		return $id && get_post_type( $id ) === 'attachment' && wp_attachment_is_image( $id ) && (int) get_post_field( 'post_author', $id ) === (int) $user_id ? $id : 0;
	}

	/**
	 * Field => message pairs of an import error.
	 *
	 * @param \WP_Error $error The error.
	 * @return array
	 */
	private static function fields_of( \WP_Error $error ) {

		$data = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['fields'] ) ) {
			return array_map( 'strval', $data['fields'] );
		}

		return array( 'item' => $error->get_error_message() );
	}

	/**
	 * One history line for a skipped row.
	 *
	 * @param array $row    The plan row.
	 * @param array $fields Field => message.
	 * @return array
	 */
	private static function log_error( array $row, array $fields ) {

		$parts = array();

		foreach ( $fields as $field => $message ) {
			$parts[] = ( 'item' === $field ? '' : $field . ': ' ) . $message;
		}

		return array(
			'line'    => (int) $row['line'],
			'ref'     => (string) $row['ref'],
			'message' => implode( '; ', $parts ),
		);
	}

	/**
	 * The same refusal for every "not allowed" case.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden() {
		return new \WP_Error( 'nexora_forbidden', __( 'You are not allowed to do that.', 'nexora' ) );
	}
}
