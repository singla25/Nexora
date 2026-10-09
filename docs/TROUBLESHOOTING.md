# Troubleshooting Nexora

Symptom → likely cause → where to look. Back to the [developer guide](NEXORA-DEVELOPER-GUIDE.md).

**First, switch on debugging locally:** set `WP_DEBUG` (and `WP_DEBUG_LOG`) to `true` in your local `wp-config.php` and read `wp-content/debug.log`. Never enable `WP_DEBUG_DISPLAY` on the live site. To see what a test is doing, run one suite: `tests/run.sh tests/php/test-foo.php`.

| Symptom | What it usually means | Check |
|---|---|---|
| **Plugin does not load / white screen on activation** | a PHP syntax error or fatal in a file loaded at boot | `php -l` on the files you touched (command in [DEVELOPMENT-WORKFLOW.md](DEVELOPMENT-WORKFLOW.md#4-other-checks)); `debug.log`; confirm PHP ≥ 8.0 (the plugin header requires it) |
| **`Class "Nexora\…" not found`** | file name or namespace does not match the class (the autoloader maps `Nexora\A\B` → `src/A/B.php`, case-sensitive on Linux), or the file is not where the namespace says | the namespace line, the file path and the class name; `tests/php/test-static-namespaces.php` reports unresolved class references in `src/`. Not a Composer problem: production does not load `vendor/` |
| **`Class "NEXORA_…" not found`** (old name) | the old name is missing from `src/Core/legacy-aliases.php` | add an alias row only if external code genuinely needs the old name |
| **A new module does nothing** | the `new \Nexora\…();` line is missing from `Core\Plugin::boot()` | `src/Core/Plugin.php` |
| **AJAX returns `-1`** | WordPress rejected the nonce (`check_ajax_referer`): missing, expired, or from a cached page; or the page sent `profile_nonce` to a chat endpoint (chat uses `nexora_chat_nonce`) | the request payload has `nonce`; the page printed a fresh nonce; exclude pages with nonces from the page cache (LiteSpeed); log in/out changes the nonce |
| **AJAX returns `0`** | no handler is registered for that `action` | spelling; use the `nexora_` name; the module is booted; guest endpoints are registered with `true` as the 3rd argument of `Ajax::register` |
| **AJAX returns `{"success":false,"data":"Unauthorized access"}`** | not logged in (session expired), or `current_user_can('read')` failed | the browser is logged in; the endpoint is a member endpoint |
| **`"Profile not found"`** | the user has no `_profile_id` user meta or it points to a post that is not a `user_profile` | usermeta `_profile_id`, and that post's type; registration creates both |
| **`"Too many requests…"`** | a rate-limit bucket tripped | buckets in [AJAX-API.md](AJAX-API.md#1-how-ajax-works-here); behind a proxy every visitor may share an IP, set `NEXORA_CLIENT_IP_HEADER`; counters are options `nexora_rl_*` or the `nexora_rl` object-cache group |
| **Captcha required / failed on local** | the local skip did not apply | `Recaptcha::is_local()` needs `WP_ENVIRONMENT_TYPE=local` **and** a hostname that resolves to a private address; or return true from the `nexora_skip_captcha` filter |
| **Profile URL `/profile-page/<user>` gives 404** | the rewrite rule is not stored, or the `profile-page` page is missing | *Settings → Permalinks → Save*; the page with slug `profile-page` containing `[profile_dashboard]` exists (Appearance → Sample Content creates it); deactivation deletes the stored rules, so re-save after reactivating |
| **Profile shows "log in" or another message** | guest wall for visitors; administrators see the admin-mode card; unknown username shows "not found" | `Profile\Page::render_profile()` order of checks |
| **Private field (email, phone) missing for another user** | by design: only the owner receives private fields | `Profile\Privacy`, `Profile\Fields::owner_only()` |
| **ID document link gives 403/404/login** | by design: only the owner/admin may fetch it, through `admin-ajax.php?action=nexora_document`; 404 = file missing in `uploads/nexora-private/` | `Private_Documents::can_view`, `resolve_path`; the file exists; for an old public file, was `wp nexora migrate-documents` run? |
| **Upload refused** | type not an image/PDF, over 8 MB, or over 100 files (members); or the request is not in an upload context | `Profile\Upload_Policy` constants and filters `nexora_member_max_upload_bytes`, `nexora_member_max_files` |
| **CSS/JS change not visible** | the browser or page cache has the old file | bump `NEXORA_VERSION` (plugin) or `NXT_VERSION` (theme); hard refresh; flush LiteSpeed/CDN; check the asset is actually enqueued on that page (`Core\Assets::is_page_for`; scripts load only where the shortcode/page is) |
| **Assets do not load on an Elementor page** | the shortcode is not in the content or in `_elementor_data` | `Assets::is_page_for()` searches the page content and the `_elementor_data` meta |
| **Migration did not run** | the stored version is already current, or a lock is held | `wp option get nexora_db_version` vs `Migrations::DB_VERSION`; `wp option get nexora_db_migrating` (a stale lock expires after 5 minutes; delete the option if a crashed run left it); check `SHOW TABLES LIKE 'wp_nexora%'`; dbDelta errors are not fatal, so look at `SHOW INDEX` and the MySQL error log |
| **Chat list shows old data** | cache not invalidated because a write bypassed `Chat\Repository` | all chat writes must go through the repository (it bumps the version); or restart the object cache |
| **Connection list stale** | a write changed connection data without hitting the watched hooks | the hooks in `Connections\Cache` watch `status`, `sender_profile_id`, `receiver_profile_id`; write through `Connections\Repository` |
| **Email not arriving** | `wp_mail` failed (server mail config) | the OTP mail and admin notification use `wp_mail`; use an SMTP plugin on the server; tests capture mail with `pre_wp_mail` |
| **Stats on the home page look wrong** | the `nexora_home_stats` transient (1 hour) | it is flushed on user/post changes; `wp transient delete nexora_home_stats` |
| **Composer dependency problem** | only affects the dev tools | `cd wp-content/plugins/nexora && composer install`; `vendor/` is git-ignored; a missing `vendor/` never breaks the site |
| **PHPCS failure** | a WordPress Coding Standards rule | read the sniff name in parentheses; `vendor/bin/phpcbf` fixes formatting; do not add ignores without a reason |
| **A golden test fails** | markup changed | if unintended, fix the code; if intended, `NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php` and review the diff |
| **`test-i18n.php` fails** | untranslated string or stale `.pot` | wrap the string; regenerate the `.pot` |
| **Tests fail with leftover users `nxtest_*`** | a previous run crashed | `tests/run.sh` purges them first; or `wp eval-file tests/purge.php` |
| **`wp: command not found`** | WP-CLI missing | install WP-CLI; tests need it |
