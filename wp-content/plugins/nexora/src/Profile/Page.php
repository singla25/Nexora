<?php

namespace Nexora\Profile;

use Nexora\Connections\Repository as Connections;
use Nexora\Content\Repository as Content;
use Nexora\Core\Assets;
use Nexora\Core\Urls;
use Nexora\Core\View;
use Nexora\Notifications\Repository as Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [profile_dashboard] page: /profile-page and /profile-page/<username>.
 * This class decides what to show to whom; the markup lives in templates/profile/.
 */
class Page {

	/**
	 * Registers the shortcode, assets and the /profile-page/<username> rewrite.
	 */
	public function __construct() {

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'profile_dashboard', array( $this, 'render_profile' ) );

		add_action( 'init', array( $this, 'rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
	}

	/**
	 * Loads the profile scripts and hands the page its data (profile page only).
	 */
	public function enqueue_assets() {

		// Profile scripts, the media library and the owner's private data are only for the profile page
		if ( ! Assets::is_page_for( 'profile-page', 'profile_dashboard' ) ) {
			return;
		}

		Assets::enqueue_tokens();
		wp_enqueue_style( 'profile-page-style', NEXORA_URL . 'assets/css/profile-page.css', array( 'nexora-tokens' ), NEXORA_VERSION );

		Assets::enqueue_sweetalert();

		wp_enqueue_script( 'profile-page-js', NEXORA_URL . 'assets/js/profile-page.js', array( 'jquery', 'sweetalert2' ), NEXORA_VERSION, true );

		// wp.media() is only needed by logged-in members (uploads)
		if ( is_user_logged_in() ) {
			wp_enqueue_media();
		}

		$current_user_id = get_current_user_id();
		$profile_id      = 0;
		$owner_user_id   = 0;

		$username = get_query_var( 'username' );

		if ( $username ) {
			$profile_id = Repository::id_by_username( $username );

			if ( $profile_id ) {
				$owner_user_id = (int) get_post_meta( $profile_id, '_wp_user_id', true );
			}
		} elseif ( $current_user_id ) {
			$profile_id    = (int) get_user_meta( $current_user_id, '_profile_id', true );
			$owner_user_id = $current_user_id;
		}

		$role_type = Privacy::role( $current_user_id, $owner_user_id );

		wp_localize_script(
			'profile-page-js',
			'profilePageData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'profile_nonce' ),
				'homeUrl'         => home_url(),
				'current_user_id' => $current_user_id,
				'roleType'        => $role_type,
				'userData'        => Privacy::script_data( $profile_id, $role_type ),
			)
		);
	}

	/**
	 * Maps /profile-page/<username> to the profile page.
	 */
	public function rewrite_rule() {
		add_rewrite_rule(
			'^profile-page/([^/]+)/?$',
			'index.php?pagename=profile-page&username=$matches[1]',
			'top'
		);
	}

	/**
	 * Registers the username query variable.
	 *
	 * @param string[] $vars Registered query variables.
	 * @return string[] Query variables including username.
	 */
	public function query_vars( $vars ) {
		$vars[] = 'username';
		return $vars;
	}

	/**
	 * Renders [profile_dashboard]: a login wall, admin card, not-found card or the profile.
	 *
	 * @return string HTML.
	 */
	public function render_profile() {

		// Only run on profile page
		if ( ! is_page( 'profile-page' ) ) {
			return '';
		}

		$username        = get_query_var( 'username' );  // From URL
		$current_user_id = get_current_user_id();

		// CASE 1: Guest User
		if ( ! $current_user_id ) {
			return View::render(
				'profile/guest-wall',
				array(
					'login_url'    => Urls::login(),
					'register_url' => Urls::registration(),
				)
			);
		}

		// CASE 2: Admin User
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return View::render(
				'profile/admin-mode',
				array(
					'user'          => wp_get_current_user(),
					'dashboard_url' => admin_url() . '?admin_access=true',
				)
			);
		}

		// CASE 3: Own profile (/profile-page)
		if ( ! $username ) {

			$profile_id    = (int) get_user_meta( $current_user_id, '_profile_id', true );
			$owner_user_id = $current_user_id;

			if ( ! $profile_id ) {
				return '<p>Profile not found.</p>';
			}
		} else {

			// CASE 4: Other user's profile (/profile-page/username)

			$profile_id = Repository::id_by_username( $username );

			if ( ! $profile_id ) {
				return View::render( 'profile/not-found', array( 'home_url' => home_url() ) );
			}

			$owner_user_id = get_post_meta( $profile_id, '_wp_user_id', true );
		}

