<?php

namespace Nexora\Connections;

use Nexora\Chat\Repository as Chat;
use Nexora\Notifications\Repository as Notifications;

if (!defined('ABSPATH')) exit;

/**
 * Connection rules: who may request, accept, reject or remove, and the notification each change sends.
 * Methods return ['ok' => true] or ['ok' => false, 'message' => '...'] so callers decide how to respond.
 */
class Service {

    /**
     * @param int $sender_user_id
     * @param int $sender_profile_id
     * @param int $receiver_profile_id An existing user_profile post id.
     */
    public function send_request($sender_user_id, $sender_profile_id, $receiver_profile_id) {

        if ($receiver_profile_id === $sender_profile_id) {
            return $this->fail('You cannot connect with yourself');
        }

        // No duplicate pending / accepted connection in either direction
        if (Repository::active_between($sender_profile_id, $receiver_profile_id)) {
            return $this->fail('A connection or request already exists');
        }

        $sender = [
            'user_id'    => $sender_user_id,
            'profile_id' => $sender_profile_id,
            'user_name'  => get_post_meta($sender_profile_id, 'user_name', true),
        ];

        $receiver = [
            'user_id'    => (int) get_post_meta($receiver_profile_id, '_wp_user_id', true),
            'profile_id' => $receiver_profile_id,
            'user_name'  => get_post_meta($receiver_profile_id, 'user_name', true),
        ];

        $post_id = Repository::create_pending($sender, $receiver);

        if (!$post_id) {
            return $this->fail('Could not send request');
        }

        $notification = new Notifications();
        $notification->insert([
            'actor_user_id'      => $sender['user_id'],
            'actor_user_name'    => $sender['user_name'],
            'receiver_user_id'   => $receiver['user_id'],
            'receiver_user_name' => $receiver['user_name'],
            'type'               => 'request',
            'connection_id'      => $post_id,
            'message'            => "{$sender['user_name']} sent a connection request to {$receiver['user_name']}"
        ]);

        return ['ok' => true];
    }

    /**
     * Accept / reject (receiver only, pending only) or remove (either party, accepted only).
     *
     * @param int    $current_user_id
     * @param int    $connection_id  A user_connections post id.
     * @param string $status         accepted | rejected | removed
     */
    public function change_status($current_user_id, $connection_id, $status) {

        $sender_user_id   = (int) get_post_meta($connection_id, 'sender_user_id', true);
        $receiver_user_id = (int) get_post_meta($connection_id, 'receiver_user_id', true);
        $old_status       = Repository::status($connection_id);

        // Only the two people on the connection may change it
        if ($current_user_id !== $sender_user_id && $current_user_id !== $receiver_user_id) {
            return $this->fail('Unauthorized');
        }

        // Only the receiver may accept / reject, and only a pending request
        if (in_array($status, ['accepted', 'rejected'], true)) {
            if ($current_user_id !== $receiver_user_id || $old_status !== 'pending') {
                return $this->fail('Unauthorized');
            }
        }

        // Only an accepted connection can be removed
        if ($status === 'removed' && $old_status !== 'accepted') {
            return $this->fail('Connection is not active');
        }

        Repository::set_status($connection_id, $status);

        if ($status === 'removed') {
            (new Chat())->inactive_threads_by_connection($connection_id);
        }

        $this->notify_status_change($connection_id, $current_user_id, $sender_user_id, $receiver_user_id, $status);

        return ['ok' => true];
    }

    /**
     * Tell the other party what the acting user just did.
     */
    private function notify_status_change($connection_id, $current_user_id, $sender_user_id, $receiver_user_id, $status) {

        // (sender and receiver here are the two sides of the user_connections post)
        $sender_user_name   = get_post_meta($connection_id, 'sender_user_name', true);
        $receiver_user_name = get_post_meta($connection_id, 'receiver_user_name', true);

        if ($current_user_id == $sender_user_id) {

            $actor_user_id   = $sender_user_id;
            $actor_user_name = $sender_user_name;

        } else {

            $actor_user_id   = $receiver_user_id;
            $actor_user_name = $receiver_user_name;

            $receiver_user_id   = $sender_user_id;
            $receiver_user_name = $sender_user_name;
        }

        if ($status === 'accepted') {
            $message = "{$actor_user_name} accepted {$receiver_user_name} connection request";
        } elseif ($status === 'rejected') {
            $message = "{$actor_user_name} rejected {$receiver_user_name} connection request";
        } elseif ($status === 'removed') {
            $message = "{$actor_user_name} removed connection with {$receiver_user_name}";
        } else {
            $message = "Connection status updated";
        }

        $notification = new Notifications();
        $notification->insert([
            'actor_user_id'      => $actor_user_id,
            'actor_user_name'    => $actor_user_name,

            'receiver_user_id'   => $receiver_user_id,
            'receiver_user_name' => $receiver_user_name,

            'type'               => $status,
            'connection_id'      => $connection_id,

            'message'            => $message,
        ]);
    }

    private function fail($message) {
        return ['ok' => false, 'message' => $message];
    }
}
