# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A WordPress site (full WP core is committed) with project-specific code in two places only:

- `wp-content/plugins/nexora/` — the custom plugin: front-end registration/login, profile dashboard, user connections, notifications, chat.
- `wp-content/themes/nexora-theme/` — classic PHP theme with Elementor Pro integration and a sample-content installer (`inc/elementor`).

Everything else (WP core, Elementor, Elementor Pro, ACF, other plugins, stock themes, Hostinger mu-plugins) is third-party; don't edit it. See [README.md](README.md) for the shortcode table, setup steps and feature list.

There is no build step or linter — it's plain PHP/CSS/JS, with a small WP-CLI test harness in `tests/`. Run and verify changes in the browser (site is served under `/nexora/`; `.htaccess` `RewriteBase` assumes that). `wp-config.php`, uploads, and DB dumps are git-ignored.

## Commands

- Tests (WP-CLI based, run against the local dev DB with self-cleaning fixtures): `tests/run.sh` for all, or `tests/run.sh tests/php/test-foo.php` for one. Helpers in `tests/bootstrap.php`. **Work test-first: follow the `tdd-workflow` skill (red, green, refactor) before building or fixing anything.**
- Syntax-check changed PHP: `php -l path/to/file.php`, or for the whole plugin/theme: `find wp-content/plugins/nexora wp-content/themes/nexora-theme -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'`
- Flush rewrites after touching rules: *Settings → Permalinks → Save* (or `wp rewrite flush` if WP-CLI is installed).
- Project skills in `.claude/skills/` (`nexora-add-module`, `nexora-add-ajax-endpoint`, `nexora-db-table`, `nexora-elementor-page`, `nexora-release-assets`) cover the common change types; use them, and run `nexora-release-assets` before finishing any plugin/theme change. Use `raise-pr` to branch, commit, push and open/merge a PR (it confirms before each outward-facing step). `nexora-data-model`, `nexora-settings-option`, `nexora-auth-flow` and `nexora-frontend-style` cover the CPT/meta conventions, settings, login/OTP/registration and CSS/JS conventions. `verify-like-a-user` (imported) is the method for checking UI in the browser; its outputs go to git-ignored `skill-outputs/`.

## Architecture

- `nexora.php` is only constants + the PSR-4 autoloader (`src/Core/Autoloader.php`, namespace `Nexora\` -> `src/`) + the activation hook + `Nexora\Core\Plugin::boot()`. **A new module is one class under `src/<Module>/` plus a `new` line in `Plugin::boot()`** (no `require`). Old global class names (`NEXORA_Notification`, `NEXORA_CHAT_DB`, ...) still resolve through `src/Core/legacy-aliases.php`.
- Layout: `Core` (Plugin, Assets, Access_Control, Urls, View), `Http` (Ajax registrar + guard, Member_Ajax base, Rate_Limiter), `Auth` (Login, Registration, Otp, Recaptcha), `Profile` (Page, Privacy, Ajax, Fields, Repository, Private_Documents, Upload_Policy), `Connections` (Ajax, Service, Repository), `Notifications`, `Content`, `Chat` (Module, Ajax, Repository), `Admin` (Menu, Settings, Pages, Meta_Boxes, List_Columns), `PostTypes`, `Shortcodes`, `Integrations`, `Database` (Installer). Markup lives in `templates/` (rendered with `Core\View::render()`; prepare data in the class, escape in the template); CSS/JS in `assets/`.
- Access control (in `Core\Access_Control`): non-admins are bounced from `wp-admin`/`wp-login.php` to their profile; logged-out visitors go to `/login-page`; AJAX/REST/cron/`admin-post.php` are exempt. Page paths come from `Core\Urls`.
- AJAX: register with `Http\Ajax::register('name', $callback, $guest = false)` which creates `wp_ajax_nexora_name` and keeps the old un-prefixed `wp_ajax_name` as a deprecated alias (fires `nexora_deprecated_ajax_action`). Member handlers extend `Http\Member_Ajax` and start with `$this->member()` (nonce + login + profile). Rate-limit abusable actions with `Http\Rate_Limiter::hit()`.
- Data model: custom post types `user_profile`, `user_connections`, `user_content` (`PostTypes\Registrar`; the "Nexora System" admin menu/settings are `Admin\Menu`/`Admin\Settings`) plus custom tables for notifications and chat (threads, participants, messages, message_meta). Tables are created only on plugin **activation** (`Database\Installer`) - schema changes require deactivate/reactivate or a manual migration until versioned migrations land.
- `/profile-page/<username>` is a rewrite rule; after changing rewrites, re-save *Settings → Permalinks*. Pages with slugs `login-page`, `registration-page` and `profile-page` must exist (Appearance → Sample Content creates them if missing).
- Chat is `src/Chat/` (Module = assets + popup, Ajax, Repository = the tables); `Integrations\Better_Messages` only adds a filter for the third-party Better Messages plugin.
- Theme: Header/footer/404 can be overridden by Elementor Pro Theme Builder templates; theme markup is the fallback. Elementor sections are styled via `nxe-*` classes in `assets/css/elementor.css`. Dynamic values in templates come from the plugin shortcodes `[nexora_stat]`, `[nexora_auth_buttons]` and theme shortcodes `[nxt_logo]`, `[nxt_setting]` (settings under Appearance → Nexora Settings). Sample pages are installed from Appearance → Sample Content and are idempotent.

## Gotchas

- **Profile privacy:** `Profile\Privacy` decides who gets private fields (email, phone, address, ID documents): only the owner, and only on the profile page; the server-rendered cards are owner-only too. Don't add private fields for non-owners. Guests get a "log in" prompt instead of any profile. ID documents are **private files**: `Profile\Private_Documents` moves them to `uploads/nexora-private/` and serves them only to the owner/admin through `nexora_document`; never link them to a public field.
- **reCAPTCHA is skipped only on a genuinely local site** (`Auth\Recaptcha::is_local`): `WP_ENVIRONMENT_TYPE=local` *and* the site's own hostname (from the stored home URL, never the request's Host header) resolves to a private address, or the `nexora_skip_captcha` filter says so.
- **`.gitignore` has a blanket `vendor/` rule**, so Composer `vendor/` folders of the committed plugins (Elementor, ACF) are not in git; those plugins fatal on activation from a fresh clone. Install/update third-party plugins through wp-admin rather than relying on the repo copy. Elementor Pro is a licensed product, committed to a public repo.
- The Header/Footer Theme Builder templates use Elementor Pro Nav Menu widgets bound to the menu slugs `nexora-main`, `nexora-footer-company` and `nexora-footer-legal`; those menus exist only after the sample-content installer has run.
- The home-page numbers (`[nexora_home]`, `[nexora_stat]`) come from `Shortcodes\Home::get_stats()`, cached in the `nexora_home_stats` transient and flushed by `save_post_user_*` hooks.
- CSS prefixes: plugin `nx-*` (tokens in `assets/css/tokens.css`), theme chrome `nxt-*` (`NXT_VERSION` in `nexora-theme/functions.php` busts theme asset caches), Elementor sections `nxe-*`.

## Conventions

- Bump `NEXORA_VERSION` in `nexora.php` whenever plugin CSS/JS changes (used for cache busting). Shared design tokens are enqueued via `Core\Assets::enqueue_tokens()`.
- Set `WP_DEBUG` to `true` in your local `wp-config.php` to surface notices.
- `tests/php/test-golden-output.php` snapshots the exact markup of every screen (`tests/golden/`). A refactor must keep them identical; if you change markup on purpose, regenerate with `NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php` and review the diff. `test-static-namespaces.php` fails on any unresolved class reference in `src/`.