		$role_type = Privacy::role( $current_user_id, $owner_user_id );

		return View::render( 'profile/page', $this->view_data( $profile_id, $role_type, $current_user_id ) );
	}

	/**
	 * Everything the profile templates print, prepared here so templates only echo.
	 *
	 * @param int    $profile_id Profile (user_profile) post ID.
	 * @param string $role_type guest, viewer or owner.
	 * @param int    $current_user_id ID of the logged-in user.
	 * @return array
	 */
	private function view_data( $profile_id, $role_type, $current_user_id ) {

		$is_owner     = ( Privacy::OWNER === $role_type );
		$is_logged_in = ( Privacy::GUEST !== $role_type );

		$default_profile = $this->default_image_url( 'default_profile_image' );
		$default_cover   = $this->default_image_url( 'default_cover_image' );
		$default_doc     = $this->default_image_url( 'default_document_image' );

		$profile_image_id = get_post_meta( $profile_id, 'profile_image', true );
		$cover_image_id   = get_post_meta( $profile_id, 'cover_image', true );

		$unread = ( new Notifications() )->get_unread_count( $current_user_id );

		// The profile's own fields (the template decides which the viewer may see)
		$meta = array();
		foreach ( array_merge( Fields::PERSONAL, Fields::ADDRESS, Fields::WORK ) as $field ) {
			$meta[ $field ] = get_post_meta( $profile_id, $field, true );
		}

		$established = $this->established_connections( $profile_id );

		$mutual_count = 0;
		if ( $is_logged_in && ! $is_owner ) {
			$current_profile_id = get_user_meta( $current_user_id, '_profile_id', true );
			$mutual_count       = count(
				array_intersect(
					Connections::accepted_profile_ids( $current_profile_id ),
					Connections::accepted_profile_ids( $profile_id )
				)
			);
		}

		$feed = $this->content_feed( $current_user_id );

		return array(
			'profile_id'        => $profile_id,
			'is_owner'          => $is_owner,
			'is_logged_in'      => $is_logged_in,

			'name'              => get_the_title( $profile_id ),
			'username'          => get_post_meta( $profile_id, 'user_name', true ),
			// Contact details are private to the owner
			'email'             => $is_owner ? get_post_meta( $profile_id, 'email', true ) : '',
			'phone'             => $is_owner ? get_post_meta( $profile_id, 'phone', true ) : '',
			'profile_image'     => $profile_image_id ? wp_get_attachment_url( $profile_image_id ) : $default_profile,
			'cover_image'       => $cover_image_id ? wp_get_attachment_url( $cover_image_id ) : $default_cover,
			'unread_count'      => $unread,
			'logout_url'        => wp_logout_url( Urls::login() ),

			'meta'              => $meta,
			'docs'              => $this->document_cards( $profile_id, $is_owner, $default_profile, $default_cover, $default_doc ),

			'established'       => $established,
			'total_connections' => count( $established ),
			'mutual_count'      => $mutual_count,
			'preview'           => array_slice( $established, 0, 3 ),

			'notifications'     => $is_owner ? $this->notification_rows( $current_user_id ) : array(),

			'feed'              => $feed['rows'],
			'any_posts'         => $feed['any_exist'],
		);
	}

	/**
	 * URL of a default image option, or an empty string.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	private function default_image_url( $option ) {

		$id = get_option( $option );

		return $id ? wp_get_attachment_url( $id ) : '';
	}

	/**
	 * Cards of the Documents section. Only the owner sees ID documents.
	 *
	 * @param int    $profile_id Profile (user_profile) post ID.
	 * @param bool   $is_owner Whether the visitor owns the profile.
	 * @param string $default_profile Default profile image URL.
	 * @param string $default_cover Default cover image URL.
	 * @param string $default_doc Default document image URL.
	 * @return array[] Rows with label and url.
	 */
	private function document_cards( $profile_id, $is_owner, $default_profile, $default_cover, $default_doc ) {

		$labels = array(
			'profile_image'   => __( 'Profile Image', 'nexora' ),
			'cover_image'     => __( 'Cover Image', 'nexora' ),
			'aadhaar_card'    => __( 'Aadhaar Card', 'nexora' ),
			'driving_license' => __( 'Driving License', 'nexora' ),
			'company_id_card' => __( 'Company ID Card', 'nexora' ),
		);

		$cards = array();

		foreach ( $labels as $key => $label ) {

			// ID documents are private to the owner
			if ( ! $is_owner && ! in_array( $key, Private_Documents::PUBLIC_KEYS, true ) ) {
				continue;
			}

			$id  = get_post_meta( $profile_id, $key, true );
			$url = $id ? wp_get_attachment_url( $id ) : '';

			// Fallback to the default image
			if ( ! $url ) {
				if ( 'profile_image' === $key ) {
					$url = $default_profile;
				} elseif ( 'cover_image' === $key ) {
					$url = $default_cover;
				} else {
					$url = $default_doc;
				}
			}

			$cards[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return $cards;
	}

	/**
	 * The profile's accepted connections as cards.
	 *
	 * @param int $profile_id Profile (user_profile) post ID.
	 * @return array[]
	 */
	private function established_connections( $profile_id ) {

		$cards = array();

		foreach ( Connections::accepted_pairs( $profile_id ) as $pair ) {

			$other_id = $pair['profile_id'];
			$username = get_post_meta( $other_id, 'user_name', true );

			$cards[] = array(
				'connection_id' => $pair['connection_id'],
				'profile_id'    => $other_id,
				'username'      => $username,
				'name'          => Repository::full_name( $other_id ),
				'image'         => Repository::get_profile_image( $other_id ),
				'profile_link'  => Urls::profile( $username ),
			);
		}

		return $cards;
	}

	/**
	 * The member's notifications prepared for the template.
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	private function notification_rows( $user_id ) {

		$rows = array();

		foreach ( ( new Notifications() )->get_notifications( $user_id ) as $noti ) {

			$actor_profile_id = get_user_meta( $noti->actor_user_id, '_profile_id', true );

			$rows[] = array(
				'id'          => $noti->id,
				'is_read'     => (bool) $noti->is_read,
				'message'     => $this->format_notification_message( $noti ),
				'actor_image' => Repository::get_profile_image( $actor_profile_id ),
				'time'        => gmdate( 'd M Y • h:i A', strtotime( $noti->created_at ) ),
			);
		}

		return $rows;
	}

	/**
	 * Posts by other members (the content tab).
	 *
	 * @param int $current_user_id ID of the logged-in user.
	 * @return array rows (array[]) and any_exist (bool).
	 */
	private function content_feed( $current_user_id ) {

		$current_profile_id = get_user_meta( $current_user_id, '_profile_id', true );

		$feed = Content::feed_for( $current_profile_id );
		$rows = array();

		foreach ( $feed['posts'] as $post ) {

			$author_profile_id = get_post_meta( $post->ID, 'user_profile_id', true );
			$user_name         = get_post_meta( $post->ID, 'user_name', true );

			$rows[] = array(
				'title'        => $post->post_title,
				'content'      => $post->post_content,
				'image'        => get_the_post_thumbnail_url( $post->ID, 'medium' ),
				'user_name'    => $user_name,
				'full_name'    => get_post_meta( $author_profile_id, 'first_name', true ) . ' ' . get_post_meta( $author_profile_id, 'last_name', true ),
				'date'         => get_the_date( 'Y-m-d H:i', $post->ID ),
				'profile_link' => Urls::profile( $user_name ),
			);
		}

		return array(
			'rows'      => $rows,
			'any_exist' => $feed['any_exist'],
		);
	}

	/**
	 * Text shown for a notification row (UI transformation of the stored type).
	 *
	 * @param object $noti Notification row.
	 * @return string Text (already escaped).
	 */
	private function format_notification_message( $noti ) {

		$actor = esc_html( $noti->actor_user_name );

		switch ( $noti->type ) {

			case 'request':
				/* translators: %s: member name. */
				return sprintf( __( '%s sent you a connection request', 'nexora' ), $actor );

			case 'accepted':
				/* translators: %s: member name. */
				return sprintf( __( '%s accepted your connection request', 'nexora' ), $actor );

			case 'rejected':
				/* translators: %s: member name. */
				return sprintf( __( '%s rejected your connection request', 'nexora' ), $actor );

			case 'removed':
				/* translators: %s: member name. */
				return sprintf( __( '%s removed the connection with you', 'nexora' ), $actor );

			case 'content':
				/* translators: %s: member name. */
				return sprintf( __( '%s uploaded new content', 'nexora' ), $actor );

			default:
				return esc_html( $noti->message ); // fallback
		}
	}
}
