---
name: nexora-data-model
description: How Nexora links WP users, profile posts, connections and content (CPT meta keys, statuses, ID conventions) and how to add or change a profile/connection/content field end to end. Use before reading or writing user_profile, user_connections or user_content data.
---

# Nexora data model

Three CPTs (registered in `src/PostTypes/Registrar.php`) hold the social data; there is no custom table for them.

| CPT | Key meta | Notes |
| --- | --- | --- |
| `user_profile` | `_wp_user_id`, `user_name`, `first_name`, `last_name`, `email`, `phone`, `bio`, address/work fields, image fields | One per member. Image/document fields store **attachment IDs**. |
| `user_connections` | `sender_user_id`, `sender_profile_id`, `sender_user_name`, `receiver_*` (same three), `status` | `status` is `pending` -> `accepted` / `rejected` / `removed`. Only `pending` and `accepted` block a new request. |
| `user_content` | `user_id`, `user_profile_id`, `user_name` | The member's feed posts. |

- WP user -> profile: `get_user_meta($user_id, '_profile_id', true)`. Profile -> WP user: `get_post_meta($profile_id, '_wp_user_id', true)`. Both are written in `src/Auth/Registration.php`.
- Both user IDs and profile IDs appear in connection meta; don't mix them up. Notifications use **user** IDs.
- Connection lookups already exist: `Connections\Repository::accepted_profile_ids($profile_id)`, `accepted_pairs()`, `for_user()`, `active_between()`. Reuse them instead of new `meta_query` copies.

## Adding a profile field
1. Front-end form + save: add the key to the field list in the matching `update_*_info()` in `src/Profile/Ajax.php` (key lists are `Profile\Fields`) (sanitize; use `absint` for attachment IDs and `user_owns_attachment()`), and render it in `class-profile-page.php` + `assets/js/profile-page.js`.
2. Admin edit screen: add the input in the matching `user_*_details()` meta box and add the key to the `$fields` array in `save_meta_boxes()` (image keys also go in its `absint` list). Without this the admin save silently drops it.
3. Registration only: add to `registration_form_handle()` in `src/Auth/Registration.php`.
4. Follow `nexora-release-assets`.

## Gotchas
- `save_meta_boxes()` returns early for AJAX and non-admins on purpose; front-end `wp_insert_post()` calls must not be overwritten by `$_POST`.
- Profile URLs are `/profile-page/<user_name>` (rawurlencode the name); admins view `/profile-page/` with no username.
