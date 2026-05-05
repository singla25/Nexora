<?php

if (!defined('ABSPATH')) exit;

class NEXORA_PROFILE_HELPER {

    public static function get_user_connection_ids($profile_id) {

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

    public static function get_profile_image($profile_id) {

        $image_id = get_post_meta($profile_id, 'profile_image', true);

        $default_id = get_option('default_profile_image');
        $default_url = $default_id ? wp_get_attachment_url($default_id) : '';

        return $image_id 
            ? wp_get_attachment_url($image_id) 
            : $default_url;
    }
}