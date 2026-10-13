# Nexora

A WordPress site (WordPress 7.1) built around a custom **Nexora** plugin that provides a small social/networking platform: front-end registration and login, user profile dashboards, user-to-user connections, notifications, and direct messaging.

## Repository layout

| Path | Purpose |
| --- | --- |
| `wp-content/plugins/nexora/` | **The custom code.** All project-specific features live here. |
| `wp-content/themes/nexora-theme/` | **The custom theme** (FST design: navy / blue, Plus Jakarta Sans). Includes the Elementor sample content installer. |
| `wp-content/plugins/` (others) | Third-party plugins: Elementor, Elementor Pro, Advanced Custom Fields, Akismet, All-in-One WP Migration, Hostinger, Hostinger Reach, LiteSpeed Cache, UpdraftPlus, WP Mail SMTP. Elementor Pro is a licensed product: keep your own licence active. |
| `wp-content/themes/` (others) | Stock themes: Twenty Twenty-Three, Twenty Twenty-Four, Twenty Twenty-Five. |
| `wp-content/mu-plugins/` | Hostinger must-use plugins. |
| `wp-config.php` | **Not tracked** (contains secrets). Create it from `wp-config-sample.php`. |

Everything else is WordPress core.

## Nexora plugin

```
nexora/
├── nexora.php                       # Constants, PSR-4 autoloader, activation hook, Plugin::boot()
├── src/                             # Namespace Nexora\ (one class per file)
│   ├── Core/                        # Plugin, Assets, Access_Control, Urls, View, I18n, Autoloader, legacy-aliases
│   ├── Http/                        # Ajax registrar + guard, Member_Ajax, Rate_Limiter
│   ├── Auth/                        # Login ([profile_login]), Registration, Otp, Recaptcha
│   ├── Profile/                     # Page ([profile_dashboard], /profile-page/<username>), Privacy, Ajax, Fields,
│   │                                #   Repository, Private_Documents, Upload_Policy, Documents_Migration
│   ├── Connections/                 # Ajax, Service, Repository, Cache
│   ├── Notifications/               # Repository (table), Ajax
│   ├── Content/                     # Ajax, Repository
│   ├── Chat/                        # Module (popup + assets), Ajax, Repository (tables)
│   ├── Admin/                       # Menu, Settings, Pages, Meta_Boxes, List_Columns ("Nexora System")
│   ├── PostTypes/                   # Registrar (user_profile, user_connections, user_content)
│   ├── Shortcodes/                  # Home, Stats, Contact_Form
│   ├── Integrations/                # Better_Messages (dead code, slated for removal; chat is fully custom)
│   └── Database/                    # Installer, Migrations, Uninstaller
├── templates/                       # HTML only (auth, profile, chat, admin, mail, shortcodes)
├── languages/                       # nexora.pot (text domain `nexora`)
└── assets/                          # css/, js/ (incl. chat), lib/ (SweetAlert2)
```

### Features

- **Registration & login** on front-end pages, with optional Google reCAPTCHA, email OTP, and password reset.
- **Profile dashboard** with personal, address, work and document sections, a password change form, and a user content feed.
- **Connections**: search users, send/accept/decline requests, view history, all connections and mutual connections.
- **Notifications** stored in a dedicated table and markable as read.
- **Chat**: threads, subjects, and messages, loaded as a popup for logged-in members.
- **Admin settings** under *Nexora System* for default images, admin email, and reCAPTCHA keys, plus Notifications and Chat views.

### Shortcodes

| Shortcode | Use on page |
| --- | --- |
| `[nexora_home]` | Landing page (live numbers, text editable under Nexora > Settings) |
| `[profile_registration]` | Registration page |
| `[profile_login]` | `/login-page` |
| `[nexora_stat type="members\|connections\|posts\|chats"]` | Any Elementor Shortcode widget: live platform number |
| `[nexora_auth_buttons]` | Header: Log in / Sign up, or profile / Log out when logged in |
| `[nexora_contact_form]` | Contact page: spam-protected form that emails the admin |
| `[profile_dashboard]` | `/profile-page` (also served at `/profile-page/<username>` via a rewrite rule) |

### Access control

- Non-admins never see the admin bar and are redirected away from `wp-admin` and `wp-login.php` to their profile page.
- Logged-out visitors hitting `wp-admin` or `wp-login.php` are sent to `/login-page`.
- Administrators keep full access. AJAX, REST, cron, and `admin-post.php` requests are left alone.

### Custom post types and tables

