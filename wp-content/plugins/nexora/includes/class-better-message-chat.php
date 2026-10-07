<?php

if (!defined('ABSPATH')) exit;

class Nexora_Better_Message_CHAT_Page {

    public function __construct() {

        add_filter('better_messages_search_user_sql_condition', [$this, 'nexora_filter_search_query_users'], 10, 4);
    }

    // CHAT FILTER
    function nexora_filter_search_query_users($conditions, $included_ids, $search, $user_id){

        // current profile
        $profile_id = get_user_meta($user_id, '_profile_id', true);

        $connections = get_posts([
            'post_type' => 'user_connections',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => 'status',
                    'value' => 'accepted'
                ]
            ]
        ]);

        $allowed_user_ids = [];

        foreach ($connections as $conn) {

            $sender = get_post_meta($conn->ID, 'sender_profile_id', true);
            $receiver = get_post_meta($conn->ID, 'receiver_profile_id', true);

            if ($sender == $profile_id) {
                $uid = $this->nexora_get_user_by_profile($receiver);
                if ($uid) $allowed_user_ids[] = $uid;
            }

            if ($receiver == $profile_id) {
                $uid = $this->nexora_get_user_by_profile($sender);
                if ($uid) $allowed_user_ids[] = $uid;
            }
        }

        $allowed_user_ids = array_unique($allowed_user_ids);

        // MAIN CONTROL
        if (!empty($allowed_user_ids)) {
            $conditions[] = "AND ID IN (" . implode(',', array_map('intval', $allowed_user_ids)) . ")";
        } else {
            $conditions[] = "AND 1=0";
        }

        return $conditions;
    }

    function nexora_get_user_by_profile($profile_id) {
        $users = get_users([
            'meta_key' => '_profile_id',
            'meta_value' => $profile_id,
            'number' => 1
        ]);

        return !empty($users) ? $users[0]->ID : false;
    }
}