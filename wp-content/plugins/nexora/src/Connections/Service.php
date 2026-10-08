<?php

namespace Nexora\Connections;

use Nexora\Chat\Repository as Chat;
use Nexora\Notifications\Repository as Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection rules: who may request, accept, reject or remove, and the notification each change sends.
 * Methods return ['ok' => true] or ['ok' => false, 'message' => '...'] so callers decide how to respond.
 */
class Service {

	/**
	 * Send request.
	 *
	 * @param int $sender_user_id User ID of the sender.
	 * @param int $sender_profile_id Profile post ID of the sender.
	 * @param int $receiver_profile_id An existing user_profile post id.
	 * @return array ok (bool) and, on failure, message.
	 */
	public function send_request( $sender_user_id, $sender_profile_id, $receiver_profile_id ) {

		if ( $receiver_profile_id === $sender_profile_id ) {
			return $this->fail( __( 'You cannot connect with yourself', 'nexora' ) );
		}

		// No duplicate pending / accepted connection in either direction
		if ( Repository::active_between( $sender_profile_id, $receiver_profile_id ) ) {
			return $this->fail( __( 'A connection or request already exists', 'nexora' ) );
		}

		$sender = array(
			'user_id'    => $sender_user_id,
			'profile_id' => $sender_profile_id,
			'user_name'  => get_post_meta( $sender_profile_id, 'user_name', true ),
		);

		$receiver = array(
			'user_id'    => (int) get_post_meta( $receiver_profile_id, '_wp_user_id', true ),
			'profile_id' => $receiver_profile_id,
			'user_name'  => get_post_meta( $receiver_profile_id, 'user_name', true ),
		);

		$post_id = Repository::create_pending( $sender, $receiver );

		if ( ! $post_id ) {
			return $this->fail( __( 'Could not send request', 'nexora' ) );
		}

		$notification = new Notifications();
		$notification->insert(
			array(
				'actor_user_id'      => $sender['user_id'],
				'actor_user_name'    => $sender['user_name'],
				'receiver_user_id'   => $receiver['user_id'],
				'receiver_user_name' => $receiver['user_name'],
				'type'               => 'request',
				'connection_id'      => $post_id,
				'message'            => "{$sender['user_name']} sent a connection request to {$receiver['user_name']}",
			)
		);

		return array( 'ok' => true );
	}

	/**
	 * Accept / reject (receiver only, pending only) or remove (either party, accepted only).
	 *
	 * @param int    $current_user_id ID of the logged-in user.
	 * @param int    $connection_id A user_connections post id.
	 * @param string $status accepted | rejected | removed.
	 * @return array ok (bool) and, on failure, message.
	 */
	public function change_status( $current_user_id, $connection_id, $status ) {

		$sender_user_id   = (int) get_post_meta( $connection_id, 'sender_user_id', true );
		$receiver_user_id = (int) get_post_meta( $connection_id, 'receiver_user_id', true );
		$old_status       = Repository::status( $connection_id );

		// Only the two people on the connection may change it
		if ( $current_user_id !== $sender_user_id && $current_user_id !== $receiver_user_id ) {
			return $this->fail( __( 'Unauthorized', 'nexora' ) );
		}

		// Only the receiver may accept / reject, and only a pending request
		if ( in_array( $status, array( 'accepted', 'rejected' ), true ) ) {
			if ( $current_user_id !== $receiver_user_id || 'pending' !== $old_status ) {
				return $this->fail( __( 'Unauthorized', 'nexora' ) );
			}
		}

		// Only an accepted connection can be removed
		if ( 'removed' === $status && 'accepted' !== $old_status ) {
			return $this->fail( __( 'Connection is not active', 'nexora' ) );
		}

		Repository::set_status( $connection_id, $status );

		if ( 'removed' === $status ) {
			( new Chat() )->inactive_threads_by_connection( $connection_id );
		}

		$this->notify_status_change( $connection_id, $current_user_id, $sender_user_id, $receiver_user_id, $status );

		return array( 'ok' => true );
	}

	/**
	 * Tell the other party what the acting user just did.
	 *
	 * @param int    $connection_id Connection (user_connections) post ID.
	 * @param int    $current_user_id ID of the logged-in user.
	 * @param int    $sender_user_id User ID of the sender.
	 * @param int    $receiver_user_id User ID of the receiving member.
	 * @param string $status The new status.
	 */
	private function notify_status_change( $connection_id, $current_user_id, $sender_user_id, $receiver_user_id, $status ) {

		// (sender and receiver here are the two sides of the user_connections post)
		$sender_user_name   = get_post_meta( $connection_id, 'sender_user_name', true );
		$receiver_user_name = get_post_meta( $connection_id, 'receiver_user_name', true );

		if ( (int) $current_user_id === (int) $sender_user_id ) {

			$actor_user_id   = $sender_user_id;
			$actor_user_name = $sender_user_name;

		} else {

			$actor_user_id   = $receiver_user_id;
			$actor_user_name = $receiver_user_name;

			$receiver_user_id   = $sender_user_id;
			$receiver_user_name = $sender_user_name;
		}

		if ( 'accepted' === $status ) {
			$message = "{$actor_user_name} accepted {$receiver_user_name} connection request";
		} elseif ( 'rejected' === $status ) {
			$message = "{$actor_user_name} rejected {$receiver_user_name} connection request";
		} elseif ( 'removed' === $status ) {
			$message = "{$actor_user_name} removed connection with {$receiver_user_name}";
		} else {
			$message = __( 'Connection status updated', 'nexora' );
		}

		$notification = new Notifications();
		$notification->insert(
			array(
				'actor_user_id'      => $actor_user_id,
				'actor_user_name'    => $actor_user_name,

				'receiver_user_id'   => $receiver_user_id,
				'receiver_user_name' => $receiver_user_name,

				'type'               => $status,
				'connection_id'      => $connection_id,

				'message'            => $message,
			)
		);
	}

	/**
	 * Failure result with a message for the caller to show.
	 *
	 * @param string $message Message for the member.
	 * @return array ok => false and the message.
	 */
	private function fail( $message ) {
		return array(
			'ok'      => false,
			'message' => $message,
		);
	}
}
