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

	// "How many products" in the API popup: the allowed counts (the last is "All, up to 500") and the default.
	const LIMITS        = array( 20, 40, 100, 500 );
	const DEFAULT_LIMIT = 20;

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
	 * The count the vendor asked for: exactly one of the allowed values written as plain digits, anything else is 20.
	 *
	 * @param mixed $value Posted value.
	 * @return int 20, 40, 100 or 500.
	 */
	public static function limit_of( $value ) {

		if ( is_int( $value ) ) {
			return in_array( $value, self::LIMITS, true ) ? $value : self::DEFAULT_LIMIT;
		}

		return is_string( $value ) && in_array( $value, array_map( 'strval', self::LIMITS ), true ) ? (int) $value : self::DEFAULT_LIMIT;
	}

	/**
	 * Plans an API import.
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $fetched Api_Source::fetch() result: items, errors, fetch_error, feed.
	 * @param int   $limit   The count the preview was made for (bound to the token).
	 * @return array|\WP_Error
	 */
	public static function plan_api( $user_id, array $fetched, $limit = self::DEFAULT_LIMIT ) {

		if ( ! Product_Service::vendor_for( (int) $user_id ) ) {
			return self::forbidden();
		}

		$entries = array();
		$new     = array();

		foreach ( (array) ( $fetched['items'] ?? array() ) as $item ) {

			if ( ! empty( $item['new_category']['slug'] ) && ! empty( $item['new_category']['label'] ) ) {
				$new[ (string) $item['new_category']['slug'] ] = (string) $item['new_category']['label'];
			}

			$entries[] = array(
				'line'        => 0,
				'ref'         => (string) ( $item['ref'] ?? $item['external_id'] ?? '' ),
				'raw'         => (array) ( $item['raw'] ?? array() ),
				'external_id' => (string) ( $item['external_id'] ?? '' ),
				'image_url'   => (string) ( $item['image_url'] ?? '' ),
				'pre'         => array(),
			);
		}

		// The categories the source asked for count as valid while planning, but nothing is written until apply().
		$pending = function ( $categories ) use ( $new ) {
			return (array) $categories + $new;
		};
		add_filter( 'nexora_product_categories', $pending, 99 );

		try {
			$plan = self::plan( (int) $user_id, 'api', $entries, (string) ( $fetched['label'] ?? '' ), (string) ( $fetched['fetch_error'] ?? '' ) );
		} finally {
			remove_filter( 'nexora_product_categories', $pending, 99 );
		}

		$plan['new_categories'] = $new;
		$plan['limit']          = self::limit_of( $limit );
		$plan['feed']           = array(
			'taken' => (int) ( $fetched['feed']['taken'] ?? 0 ),
			'seen'  => (int) ( $fetched['feed']['seen'] ?? 0 ),
			'total' => isset( $fetched['feed']['total'] ) ? (int) $fetched['feed']['total'] : null,
			'more'  => ! empty( $fetched['feed']['more'] ),
		);

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

		// Categories an API source asked for are created first (they come from the server-held plan, never from the browser).
		if ( ! empty( $plan['new_categories'] ) && is_array( $plan['new_categories'] ) ) {
			\Nexora\Products\Fields::add_categories( $plan['new_categories'] );
		}

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
				'limit'   => isset( $plan['limit'] ) ? (int) $plan['limit'] : 0,
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
	 * @param mixed  $limit   API only: the "How many products" value sent with the import. It must be the count the preview
	 *                        was made for, so a preview of 20 can never import 100 (a refusal leaves the token usable).
	 * @return array|\WP_Error
	 */
	public static function take( $token, $user_id, $source, $limit = null ) {

		$stored = is_string( $token ) && preg_match( '/^[a-f0-9]{32}$/', $token ) ? get_transient( 'nexora_import_' . $token ) : false;

		if ( ! is_array( $stored ) || (int) $stored['user_id'] !== (int) $user_id || $stored['source'] !== $source ) {
			return new \WP_Error( 'nexora_token', __( 'This preview has expired. Please preview again.', 'nexora' ) );
		}

		if ( 'api' === $source && self::limit_of( $limit ) !== (int) ( $stored['limit'] ?? 0 ) ) {
			return new \WP_Error( 'nexora_limit_changed', __( 'The number of products was changed after the preview. Please preview again.', 'nexora' ) );
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
			'feed'        => self::feed_of( $plan ),
			'feed_note'   => self::feed_note( $plan ),
		);
	}

	/**
	 * What the preview knows about the feed (API only): valid items taken, entries read, the feed's total (null when
	 * unknown) and whether it has more than was taken.
	 *
	 * @param array $plan A plan.
	 * @return array|null
	 */
	private static function feed_of( array $plan ) {

		if ( empty( $plan['feed'] ) ) {
			return null;
		}

		return array(
			'taken'    => $plan['feed']['taken'],
			'total'    => $plan['feed']['total'],
			'at_least' => $plan['feed']['seen'],
			'more'     => $plan['feed']['more'],
			'limit'    => $plan['limit'] ?? self::DEFAULT_LIMIT,
		);
	}

	/**
	 * "Showing the first N of M." when the feed has more than was taken; "of at least M" when the stop came early and the
	 * feed's total is unknown. Empty when everything was taken.
	 *
	 * @param array $plan A plan.
	 * @return string
	 */
	private static function feed_note( array $plan ) {

		if ( empty( $plan['feed']['more'] ) ) {
			return '';
		}

		$taken = (int) $plan['feed']['taken'];

		if ( null === $plan['feed']['total'] ) {
			/* translators: 1: products taken, 2: products the feed is known to have (at least). */
			return sprintf( __( 'Showing the first %1$d of at least %2$d.', 'nexora' ), $taken, (int) $plan['feed']['seen'] );
		}

		/* translators: 1: products taken, 2: products in the feed. */
		return sprintf( __( 'Showing the first %1$d of %2$d.', 'nexora' ), $taken, (int) $plan['feed']['total'] );
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
