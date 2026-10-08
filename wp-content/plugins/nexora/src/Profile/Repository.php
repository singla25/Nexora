<?php

namespace Nexora\Profile;

if (!defined('ABSPATH')) exit;

/**
 * Read access to user_profile posts.
 */
class Repository {

    /** Profile post id of a WP user (0 when none). */
    public static function id_for_user($user_id) {

        $id = (int) get_user_meta($user_id, '_profile_id', true);

        return ($id && get_post_type($id) === 'user_profile') ? $id : 0;
    }

    /** Profile post id for a username (the user_name meta), or 0. */
    public static function id_by_username($username) {

        $username = sanitize_user($username, true);

        if ($username === '') {
            return 0;
        }

        $ids = get_posts([
            'post_type'      => 'user_profile',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'user_name',
                    'value'   => $username,
                    'compare' => '='
                ]
            ]
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /** Name shown for a profile (first + last). */
    public static function full_name($profile_id) {
        return trim(get_post_meta($profile_id, 'first_name', true) . ' ' . get_post_meta($profile_id, 'last_name', true));
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
