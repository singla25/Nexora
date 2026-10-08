<?php

namespace Nexora\Integrations;

use Nexora\Connections\Repository as Connections;

if (!defined('ABSPATH')) exit;

/**
 * Third-party Better Messages plugin: its user search only offers people the member is
 * connected with (accepted connections, either direction).
 */
class Better_Messages {

    public function __construct() {

        add_filter('better_messages_search_user_sql_condition', [$this, 'nexora_filter_search_query_users'], 10, 4);
    }

    /**
     * @param string[] $conditions  SQL conditions Better Messages builds.
     * @param int[]    $included_ids
     * @param string   $search
     * @param int      $user_id     The member searching.
     * @return string[]
     */
    public function nexora_filter_search_query_users($conditions, $included_ids, $search, $user_id) {

        $profile_id = (int) get_user_meta($user_id, '_profile_id', true);

        $allowed_user_ids = [];

        if ($profile_id) {

            foreach (Connections::accepted_pairs($profile_id) as $pair) {

                $uid = (int) get_post_meta($pair['profile_id'], '_wp_user_id', true);

                if ($uid) {
                    $allowed_user_ids[] = $uid;
                }
            }
        }

        $allowed_user_ids = array_unique($allowed_user_ids);

        if (!empty($allowed_user_ids)) {
            $conditions[] = "AND ID IN (" . implode(',', array_map('intval', $allowed_user_ids)) . ")";
        } else {
            $conditions[] = "AND 1=0";
        }

        return $conditions;
    }
}