- Post types: `user_profile`, `user_connections`, `user_content`.
- Tables (created and kept current by `Database\Migrations`, with the site's table prefix): `nexora_notifications`, `nexora_threads`, `nexora_thread_participants`, `nexora_messages`, `nexora_message_meta`.

## Nexora theme and Elementor templates

The theme (`wp-content/themes/nexora-theme`) is a classic PHP theme. Header, footer and 404 can be replaced by Elementor Pro Theme Builder templates; the theme's own markup is the fallback.

**Install the sample pages**

1. Activate Elementor, Elementor Pro and the **Nexora** theme.
2. Go to **Appearance > Sample Content** and press **Install sample content**.

It creates (using Elementor's own API, safe to run again):

- Pages: Home (set as front page), About, Contact, FAQs, Privacy Policy, Terms of Use, Community Guidelines, plus Login / Registration / Profile if missing.
- Elementor library templates, one per page layout (Templates > Saved Templates), to insert into any page.
- Theme Builder templates: Header, Footer and 404 (Elementor Pro).
- Menus: Nexora Main, Nexora Footer Company, Nexora Footer Legal.

Existing pages are never changed unless this installer created them and "Replace" is ticked.

**Dynamic data in the templates**: numbers come from `[nexora_stat]`, the header buttons from `[nexora_auth_buttons]`, and the logo, tagline, email, phone and address from `[nxt_logo]` and `[nxt_setting]` (set them under **Appearance > Nexora Settings**). Images use one sample image (`assets/images/sample.webp`, also added to the Media Library): replace it in the Elementor editor.

Sections are styled by CSS classes (`nxe-*`, `assets/css/elementor.css`), so layout stays editable in Elementor.

## Setup

**Requirements:** PHP and MySQL/MariaDB as required by WordPress 7.1, plus Apache with `mod_rewrite` (the `.htaccess` assumes the site is served from `/nexora/`; change `RewriteBase` if you host it elsewhere).

1. Place the project in your web root (for example `/var/www/html/83/nexora`).
2. Create a database, then copy `wp-config-sample.php` to `wp-config.php` and fill in the DB credentials and salts.
3. Run the WordPress installer at `/nexora/wp-admin/install.php`, or import a backup (see below).
4. Activate the **Nexora** plugin. Activation creates the notification and chat tables (`Database\Migrations` keeps them current afterwards).
5. Create pages with slugs `login-page`, `registration-page` and `profile-page` and the matching shortcodes, plus a home page (or use *Appearance → Sample Content*, which creates missing ones). Then go to *Settings → Permalinks* and click Save to flush the rewrite rules.
6. Under *Nexora System → Settings*, set the default images, admin email, and (optionally) reCAPTCHA keys.
7. Make sure outgoing email works (the repo includes WP Mail SMTP). OTP and password reset depend on it.

## Development notes

- Bump `NEXORA_VERSION` in `nexora.php` when changing CSS or JS, because it is used for cache busting.
- Register AJAX handlers with `Http\Ajax::register()` (creates `wp_ajax_nexora_<name>`; the old un-prefixed action is a deprecated alias). Member handlers extend `Http\Member_Ajax`; keep nonce and login checks in place and rate-limit abusable actions.
- Set `WP_DEBUG` to `true` in `wp-config.php` locally to surface PHP notices.
- Wrap user-facing strings in `__()` with the `nexora` text domain and regenerate `languages/nexora.pot` (see the plugin README).

### Tests and coding standards

- Tests (WP-CLI, self-cleaning fixtures): `tests/run.sh` for all, or `tests/run.sh tests/php/test-foo.php` for one. Golden markup snapshots live in `tests/golden/`; regenerate on purpose with `NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php`.
- Coding standards: `cd wp-content/plugins/nexora && composer install && vendor/bin/phpcs` (auto-fix with `vendor/bin/phpcbf`).

### Further documentation

Deeper references are in [`docs/`](docs/): `ARCHITECTURE.md`, `AJAX-API.md`, `AUTHENTICATION.md`, `DATABASE.md`, `THEME.md`, `DEVELOPMENT-WORKFLOW.md`, `TROUBLESHOOTING.md`, `CODE-REFERENCE.md` and `NEXORA-DEVELOPER-GUIDE.md`.

## What is not in git

`.gitignore` excludes `wp-config.php`, `.env`, `wp-content/uploads/`, caches, and backup folders (`updraft/`, `ai1wm-backups/`, `*.sql`, `*.wpress`). Obtain database dumps and uploads separately; never commit them, since they contain user data.

## License

WordPress core is licensed under GPLv2 or later (see `license.txt`). Third-party plugins keep their own licenses. The Nexora plugin was written by Sahil Singla.
