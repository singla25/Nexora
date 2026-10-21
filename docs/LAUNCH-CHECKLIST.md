# Go-live checklist

What to do on the day the site is published. Each item says **done**, **to do** or **check**, judged from the code and from the settings of the site as it stands on 2026-10-21 (the local copy). "Check" means it depends on the live server, which I cannot see from here. Nothing in this file contains a password or key.

## Before the doors open

| # | Item | Status | Notes |
|---|---|---|---|
| 1 | WooCommerce "Coming soon" mode is **off** | **done** locally (`woocommerce_coming_soon = no`) | Check it again on the live site (WooCommerce → Settings → Site visibility). While it is on, cart and checkout are hidden from everyone but administrators. |
| 2 | **WP Mail SMTP** verified with a real test mail | **to do** | It is set up (mailer: Gmail) but nothing proves a message reaches an inbox. WP Mail SMTP → Tools → Email Test, to a mailbox you read. The new order, shipped and low-stock mails, the sign-up and password-reset mails and the contact form all depend on it. Look at WP Mail SMTP → Email Log afterwards. |
| 3 | **HTTPS** and the correct address | **to do** | Today the site address is `http://localhost83.local.com/nexora`. Set Settings → General (WordPress and Site Address) to the real `https://` address, install the certificate, redirect http to https, and keep `RewriteBase` in `.htaccess` right for where the site lives (it assumes the `/nexora/` folder). |
| 4 | `WP_ENVIRONMENT_TYPE` = `production` (the reCAPTCHA check is then active) | **to do** | `wp-config.php` says `local` today, and Nexora skips reCAPTCHA only on a genuinely local site. On the live site set `define( 'WP_ENVIRONMENT_TYPE', 'production' );`. |
| 5 | Real **reCAPTCHA keys** | **check** | Keys are saved in Nexora System → Settings (site key and secret key are both set locally). Make sure they are the keys registered for the live domain, then try a login and a sign-up. |
| 6 | `WP_DEBUG` **off** | **to do** | It is `true` today. On the live site: `define( 'WP_DEBUG', false );` and no `WP_DEBUG_DISPLAY`. Nexora never prints errors itself (a test checks that), but PHP and other plugins can. |
| 7 | `DISALLOW_FILE_EDIT` | **to do** | Not set. Add `define( 'DISALLOW_FILE_EDIT', true );` so nobody can edit plugin or theme code from wp-admin. |
| 8 | A real **server cron** for WP-Cron | **to do** | The queued e-mails (new order, shipped, low stock) run as WP-Cron events, which by default only run when someone visits the site. Add `define( 'DISABLE_WP_CRON', true );` and a server cron every minute or two: `* * * * * cd /path/to/site && php wp-cron.php` (or `wp cron event run --due-now`). Without it a quiet shop sends its mail late. |
| 9 | Scheduled, tested **backups stored off the server** | **to do** | UpdraftPlus is active and schedules daily files and database backups, but **no remote storage is set** and it keeps only one copy. Choose a remote destination (a cloud drive or storage bucket), keep several copies, run one backup now and **restore it on a test site** to prove it works. A backup stored only on the same server is lost with the server. |
| 10 | **ACF** installed and active | **done** | The free ACF is active (the administrator edit screens need it; without it they are read-only with a notice). Install it from wp-admin on the live site: its `vendor/` folder is not in git. |
| 11 | **Settings → Permalinks** saved | **check** | The structure is `/%postname%/` today. After copying the site, open Settings → Permalinks and press Save once so the `/profile-page/<name>` rule is written. |
| 12 | The pages **login-page**, **registration-page** and **profile-page** exist | **done** locally | All three are published. They are created by Appearance → Sample Content; check that the same slugs exist on the live site. |
| 13 | Test accounts, test orders and leftover test data **removed** | **to do** | The site still holds test accounts (`test_user_1`, `test_user_2`, `test_user_3`, `test_vendor_1`, `wekocurim`), 8 test orders, and test products imported from the demo API sources. Delete the test orders in WooCommerce → Orders, delete the test products, then delete the test users (the Users screen explains why it refuses a seller who has orders). Run a final look at Nexora System → Notifications and the Marketplace overview for leftovers. |
| 14 | The **`nexora-private` folder is not web-readable** | **done** for Apache, **check** on the live server | `wp-content/uploads/nexora-private/` has an `.htaccess` that denies everything, and documents are only served through the logged-in `nexora_document` download. That file is ignored by nginx and some hosts: open a document's direct URL (`.../uploads/nexora-private/<file>`) in a private window on the live site and confirm you get 403 or 404, not the file. |
| 15 | **Rotate every credential that was ever in the repository history** | **to do** | The public history of the repository contains an old `wp-config.php` and UpdraftPlus backups (commit `47304e31`). Treat everything in them as exposed: change the **database password**, **regenerate the salts and keys** (WordPress secret-key service, which also logs everybody out), change **SMTP / Gmail app passwords and OAuth credentials**, **reCAPTCHA** keys, and any **API keys** (for example product API sources, payment gateway keys). Do this on the live server before launch; rewriting history does not undo an exposure. |
| 16 | Payment gateways | **check** | Decide which gateways are on (cash on delivery and bank transfer are what the tests use; the PayPal Payments plugin is installed but inactive). Place one real test order end to end on the live site, then refund and delete it. |
| 17 | Commission rate and categories | **check** | Nexora System → Settings: the marketplace commission (10% until changed), the product categories, the low-stock threshold (5 until changed). |
| 18 | Administrator accounts | **check** | Only the people who should have access; strong unique passwords; remove any shared or test administrator. |
| 19 | Elementor / Elementor Pro / other third-party plugins | **check** | Their licences and `vendor/` folders are not in git. Install and update them through wp-admin on the live site. Keep WooCommerce and WordPress updated; the tests ran on WooCommerce 11.2.1. |
| 20 | Automatic tests | **done** | `.github/workflows/tests.yml` runs the suite and PHPCS on every push once the repository is on GitHub (see the plugin README, "Tests"). Keep it green before each release. |

## The first day

- Watch WooCommerce → Status → Logs for the source `nexora-mail` (a mail that could not be sent is logged there, never shown to a visitor).
- Check the Marketplace overview and a seller's Sales tab against one real order.
- Make a fresh backup and confirm it reaches the off-server storage.
