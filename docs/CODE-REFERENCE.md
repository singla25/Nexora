# Nexora code reference

Every class under `src/`, with each method's visibility, signature and one-line purpose. **Generated from the source** (class and method docblocks) on branch `docs/developer-guide`; the docblocks in the code are the source of truth. Back to the [developer guide](NEXORA-DEVELOPER-GUIDE.md).

Legend: `public` methods are called by WordPress hooks, by other modules or by tests; `private` methods are internal helpers. A hook-registered method is attached in its class constructor. The "callers" for any method can be found with `grep -rn "method_name(" wp-content/plugins/nexora/src`.

### `Nexora\Admin\List_Columns`  
`src/Admin/List_Columns.php`

Extra columns on the three post-type list screens.

| Method | What it does |
|---|---|
| `public __construct()` | Adds the extra columns to the three post-type list screens. |
| `public add_name_column($columns)` | Adds a "Name" column after the title on the profile list. |
| `public manage_name_column($column, $post_id)` | Prints the member's first and last name in the "Name" column. |
| `public add_status_column($columns)` | Adds a "Status" column after the title on the connections list. |
| `public manage_status_column($column, $post_id)` | Prints the connection status (Accepted / Rejected / Removed / Pending) with its colour. |
| `public add_user_name_column($columns)` | Adds a "Name" column after the title on the content list. |
| `public manage_user_name_column($column, $post_id)` | Prints the author's full name in the content list. |

### `Nexora\Admin\Menu`  
`src/Admin/Menu.php`

The "Nexora System" admin menu and the admin script it needs.

| Method | What it does |
|---|---|
| `public __construct(Settings $settings, Pages $pages)` | Hooks the menu and admin script. |
| `public enqueue_admin_scripts()` | Loads the media library and the admin upload/remove script on admin screens. |
| `public register_main_menu()` | Registers the "Nexora System" menu with Settings, Notifications and Chat. |

### `Nexora\Admin\Meta_Boxes`  
`src/Admin/Meta_Boxes.php`

The edit-screen panels for user profiles, connections and content, and saving them. Markup lives in templates/admin/metabox-*.php.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the meta boxes and the save handler. |
| `public add_meta_boxes()` | Registers the panels on the profile, connection and content edit screens. |
| `private meta_of($post_id, array $keys)` | Meta values of a post for the given keys. |
| `public user_personal_details($post)` | Personal details panel of a profile. |
| `public user_address_details($post)` | Permanent and correspondence address panel of a profile. |
| `public user_work_details($post)` | Work details panel of a profile. |
| `public user_document_details($post)` | Documents panel of a profile (media upload for each file). |
| `public user_connection_details($post)` | Received and sent connection requests of a profile. |
| `private connection_rows(array $connections, $other_side)` | Rows for a connection list: the other side's profile id and user name, and the status. |
| `public user_content_details($post)` | Posts written by a profile. |
| `public user_chat_details($post)` | Chat overview of a profile: each connection and its conversations. |
| `public user_connection_meta_box($post)` | Sender, receiver and status fields of a connection. |
| `public user_connection_chat_box($post)` | Conversations that belong to a connection. |
| `public render_user_content_meta_box($post)` | Author fields of a content post. |
| `public save_meta_boxes($post_id)` | Saves the fields of the three edit screens. Administrators only; ignored for autosaves, revisions, AJAX and bad nonces. |

### `Nexora\Admin\Pages`  
`src/Admin/Pages.php`

Read-only admin overviews: every notification and every chat thread.

| Method | What it does |
|---|---|
| `public notifications_page()` | Lists every notification (Nexora System > Notifications). |
| `public nexora_user_chat()` | Lists every chat thread with its two participants and last message (Nexora System > Nexora Chat). |

### `Nexora\Admin\Settings`  
`src/Admin/Settings.php`

Nexora System > Settings: the options (registered through the Settings API) and the page that edits them.

| Method | What it does |
|---|---|
| `private images()` | Default-image options: option name, label, preview width. |
| `public __construct()` | Hooks the settings registration. |
| `public register_settings()` | Registers the options of the settings page with their sanitisers. |
| `public settings_page()` | Prints Nexora System > Settings. |

### `Nexora\Auth\Login`  
`src/Auth/Login.php`

