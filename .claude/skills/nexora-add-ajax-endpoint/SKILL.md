---
name: nexora-add-ajax-endpoint
description: Add a new AJAX handler to the Nexora plugin (profile/connections/notifications/content or chat) using Http\Ajax::register, the shared guard, rate limiting and the JS call. Use when asked to add or change an AJAX action.
---

# Add a Nexora AJAX endpoint

Member handlers live in the class of their feature and extend `Nexora\Http\Member_Ajax`:

| Feature | Class | Nonce action | JS global |
| --- | --- | --- | --- |
| Profile edits, documents, password | `Profile\Ajax` | `profile_nonce` | `profilePageData.nonce` (`profileData` on login/registration) |
| Connections tab | `Connections\Ajax` (rules in `Connections\Service`, queries in `Connections\Repository`) | `profile_nonce` | same |
| Notifications | `Notifications\Ajax` | `profile_nonce` | same |
| Content | `Content\Ajax` | `profile_nonce` | same |
| Chat | `Chat\Ajax` (`authorize()`, `require_participant()`) | `nexora_chat_nonce` | `nexoraChat.nonce` |

## Steps
1. Test first (`tdd-workflow`): use `nx_assert_guard_matrix()` and `nx_call_ajax()`; cover no nonce, wrong nonce, logged out, wrong user (ownership), valid, malformed input.
2. Register in the class constructor: `\Nexora\Http\Ajax::register('my_action', [$this, 'my_action']);`. This creates `wp_ajax_nexora_my_action` and keeps `wp_ajax_my_action` as a deprecated alias. Pass `true` as the third argument only for logged-out endpoints (login / registration / OTP), which also need reCAPTCHA (`Auth\Recaptcha`) and rate limiting.
3. First line of the handler: `$auth = $this->member();` (nonce + login + capability + profile; returns `user_id`, `profile_id`). Chat uses `$this->authorize()`.
4. Read input with `wp_unslash` + a sanitizer (`$this->post_value()`); cast IDs with `absint`.
5. Check ownership of anything referenced by ID: attachments via `$this->owns_attachment()` (and never accept `Profile\Private_Documents::is_private()` files for public use), threads via `require_participant()`, connection posts against the current user. Never trust an ID from the client.
6. Abusable action (creates rows, sends messages, guesses passwords)? `if (\Nexora\Http\Rate_Limiter::hit('my_bucket', 'u' . $user_id)) wp_send_json_error(Rate_Limiter::message());` and add the bucket to `Rate_Limiter::defaults()`.
7. Put rules in a Service and queries in a Repository, not in the handler. HTML replies come from `View::render()` templates.
8. Reply only with `wp_send_json_success()` / `wp_send_json_error()`. Business errors stay HTTP 200 + `success:false` (the front end has no error callbacks).
9. Front-end: call `nexora_my_action` from `assets/js/profile-page.js` (or `assets/js/chat.js`) using the localized `ajaxUrl` and `nonce`. A new localized value goes in the `wp_localize_script` call of the class that enqueues that script.
10. Bump `NEXORA_VERSION` (see `nexora-release-assets`).

## Notifications
To notify another user, go through `Notifications\Repository::insert()` (as `Connections\Service` does), never insert rows directly.
