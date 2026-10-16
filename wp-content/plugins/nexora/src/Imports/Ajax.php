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
		\Nexora\Http\Ajax::register( 'api_test', array( $this, 'api_test' ) );
		\Nexora\Http\Ajax::register( 'api_connection_save', array( $this, 'connection_save' ) );
		\Nexora\Http\Ajax::register( 'api_connection_delete', array( $this, 'connection_delete' ) );
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

		$resolved = $this->source( $member['user_id'] );
		$limit    = $this->limit();
		$plan     = Importer::plan_api( $member['user_id'], Api_Source::fetch( $resolved['source'], array( 'valid' => $limit ) ), $limit );

		if ( is_wp_error( $plan ) ) {
			$this->forbid();
		}

		// Remembered with the plan so the confirm step can record the import on the connection; never sent to the browser.
		$plan['conn_id'] = $resolved['conn_id'];

		$this->send_preview( $member['user_id'], $plan );
	}

	/**
	 * "Test my API": reads the first page and explains what would be refused. Writes nothing.
	 */
	public function api_test() {

		$member = $this->seller();
		$this->throttle( 'api_test', $member['user_id'] );

		$resolved = $this->source( $member['user_id'] );

		wp_send_json_success( Api_Test::run( $resolved['source'] ) );
	}

	/**
	 * Saves (adds or edits) one of the vendor's API connections.
	 */
	public function connection_save() {

		$member = $this->seller();
		$this->throttle( 'api_connection', $member['user_id'] );

		$raw = array();

		foreach ( array( 'name', 'url', 'auth_type', 'auth_header', 'secret' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in seller(); every field is validated and cleaned in Connections::save().
			$raw[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		}

		$result = Connections::save( $member['user_id'], $raw, $this->connection_id( false ) );

		if ( is_wp_error( $result ) ) {

			if ( 'nexora_forbidden' === $result->get_error_code() ) {
				$this->forbid();
			}

			$data = $result->get_error_data();

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'fields'  => is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Connection saved.', 'nexora' ),
				'id'          => $result['id'],
				'connections' => $this->connections( $member['user_id'] ),
			)
		);
	}

	/**
	 * Deletes one of the vendor's own connections.
	 */
	public function connection_delete() {

		$member = $this->seller();
		$this->throttle( 'api_connection', $member['user_id'] );

		$id = $this->connection_id( true );

		if ( is_wp_error( Connections::delete( $member['user_id'], $id ) ) ) {
			$this->forbid();
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Connection deleted.', 'nexora' ),
				'connections' => $this->connections( $member['user_id'] ),
			)
		);
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

		if ( 'api' === $source ) {
			$this->throttle( 'api_apply', $member['user_id'] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the token is matched against a strict 32 hex pattern in Importer::take().
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		$plan  = Importer::take( $token, $member['user_id'], $source, 'api' === $source ? $this->limit() : null );

		if ( is_wp_error( $plan ) ) {
			$this->fail( $plan->get_error_message() );
		}

		$result = Importer::apply( $member['user_id'], $plan );

		if ( is_wp_error( $result ) ) {
			$this->forbid();
		}

		$this->record_use( $member['user_id'], $plan );

		wp_send_json_success( array( 'summary' => $result['summary'] ) );
	}

	/**
	 * The posted "How many products": one of the allowed counts, else 20. Anything that is not a plain string is ignored.
	 *
	 * @return int
	 */
	private function limit() {

		// Nonce checked in seller(); Importer::limit_of() accepts only the allowed digits.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return Importer::limit_of( isset( $_POST['limit'] ) ? wp_unslash( $_POST['limit'] ) : null );
	}

	/**
	 * The vendor's saved connections as the popup lists them (never with a key).
	 *
	 * @param int $user_id The vendor's user ID.
	 * @return array[]
	 */
	private function connections( $user_id ) {
		return Connection_Repository::for_vendor( \Nexora\Vendors\Repository::id_for_user( $user_id ) );
	}

	/**
	 * Notes the import on the saved connection it came from (if it is still the vendor's own).
	 *
	 * @param int   $user_id The vendor's user ID.
	 * @param array $plan    The applied plan.
	 */
	private function record_use( $user_id, array $plan ) {

		$conn_id   = (int) ( $plan['conn_id'] ?? 0 );
		$vendor_id = \Nexora\Vendors\Repository::id_for_user( $user_id );

		if ( $conn_id > 0 && Connection_Repository::find( $conn_id, $vendor_id ) ) {
			Connection_Repository::touch( $conn_id, '' === $plan['fetch_error'] ? 'ok' : 'error' );
		}
	}

	/**
	 * The source the request asks for, or the request ends with a plain message.
	 *
	 * @param int $user_id The vendor's user ID.
	 * @return array source, conn_id
	 */
	private function source( $user_id ) {

		$request = array();

		foreach ( array( 'source', 'url', 'auth_type', 'auth_header', 'secret' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in seller(); Connections::resolve_source() validates every value (a platform source ignores all but its id).
			$request[ $key ] = isset( $_POST[ $key ] ) ? ( is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array() ) : '';
		}

		$resolved = Connections::resolve_source( $user_id, $request );

		if ( is_wp_error( $resolved ) ) {

			if ( 'nexora_forbidden' === $resolved->get_error_code() ) {
				$this->forbid();
			}

			$data = $resolved->get_error_data();

			wp_send_json_error(
				array(
					'message' => $resolved->get_error_message(),
					'fields'  => is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array(),
				)
			);
		}

		return $resolved;
	}

	/**
	 * The posted connection_id: 0 when absent or empty; anything that is not a whole number ends the request.
	 *
	 * @param bool $required Whether an id must be given.
	 * @return int
	 */
	private function connection_id( $required ) {

		// Nonce checked in seller(); the value is accepted only if it is digits (checked next).
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$value = isset( $_POST['connection_id'] ) ? wp_unslash( $_POST['connection_id'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $value && ! $required ) {
			return 0;
		}

		if ( ! is_string( $value ) || ! ctype_digit( $value ) || strlen( $value ) > 12 || ( $required && (int) $value <= 0 ) ) {
			$this->fail( __( 'Invalid request.', 'nexora' ) );
		}

		return (int) $value;
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
