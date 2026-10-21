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

The suite runs inside WordPress through WP-CLI and **only on a scratch copy of the database**: the runner, `tests/bootstrap.php`, `tests/purge.php` and `tests/guard.sh` refuse (exit 3) to run on the database named in `wp-config.php`. Make a copy of the site database, then, from the repository root:

```
NX_TEST_DB=<scratch database> NX_SKIP_LIVE=1 tests/run.sh                          # everything
NX_TEST_DB=<scratch database> NX_SKIP_LIVE=1 tests/run.sh tests/php/test-foo.php   # one file
NX_TEST_DB=<scratch database> NX_UPDATE_GOLDEN=1 tests/run.sh tests/php/test-golden-output.php   # re-record markup snapshots (review the diff!)
```

`NX_SKIP_LIVE=1` skips the few tests that make real web requests (the web server only sees the live database). The suite needs WooCommerce with high-performance order storage, the free ACF, and the pages `login-page`, `registration-page`, `profile-page`; Elementor and the other third-party plugins are not needed. New tests are written first and run to see them fail (`tdd-workflow`), the rules for tests that share a database are in `CLAUDE.md`, and every entry point must be listed in `docs/SECURITY-MATRIX.md` (`test-security-matrix.php` fails otherwise).

### Automatic tests on every push

`.github/workflows/tests.yml` runs on every push and pull request, with read-only permissions, no secrets and pinned actions. It starts MySQL with two databases (the site and a scratch test one), builds a fixture site with `tests/ci/setup-site.sh` (WordPress is in the repository; WooCommerce and the free ACF are installed from wordpress.org at pinned versions; `wp-config.php` is written inside the job and never committed), then runs PHPCS, a syntax check of every PHP file, and the whole suite. To rebuild the same site by hand, set `CI_DB_HOST`, `CI_DB_USER`, `CI_DB_PASSWORD`, `CI_SITE_DB`, `CI_TEST_DB` and `CI_SITE_URL` and run `bash tests/ci/setup-site.sh` in a **fresh clone** (it refuses to run where a `wp-config.php` already exists).

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
