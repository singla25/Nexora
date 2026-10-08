<?php

namespace Nexora\Profile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves ID documents that were uploaded before they became private: wp nexora migrate-documents [--dry-run].
 */
class Documents_Migration {

	public function __construct() {

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'nexora migrate-documents', array( $this, 'cli_migrate' ) );
		}
	}

	/**
	 * Move every ID document that is still public. Idempotent: private ones are skipped.
	 *
	 * @return array{moved:int,failed:int,skipped:int,would_move:int,errors:string[]}
	 */
	public static function migrate_all( $dry_run = false ) {

		global $wpdb;

		$result = array(
			'moved'      => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'would_move' => 0,
			'errors'     => array(),
		);

		$placeholders = implode( ',', array_fill( 0, count( Private_Documents::DOC_KEYS ), '%s' ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'user_profile'
             WHERE pm.meta_key IN ($placeholders) AND pm.meta_value REGEXP '^[0-9]+$' AND pm.meta_value <> '0'",
				Private_Documents::DOC_KEYS
			)
		);

		foreach ( $ids as $id ) {

			$id = (int) $id;

			if ( get_post_type( $id ) !== 'attachment' || Private_Documents::is_private( $id ) ) {
				++$result['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$result['would_move'];
				continue;
			}

			if ( Private_Documents::protect( $id ) ) {
				++$result['moved'];
			} else {
				++$result['failed'];
				$result['errors'][] = "attachment $id could not be moved (missing file or unwritable folder)";
			}
		}

		return $result;
	}

	/**
	 * wp nexora migrate-documents [--dry-run]
	 */
	public function cli_migrate( $args, $assoc_args ) {

		$dry = ! empty( $assoc_args['dry-run'] );
		$res = self::migrate_all( $dry );

		\WP_CLI::log( sprintf( 'moved=%d failed=%d skipped=%d would_move=%d', $res['moved'], $res['failed'], $res['skipped'], $res['would_move'] ) );

		foreach ( $res['errors'] as $e ) {
			\WP_CLI::warning( $e );
		}

		if ( $res['failed'] ) {
			\WP_CLI::halt( 1 );
		}

		\WP_CLI::success( $dry ? 'Dry run finished, nothing changed.' : 'Done.' );
	}
}
