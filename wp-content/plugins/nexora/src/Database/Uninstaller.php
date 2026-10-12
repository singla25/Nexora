<?php

namespace Nexora\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes everything the plugin stored - but only when the admin ticked
 * "Delete all data when the plugin is deleted" (default off). Members' WordPress user
 * accounts and Media Library uploads are never touched.
 */
class Uninstaller {

	const SETTING = 'nexora_delete_data_on_uninstall';

	/**
	 * What to remove: tables, options, user_meta keys, post_types, dirs, transient_prefixes.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Builds an uninstaller.
	 *
	 * @param array|null $config Overrides for the lists in defaults() (tests point it at scratch data).
	 */
	public function __construct( $config = null ) {
		$this->config = null !== $config ? array_merge( self::defaults(), $config ) : self::defaults();
	}

	/**
	 * Everything the plugin stores.
	 *
	 * @return array
	 */
	public static function defaults() {

		global $wpdb;

		$upload = wp_upload_dir();

		return array(
			'tables'             => array(
				$wpdb->prefix . 'nexora_notifications',
				$wpdb->prefix . 'nexora_threads',
				$wpdb->prefix . 'nexora_thread_participants',
				$wpdb->prefix . 'nexora_messages',
				$wpdb->prefix . 'nexora_message_meta',
			),
			'options'            => array(
				Migrations::OPTION,
				Migrations::LOCK,
				self::SETTING,
				'nexora_upload_cap_cleaned',
				'default_profile_image',
				'default_cover_image',
				'default_document_image',
				'default_home_cover_image',
				'default_feed_experience_image',
				'default_real_time_chat_image',
				'default_smart_connections_image',
				'default_admin_mail',
				'nexora_home_eyebrow',
				'nexora_home_title',
				'nexora_home_subtitle',
				'nexora_home_features',
				'nexora_home_testimonials',
				'recaptcha_site_key',
				'recaptcha_secret_key',
				'recaptcha_enabled',
			),
			'user_meta'          => array( '_profile_id', 'reset_otp', 'reset_token', 'reset_token_expiry', 'otp_attempts', 'otp_expiry' ),
			'post_types'         => array( 'user_profile', 'user_connections', 'user_content' ),
			'dirs'               => array( $upload['basedir'] . '/nexora-private' ),
			'transient_prefixes' => array( 'nexora_', 'nx_rl_', 'nx_reg_', 'nx_contact_' ),
		);
	}

	/**
	 * True when the admin asked for the data to be deleted.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::SETTING );
	}

	/**
	 * Deletes the data if (and only if) the setting is on.
	 *
	 * @return bool Whether anything was deleted.
	 */
	public function run() {

		if ( ! self::enabled() ) {
			return false;
		}

		$this->delete_posts();
		$this->delete_user_meta();
		$this->delete_transients();
		$this->drop_tables();
		$this->delete_dirs();
		// Options last: they hold the setting itself.
		$this->delete_options();

		return true;
	}

	/**
	 * Deletes the plugin's custom post type posts (and their meta), in batches.
	 */
	private function delete_posts() {

		foreach ( $this->config['post_types'] as $type ) {

			do {
				$ids = get_posts(
					array(
						'post_type'      => $type,
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit' ),
						'fields'         => 'ids',
						// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- batch size for a one-off cleanup.
						'posts_per_page' => 200,
						'no_found_rows'  => true,
					)
				);

				foreach ( $ids as $id ) {
					wp_delete_post( $id, true );
				}
			} while ( ! empty( $ids ) );
		}
	}

	/**
	 * Deletes the plugin's user meta for every user.
	 */
	private function delete_user_meta() {

		foreach ( $this->config['user_meta'] as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Deletes the plugin's transients (stats cache, OTP references, rate-limit counters).
	 */
	private function delete_transients() {

		global $wpdb;

		// One-off cleanup: direct queries, and caching does not apply to deletes.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		foreach ( $this->config['transient_prefixes'] as $prefix ) {

			foreach ( array( '_transient_', '_transient_timeout_' ) as $type ) {

				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( $type . $prefix ) . '%'
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Drops the custom tables.
	 */
	private function drop_tables() {

		global $wpdb;

		foreach ( $this->config['tables'] as $table ) {
			// Table names come from the plugin's own list, never from input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
		}
	}

	/**
	 * Deletes the private-file folders (members' ID documents).
	 */
	private function delete_dirs() {

		foreach ( $this->config['dirs'] as $dir ) {

			if ( ! is_dir( $dir ) || is_link( $dir ) ) {
				continue;
			}

			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {

				if ( $item->isDir() && ! $item->isLink() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					rmdir( $item->getPathname() );
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $dir );
		}
	}

	/**
	 * Deletes the plugin's options.
	 */
	private function delete_options() {

		foreach ( $this->config['options'] as $option ) {
			delete_option( $option );
		}
	}
}
