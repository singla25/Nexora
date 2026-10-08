---
name: nexora-add-ajax-endpoint
description: Add a new wp_ajax_* handler to the Nexora plugin (profile/connections/notifications or chat) with the correct nonce, login and ownership checks, plus the JS call. Use when asked to add or change an AJAX action.
---

# Add a Nexora AJAX endpoint

Two AJAX families exist; pick by feature and reuse its guard.

| Family | File | Nonce action | JS global (nonce key) | Guard helper |
| --- | --- | --- | --- | --- |
| Profile, connections, notifications, content | `wp-content/plugins/nexora/includes/class-profile-ajax.php` | `profile_nonce` | `profilePageData.nonce` (also `profileData` on login/registration) | `validate_request()` returns `user_id` + `profile_id` |
| Chat | `wp-content/plugins/nexora/chat/class-chat-ajax.php` | `nexora_chat_nonce` | `nexoraChat.nonce` | `authorize()`, `require_participant($thread_id, $user_id)` |

## Steps
1. Register in the class constructor: `add_action('wp_ajax_<name>', [$this, '<name>']);`. Do not add `wp_ajax_nopriv_*` unless the action is for logged-out visitors (login/registration/OTP in `class-login.php` / `class-registration.php`); those need their own abuse protection (reCAPTCHA via `Nexora_ReCaptcha`).
2. First line of the handler: call the family's guard (never skip). It does nonce, login and (profile) profile-post checks.
3. Read input with `wp_unslash` + a sanitizer (`post_value()` exists in the profile class). Cast IDs with `(int)`.
4. Check ownership of anything referenced by ID: attachments via `user_owns_attachment()`, threads via `require_participant()`, connection posts against the current user. Never trust an ID from the client.
5. Reply only with `wp_send_json_success()` / `wp_send_json_error()`. Escape any HTML you return.
6. Front-end: add the call in the matching JS (`assets/js/profile-page.js`, or `chat/assets/js/chat.js`) using the localized `ajax_url` and `nonce`. A new localized value goes in the `wp_localize_script` call of the class that enqueues that script.
7. Bump `NEXORA_VERSION` in `nexora.php` (see skill `nexora-release-assets`).

## Notifications
If the action should notify another user, create it through `NEXORA_Notification` (`includes/class-notification.php`) rather than inserting rows directly.
