# Nexora AJAX reference

Every AJAX action the plugin registers, how a request is checked, what comes back, and which JavaScript calls it. Verified against `src/` and `assets/js/`. Back to the [developer guide](NEXORA-DEVELOPER-GUIDE.md).

Contents: [1 How AJAX works here](#1-how-ajax-works-here) · [2 Member endpoints](#2-member-endpoints-nonce-profile_nonce) · [3 Chat endpoints](#3-chat-endpoints-nonce-nexora_chat_nonce) · [4 Guest endpoints](#4-guest-endpoints) · [5 Other request types](#5-other-non-ajax-entry-points) · [6 Responses](#6-response-format) · [7 JS map](#7-javascript-map)

---

## 1. How AJAX works here

WordPress routes every call to `wp-admin/admin-ajax.php?action=X` (POST) to the hook `wp_ajax_X` for logged-in users and `wp_ajax_nopriv_X` for visitors. Nexora never writes those hook names by hand (except in `Chat\Ajax`, which registers the prefixed names directly, and `Private_Documents`). It calls:

```php
\Nexora\Http\Ajax::register( 'get_requests', array( $this, 'get_requests' ) );          // members
\Nexora\Http\Ajax::register( 'send_otp',     array( $this, 'send_otp' ), true );        // + visitors
```

`register()` ([Http/Ajax.php](../wp-content/plugins/nexora/src/Http/Ajax.php)) creates **two** names for the same handler:

| Name | Hook | Status |
|---|---|---|
| `nexora_get_requests` | `wp_ajax_nexora_get_requests` | current. The front-end JS uses these |
| `get_requests` | `wp_ajax_get_requests` | **deprecated alias**: fires `do_action('nexora_deprecated_ajax_action', $legacy, $new)` then calls the same handler. Kept so cached pages with old JS keep working. Do not remove before checking the hook shows no traffic |

For guest endpoints it also registers `wp_ajax_nopriv_` for both names.

### The standard guard

Member handlers extend [Http\Member_Ajax](../wp-content/plugins/nexora/src/Http/Member_Ajax.php) and begin with `$auth = $this->member();`, which calls `Http\Ajax::member('profile_nonce', true, ...)`:

```text
check_ajax_referer('profile_nonce','nonce')   → dies with -1 / error on a bad or missing nonce
is_user_logged_in()                           → "Unauthorized access"
current_user_can('read')                      → "Permission denied"
_profile_id user meta → a user_profile post   → "Profile not found"
returns ['user_id' => ..., 'profile_id' => ...]  (taken from the SESSION, never from the request)
```

Three different things are checked and they are not interchangeable: the **nonce** proves the request came from a page we rendered (CSRF protection); **login + capability** proves who is asking (authentication); the **ownership check** inside each handler proves they may touch *that* object (authorization). A nonce alone never authorizes anything.

Rate limits use [Http\Rate_Limiter](../wp-content/plugins/nexora/src/Http/Rate_Limiter.php) buckets (limit per window; per member id or per client IP):

| Bucket | Limit | Window |
|---|---|---|
| `login` | 10 | 15 min |
| `otp_send` | 5 | 15 min |
| `otp_verify` | 20 | 15 min |
| `reset_password` | 10 | 15 min |
| `register` | 5 | 1 h |
| `contact` | 3 | 10 min |
| `password_change` | 5 | 15 min |
| `connection_request` | 20 | 1 h |
| `content_save` | 20 | 1 h |
| `chat_message` | 30 | 1 min |

Override with the `nexora_rate_limits` filter. The client IP is `REMOTE_ADDR` unless `NEXORA_CLIENT_IP_HEADER` (constant) or the `nexora_client_ip_header` filter names a trusted proxy header.

Every endpoint is covered by `tests/php/test-endpoint-matrix.php`, which fails if an action is registered but not listed in its manifest.

---

## 2. Member endpoints (nonce `profile_nonce`)

All require login, `read` capability and a linked profile unless noted. Names below are the current `nexora_` names.

| Action | Handler | Input (POST) | Ownership / rules | Output (`data`) |
|---|---|---|---|---|
| `nexora_update_personal_info` | `Profile\Ajax::update_personal_info` | any of `first_name last_name phone gender birthdate linkedin_id bio` | writes only the session's own profile; gender whitelist; strict `Y-m-d` past date | message string |
| `nexora_update_address_info` | `Profile\Ajax::update_address_info` | the 8 address fields | own profile | message |
| `nexora_update_work_info` | `Profile\Ajax::update_work_info` | the 5 work fields | own profile; `company_email` must be a valid email | message |
| `nexora_update_documents_info` | `Profile\Ajax::update_documents_info` | `profile_image cover_image aadhaar_card driving_license company_id_card` (attachment ids; empty = remove) | attachment must belong to the user (or admin); private documents cannot become public images; a file already used publicly cannot become a document | message |
| `nexora_update_profile_password` | `Profile\Ajax::update_profile_password` | `current_password new_password confirm_password` | profile **not** required; verifies current password; ≥ 8 chars; must differ; rate-limited (`password_change`, per user); re-issues the auth cookie | message |
| `nexora_get_add_new_users` | `Connections\Ajax::get_add_new_users` | none | excludes self and anyone with a pending/accepted connection; max 200 | `[{profile_id, username, name, image}]` |
| `nexora_send_connection_request` | `Connections\Ajax::send_connection_request` | `receiver_profile_id` | must be a `user_profile`; not yourself; no active connection already; limiter `connection_request` per user | "Request sent" |
| `nexora_get_requests` | `Connections\Ajax::get_requests` | none | pending requests addressed to the session's profile | `[{connection_id, profile_id, username, name, image}]` |
| `nexora_update_connection_status` | `Connections\Ajax::update_connection_status` | `connection_id`, `status` ∈ `accepted rejected removed` | `Service::change_status`: caller must be sender or receiver; only the **receiver** of a **pending** request may accept/reject; only an **accepted** connection can be removed; removing closes chat threads; notifies the other side | none |
| `nexora_get_history` | `Connections\Ajax::get_history` | none | own received and sent requests | HTML (`profile/history`) |
| `nexora_view_all_connection` | `Connections\Ajax::view_all_connection` | `profile_id` | any member may list any profile's accepted connections (public network by design) | HTML (`profile/connection-cards`) |
| `nexora_view_mutual_connection` | `Connections\Ajax::view_mutual_connection` | `profile_id` | intersection of the caller's and that profile's accepted connections | HTML |
| `nexora_mark_notification_read` | `Notifications\Ajax::mark_notification_read` | `id` | the notification's `receiver_user_id` must equal the session user | `{message}` |
| `nexora_save_user_content` | `Content\Ajax::save_user_content` | `title`, `description`, `image` (attachment id) | title required; image must be the user's own and not a private document; limiter `content_save` per user | "Post created" |
| `nexora_get_user_content_history` | `Content\Ajax::get_user_content_history` | none | own posts (max 100) | HTML (`profile/content-history`) |

Errors are always `wp_send_json_error(message)` with HTTP 200.

---

## 3. Chat endpoints (nonce `nexora_chat_nonce`)

Registered directly in [Chat/Ajax.php](../wp-content/plugins/nexora/src/Chat/Ajax.php) with `add_action('wp_ajax_nexora_…')` (no legacy aliases; these were always prefixed). `authorize()` is `Http\Ajax::member('nexora_chat_nonce', false, 'Unauthorized')`: nonce + login, **profile not required**.

| Action | Handler | Input | Authorization | Output |
|---|---|---|---|---|
| `nexora_search_users` | `search_users` | `keyword` (empty = all) | only the caller's accepted connections are searched (case-insensitive match on username in PHP) | `[{user_id, username, connection_id, status}]` |
| `nexora_get_latest_thread_between_users` | `get_latest_thread_between_users` | `connection_id` | caller must be a party of that connection | `{thread_id, status}` (both `null` if no thread yet) |
| `nexora_get_messages` | `get_messages` | `thread_id` | `require_participant()` | latest 20 messages, oldest first, each with `sender_name`; **also marks the thread read for the caller** |
| `nexora_get_user_threads` | `get_user_threads` | none | own threads only (query joins on the session user) | threads (active first, newest first) with `unread_count`, `other_user_id`, `last_message`, `name`; served from the thread-list cache |
| `nexora_send_message` | `send_message` | `thread_id`, `message` (≤ 2000) | `require_participant()`; thread must be `active`; limiter `chat_message` per user | `{message_id}` |
| `nexora_create_thread_with_subject` | `create_thread_with_subject` | `user_id`, `subject` (≤ 255), `connection_id` | caller in the connection, status `accepted`, and `user_id` is the *other* party | `{thread_id}` |
| `nexora_get_thread_subject` | `get_thread_subject` | `thread_id` | `require_participant()` | subject |
| `nexora_update_subject` | `update_subject` | `thread_id`, `subject` | `require_participant()` | message |

---

## 4. Guest endpoints

Registered with `Ajax::register(..., true)`, so they exist for visitors too. All use the nonce `profile_nonce` (printed on the login/registration pages) and no login. Details and the OTP state machine: [AUTHENTICATION.md](AUTHENTICATION.md).

| Action | Handler | Input | Protection | Output |
|---|---|---|---|---|
| `nexora_profile_login` | `Auth\Login::handle_login` | `user_name` (login or email), `password`, `g-recaptcha-response` | nonce, limiter `login` (IP), reCAPTCHA, same error for unknown user / wrong password | `{redirect}` |
| `nexora_send_otp` | `Auth\Login::send_otp` | `username`, `email` | nonce, limiter `otp_send`; **identical reply** for unknown/mismatched accounts; no new OTP while one is valid | `{user_id: <opaque ref>, message}` |
| `nexora_verify_otp` | `Auth\Login::verify_otp` | `user_id` (the opaque ref), `otp` | nonce, limiter `otp_verify`; 5 wrong tries per OTP | `{message, token}` |
| `nexora_reset_password` | `Auth\Login::reset_password` | `user_id` (ref), `token`, `password` | nonce, limiter `reset_password`; valid hashed single-use token; ≥ 8 chars | `{redirect}` (also logs the member in) |
| `nexora_profile_register` | `Auth\Registration::registration_form_handle` | `email user_name password confirm_password first_name last_name phone gender birthdate g-recaptcha-response` | nonce, limiter `register` (IP, counts successes), reCAPTCHA, validation | `{message, redirect}` (also logs the member in) |

## 5. Other non-AJAX entry points

| Entry | Hook | Handler | Notes |
|---|---|---|---|
| ID document download | `wp_ajax_nexora_document` (GET, `?id=<attachment>&size=`) | `Profile\Private_Documents::serve` | logged in (401 otherwise), `can_view()` owner/admin only (403), file must exist (404); `nosniff`, `private, no-store` headers. Not JSON. Logged-in only: there is no `nopriv` hook |
| Contact form | `admin_post_nopriv_nexora_contact` and `admin_post_nexora_contact` | `Shortcodes\Contact_Form::handle` | form POST (not AJAX): nonce field `nx_contact_nonce`, honeypot, limiter `contact` (3 / 10 min), then redirect with a result flag |
| Better Messages filter | `better_messages_search_user_sql_condition` | `Integrations\Better_Messages` | restricts the third-party plugin's user search to accepted connections |

REST API: the plugin registers **no** REST routes, and its post types are not exposed to REST.

---

## 6. Response format

Every handler ends with exactly one of:

```php
wp_send_json_success( $data );   // {"success": true,  "data": <string|array|HTML>}
wp_send_json_error( $message );  // {"success": false, "data": "<message>"}
```

Both send HTTP **200**. Business errors (wrong password, not allowed, validation) are **not** HTTP errors: the front-end checks `response.success`. Only a failed nonce check produces a non-200/`-1` style answer from WordPress itself (`check_ajax_referer` → `wp_die`, HTTP 403 with body `-1`). The document download uses real HTTP codes (401/403/404) because it is not JSON.

Examples (real strings from the code): `{"success":true,"data":"Request sent"}`, `{"success":false,"data":"A connection or request already exists"}`, `{"success":false,"data":"Too many requests. Please try again later."}`.

Some endpoints return **server-rendered HTML** inside `data` (`get_history`, `view_all_connection`, `view_mutual_connection`, `get_user_content_history`). The HTML comes from a template, so escaping happens there.

---

## 7. JavaScript map

All requests are `jQuery.ajax`/`$.post` to the localized `ajaxUrl` (`admin-ajax.php`) with the localized nonce. The nonce and URL reach the browser via `wp_localize_script`:

| JS object | Set by | Fields |
|---|---|---|
| `profilePageData` | `Profile\Page::enqueue_assets` | `ajaxUrl, nonce, homeUrl, current_user_id, roleType, userData` |
| `nexoraChat` | `Chat\Module::enqueue_assets` | `ajax_url, user_id, nonce` |
| login / registration objects | `Auth\Login::login_enqueue_assets`, `Auth\Registration::enqueue_assets` | `ajaxUrl, nonce` |

| JS file | Does | Actions it calls |
|---|---|---|
| [assets/js/profile-page.js](../wp-content/plugins/nexora/assets/js/profile-page.js) (939 lines) | the profile dashboard: tabs, edit forms, media picker, connections, history, notifications, content | `nexora_update_personal_info`, `nexora_update_address_info`, `nexora_update_work_info`, `nexora_update_documents_info`, `nexora_update_profile_password` (chosen in a `type → action` switch around line 327), `nexora_get_add_new_users`, `nexora_get_requests`, `nexora_send_connection_request`, `nexora_update_connection_status`, `nexora_get_history`, `nexora_view_all_connection`, `nexora_view_mutual_connection`, `nexora_mark_notification_read`, `nexora_save_user_content`, `nexora_get_user_content_history`. `esc()` escapes interpolated text |
| [assets/js/chat.js](../wp-content/plugins/nexora/assets/js/chat.js) (659 lines) | chat popup: search, thread list, messages, subject; polls on a 3-second `setInterval` (chat.js line ~544) | all eight `nexora_*` chat actions. `nxEsc()` escapes interpolated text |
| [assets/js/profile-login.js](../wp-content/plugins/nexora/assets/js/profile-login.js) | login form and the SweetAlert OTP and reset popups (`showOtpPopup`, `showResetPasswordPopup`) | `nexora_profile_login`, `nexora_send_otp`, `nexora_verify_otp`, `nexora_reset_password` |
| [assets/js/profile-registration.js](../wp-content/plugins/nexora/assets/js/profile-registration.js) | registration form submit | `nexora_profile_register` |
| [assets/js/profile-admin.js](../wp-content/plugins/nexora/assets/js/profile-admin.js) | admin-only: media upload/remove buttons in meta boxes | none |
| `assets/lib/sweetalert2/sweetalert2.all.min.js` | bundled popup library (v11.14.5, served locally, not from a CDN) | none |

Flow for one interaction: `click → handler in profile-page.js → $.post(ajaxUrl, {action, nonce, ...}) → Http\Ajax → handler class → success callback → DOM update (esc()'d text or server HTML)`.

The front end has no `error` callbacks for business errors because they arrive as `success:false` with HTTP 200.
