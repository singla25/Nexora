---
name: nexora-release-assets
description: Checklist to run after changing Nexora plugin or theme CSS/JS/markup: cache-busting version bump, docs sync, and syntax checks. Use before finishing any change in the nexora plugin or nexora-theme.
---

# Finish-a-change checklist

There is no build step. Run `tests/run.sh` (see `tdd-workflow`) and do this:

1. **Coding standards**: `cd wp-content/plugins/nexora && vendor/bin/phpcs` must report nothing (`phpcbf` fixes formatting). New user-facing strings use the `nexora` text domain and the `.pot` is regenerated (see the plugin README).
2. **Syntax check** every PHP file you touched: `php -l <file>`; `tests/run.sh` must be fully green (it includes the golden-output snapshots and the static namespace check). If you changed markup on purpose, regenerate the affected snapshot with `NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php` and read the diff: only your intended change may differ.
3. **Cache busting**: if any plugin CSS/JS changed, bump `NEXORA_VERSION` in `wp-content/plugins/nexora/nexora.php`. All plugin assets, chat included, use it.  For theme asset changes, bump the version the theme uses when enqueuing (see `inc/theme-setup.php`).
4. **Security re-check** for anything handling requests: nonce, login check, ownership check, sanitized input, escaped output, `$wpdb->prepare`.
5. **Docs**: update `README.md` (shortcode table, tables list, setup) and `CLAUDE.md` when you add a module, shortcode, table, or required page.
6. **Rewrites/schema**: if you changed rewrite rules, tell the user to re-save Permalinks; if you changed a table, tell them to reactivate the plugin (see `nexora-db-table`).
7. Do not touch WP core, third-party plugins, stock themes or `wp-config.php`; never commit uploads or DB dumps.
8. UI is verified in the browser under `/nexora/` (see `verify-like-a-user`); the tests cover logic and exact markup but not layout or JS behaviour. Say plainly what you could not test in a browser.
