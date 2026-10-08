<?php

namespace Nexora\Profile;

use Nexora\Http\Ajax as Http;
use Nexora\Http\Member_Ajax;
use Nexora\Http\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile editing for the logged-in member: personal, address, work, documents, password.
 * (Connections, notifications and content have their own classes.)
 */
class Ajax extends Member_Ajax {

	public function __construct() {

		Http::register( 'update_personal_info', array( $this, 'update_personal_info' ) );
		Http::register( 'update_address_info', array( $this, 'update_address_info' ) );
		Http::register( 'update_work_info', array( $this, 'update_work_info' ) );
		Http::register( 'update_documents_info', array( $this, 'update_documents_info' ) );
		Http::register( 'update_profile_password', array( $this, 'update_profile_password' ) );
	}

	// PERSONAL INFO
	public function update_personal_info() {

		$auth = $this->member();
		$id   = $auth['profile_id'];

		$fields = Fields::PERSONAL;

		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$value = ( $field === 'bio' )
				? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) )
				: $this->post_value( $field );

			if ( $field === 'gender' && ! in_array( $value, array( 'male', 'female', 'other', '' ), true ) ) {
				continue;
			}

			if ( $field === 'birthdate' && $value !== '' ) {
				$dt = \DateTime::createFromFormat( 'Y-m-d', $value );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value || $dt > new \DateTime( 'today' ) ) {
					wp_send_json_error( 'Invalid date of birth' );
				}
			}

			if ( $field === 'linkedin_id' ) {
				$value = sanitize_text_field( $value );
			}

			update_post_meta( $id, $field, $value );
		}

		wp_send_json_success( 'Personal Info Updated' );
	}

	// ADDRESS INFO
	public function update_address_info() {

		$auth = $this->member();
		$id   = $auth['profile_id'];

		$fields = Fields::ADDRESS;

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $id, $field, $this->post_value( $field ) );
			}
		}

		wp_send_json_success( 'Address Info Updated' );
	}

	// WORK INFO
	public function update_work_info() {

		$auth = $this->member();
		$id   = $auth['profile_id'];

		$fields = Fields::WORK;

		foreach ( $fields as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$value = $this->post_value( $field );

			if ( $field === 'company_email' && $value !== '' ) {
				$value = sanitize_email( $value );
				if ( ! is_email( $value ) ) {
					wp_send_json_error( 'Invalid company email' );
				}
			}

			update_post_meta( $id, $field, $value );
		}

		wp_send_json_success( 'Work Info Updated' );
	}

	// DOCUMENTS
	public function update_documents_info() {

		$auth = $this->member();
		$id   = $auth['profile_id'];

		$fields = Fields::DOCUMENTS;

		foreach ( $fields as $field ) {

			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}

			$value = trim( (string) wp_unslash( $_POST[ $field ] ) );

			// REMOVE CASE (IMPORTANT)
			if ( $value === '' ) {
				delete_post_meta( $id, $field );
				continue;
			}

			$attachment_id = absint( $value );

			// Only attachments uploaded by this user can be linked
			if ( ! $attachment_id || ! $this->owns_attachment( $attachment_id, $auth['user_id'] ) ) {
				wp_send_json_error( 'Invalid file selected' );
			}

			// ID documents are private files; profile / cover images are shown to other members
			$is_private_file = Private_Documents::is_private( $attachment_id );

			if ( in_array( $field, Private_Documents::PUBLIC_KEYS, true ) && $is_private_file ) {
				wp_send_json_error( 'This file is a private document and cannot be used as a public image' );
			}

			if ( in_array( $field, Private_Documents::DOC_KEYS, true )
				&& ! $is_private_file
				&& Private_Documents::in_public_use( $attachment_id ) ) {
				wp_send_json_error( 'This file is already used as a public image. Please upload a separate file for your document' );
			}

			update_post_meta( $id, $field, $attachment_id );
		}

		wp_send_json_success( 'Documents updated' );
	}

	// CHANGE PASSWORD
	public function update_profile_password() {

		$user_id = Http::member( 'profile_nonce', false, 'Not logged in' )['user_id'];

		if ( Rate_Limiter::hit( 'password_change', 'u' . $user_id ) ) {
			wp_send_json_error( Rate_Limiter::message() );
		}

		$current_password = wp_unslash( $_POST['current_password'] ?? '' );
		$new_password     = wp_unslash( $_POST['new_password'] ?? '' );
		$confirm_password = wp_unslash( $_POST['confirm_password'] ?? '' );

		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
			wp_send_json_error( 'Current password is incorrect' );
		}

		if ( strlen( $new_password ) < 8 ) {
			wp_send_json_error( 'Password must be at least 8 characters' );
		}

		if ( $new_password !== $confirm_password ) {
			wp_send_json_error( 'Passwords do not match' );
		}

		if ( $current_password === $new_password ) {
			wp_send_json_error( 'New password must be different' );
		}

		wp_set_password( $new_password, $user_id );

		// wp_set_password() destroys the session; keep this user logged in
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		wp_send_json_success( 'Password updated successfully' );
	}
}
