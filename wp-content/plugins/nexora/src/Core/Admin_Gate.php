<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one switch that lets an administrator past Core\Post_Guard. It is open for ONE post and ONE operation, and only when all of
 * this is true at the same moment:
 *
 *  - the user has `manage_options`;
 *  - the request is a real wp-admin one (the post.php screen), not the front end, not admin-ajax, not REST, not cron;
 *  - it is the edit-screen save of that post (a POST, `editpost`, `post_ID` = the post) or the row action asked for (a GET,
 *    `action` = trash / untrash / delete, `post` = the post);
 *  - the WordPress nonce for that exact post and action is valid.
 *
 * Creating a post is never lifted. Nothing in here changes state: it only answers yes or no.
 */
class Admin_Gate {

	/**
	 * Whether the current request is a genuine administrator action of this kind on this post.
	 *
	 * @param int    $post_id   The post being changed.
	 * @param string $operation edit | trash | untrash | delete.
	 * @return bool
	 */
	public static function open( $post_id, $operation ) {

		$post_id = (int) $post_id;

		if ( $post_id <= 0 || ! in_array( $operation, array( 'edit', 'trash', 'untrash', 'delete' ), true ) ) {
			return false;
		}

		if ( ! self::admin_screen() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// The request carries the action, the post and the nonce; the nonce is verified below, per post and action.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $operation ) {

			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			$ok     = 'POST' === $method
				&& isset( $_POST['action'], $_POST['post_ID'] )
				&& 'editpost' === sanitize_text_field( wp_unslash( $_POST['action'] ) )
				&& (int) $_POST['post_ID'] === $post_id;
			$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			$action = 'update-post_' . $post_id;

		} else {

			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			$ok     = 'GET' === $method
				&& isset( $_GET['action'], $_GET['post'] )
				&& sanitize_text_field( wp_unslash( $_GET['action'] ) ) === $operation
				&& (int) $_GET['post'] === $post_id;
			$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$action = $operation . '-post_' . $post_id;
		}
		// phpcs:enable

		return $ok && '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Whether this process is serving the post.php screen of wp-admin (and nothing automatic: no AJAX, REST or cron).
	 *
	 * @return bool
	 */
	private static function admin_screen() {

		global $pagenow;

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		return is_admin() && 'post.php' === $pagenow;
	}
}
