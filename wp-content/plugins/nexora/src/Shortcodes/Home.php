<?php

namespace Nexora\Shortcodes;

use Nexora\Core\Urls;
use Nexora\Core\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [nexora_home] shortcode.
 *
 * Nothing on this page is hard-coded any more:
 *  - the numbers come from the database (members, connections, posts, chats)
 *  - hero text, features and testimonials come from Nexora > Settings
 *  - buttons follow the visitor's login state
 *  - images come from the media chosen in Settings (section skipped if none)
 */
class Home {

	const STATS_TRANSIENT = 'nexora_home_stats';

	/**
	 * Registers [nexora_home] and keeps its numbers fresh.
	 */
	public function __construct() {
		add_shortcode( 'nexora_home', array( $this, 'render_home_page' ) );

		// Keep the numbers fresh without querying on every page view
		add_action( 'user_register', array( $this, 'flush_stats' ) );
		add_action( 'deleted_user', array( $this, 'flush_stats' ) );
		add_action( 'save_post_user_connections', array( $this, 'flush_stats' ) );
		add_action( 'save_post_user_content', array( $this, 'flush_stats' ) );
		add_action( 'save_post_user_profile', array( $this, 'flush_stats' ) );
	}

	/**
	 * Drops the cached platform numbers.
	 */
	public function flush_stats() {
		delete_transient( self::STATS_TRANSIENT );
	}

	/*
	===============================
		DYNAMIC DATA
	=============================== */

	/**
	 * Live platform numbers (cached for an hour).
	 *
	 * @return array members, connections, posts, chats.
	 */
	public static function get_stats() {

		$stats = get_transient( self::STATS_TRANSIENT );

		if ( is_array( $stats ) ) {
			return $stats;
		}

		global $wpdb;

		$profiles = wp_count_posts( 'user_profile' );
		$content  = wp_count_posts( 'user_content' );

		$connections = new \WP_Query(
			array(
				'post_type'      => 'user_connections',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => 'status',
						'value' => 'accepted',
					),
				),
			)
		);

		$threads_table = $wpdb->prefix . 'nexora_threads';
		$threads       = 0;

		// The chat table only exists after the plugin was activated
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $threads_table ) ) === $threads_table ) {
			$threads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$threads_table}" );
		}

		$stats = array(
			'members'     => (int) ( $profiles->publish ?? 0 ),
			'connections' => (int) $connections->found_posts,
			'posts'       => (int) ( $content->publish ?? 0 ),
			'chats'       => $threads,
		);

		set_transient( self::STATS_TRANSIENT, $stats, HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * Compact number: 1,250 -> 1.2K
	 *
	 * @param int|string $n The number.
	 * @return string
	 */
	public static function short_number( $n ) {

		$n = (int) $n;

		if ( $n >= 1000000 ) {
			return round( $n / 1000000, 1 ) . 'M';
		}
		if ( $n >= 1000 ) {
			return round( $n / 1000, 1 ) . 'K';
		}

		return number_format_i18n( $n );
	}

	/**
	 * Parse "a | b | c" lines from a settings textarea.
	 *
	 * @param string $option Option holding the textarea.
	 * @param int    $columns Number of "|"-separated cells per line.
	 * @return array[]
	 */
	private function parse_lines( $option, $columns ) {

		$rows  = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) get_option( $option, '' ) );

		foreach ( $lines as $line ) {

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$cells = array_map( 'trim', explode( '|', $line, $columns ) );
			$cells = array_pad( $cells, $columns, '' );

			$rows[] = $cells;
		}

		return $rows;
	}

	/**
	 * Feature cards shown when none are set in Settings.
	 *
	 * @return array[] Pairs of title and description.
	 */
	public static function default_features() {
		return array(
			array( __( 'Real-time chat', 'nexora' ), __( 'Instant conversations with subject-based threads.', 'nexora' ) ),
			array( __( 'Smart connections', 'nexora' ), __( 'Build a meaningful network, not random followers.', 'nexora' ) ),
			array( __( 'Share content', 'nexora' ), __( 'Post updates with images and keep a personal history.', 'nexora' ) ),
			array( __( 'Notifications', 'nexora' ), __( 'Stay up to date on requests and responses.', 'nexora' ) ),
			array( __( 'Your profile', 'nexora' ), __( 'Showcase your identity, work and documents securely.', 'nexora' ) ),
		);
	}

	/**
	 * Option or.
	 *
	 * @param string $name Rate-limit bucket name.
	 * @param string $fallback Text used when the option is empty.
	 * @return string
	 */
	private function option_or( $name, $fallback ) {

		$value = get_option( $name );

		return $value ? $value : $fallback;
	}

	/**
	 * Renders the landing page.
	 *
	 * @return string HTML.
	 */
	public function render_home_page() {

		$logged_in = is_user_logged_in();

		$cover_id = (int) get_option( 'default_home_cover_image' );

		$stats = self::get_stats();

		$features = $this->parse_lines( 'nexora_home_features', 2 );
		if ( ! $features ) {
			$features = self::default_features();
		}

		// Preview images: only the ones set in Settings
		$shots = array();
		foreach ( array(
			array( 'default_feed_experience_image', __( 'Feed experience', 'nexora' ) ),
			array( 'default_real_time_chat_image', __( 'Real-time chat', 'nexora' ) ),
			array( 'default_smart_connections_image', __( 'Smart connections', 'nexora' ) ),
		) as $item ) {
			$id  = (int) get_option( $item[0] );
			$url = $id ? wp_get_attachment_url( $id ) : '';
			if ( $url ) {
				$shots[] = array( $url, $item[1] );
			}
		}

		$vars = array(
			'logged_in'    => $logged_in,
			'login_url'    => Urls::login( true ),
			'reg_url'      => Urls::registration( true ),
			'eyebrow'      => $this->option_or( 'nexora_home_eyebrow', __( 'Your professional network', 'nexora' ) ),
			'title'        => $this->option_or( 'nexora_home_title', __( 'Connect. Grow. Discover.', 'nexora' ) ),
			'subtitle'     => $this->option_or( 'nexora_home_subtitle', __( 'Nexora helps you connect, share, and grow your network in real-time.', 'nexora' ) ),
			'cover'        => $cover_id ? wp_get_attachment_url( $cover_id ) : '',
			'stats'        => array_map( array( self::class, 'short_number' ), $stats ),
			'features'     => $features,
			'shots'        => $shots,
			'testimonials' => $this->parse_lines( 'nexora_home_testimonials', 3 ),
		);

		if ( $logged_in ) {
			$vars['user']        = wp_get_current_user();
			$vars['primary_url'] = Urls::profile_for( $vars['user'], true );
		}

		return View::render( 'shortcodes/home', $vars );
	}
}
