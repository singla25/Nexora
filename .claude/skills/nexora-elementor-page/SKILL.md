---
name: nexora-elementor-page
description: Add or change a sample Elementor page, library template, or Theme Builder template (header/footer/404) in the nexora-theme installer, using the NXT_EL builder and nxe-* classes. Use when editing sample content or the theme's Elementor layouts.
---

# Nexora theme: Elementor sample content

Code is in `wp-content/themes/nexora-theme/inc/elementor/`:

- `builder.php`: `NXT_EL` helpers that emit Elementor element arrays (`container`, `widget`, `heading`, `text`, `button`, `image`, `shortcode`, `html`, `nav_menu`, `wrap`, `section`).
- `templates.php`: layout functions. Reusable pieces are `nxt_tpl_*` (`page_hero`, `section_title`, `card`, `stat`, `step`, `quote`, `cta`). Each page/theme layout function takes a context array `$c` (`img`, `u( $slug )` URL helper, `date`). `nxt_sample_registry()` maps slugs to builders.
- `installer.php`: Appearance > Sample Content. Idempotent: it never overwrites pages the user made; "Replace" only touches pages it created (tracked by meta). Also creates menus and the Nexora plugin pages.
- `support.php`: Elementor locations, CSS enqueue, `[nxt_setting]`, `[nxt_logo]`, `[nxt_year]`.

## Add a page
1. Write `nxt_tpl_<name>( $c )` in `templates.php`, composing `NXT_EL::section()` and the `nxt_tpl_*` pieces. Do not hard-code numbers or contact details: use `[nexora_stat type="..."]`, `[nexora_auth_buttons]`, `[nxt_setting]`, `[nxt_logo]`.
2. Add `'slug' => array( 'Title', 'nxt_tpl_<name>' )` to `nxt_sample_registry()['pages']` (header/footer/404 go under `'theme'`).
3. Add it to the menu page lists in `installer.php` (`nxt_sample_menu_ensure` calls) if it should appear in navigation.
4. Style with `nxe-*` classes in `assets/css/elementor.css`, not inline Elementor styling, so layouts stay editable. Bump the theme version if you changed CSS (check `style.css` / `nxt_enqueue_*` for the version constant).
5. Test: Appearance > Sample Content > Install (run twice to confirm idempotence). Needs Elementor and Elementor Pro active; never edit those plugins.
