# Nexora plugin (developer notes)

Front-end registration and login, profile dashboard, connections, notifications and chat.
Site-level setup and the shortcode table are in the repository [README](../../../README.md); this file is
about how the code is organised.

## Layout

```
nexora.php        constants, PSR-4 autoloader, activation hook, Plugin::boot()   (no logic)
src/              namespace Nexora\  - one class per file, file name = class name
  Core/           Plugin (wires modules), Assets, Access_Control, Urls, View, I18n, Autoloader
  Http/           Ajax (registrar + guard), Member_Ajax (base class), Rate_Limiter
  Auth/           Login, Registration, Otp, Recaptcha
  Profile/        Page, Privacy, Ajax, Fields, Repository, Private_Documents, Upload_Policy
  Connections/    Ajax (HTTP) -> Service (rules) -> Repository (queries)
  Notifications/  Content/  Chat/  Admin/  PostTypes/  Shortcodes/  Integrations/  Database/
templates/        HTML only. Rendered with Core\View::render('area/name', $vars); every value is escaped here.
assets/           css/, js/, lib/ (SweetAlert2, pinned)
languages/        nexora.pot  (text domain "nexora")
```

Layers: **Ajax/Page classes** read the request and call a **Service**; **Services** hold the rules;
**Repositories** are the only code that queries; **templates** print what the class prepared.

## Rules the code relies on

- A new module is one class under `src/<Module>/` plus a `new` line in `Core\Plugin::boot()`.
- Every AJAX action is registered with `Http\Ajax::register()` (creates `wp_ajax_nexora_<name>` and keeps the old
  un-prefixed name as a deprecated alias). Member handlers extend `Http\Member_Ajax` and begin with `$this->member()`.
- Abusable actions are rate limited (`Http\Rate_Limiter`; buckets in `defaults()`, tunable with the `nexora_rate_limits` filter).
- Private data: `Profile\Privacy` decides who may receive what; ID documents are stored by
  `Profile\Private_Documents` outside the public uploads path.
- WordPress classes inside a namespace need a leading backslash (`\WP_Query`); `tests/php/test-static-namespaces.php` checks this.
- Every user-facing string uses the `nexora` text domain. After adding or changing strings run
  `wp i18n make-pot wp-content/plugins/nexora wp-content/plugins/nexora/languages/nexora.pot --slug=nexora --domain=nexora --exclude=vendor,assets/lib`
  (a test fails when the `.pot` is stale).
- Bump `NEXORA_VERSION` (and the `Version:` header, a test compares them) when CSS/JS changes.

## Tests

From the repository root (needs WP-CLI; runs against the local dev database with self-cleaning fixtures):

```
tests/run.sh                          # everything
tests/run.sh tests/php/test-foo.php   # one file
NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php   # re-record markup snapshots (review the diff!)
```

## Coding standards

WordPress Coding Standards through PHPCS. Composer is only for these development tools; production needs no `vendor/`.

```
cd wp-content/plugins/nexora
composer install          # or: php composer.phar install
vendor/bin/phpcs          # must report nothing
vendor/bin/phpcbf         # auto-fix formatting
```

Deliberate exclusions (and why) are written in `phpcs.xml.dist`. Do not ship `vendor/` or `composer.*` to production.

## Known follow-ups

- Front-end JS is not translated yet (its strings live in `assets/js`; they need `wp.i18n` or localized strings).
- Front-end JS uses global functions/variables (`profilePageData`, `profileData`, `nexoraChat`, helpers in `chat.js`); namespacing them is a separate, UI-tested change.
- Table schema is versioned: bump `Migrations::DB_VERSION` after editing a `create_table()`; the site upgrades itself. Uninstall deletes data only when the "Delete data on uninstall" setting is on.
