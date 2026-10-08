<?php

namespace Nexora\Admin;

use Nexora\Chat\Repository as Chat;
use Nexora\Core\View;
use Nexora\Notifications\Repository as Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only admin overviews: every notification and every chat thread.
 */
class Pages {

	/**
	 * Lists every notification (Nexora System > Notifications).
	 */
	public function notifications_page() {

		View::output(
			'admin/notifications',
			array(
				'notifications' => ( new Notifications() )->get_all(),
			)
		);
	}

	/**
	 * Lists every chat thread with its two participants and last message (Nexora System > Nexora Chat).
	 */
	public function nexora_user_chat() {

		$threads = ( new Chat() )->get_all_threads_with_last_message();

		foreach ( $threads as $thread ) {

			$user_ids = explode( ',', $thread->participants );

			$thread->user1 = '-';
			$thread->user2 = '-';

			if ( isset( $user_ids[0] ) ) {
				$u1            = get_userdata( $user_ids[0] );
				$thread->user1 = $u1 ? $u1->display_name : '-';
			}

			if ( isset( $user_ids[1] ) ) {
				$u2            = get_userdata( $user_ids[1] );
				$thread->user2 = $u2 ? $u2->display_name : '-';
			}

			$thread->last_message_text = $thread->last_message ? wp_trim_words( $thread->last_message, 10 ) : '-';

			// The participant who is not the logged-in admin
			$thread->other_user = null;

			$admin_id = get_current_user_id();

			foreach ( $user_ids as $uid ) {
				if ( (int) $uid !== $admin_id ) {
					$thread->other_user = $uid;
					break;
				}
			}
		}

		View::output( 'admin/chat', array( 'threads' => $threads ) );
	}
}
