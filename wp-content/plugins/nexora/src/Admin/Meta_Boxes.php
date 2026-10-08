<?php

namespace Nexora\Admin;

use Nexora\Chat\Repository as Chat;
use Nexora\Connections\Repository as Connections;
use Nexora\Content\Repository as Content;
use Nexora\Core\View;
use Nexora\Profile\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The edit-screen panels for user profiles, connections and content, and saving them.
 * Markup lives in templates/admin/metabox-*.php.
 */
class Meta_Boxes {

	/**
	 * Hooks the meta boxes and the save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_boxes' ) );
	}

	/**
	 * Registers the panels on the profile, connection and content edit screens.
	 */
	public function add_meta_boxes() {

		add_meta_box( 'user_personal_details', 'User Personal Details', array( $this, 'user_personal_details' ), 'user_profile' );
		add_meta_box( 'user_address_details', 'User Address Details', array( $this, 'user_address_details' ), 'user_profile' );
		add_meta_box( 'user_work_details', 'User Work Details', array( $this, 'user_work_details' ), 'user_profile' );
		add_meta_box( 'user_document_details', 'User Document Details', array( $this, 'user_document_details' ), 'user_profile' );
		add_meta_box( 'user_connection_details', 'User Connection Details', array( $this, 'user_connection_details' ), 'user_profile' );
		add_meta_box( 'user_content_details', 'User Content Details', array( $this, 'user_content_details' ), 'user_profile' );
		add_meta_box( 'user_chat_details', 'User Chat Details', array( $this, 'user_chat_details' ), 'user_profile' );

		add_meta_box( 'user_connection_meta_box', 'User Connection Details', array( $this, 'user_connection_meta_box' ), 'user_connections' );
		add_meta_box( 'user_connection_chat_box', 'User Connection Chat Details', array( $this, 'user_connection_chat_box' ), 'user_connections' );

		add_meta_box( 'user_content_meta_box', 'User Content Info', array( $this, 'render_user_content_meta_box' ), 'user_content' );
	}