The [profile_login] page: password login, and the email one-time-code flow for resetting a password.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the [profile_login] shortcode and the login / OTP / reset AJAX actions. |
| `public login_enqueue_assets()` | Loads the login scripts, styles and captcha on the login page only. |
| `public send_otp()` | Emails a one-time code to a member who asks to reset their password. The reply is identical for unknown accounts. |
| `public verify_otp()` | Checks the one-time code and, if right, returns a short-lived reset token. |
| `public reset_password()` | Sets a new password for a member who holds a valid reset token, then logs them in. |
| `public login_form()` | Renders the login form, or an "already logged in" card. |
| `public handle_login()` | Logs a member in by username or email after the captcha and rate-limit checks. |

### `Nexora\Auth\Otp`  
`src/Auth/Otp.php`

Password-reset proof of ownership: a short emailed OTP, swapped for a single-use reset token. Storage (user meta): reset_otp (hash), otp_expiry, otp_attempts, reset_token (hash), reset_token_expiry. The browser never learns a user id: it holds an opaque reference (see issue_ref) that maps to the account server-side only for a real username+email pair.

| Method | What it does |
|---|---|
| `public static issue_ref($user_id = 0)` | New opaque reference. With a user id it resolves to that account; without one it is a |
| `public static resolve_ref($ref)` | Account id behind a reference, or 0. |
| `public static forget_ref($ref)` | Discards a reset reference once it is no longer needed. |
| `public static has_active_otp($user_id)` | True while an issued OTP has not expired yet. |
| `public static issue($user_id)` | Create an OTP for the user and return it in clear (to be emailed). Only a hash is stored. |
| `public static verify($user_id, $otp)` | Check an OTP. On success it is consumed and a one-time reset token is returned. |
| `public static token_valid($user_id, $token)` | True when $token is the live reset token of the user (does not consume it). |
| `public static clear($user_id)` | Remove every trace of an OTP / reset token. |

### `Nexora\Auth\Recaptcha`  
`src/Auth/Recaptcha.php`

Google reCAPTCHA: renders the widget and verifies the answer with Google. The keys and the on/off switch are options set in Nexora System > Settings.

| Method | What it does |
|---|---|
| `public __construct()` | Reads the captcha options. |
| `public is_local()` | The captcha is skipped only on a genuinely local site: |
| `public is_enabled()` | True when the captcha is switched on and both keys are set. |
| `public render()` | Returns the captcha widget markup, or an empty string when it is off. |
| `public enqueue_script()` | Loads Google's captcha script when the captcha is active. |
| `public verify($captcha_response)` | Checks the captcha response with Google. |

### `Nexora\Auth\Registration`  
`src/Auth/Registration.php`

The [profile_registration] page: creates a member (WP user + linked profile) from the sign-up form.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the [profile_registration] shortcode and the sign-up AJAX action. |
| `public enqueue_assets()` | Loads the registration scripts, styles and captcha on the registration page only. |
| `public registration_form()` | Renders the sign-up form, or an "already logged in" card. |
| `public registration_form_handle()` | Creates a member account from the sign-up form (captcha, rate limit, validation, profile, auto login). |
| `private read_input()` | Sanitised copy of the submitted form. |
| `private validate(array &$input)` | Ends the request with a JSON error for the first problem found. |
| `private create_member(array $input)` | Creates the WP user and the linked user_profile post, notifies the admin and logs the |
| `private send_admin_notification($user_name, $email, $full_name)` | Emails the site admin that a new member signed up. |

### `Nexora\Chat\Ajax`  
`src/Chat/Ajax.php`

Chat AJAX actions: search connections, list conversations, read and send messages.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the chat AJAX actions. |
| `private authorize()` | Nonce and login check shared by every chat action. |
| `private require_participant($thread_id, $user_id)` | Stops the request unless the user is a participant of the thread. |
| `public search_users()` | Searches the member's accepted connections by username. |
| `public get_latest_thread_between_users()` | Returns the latest conversation of a connection. |
| `private user_in_connection($connection_id, $user_id)` | True when the user is the sender or receiver of the connection post. |
| `public get_messages()` | Returns the latest messages of a conversation and marks it read. |
| `public get_user_threads()` | Returns the member's conversations. |
| `public send_message()` | Sends a message into a conversation the member belongs to (rate limited). |
| `public create_thread_with_subject()` | Starts a conversation with an accepted connection. |
| `public get_thread_subject()` | Returns the subject of a conversation. |
| `public update_subject()` | Renames a conversation. |

