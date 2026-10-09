<?php

namespace Nexora\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object-cache layer for the connection lists. Every list is stored under a version
 * stamp that any change to a user_connections post (status, parties, trash, delete)
 * replaces, so a cached list is never served after the connections changed.
 */
class Cache {

	const GROUP = 'nexora_connections';

	/**
	 * Meta keys whose change alters a connection list.
	 *
	 * @var string[]
	 */
	const META_KEYS = array( 'status', 'sender_profile_id', 'receiver_profile_id' );

	/**
	 * Hooks the invalidation.
	 */
	public function __construct() {

		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ), 10, 2 );
	}

	/**
	 * Returns the cached value, or builds and stores it.
	 *
	 * @param string   $name Cache entry name.
	 * @param int      $profile_id Profile (user_profile) post ID.
	 * @param callable $build Builds the value on a miss.
	 * @return mixed
	 */
	public static function remember( $name, $profile_id, $build ) {

		$key   = $name . '_' . (int) $profile_id . '_' . self::version();
		$value = wp_cache_get( $key, self::GROUP );

		if ( false === $value ) {
			$value = call_user_func( $build );
			wp_cache_set( $key, $value, self::GROUP, HOUR_IN_SECONDS );
		}

		return $value;
	}

	/**
	 * Version stamp the cached lists are stored under.
	 *
	 * @return string
	 */
	private static function version() {

		$version = wp_cache_get( 'version', self::GROUP );

		if ( false === $version ) {
			$version = (string) microtime( true );
			wp_cache_set( 'version', $version, self::GROUP );
		}

		return $version;
	}

	/**
	 * Retires every cached connection list.
	 */
	public static function flush() {
		wp_cache_set( 'version', (string) microtime( true ), self::GROUP );
	}

	/**
	 * Flushes when a connection's status or parties change.
	 *
	 * @param int    $meta_id Meta row ID (unused).
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ) {

		if ( in_array( $meta_key, self::META_KEYS, true ) && get_post_type( $post_id ) === 'user_connections' ) {
			self::flush();
		}
	}

	/**
	 * Flushes when a connection is created, trashed or restored.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post The post.
	 */
	public function on_status_transition( $new_status, $old_status, $post ) {

		if ( $new_status !== $old_status && 'user_connections' === $post->post_type ) {
			self::flush();
		}
	}

	/**
	 * Flushes when a connection is deleted for good.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post The deleted post.
	 */
	public function on_deleted_post( $post_id, $post = null ) {

		if ( $post && 'user_connections' === $post->post_type ) {
			self::flush();
		}
	}
}
