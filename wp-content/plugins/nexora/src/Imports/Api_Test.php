<?php

namespace Nexora\Imports;

use Nexora\Products\Fields;
use Nexora\Products\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Test my API": reads the first page only and explains, item by item and in plain words, what would be refused.
 * Writes nothing.
 */
class Api_Test {

	const MAX_PROBLEMS = 20;
	const MAX_ITEMS    = 50;

	/**
	 * Runs the test.
	 *
	 * @param array $source A source (see Api_Source).
	 * @return array label, fetch_error, checked, valid, problems [ item, message ]
	 */
	public static function run( array $source ) {

		$fetched  = Api_Source::fetch(
			$source,
			array(
				'pages' => 1,
				'items' => self::MAX_ITEMS,
			)
		);
		$labels   = Fields::labels();
		$problems = array();
		$valid    = 0;

		foreach ( $fetched['errors'] as $error ) {
			$problems[] = array(
				'item'    => $error['ref'],
				'message' => $error['message'],
			);
		}

		foreach ( $fetched['items'] as $item ) {

			$ref     = (string) ( $item['ref'] ?? $item['external_id'] );
			$checked = Validator::validate( $item['raw'] );

			if ( ! $checked['errors'] ) {
				++$valid;
			}

			foreach ( $checked['errors'] as $field => $message ) {
				$problems[] = array(
					'item'    => $ref,
					'message' => sprintf(
						/* translators: 1: item id, 2: field name, 3: what is wrong. */
						__( 'Item %1$s - %2$s: %3$s', 'nexora' ),
						$ref,
						strtolower( $labels[ $field ] ?? $field ),
						$message
					),
				);
			}

			foreach ( (array) ( $item['notes'] ?? array() ) as $note ) {
				$problems[] = array(
					'item'    => $ref,
					'message' => sprintf(
						/* translators: 1: item id, 2: the note. */
						__( 'Item %1$s: %2$s', 'nexora' ),
						$ref,
						$note
					),
				);
			}
		}

		return array(
			'label'       => $fetched['label'],
			'fetch_error' => $fetched['fetch_error'],
			'checked'     => count( $fetched['items'] ) + count( $fetched['errors'] ),
			'valid'       => $valid,
			'problems'    => array_slice( $problems, 0, self::MAX_PROBLEMS ),
			'more'        => max( 0, count( $problems ) - self::MAX_PROBLEMS ),
		);
	}
}
