<?php

namespace Nexora\Imports;

use Nexora\Http\Rate_Limiter;
use Nexora\Vendors\Vendor_Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The import endpoints for approved vendors: preview (a dry run that writes nothing and returns a one-time token) and
 * import (applies exactly that previewed plan). The CSV upload is read here with its own checks; it is never stored
 * and the member upload policy for the Media Library is untouched.
 */
class Ajax extends Vendor_Ajax {

	/**
	 * Registers the four actions (nonce `profile_nonce`, approved vendors only).
	 */
	public function __construct() {
		\Nexora\Http\Ajax::register( 'csv_preview', array( $this, 'csv_preview' ) );
		\Nexora\Http\Ajax::register( 'csv_import', array( $this, 'csv_import' ) );
		\Nexora\Http\Ajax::register( 'api_preview', array( $this, 'api_preview' ) );
		\Nexora\Http\Ajax::register( 'api_import', array( $this, 'api_import' ) );
	}

	/**
	 * Reads the uploaded CSV, checks it and returns the dry-run report.
	 */
	public function csv_preview() {

		$member = $this->seller();
		$this->throttle( 'csv_import', $member['user_id'] );

		// The upload is checked field by field below; nothing from it is trusted before that.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array();

		if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			$this->fail( __( 'Choose a .csv file first.', 'nexora' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->fail( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? __( 'The file is larger than 2 MB.', 'nexora' ) : __( 'The upload did not complete. Please try again.', 'nexora' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			$this->fail( __( 'The upload could not be verified.', 'nexora' ) );
		}

		$name   = isset( $file['name'] ) && is_string( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$parsed = Csv_Parser::parse( $file['tmp_name'], $name );

		if ( is_wp_error( $parsed ) ) {
			$this->fail( $parsed->get_error_message() );
		}

		$plan = Importer::plan_csv( $member['user_id'], $parsed['rows'], $name );

		if ( is_wp_error( $plan ) ) {
			$this->forbid();
		}

		// Rows the parser could not read are reported like any other row error.
		foreach ( $parsed['errors'] as $error ) {
			$plan['rows'][] = array(
				'line'        => (int) $error['line'],
				'ref'         => (string) $error['line'],
				'sku'         => '',
				'action'      => 'error',
				'errors'      => array( 'row' => $error['message'] ),
				'raw'         => array(),
				'external_id' => '',
				'image_url'   => '',
			);
			++$plan['summary']['error'];
			++$plan['summary']['total'];
		}

		$this->send_preview( $member['user_id'], $plan );
	}

	/**
	 * Applies the previewed CSV plan.
	 */
	public function csv_import() {
		$this->import( 'csv' );
	}

	/**
	 * Fetches the catalog from the product source and returns the dry-run report.
	 */
	public function api_preview() {

		$member = $this->seller();
		$this->throttle( 'api_import', $member['user_id'] );

		$plan = Importer::plan_api( $member['user_id'], Api_Source::fetch() );

		if ( is_wp_error( $plan ) ) {
			$this->forbid();
		}

		$this->send_preview( $member['user_id'], $plan );
	}

	/**
	 * Applies the previewed API plan.
	 */
	public function api_import() {
		$this->import( 'api' );
	}

	/**
	 * Applies a stashed plan.
	 *
	 * @param string $source csv | api.
	 */
	private function import( $source ) {

		$member = $this->seller();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the token is matched against a strict 32 hex pattern in Importer::take().
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		$plan  = Importer::take( $token, $member['user_id'], $source );

		if ( is_wp_error( $plan ) ) {
			$this->fail( $plan->get_error_message() );
		}

		$result = Importer::apply( $member['user_id'], $plan );

		if ( is_wp_error( $result ) ) {
			$this->forbid();
		}

		wp_send_json_success( array( 'summary' => $result['summary'] ) );
	}

	/**
	 * Stores the plan under a token (when there is something to import) and answers with the report.
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $plan    The plan.
	 */
	private function send_preview( $user_id, array $plan ) {

		$report          = Importer::report( $plan );
		$report['token'] = ( $plan['summary']['create'] + $plan['summary']['update'] ) > 0 ? Importer::stash( $user_id, $plan ) : '';

		wp_send_json_success( $report );
	}

	/**
	 * Counts one use of an import bucket and ends the request when the vendor is over the limit.
	 *
	 * @param string $bucket  Rate-limit bucket.
	 * @param int    $user_id The vendor's user ID.
	 */
	private function throttle( $bucket, $user_id ) {

		if ( Rate_Limiter::hit( $bucket, (string) $user_id ) ) {
			$this->fail( Rate_Limiter::message() );
		}
	}

	/**
	 * Ends the request with a JSON error message.
	 *
	 * @param string $message What went wrong.
	 */
	private function fail( $message ) {
		wp_send_json_error( array( 'message' => $message ) );
	}
}