### `Nexora\Chat\Module`  
`src/Chat/Module.php`

Chat module: loads the chat assets and prints the chat popup for logged-in members.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the chat assets and popup. |
| `public enqueue_assets()` | Loads the chat styles and script for logged-in members. |
| `public load_chat_template()` | Prints the chat popup in the footer for logged-in members. |

### `Nexora\Chat\Repository`  
`src/Chat/Repository.php`

Data access for chat: the threads, participants, messages and message_meta tables.

| Method | What it does |
|---|---|
| `private static cache_version()` | Version stamp of the cached thread lists; changing it retires every cached list. |
| `private static flush_cache()` | Retires the cached thread lists (any thread, message or read-state change). |
| `public __construct()` | Resolves the table names. |
| `public create_table()` | Creates or updates the chat tables (dbDelta: safe to run repeatedly). |
| `public create_thread($users, $connection_id, $thread_status, $type = 'private', $subject = '')` | Insert New Thread and It's Participants |
| `public get_thread_by_connection($connection_id)` | GET LATEST THREAD BETWEEN USERS |
| `public get_thread_status($thread_id)` | GET THREAD STATUS |
| `public is_user_in_thread($thread_id, $user_id)` | GET USER PARTICIPANTS |
| `public get_user_threads($user_id)` | GET USER THREADS (CHAT LIST) |
| `private query_user_threads($user_id)` | Uncached thread list for a member. |
| `public send_message($thread_id, $sender_id, $message)` | Send Messgae |
| `public get_latest_messages($thread_id, $limit = 20)` | Get Latest Messages (INITIAL LOAD) |
| `public mark_as_read_chat($thread_id, $user_id)` | MARK AS READ |
| `public get_all_threads()` | GET ALL USER THREADS (Thread List For Admin) |
| `public get_all_threads_with_last_message()` | GET ALL THREADS WITH LAST MESSAGE (ADMIN) |
| `public get_threads_by_connection($connection_id)` | Every conversation of a connection with its participant ids. |
| `public get_thread_subject($thread_id)` | GET THREAD SUBJECT |
| `public update_thread_subject($thread_id, $subject)` | UPDATE THREAD SUBJECT |
| `public inactive_threads_by_connection($connection_id)` | UPDATE THREAD STATUS |

### `Nexora\Connections\Ajax`  
`src/Connections/Ajax.php` · extends `Member_Ajax`

Connections tab: find people, send / answer requests, history, connection lists.

| Method | What it does |
|---|---|
| `public __construct(Service $service = null)` | Registers the connections AJAX actions. |
| `public get_add_new_users()` | Lists members the user can still send a request to. |
| `public send_connection_request()` | Sends a connection request to another member (rate limited). |
| `public get_requests()` | Lists pending requests addressed to the member. |
| `public update_connection_status()` | Accepts, rejects or removes a connection. |
| `public get_history()` | Renders the received / sent request history. |
| `private history_rows(array $connections, $other_side_meta_key)` | Rows for the history cards: the other person on each connection post. |
| `public view_all_connection()` | Renders all accepted connections of a profile. |
| `public view_mutual_connection()` | Renders the connections the member shares with another profile. |
| `private card_rows(array $profile_ids)` | Cards for a list of profile ids. |

### `Nexora\Connections\Cache`  
`src/Connections/Cache.php`

Object-cache layer for the connection lists. Every list is stored under a version stamp that any change to a user_connections post (status, parties, trash, delete) replaces, so a cached list is never served after the connections changed.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the invalidation. |
| `public static remember($name, $profile_id, $build)` | Returns the cached value, or builds and stores it. |
| `private static version()` | Version stamp the cached lists are stored under. |
| `public static flush()` | Retires every cached connection list. |
| `public on_meta_change($meta_id, $post_id, $meta_key)` | Flushes when a connection's status or parties change. |
| `public on_status_transition($new_status, $old_status, $post)` | Flushes when a connection is created, trashed or restored. |
| `public on_deleted_post($post_id, $post = null)` | Flushes when a connection is deleted for good. |

### `Nexora\Connections\Repository`  
`src/Connections/Repository.php`

Reads and writes user_connections posts. Meta keys: sender_/receiver_ + user_id, profile_id, user_name, and status (pending, accepted, rejected, removed).

