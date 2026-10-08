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

- `nexora.php` bootstraps: it `require`s every `includes/class-*.php` and `chat/class-chat-core.php`, then `NEXORA_System::__construct` instantiates each module class. A new module needs both a `require_once` and a `new` there. It also owns global asset enqueueing, access control (non-admins are bounced from `wp-admin`/`wp-login.php` to their profile; logged-out visitors go to `/login-page`; AJAX/REST/cron/`admin-post.php` are exempt), and table creation on activation.
- Each feature is a class registering its own shortcode(s) and `wp_ajax_*` handlers. `class-profile-ajax.php` holds most AJAX (profile edit, connections, notifications, content). Keep nonce and capability checks on every handler.
- Data model: custom post types `user_profile`, `user_connections`, `user_content` (`class-cpt.php`, which also builds the "Nexora System" admin menu/settings) plus custom tables for notifications and chat (threads, participants, messages, message_meta). Tables are created only on plugin **activation** — schema changes require deactivate/reactivate or a manual migration.
- `/profile-page/<username>` is a rewrite rule; after changing rewrites, re-save *Settings → Permalinks*. Pages with slugs `login-page` and `profile-page` must exist.
- `chat/` is a self-contained module (core, ajax, db, templates). `class-better-message-chat.php` only adds filters for the third-party Better Messages plugin.
- Theme: Header/footer/404 can be overridden by Elementor Pro Theme Builder templates; theme markup is the fallback. Elementor sections are styled via `nxe-*` classes in `assets/css/elementor.css`. Dynamic values in templates come from the plugin shortcodes `[nexora_stat]`, `[nexora_auth_buttons]` and theme shortcodes `[nxt_logo]`, `[nxt_setting]` (settings under Appearance → Nexora Settings). Sample pages are installed from Appearance → Sample Content and are idempotent.

## Conventions

- Bump `NEXORA_VERSION` in `nexora.php` whenever plugin CSS/JS changes (used for cache busting). Shared design tokens are enqueued via `NEXORA_System::enqueue_tokens()`.
- Set `WP_DEBUG` to `true` in your local `wp-config.php` to surface notices.
