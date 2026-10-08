<?php

namespace Nexora\Connections;

if (!defined('ABSPATH')) exit;

/**
 * Reads and writes user_connections posts. Meta keys: sender_/receiver_ + user_id, profile_id,
 * user_name, and status (pending, accepted, rejected, removed).
 */
class Repository {

    /** Profile ids connected (accepted) with this profile. */
    public static function accepted_profile_ids($profile_id) {

        $connections = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'status',
                    'value' => 'accepted'
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => 'sender_profile_id',
                        'value' => $profile_id
                    ],
                    [
                        'key' => 'receiver_profile_id',
                        'value' => $profile_id
                    ]
                ]
            ]
        ]);

        $ids = [];

        foreach ($connections as $conn) {

            $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
            $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

            $ids[] = ($sender == $profile_id) ? $receiver : $sender;
        }

        return $ids;
    }

    /**
     * Profiles that must not be offered as "add new": the profile itself and everyone it already
     * has a pending or accepted connection with.
     */
    public static function unavailable_profile_ids($profile_id) {

        $connections = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'sender_profile_id',   'value' => $profile_id],
                ['key' => 'receiver_profile_id', 'value' => $profile_id]
            ]
        ]);

        $blocked = [$profile_id];

        foreach ($connections as $conn_id) {

            $status = get_post_meta($conn_id, 'status', true);

            if (in_array($status, ['pending', 'accepted'], true)) {
                $blocked[] = (int) get_post_meta($conn_id, 'sender_profile_id', true);
                $blocked[] = (int) get_post_meta($conn_id, 'receiver_profile_id', true);
            }
        }

        return array_values(array_unique($blocked));
    }

    /** True when a pending or accepted connection exists between the two profiles, in either direction. */
    public static function active_between($profile_a, $profile_b) {

        $existing = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => ['pending', 'accepted'],
                    'compare' => 'IN'
                ],
                [
                    'relation' => 'OR',
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_profile_id',   'value' => $profile_a],
                        ['key' => 'receiver_profile_id', 'value' => $profile_b]
                    ],
                    [
                        'relation' => 'AND',
                        ['key' => 'sender_profile_id',   'value' => $profile_b],
                        ['key' => 'receiver_profile_id', 'value' => $profile_a]
                    ]
                ]
            ]
        ]);

        return !empty($existing);
    }

    /** Pending requests addressed to this profile. */
    public static function pending_for($profile_id) {

        return get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'receiver_profile_id',
                    'value' => $profile_id
                ],
                [
                    'key' => 'status',
                    'value' => 'pending'
                ]
            ]
        ]);
    }

    /** Every connection post received by this profile (any status). */
    public static function received_by($profile_id) {
        return self::by_side('receiver_profile_id', $profile_id);
    }

    /** Every connection post sent by this profile (any status). */
    public static function sent_by($profile_id) {
        return self::by_side('sender_profile_id', $profile_id);
    }

    private static function by_side($meta_key, $profile_id) {

        return get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => $meta_key,
                    'value' => $profile_id
                ]
            ]
        ]);
    }

    /** Connection posts where the WP user is sender or receiver. */
    public static function for_user($user_id) {

        return get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'sender_user_id',
                    'value' => $user_id
                ],
                [
                    'key' => 'receiver_user_id',
                    'value' => $user_id
                ]
            ]
        ]);
    }

    /**
     * Create a pending request. $sender / $receiver: user_id, profile_id, user_name.
     *
     * @return int Post id, 0 on failure.
     */
    public static function create_pending(array $sender, array $receiver) {

        $post_id = wp_insert_post([
            'post_type'   => 'user_connections',
            'post_status' => 'publish',
            'post_title'  => $sender['user_name'] . '->' . $receiver['user_name']
        ]);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        update_post_meta($post_id, 'sender_user_id', $sender['user_id']);
        update_post_meta($post_id, 'sender_profile_id', $sender['profile_id']);
        update_post_meta($post_id, 'sender_user_name', $sender['user_name']);

        update_post_meta($post_id, 'receiver_user_id', $receiver['user_id']);
        update_post_meta($post_id, 'receiver_profile_id', $receiver['profile_id']);
        update_post_meta($post_id, 'receiver_user_name', $receiver['user_name']);

        update_post_meta($post_id, 'status', 'pending');

        return (int) $post_id;
    }

    public static function status($connection_id) {
        return get_post_meta($connection_id, 'status', true);
    }

    public static function set_status($connection_id, $status) {
        update_post_meta($connection_id, 'status', $status);
    }
}