| Method | What it does |
|---|---|
| `public static accepted_profile_ids($profile_id)` | Profile ids connected (accepted) with this profile. |
| `private static build_accepted_profile_ids($profile_id)` | Uncached version of accepted_profile_ids(). |
| `public static accepted_pairs($profile_id)` | Accepted connections of a profile as [connection post id, the other profile id] pairs, newest first. |
| `private static build_accepted_pairs($profile_id)` | Uncached version of accepted_pairs(). |
| `public static unavailable_profile_ids($profile_id)` | Profiles that must not be offered as "add new": the profile itself and everyone it already |
| `private static build_unavailable_profile_ids($profile_id)` | Uncached version of unavailable_profile_ids(). |
| `public static active_between($profile_a, $profile_b)` | True when a pending or accepted connection exists between the two profiles, in either direction. |
| `public static pending_for($profile_id)` | Pending requests addressed to this profile. |
| `public static received_by($profile_id)` | Every connection post received by this profile (any status). |
| `public static sent_by($profile_id)` | Every connection post sent by this profile (any status). |
| `private static by_side($meta_key, $profile_id)` | Connection posts whose given profile meta equals the profile id. |
| `public static for_user($user_id)` | Connection posts where the WP user is sender or receiver. |
| `public static create_pending(array $sender, array $receiver)` | Create a pending request. $sender / $receiver: user_id, profile_id, user_name. |
| `public static status($connection_id)` | Current status of a connection post. |
| `public static set_status($connection_id, $status)` | Stores a new status on a connection post. |

### `Nexora\Connections\Service`  
`src/Connections/Service.php`

Connection rules: who may request, accept, reject or remove, and the notification each change sends. Methods return ['ok' => true] or ['ok' => false, 'message' => '...'] so callers decide how to respond.

| Method | What it does |
|---|---|
| `public send_request($sender_user_id, $sender_profile_id, $receiver_profile_id)` | Send request. |
| `public change_status($current_user_id, $connection_id, $status)` | Accept / reject (receiver only, pending only) or remove (either party, accepted only). |
| `private notify_status_change($connection_id, $current_user_id, $sender_user_id, $receiver_user_id, $status)` | Tell the other party what the acting user just did. |
| `private fail($message)` | Failure result with a message for the caller to show. |

### `Nexora\Content\Ajax`  
`src/Content/Ajax.php` · extends `Member_Ajax`

Posts a member publishes on their profile.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the content AJAX actions. |
| `public save_user_content()` | Publishes a post on the member's profile (rate limited). |
| `public get_user_content_history()` | Renders the member's own posts. |

### `Nexora\Content\Repository`  
`src/Content/Repository.php`

Member posts (user_content). Meta: user_id, user_profile_id, user_name.

| Method | What it does |
|---|---|
| `public static create($user_id, $profile_id, $title, $description, $image_id = 0)` | Create. |
| `public static for_profile($profile_id, $limit = 100)` | A profile's own posts, newest first. |
| `public static feed_for($profile_id)` | The content feed for a profile: every post written by someone else, newest first. |

### `Nexora\Core\Access_Control`  
`src/Core/Access_Control.php`

Keeps members out of wp-admin and wp-login.php and sends everyone to the custom login / profile pages. Administrators are untouched; AJAX, REST, cron and admin-post.php stay open.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the admin-bar, wp-admin, wp-login and login-redirect rules. |
| `public hide_admin_bar()` | Hides the admin bar from everyone except administrators. |
| `public block_wp_admin()` | Sends visitors and members away from wp-admin (AJAX, REST, cron and admin-post stay open). |
| `public block_wp_login()` | Sends non-administrators away from wp-login.php (logout and post-password stay open). |
| `public login_redirect($redirect_to, $request, $user)` | Where a user lands after logging in. |

### `Nexora\Core\Assets`  
`src/Core/Assets.php`