	/**
	 * Meta values of a post for the given keys.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $keys Meta keys to read.
	 * @return array Meta values keyed by meta key.
	 */
	private function meta_of( $post_id, array $keys ) {

		$meta = array();

		foreach ( $keys as $key ) {
			$meta[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $meta;
	}

	/**
	 * Personal details panel of a profile.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_personal_details( $post ) {
		View::output( 'admin/metabox-personal', array( 'meta' => $this->meta_of( $post->ID, array( 'user_name', 'first_name', 'last_name', 'email', 'phone', 'linkedin_id', 'gender', 'birthdate', 'bio' ) ) ) );
	}

	/**
	 * Permanent and correspondence address panel of a profile.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_address_details( $post ) {
		View::output( 'admin/metabox-address', array( 'meta' => $this->meta_of( $post->ID, Fields::ADDRESS ) ) );
	}

	/**
	 * Work details panel of a profile.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_work_details( $post ) {
		View::output( 'admin/metabox-work', array( 'meta' => $this->meta_of( $post->ID, Fields::WORK ) ) );
	}

	/**
	 * Documents panel of a profile (media upload for each file).
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_document_details( $post ) {

		$labels = array(
			'profile_image'   => 'Profile Image',
			'cover_image'     => 'Cover Image',
			'aadhaar_card'    => 'Aadhar Card',
			'driving_license' => 'Driving License',
			'company_id_card' => 'Company ID Card',
		);

		$docs = array();

		foreach ( $labels as $key => $label ) {

			$image_id = get_post_meta( $post->ID, $key, true );

			$docs[] = array(
				'key'   => $key,
				'label' => $label,
				'id'    => $image_id,
				'url'   => $image_id ? wp_get_attachment_url( $image_id ) : '',
			);
		}

		View::output( 'admin/metabox-documents', array( 'docs' => $docs ) );
	}

	/**
	 * Received and sent connection requests of a profile.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_connection_details( $post ) {

		$profile_id = $post->ID;

		View::output(
			'admin/metabox-connections',
			array(
				'received' => $this->connection_rows( Connections::received_by( $profile_id ), 'sender' ),
				'sent'     => $this->connection_rows( Connections::sent_by( $profile_id ), 'receiver' ),
			)
		);
	}

	/**
	 * Rows for a connection list: the other side's profile id and user name, and the status.
	 *
	 * @param \WP_Post[] $connections Connection posts.
	 * @param string     $other_side Which side is the "other" person: sender or receiver.
	 * @return array[] Rows with profile_id, user_name and status.
	 */
	private function connection_rows( array $connections, $other_side ) {

		$rows = array();

		foreach ( $connections as $conn ) {
			$rows[] = array(
				'profile_id' => get_post_meta( $conn->ID, $other_side . '_profile_id', true ),
				'user_name'  => get_post_meta( $conn->ID, $other_side . '_user_name', true ),
				'status'     => get_post_meta( $conn->ID, 'status', true ),
			);
		}

		return $rows;
	}

	/**
	 * Posts written by a profile.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_content_details( $post ) {

		$contents = array();

		foreach ( Content::for_profile( $post->ID, -1 ) as $content ) {
			$contents[] = array(
				'title'    => $content->post_title,
				'date'     => get_the_date( 'Y-m-d H:i:s', $content->ID ),
				'edit_url' => admin_url( 'post.php?post=' . $content->ID . '&action=edit' ),
			);
		}

		View::output( 'admin/metabox-contents', array( 'contents' => $contents ) );
	}

	/**
	 * Chat overview of a profile: each connection and its conversations.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_chat_details( $post ) {

		$user_id = get_post_meta( $post->ID, '_wp_user_id', true );

		if ( ! $user_id ) {
			View::output(
				'admin/metabox-user-chat',
				array(
					'state' => 'no_user',
					'rows'  => array(),
				)
			);
			return;
		}

		$connections = Connections::for_user( $user_id );

		if ( ! $connections ) {
			View::output(
				'admin/metabox-user-chat',
				array(
					'state' => 'no_connections',
					'rows'  => array(),
				)
			);
			return;
		}

		$chat_db = new Chat();
		$rows    = array();

		foreach ( $connections as $conn ) {

			$conn_id = $conn->ID;

			$sender_id   = get_post_meta( $conn_id, 'sender_user_id', true );
			$receiver_id = get_post_meta( $conn_id, 'receiver_user_id', true );
			$status      = get_post_meta( $conn_id, 'status', true );

			// Other user
			$other_user_id = ( (int) $sender_id === (int) $user_id ) ? $receiver_id : $sender_id;
			$other_user    = get_userdata( $other_user_id );

			$threads = array();

			foreach ( $chat_db->get_threads_by_connection( $conn_id ) as $t ) {
				$threads[] = array(
					'subject' => ! empty( $t->subject ) ? $t->subject : 'No Subject',
					'status'  => $t->status,
					'color'   => 'active' === $t->status ? '#16a34a' : '#dc2626',
				);
			}

			$rows[] = array(
				'name'         => $other_user ? $other_user->display_name : '-',
				'conn_id'      => $conn_id,
				'status'       => $status,
				'status_color' => match ( $status ) {
					'accepted' => '#16a34a',
					'pending'  => '#f59e0b',
					'removed'  => '#6b7280',
					default    => '#dc2626'
				},
				'time'         => get_the_date( 'd M Y, H:i', $conn_id ),
				'threads'      => $threads,
			);
		}

		View::output(
			'admin/metabox-user-chat',
			array(
				'state' => 'ok',
				'rows'  => $rows,
			)
		);
	}

	/**
	 * Sender, receiver and status fields of a connection.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_connection_meta_box( $post ) {

		View::output(
			'admin/metabox-connection',
			array(
				'm' => $this->meta_of(
					$post->ID,
					array(
						'sender_user_id',
						'sender_profile_id',
						'sender_user_name',
						'receiver_user_id',
						'receiver_profile_id',
						'receiver_user_name',
						'status',
					)
				),
			)
		);
	}

	/**
	 * Conversations that belong to a connection.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function user_connection_chat_box( $post ) {

		$threads = array();

		foreach ( ( new Chat() )->get_threads_by_connection( $post->ID ) as $thread ) {

			$user_ids = explode( ',', $thread->participants );

			// Get user names safely
			$user1 = '-';
			$user2 = '-';

			if ( ! empty( $user_ids[0] ) ) {
				$u1    = get_userdata( $user_ids[0] );
				$user1 = $u1 ? $u1->display_name : '-';
			}

			if ( ! empty( $user_ids[1] ) ) {
				$u2    = get_userdata( $user_ids[1] );
				$user2 = $u2 ? $u2->display_name : '-';
			}

			$threads[] = array(
				'id'         => $thread->id,
				'users'      => $user1 . ' & ' . $user2,
				'subject'    => ! empty( $thread->subject ) ? $thread->subject : 'No Subject',
				'status'     => $thread->status,
				'color'      => 'active' === $thread->status ? '#16a34a' : '#dc2626',
				// Admin screen: never use the logged-in admin as the "other" user
				'other_user' => $user_ids[1] ?? $user_ids[0] ?? 0,
				'name'       => $user1 . ' and ' . $user2,
			);
		}

		View::output( 'admin/metabox-connection-chat', array( 'threads' => $threads ) );
	}

	/**
	 * Author fields of a content post.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public function render_user_content_meta_box( $post ) {

		View::output( 'admin/metabox-content', array( 'm' => $this->meta_of( $post->ID, array( 'user_id', 'user_profile_id', 'user_name' ) ) ) );
	}

	/**
	 * Saves the fields of the three edit screens. Administrators only; ignored for autosaves, revisions, AJAX and bad nonces.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_boxes( $post_id ) {

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only react to the real admin edit screen. Front-end AJAX handlers also
		// call wp_insert_post() and must never be overwritten with raw $_POST data.
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-post_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		// ===============================
		// USER PROFILE SAVE
		// ===============================
		if ( 'user_profile' === $post_type ) {

			$fields = Fields::admin_editable();

			foreach ( $fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {

					if ( in_array( $field, Fields::DOCUMENTS, true ) ) {
						update_post_meta( $post_id, $field, absint( $_POST[ $field ] ) );
					} else {
						update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
					}
				}
			}
		}

		// ===============================
		// USER CONTENT SAVE
		// ===============================
		if ( 'user_content' === $post_type ) {

			$fields = array( 'user_id', 'user_profile_id', 'user_name' );

			foreach ( $fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				}
			}
		}

		// ===============================
		// USER CONNECTION SAVE
		// ===============================
		if ( 'user_connections' === $post_type ) {

			$fields = array(
				'sender_user_id',
				'sender_profile_id',
				'sender_user_name',
				'receiver_user_id',
				'receiver_profile_id',
				'receiver_user_name',
				'status',
			);

			foreach ( $fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				}
			}
		}
	}
}
