---
name: nexora-settings-option
description: Add or change a configurable setting, either a plugin option (Nexora System > Settings) or a theme option (Appearance > Nexora Settings, nxt_settings). Use when something hard-coded should become editable.
---

# Add a setting

## Plugin option (Nexora System > Settings)
1. Register in `register_settings()` in `includes/class-cpt.php` under group `profile_settings_group`, always with a `sanitize_callback` (`sanitize_text_field`, `sanitize_email`, `sanitize_textarea_field`; image options store an attachment ID).
2. Add the input to `settings_page()` in the same file (inside the existing `<form action="options.php">` / `settings_fields('profile_settings_group')`). Images use the `.upload-btn` / `.remove-btn` pattern with a hidden input, wired by `assets/js/profile-admin.js`.
3. Read it with `get_option('<key>', <default>)` and escape on output. Secrets (reCAPTCHA secret) must never be echoed into front-end markup or localized JS.
4. Existing keys use unprefixed names (`default_profile_image`, `recaptcha_*`) plus `nexora_home_*`; new keys should use the `nexora_` prefix.

## Theme option (`nxt_settings`)
1. Add one row to `nxt_settings_fields()` in `themes/nexora-theme/inc/settings-page.php`: `'key' => [label, type]` with type `text`, `email` or `url`. Sanitizing and the form loop are driven by that array.
2. Read with `nxt_setting('key', 'default')` (`inc/helpers.php`) in PHP, or `[nxt_setting key="..."]` in Elementor templates (`inc/elementor/support.php`).
3. If sample content should use it, check `inc/elementor/installer.php`, which seeds `nxt_settings` once.

Then run `nexora-release-assets` and update the README if the setting is user-facing.