Assets every Nexora page shares (design tokens, global stylesheet) plus the helpers the feature modules use to load their own scripts only where they are needed.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the global stylesheet. |
| `public enqueue_assets()` | Loads the design tokens and global stylesheet on every page. |
| `public static enqueue_tokens()` | Shared design tokens (+ the brand font when the Nexora theme, which |
| `public static enqueue_sweetalert()` | SweetAlert2 is shipped with the plugin (pinned version) instead of a floating CDN tag. |
| `public static is_page_for($slug, $shortcode)` | True when the current front-end page is the given page slug, or contains the |

### `Nexora\Core\Autoloader`  
`src/Core/Autoloader.php`

PSR-4 autoloader for the Nexora\ namespace (src/), no Composer needed on the server. Old global class names (NEXORA_System, NEXORA_CHAT_DB, ...) keep resolving through a lazy alias map, so code and tests written against them continue to work.

| Method | What it does |
|---|---|
| `public static register($base_dir, array $legacy_map = array()` | Registers the PSR-4 autoloader and the legacy class-name aliases. |
| `public static load($class_name)` | Loads a Nexora\ class from src/, or aliases an old global class name. |

### `Nexora\Core\I18n`  
`src/Core/I18n.php`

Loads the plugin's translations (text domain "nexora", files in languages/).

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the loader. It runs before the post types and menus are registered, so their labels translate too. |
| `public load_textdomain()` | Loads languages/nexora-<locale>.mo. |

### `Nexora\Core\Plugin`  
`src/Core/Plugin.php`

Single place that wires the plugin's modules. Each module registers its own hooks, shortcodes and AJAX handlers in its constructor.

| Method | What it does |
|---|---|
| `public static boot()` | Creates every module so each can register its hooks. |

### `Nexora\Core\Urls`  
`src/Core/Urls.php`

The URLs of the pages Nexora depends on (slugs login-page, registration-page, profile-page). One place to change them; everything else asks here instead of hard-coding paths.

| Method | What it does |
|---|---|
| `public static login($trailing_slash = false)` | URL of the login page. |
| `public static registration($trailing_slash = false)` | URL of the registration page. |
| `public static profile($username = '', $trailing_slash = false)` | Profile page of a member, or the bare profile page when no username is given. |
| `public static profile_for($user, $trailing_slash = false)` | Where a user lands: administrators use the bare profile page, members their own. |

### `Nexora\Core\View`  
`src/Core/View.php`

Renders a template from templates/ with the given variables. Templates contain markup only: every value they print is escaped there.

| Method | What it does |
|---|---|
| `public static render($template, array $vars = array()` | Render. |
| `public static output($template, array $vars = array()` | Render and print. |

### `Nexora\Database\Installer`  
`src/Database/Installer.php`

Plugin lifecycle: activation creates / updates the tables (Migrations keeps them current afterwards), deactivation tidies up what must not outlive the plugin.

| Method | What it does |
|---|---|
| `public static activate()` | Plugin activation: build the tables and record the schema version. |
| `public static deactivate()` | Plugin deactivation. Data is kept (only uninstall may delete it, and only if the |

### `Nexora\Database\Migrations`  
`src/Database/Migrations.php`

Keeps the custom tables in step with the plugin version. The stored nexora_db_version is compared on every load; when it is older the schema is synced (dbDelta is idempotent) and the version recorded, so updating the plugin files is enough - no reactivation.

| Method | What it does |
|---|---|
| `public __construct()` | Checks the stored version early on every request. |
| `public static maybe_upgrade()` | Upgrades when the stored version is behind (a fresh install counts as behind). |
| `public static run()` | Syncs the schema and records the version. A short-lived lock keeps two simultaneous |
| `public static sync_schema()` | Creates missing tables / columns / indexes through dbDelta. |

### `Nexora\Database\Uninstaller`  
`src/Database/Uninstaller.php`

Removes everything the plugin stored - but only when the admin ticked "Delete all data when the plugin is deleted" (default off). Members' WordPress user accounts and Media Library uploads are never touched.

| Method | What it does |
|---|---|
| `public __construct($config = null)` | Builds an uninstaller. |
| `public static defaults()` | Everything the plugin stores. |
| `public static enabled()` | True when the admin asked for the data to be deleted. |
| `public run()` | Deletes the data if (and only if) the setting is on. |
| `private delete_posts()` | Deletes the plugin's custom post type posts (and their meta), in batches. |
| `private delete_user_meta()` | Deletes the plugin's user meta for every user. |
| `private delete_transients()` | Deletes the plugin's transients (stats cache, OTP references, rate-limit counters). |
| `private drop_tables()` | Drops the custom tables. |
| `private delete_dirs()` | Deletes the private-file folders (members' ID documents). |
| `private delete_options()` | Deletes the plugin's options. |

### `Nexora\Http\Ajax`  
`src/Http/Ajax.php`

Shared plumbing for AJAX handlers: registering an action under its nexora_ name (with the old generic name kept as a deprecated alias) and the standard nonce + login + profile guard.

| Method | What it does |
|---|---|
| `public static register($legacy, $callback, $guest = false)` | Register $callback for wp_ajax_nexora_<legacy>. The un-prefixed $legacy name keeps |
| `public static member($nonce_action = 'profile_nonce', $require_profile = true, $unauth_message = null)` | Guard for logged-in member endpoints: nonce, login, 'read' capability and |

### `Nexora\Http\Member_Ajax`  
`src/Http/Member_Ajax.php`

Base for the AJAX classes serving logged-in members (profile, connections, content, ...): the standard guard plus the small input helpers they all need.

| Method | What it does |
|---|---|
| `protected member()` | Nonce + login + capability + linked profile. Ends the request on failure. |
| `protected post_value($key)` | A sanitised single-line value from $_POST ('' when absent). |
| `protected owns_attachment($attachment_id, $user_id)` | An attachment may only be linked by the user who uploaded it (or an administrator). |

### `Nexora\Http\Rate_Limiter`  
`src/Http/Rate_Limiter.php`

Fixed-window rate limiter. Counting is a single atomic statement (object-cache increment when a persistent cache exists, otherwise INSERT ... ON DUPLICATE KEY UPDATE), so concurrent requests cannot slip past the limit by racing a read-modify-write. Limits are named buckets (see defaults()) that can be tuned with the 'nexora_rate_limits' filter. Per-visitor buckets key on the client IP; per-member buckets pass the user id as the subject.

| Method | What it does |
|---|---|
| `public static defaults()` | Default limits, keyed by bucket name: [max hits, window in seconds]. |
| `private static config($name)` | Limit and window of a bucket, or null for an unknown bucket. |
| `private static now()` | Current unix time (overridable in tests). |
| `public static client_ip()` | REMOTE_ADDR, unless the site owner names a trusted proxy header |
| `private static key($name, $subject, $window)` | Storage key for a bucket, subject and the current window. |
| `public static count($name, $subject = null)` | Hits recorded for this subject in the current window. |
| `public static blocked($name, $subject = null)` | True when the subject has already used up the window (does not count a hit). |
| `public static hit($name, $subject = null)` | Record one hit. Returns true when this hit is OVER the limit. |
| `public static message()` | Standard message for AJAX replies. |

### `Nexora\Integrations\Better_Messages`  
`src/Integrations/Better_Messages.php`

Third-party Better Messages plugin: its user search only offers people the member is connected with (accepted connections, either direction).

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the Better Messages user-search filter. |
| `public nexora_filter_search_query_users($conditions, $included_ids, $search, $user_id)` | Limits Better Messages' user search to the searching member's accepted connections. |

### `Nexora\Notifications\Ajax`  
`src/Notifications/Ajax.php`

Notification actions for logged-in members.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the notification AJAX action. |
| `public mark_notification_read()` | Marks one of the member's own notifications as read. |

### `Nexora\Notifications\Repository`  
`src/Notifications/Repository.php`

Data access for the notifications table.

| Method | What it does |
|---|---|
| `public __construct()` | Resolves the table name. |
| `public create_table()` | Creates or updates the notifications table (dbDelta: safe to run repeatedly). |
| `public insert($data)` | Stores a notification. |
| `public get_all()` | Every notification, newest first (admin overview). |
| `public get_notifications($user_id, $limit = 50)` | A member's notifications, unread first then newest. |
| `public get_row($id)` | One notification by id. |
| `public get_unread_count($user_id)` | Number of unread notifications of a member. |
| `public mark_as_read($id)` | Marks a notification as read. |

### `Nexora\PostTypes\Registrar`  
`src/PostTypes/Registrar.php`

The three private post types the social data lives in. They are never public and never in REST; they appear only under the "Nexora System" admin menu.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the post type registration. |
| `public register_cpt()` | Registers user_profile, user_connections and user_content (private, admin-only). |

### `Nexora\Profile\Ajax`  
`src/Profile/Ajax.php` · extends `Member_Ajax`

Profile editing for the logged-in member: personal, address, work, documents, password. (Connections, notifications and content have their own classes.)

| Method | What it does |
|---|---|
| `public __construct()` | Registers the profile editing AJAX actions. |
| `public update_personal_info()` | Saves the member's personal details. |
| `public update_address_info()` | Saves the member's addresses. |
| `public update_work_info()` | Saves the member's work details. |
| `public update_documents_info()` | Links (or unlinks) the member's images and ID documents. |
| `public update_profile_password()` | Changes the member's password (rate limited). |

### `Nexora\Profile\Documents_Migration`  
`src/Profile/Documents_Migration.php`

Moves ID documents that were uploaded before they became private: wp nexora migrate-documents [--dry-run].

| Method | What it does |
|---|---|
| `public __construct()` | Registers the WP-CLI command. |
| `public static migrate_all($dry_run = false)` | Move every ID document that is still public. Idempotent: private ones are skipped. |
| `public cli_migrate($args, $assoc_args)` | Runs `wp nexora migrate-documents [--dry-run]`. |

### `Nexora\Profile\Fields`  
`src/Profile/Fields.php`

The profile meta keys, grouped the way the UI edits them. One list shared by the member AJAX handlers, the profile page and the admin meta boxes.

| Method | What it does |
|---|---|
| `public static owner_only()` | Details only the owner may see (sent to the browser for the owner and nobody else). |
| `public static admin_editable()` | Everything an administrator can edit on a user_profile post. |

### `Nexora\Profile\Page`  
`src/Profile/Page.php`

The [profile_dashboard] page: /profile-page and /profile-page/<username>. This class decides what to show to whom; the markup lives in templates/profile/.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the shortcode, assets and the /profile-page/<username> rewrite. |
| `public enqueue_assets()` | Loads the profile scripts and hands the page its data (profile page only). |
| `public rewrite_rule()` | Maps /profile-page/<username> to the profile page. |
| `public query_vars($vars)` | Registers the username query variable. |
| `public render_profile()` | Renders [profile_dashboard]: a login wall, admin card, not-found card or the profile. |
| `private view_data($profile_id, $role_type, $current_user_id)` | Everything the profile templates print, prepared here so templates only echo. |
| `private default_image_url($option)` | URL of a default image option, or an empty string. |
| `private document_cards($profile_id, $is_owner, $default_profile, $default_cover, $default_doc)` | Cards of the Documents section. Only the owner sees ID documents. |
| `private established_connections($profile_id)` | The profile's accepted connections as cards. |
| `private notification_rows($user_id)` | The member's notifications prepared for the template. |
| `private content_feed($current_user_id)` | Posts by other members (the content tab). |
| `private format_notification_message($noti)` | Text shown for a notification row (UI transformation of the stored type). |

### `Nexora\Profile\Privacy`  
`src/Profile/Privacy.php`

What a visitor may learn about a profile. The single place that decides who gets private data. Guest: not logged in. Viewer: logged in, looking at somebody else's profile. Owner: logged in, looking at their own profile.

| Method | What it does |
|---|---|
| `public static role($current_user_id, $owner_user_id)` | Relationship of the visitor to the profile (guest, viewer or owner). |
| `public static script_data($profile_id, $role)` | Profile data handed to the browser (profilePageData.userData). |

### `Nexora\Profile\Private_Documents`  
`src/Profile/Private_Documents.php`

ID documents (Aadhaar, driving licence, company ID) are never served from the public uploads folder. When one is linked to a profile its files are moved to uploads/nexora-private/ under unguessable names, and every URL WordPress builds for it becomes a gated download (wp_ajax_nexora_document) that only the owner or an administrator can open. Attachment IDs and profile meta keys are unchanged, so the profile UI, the admin screens and existing data keep working.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the protection of ID documents and the download action. |
| `public static is_private($attachment_id)` | True when the attachment is a private ID document. |
| `public static dir()` | Absolute path of the private folder. |
| `public static url_for($attachment_id, $size = '')` | Gated download URL for an attachment (optionally one image size). |
| `public static in_public_use($attachment_id)` | True when the attachment is already used by something other members can see |
| `private static ensure_dir()` | Creates the private folder with its deny rule and index file. |
| `public static protect($attachment_id)` | Move an attachment (all sizes) into the private folder. Copy first, verify, |
| `public on_meta_change($meta_id, $post_id, $key, $value)` | Makes an attachment private when it is linked as an ID document. |
| `public filter_url($url, $attachment_id)` | Points a private attachment's URL at the gated download. |
| `public filter_image_src($image, $attachment_id, $size, $icon)` | Points a private attachment's image URL at the gated download. |
| `public filter_js($response, $attachment)` | Gives the media library the gated URLs of a private attachment. |
| `public filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)` | Removes responsive sources for private attachments. |
| `public static can_view($attachment_id, $user_id)` | True when the user may open this private document (its owner or an administrator). |
| `public static resolve_path($attachment_id, $size = '')` | Absolute path of the full file or of a named size; null when the size is |
| `public serve()` | Streams a private document to its owner or an administrator. |

### `Nexora\Profile\Repository`  
`src/Profile/Repository.php`

Read access to user_profile posts.

| Method | What it does |
|---|---|
| `public static id_for_user($user_id)` | Profile post id of a WP user (0 when none). |
| `public static id_by_username($username)` | Profile post id for a username (the user_name meta), or 0. |
| `public static full_name($profile_id)` | Name shown for a profile (first + last). |
| `public static get_profile_image($profile_id)` | URL of a profile's image, or the default image. |

### `Nexora\Profile\Upload_Policy`  
`src/Profile/Upload_Policy.php`

Who may upload, where from, and how much. Members do not hold a permanent upload_files capability. They receive it only while the profile page is rendered or while the media uploader's own AJAX requests run, so REST /wp/v2/media and wp-admin uploads stay closed to them. Members are also limited in file size and in total number of files.

| Method | What it does |
|---|---|
| `public __construct()` | Hooks the upload capability, size and type rules. |
| `public own_files_only($query)` | Limits a member's media library to their own files. |
| `public restrict_member_mimes($mimes)` | Limits members to images and PDFs. |
| `public maybe_cleanup_role_cap()` | Earlier versions stored upload_files on the subscriber role for everyone. |
| `public static cleanup_role_cap()` | Removes the old stored upload_files capability from the subscriber role. |
| `private upload_context()` | True while the request is the profile page or the media uploader's own AJAX. |
| `public grant_upload_in_context($allcaps, $caps, $args, $user)` | Gives a member upload_files only in an upload context. |
| `private is_limited_member()` | True for logged-in users who are not administrators. |
| `private max_bytes()` | Largest file a member may upload. |
| `public limit_size($size)` | Caps the upload size for members. |
| `public check_upload($file)` | Refuses a member's upload that is too large or over the file-count limit. |

### `Nexora\Shortcodes\Contact_Form`  
`src/Shortcodes/Contact_Form.php`

[nexora_contact_form] A small, spam-resistant contact form that mails the site admin (or the "Admin Notification Email" from Nexora settings). Protections: nonce, honeypot field, per-IP rate limit, header-injection safe Reply-To.

| Method | What it does |
|---|---|
| `public __construct()` | Registers the shortcode and the form handler. |
| `private notices()` | Messages shown after the form was submitted, keyed by the nx_contact query argument. |
| `public render()` | Renders the contact form with the result of the last submission. |
| `public handle()` | Validates the form and emails the site admin (nonce, honeypot, rate limit). |

### `Nexora\Shortcodes\Home`  
`src/Shortcodes/Home.php`

[nexora_home] shortcode. Nothing on this page is hard-coded any more: - the numbers come from the database (members, connections, posts, chats) - hero text, features and testimonials come from Nexora > Settings - buttons follow the visitor's login state - images come from the media chosen in Settings (section skipped if none)

| Method | What it does |
|---|---|
| `public __construct()` | Registers [nexora_home] and keeps its numbers fresh. |
| `public flush_stats()` | Drops the cached platform numbers. |
| `public static get_stats()` | Live platform numbers (cached for an hour). |
| `public static short_number($n)` | Compact number: 1,250 -> 1.2K |
| `private parse_lines($option, $columns)` | Parse "a | b | c" lines from a settings textarea. |
| `public static default_features()` | Feature cards shown when none are set in Settings. |
| `private option_or($name, $fallback)` | Option or. |
| `public render_home_page()` | Renders the landing page. |

### `Nexora\Shortcodes\Stats`  
`src/Shortcodes/Stats.php`

Small dynamic shortcodes meant to be dropped into Elementor (Shortcode widget) so templates never hard-code numbers or login-dependent buttons. [nexora_stat type="members|connections|posts|chats"] [nexora_auth_buttons]

| Method | What it does |
|---|---|
| `public __construct()` | Registers [nexora_stat] and [nexora_auth_buttons]. |
| `public stat($atts)` | Shortcode: one live platform number. |
| `public auth_buttons()` | Shortcode: Log in / Sign up, or the member's profile link and Log out. |
