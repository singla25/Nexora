---
name: nexora-add-module
description: Add a new feature class, shortcode or admin screen to the Nexora plugin (PSR-4 under src/) and wire it into Plugin::boot(). Use when creating a new module, a shortcode, or a post type.
---

# Add a Nexora plugin module

All custom plugin code is in `wp-content/plugins/nexora/`. Classes are autoloaded (PSR-4): namespace `Nexora\<Module>` maps to `src/<Module>/<Class>.php`, one class per file, file name = class name.

## Steps
1. Write the failing test first (`tdd-workflow`).
2. Create `src/<Module>/<Class>.php`:
   ```php
   <?php
   namespace Nexora\Shortcodes;
   if (!defined('ABSPATH')) exit;
   class My_Thing { public function __construct() { /* add_action / add_shortcode here */ } }
   ```
   Put it in the module it belongs to (`Auth`, `Profile`, `Connections`, `Notifications`, `Content`, `Chat`, `Admin`, `Shortcodes`, `Integrations`, ...) or create a new module folder. Reference WordPress classes with a leading backslash (`\WP_Query`, `\WP_Error`, `\DateTime`): inside a namespace an unqualified name does not resolve. `tests/php/test-static-namespaces.php` fails on any unresolved reference.
3. Wire it: add `new \Nexora\<Module>\<Class>();` to `Nexora\Core\Plugin::boot()` (`src/Core/Plugin.php`). No `require` anywhere. Missing the `new` means the module silently does nothing.
4. Markup goes in `templates/<area>/<name>.php`, rendered with `\Nexora\Core\View::render('area/name', $vars)`. Prepare data in the class; escape every printed value in the template. Do not build HTML strings in PHP.
5. Shortcode: `add_shortcode()` in the constructor, `shortcode_atts` + `sanitize_key`, escape all output. Small Elementor-friendly shortcodes live in `Shortcodes\Stats` (`[nexora_stat]`, `[nexora_auth_buttons]`). Add a row to the shortcode table in `README.md`.
6. Assets: enqueue only on the pages that need them (`Core\Assets::is_page_for($slug, $shortcode)`), depend on `nexora-tokens` and call `Core\Assets::enqueue_tokens()` first, version every asset with `NEXORA_VERSION`. Use `Core\Assets::enqueue_sweetalert()` for SweetAlert.
7. Post types: `PostTypes\Registrar`. Admin menu / settings / edit-screen panels: `Admin\Menu`, `Admin\Settings`, `Admin\Meta_Boxes`, `Admin\List_Columns` (views in `templates/admin/`).
8. Page paths: use `Core\Urls` (`login()`, `registration()`, `profile()`, `profile_for()`), never hard-code `/login-page`.
9. A new page the plugin depends on (like `login-page`, `profile-page`) must exist by slug: add it to `$plugin_pages` in the theme installer (`nexora-theme/inc/elementor/installer.php`) and document it in the README setup steps.
10. If you add a rewrite rule, tell the user to re-save Settings > Permalinks.

Access control (non-admins locked out of wp-admin / wp-login) is `Core\Access_Control`; exempt a new endpoint there only if it truly must be reachable by visitors.
