# Nexora

A WordPress site (WordPress 7.1) built around a custom **Nexora** plugin that provides a small social/networking platform: front-end registration and login, user profile dashboards, user-to-user connections, notifications, and direct messaging.

## Repository layout

| Path | Purpose |
| --- | --- |
| `wp-content/plugins/nexora/` | **The custom code.** All project-specific features live here. |
| `wp-content/plugins/` (others) | Third-party plugins: Akismet, All-in-One WP Migration, Hostinger, Hostinger Reach, LiteSpeed Cache, UpdraftPlus, WP Mail SMTP. |
| `wp-content/themes/` | Stock themes: Twenty Twenty-Three, Twenty Twenty-Four, Twenty Twenty-Five. |
| `wp-content/mu-plugins/` | Hostinger must-use plugins. |
| `wp-config.php` | **Not tracked** (contains secrets). Create it from `wp-config-sample.php`. |

Everything else is WordPress core.

## Nexora plugin

```
nexora/
├── nexora.php                       # Bootstrap, access control, redirects, table creation
├── includes/
│   ├── class-cpt.php                # Custom post types + "Nexora System" admin menu & settings
│   ├── class-registration.php       # [profile_registration]
│   ├── class-login.php              # [profile_login], OTP + password reset
│   ├── class-profile-page.php       # [profile_dashboard], /profile-page/<username> routing
│   ├── class-profile-ajax.php       # Profile editing, connections, notifications, content AJAX
│   ├── class-profile-helper.php     # Shared profile helpers (e.g. default images)
│   ├── class-home-page.php          # [nexora_home]
│   ├── class-notification.php       # Notifications table and logic
│   ├── class-google-recaptcha.php   # reCAPTCHA verification
│   └── class-better-message-chat.php# Filters for the Better Messages plugin user search
├── chat/                            # Built-in chat (AJAX, DB, templates, assets)
└── assets/                          # Global, profile, login and registration CSS/JS
```

### Features

- **Registration & login** on front-end pages, with optional Google reCAPTCHA, email OTP, and password reset.
- **Profile dashboard** with personal, address, work and document sections, a password change form, and a user content feed.
- **Connections**: search users, send/accept/decline requests, view history, all connections and mutual connections.
- **Notifications** stored in a dedicated table and markable as read.
- **Chat**: threads, subjects, and messages, loaded as a popup on every front-end and admin page.
- **Admin settings** under *Nexora System* for default images, admin email, and reCAPTCHA keys, plus Notifications and Chat views.

### Shortcodes

| Shortcode | Use on page |
| --- | --- |
| `[nexora_home]` | Landing page |
| `[profile_registration]` | Registration page |
| `[profile_login]` | `/login-page` |
| `[profile_dashboard]` | `/profile-page` (also served at `/profile-page/<username>` via a rewrite rule) |

### Access control

- Non-admins never see the admin bar and are redirected away from `wp-admin` and `wp-login.php` to their profile page.
- Logged-out visitors hitting `wp-admin` or `wp-login.php` are sent to `/login-page`.
- Administrators keep full access. AJAX, REST, cron, and `admin-post.php` requests are left alone.

### Custom post types and tables

- Post types: `user_profile`, `user_connections`, `user_content`.
- Tables (created on plugin activation, with the site's table prefix): `nexora_notifications`, `nexora_threads`, `nexora_thread_participants`, `nexora_messages`, `nexora_message_meta`.

## Setup

**Requirements:** PHP and MySQL/MariaDB as required by WordPress 7.1, plus Apache with `mod_rewrite` (the `.htaccess` assumes the site is served from `/nexora/`; change `RewriteBase` if you host it elsewhere).

1. Place the project in your web root (for example `/var/www/html/83/nexora`).
2. Create a database, then copy `wp-config-sample.php` to `wp-config.php` and fill in the DB credentials and salts.
3. Run the WordPress installer at `/nexora/wp-admin/install.php`, or import a backup (see below).
4. Activate the **Nexora** plugin. Activation creates the notification and chat tables.
5. Create pages with slugs `login-page` and `profile-page` and the matching shortcodes, plus pages for registration and home. Then go to *Settings → Permalinks* and click Save to flush the rewrite rules.
6. Under *Nexora System → Settings*, set the default images, admin email, and (optionally) reCAPTCHA keys.
7. Make sure outgoing email works (the repo includes WP Mail SMTP). OTP and password reset depend on it.

## Development notes

- Bump `NEXORA_VERSION` in `nexora.php` when changing CSS or JS, because it is used for cache busting.
- Plugin AJAX handlers are registered with `wp_ajax_*` hooks. Keep nonce and capability checks in place when adding new ones.
- Set `WP_DEBUG` to `true` in `wp-config.php` locally to surface PHP notices.

## What is not in git

`.gitignore` excludes `wp-config.php`, `.env`, `wp-content/uploads/`, caches, and backup folders (`updraft/`, `ai1wm-backups/`, `*.sql`, `*.wpress`). Obtain database dumps and uploads separately; never commit them, since they contain user data.

## License

WordPress core is licensed under GPLv2 or later (see `license.txt`). Third-party plugins keep their own licenses. The Nexora plugin was written by Sahil Singla.
