<?php

namespace Nexora\Notifications;

use Nexora\Http\Ajax as Http;

if (!defined('ABSPATH')) exit;

/**
 * Notification actions for logged-in members.
 */
class Ajax {

    public function __construct() {
        Http::register('mark_notification_read', [$this, 'mark_notification_read']);
    }

    public function mark_notification_read() {

        $user_id = Http::member('profile_nonce', false, 'Not logged in')['user_id'];

        $id = absint($_POST['id'] ?? 0);

        $notification = new Repository();

        $row = $notification->get_row($id);

        // A member can only touch their own notifications
        if (!$row || (int) $row->receiver_user_id !== (int) $user_id) {
            wp_send_json_error('Unauthorized');
        }

        $notification->mark_as_read($id);

        wp_send_json_success([
            'message' => $row->message
        ]);
    }
}
