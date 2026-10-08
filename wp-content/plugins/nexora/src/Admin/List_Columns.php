<?php

namespace Nexora\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Extra columns on the three post-type list screens.
 */
class List_Columns {

    public function __construct() {

        add_filter('manage_user_profile_posts_columns', [$this, 'add_name_column']);
        add_action('manage_user_profile_posts_custom_column', [$this, 'manage_name_column'], 10, 2);

        add_filter('manage_user_connections_posts_columns', [$this, 'add_status_column']);
        add_action('manage_user_connections_posts_custom_column', [$this, 'manage_status_column'], 10, 2);

        add_filter('manage_user_content_posts_columns', [$this, 'add_user_name_column']);
        add_action('manage_user_content_posts_custom_column', [$this, 'manage_user_name_column'], 10, 2);
    }

    public function add_name_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['user_full_name'] = 'Name';
            }
        }

        return $new_columns;
    }

    public function manage_name_column($column, $post_id) {

        if ($column === 'user_full_name') {

            $first_name = get_post_meta($post_id, 'first_name', true);
            $last_name  = get_post_meta($post_id, 'last_name', true);
            $full_name  = $first_name . ' ' . $last_name;

            echo esc_html($full_name);
        }

    }

    public function add_status_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['connection_status'] = 'Status';
            }
        }

        return $new_columns;
    }

    public function manage_status_column($column, $post_id) {

        if ($column === 'connection_status') {

            $status = get_post_meta($post_id, 'status', true);

            if (!$status) {
                $status = 'pending';
            }

            if ($status === 'accepted') {
                echo '<span style="color: green; font-weight: 600;">Accepted</span>';
            } elseif ($status === 'rejected') {
                echo '<span style="color: red; font-weight: 600;">Rejected</span>';
            } elseif ($status === 'removed') {
                echo '<span style="color: #374151; font-weight: 600;">Removed</span>';
            } else {
                echo '<span style="color: orange; font-weight: 600;">Pending</span>';
            }
        }
    }

    public function add_user_name_column($columns) {

        $new_columns = [];

        foreach ($columns as $key => $value) {

            $new_columns[$key] = $value;

            // Add after Title column
            if ($key === 'title') {
                $new_columns['user_name'] = 'Name';
            }
        }

        return $new_columns;
    }

    public function manage_user_name_column($column, $post_id) {

        if ($column === 'user_name') {

            $user_profile_id = get_post_meta($post_id, 'user_profile_id', true);
            $first_name = get_post_meta($user_profile_id, 'first_name', true);
            $last_name  = get_post_meta($user_profile_id, 'last_name', true);
            $full_name  = $first_name . ' ' . $last_name;

            echo esc_html($full_name);
        }
    }
}
