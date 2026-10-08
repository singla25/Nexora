---
name: nexora-add-module
description: Add a new feature class or shortcode to the Nexora plugin and wire it into the bootstrap. Use when creating a new includes/class-*.php module, a shortcode, or a CPT.
---

# Add a Nexora plugin module

All custom plugin code is in `wp-content/plugins/nexora/`. Modules are not autoloaded.

## Steps
1. Create `includes/class-<name>.php`. Start with `if (!defined('ABSPATH')) exit;`. Class names follow the existing mix (`NEXORA_*` or `Nexora_*`); register hooks and shortcodes in `__construct`.
2. In `nexora.php`: add `require_once NEXORA_PATH . 'includes/class-<name>.php';` with the others, **and** `new <Class>();` in `NEXORA_System::__construct`. Missing either one means the module silently does nothing.
3. Shortcode: `add_shortcode()` in the constructor, use `shortcode_atts` + `sanitize_key`, and escape all output. Small Elementor-friendly shortcodes live in `class-shortcodes.php` (`[nexora_stat]`, `[nexora_auth_buttons]`); put new ones of that kind there. Add a row to the shortcode table in `README.md`.
4. Assets: enqueue only on the pages that need them. Depend on `nexora-tokens` and call `NEXORA_System::enqueue_tokens()` first so the design tokens and font load. Version every asset with `NEXORA_VERSION`.
5. New post type or Nexora System admin menu/settings: extend `includes/class-cpt.php` (it owns both).
6. New page the plugin depends on (like `login-page`, `profile-page`): the page must exist by slug. Add it to `$plugin_pages` in the theme installer (`nexora-theme/inc/elementor/installer.php`) and document it in the README setup steps.
7. If you add a rewrite rule, tell the user to re-save Settings > Permalinks.

Access control (non-admins locked out of wp-admin / wp-login) lives in `nexora.php`; exempt a new endpoint there only if it truly must be reachable by visitors.
