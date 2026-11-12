# Nexora

**A social network and multi-vendor marketplace in one WordPress site.**

Members create a profile, connect with people they trust, chat in organised conversations, and buy from or sell to each other. Anyone can apply to become a seller. Once approved, a seller lists products (by hand, CSV, or from an API), receives orders, ships them, and watches their earnings, with the platform's commission worked out and recorded on every order.

Nexora is a custom plugin and theme for WordPress and WooCommerce. WooCommerce handles checkout and payments, and Nexora supplies the social layer, the seller side, and the rules that tie them together.

## What you can do with it

### As a member

- **Sign up and log in** on pages that match the site, with optional Google reCAPTCHA and email one-time codes for password reset.
- **Build a profile**: personal, address and work details, a profile photo and cover image, and identity documents. Your email, phone, address and documents stay private to you; other members see your name, photo and bio.
- **Connect** with other members: search, send, accept or decline requests, see your connections and your mutual connections.
- **Chat** with your connections in threads that each start with a subject, so a conversation is easy to find again.
- **Share content** in a personal feed, and get **notifications** for requests and orders.

### As a buyer

- Browse the marketplace, add products from several sellers to **one cart** (grouped by seller), and check out with cash on delivery or bank transfer.
- Follow your orders in **My Orders**: status, payment, items, seller, and shipping.
- Prices, names and stock are always read from the product on the server, so nothing a browser sends can change what you pay.

### As a seller

- **Apply** when you sign up or later with "Become a seller", giving your business details (GSTIN is required and format-checked). An administrator approves, rejects or suspends the application.
- **List products** with the form, or **import** them from a CSV file (up to 500 rows) or an API (DummyJSON, Fake Store, the Nexora format, or your own API connection). Every import starts with a **dry-run preview** that writes nothing, and only then applies exactly what you previewed.
- **Fulfil orders**: see only your own lines of an order, mark them shipped, and get e-mail and in-app alerts for new orders and low stock.
- **Track earnings** in the Sales tab: totals for 7, 30 or 90 days or all time, a daily earnings chart, best sellers, and an order table. Commission is calculated per order line in whole cents and frozen at checkout, so changing the rate later never rewrites history. Refunds reverse the seller's share automatically.

### As an administrator

- Approve, reject, suspend or reactivate sellers, and edit records from the **Nexora System** menu.
- Set the commission rate (10% by default), product categories, the low-stock threshold, default images, the admin e-mail and reCAPTCHA keys.
- See a **marketplace overview** with total sales, commission, seller earnings and a per-seller table.

## Built to be trusted

- **Server-side everything.** Roles, prices, stock and ownership are decided on the server; hiding a button is never the security.
- **Private by design.** Identity documents are stored in a private folder and served only to their owner or an administrator. A seller never sees a buyer's billing details, email or phone.
- **Safe imports.** API imports only reach public https addresses, never follow redirects, and cap time and size; API keys are stored encrypted and never shown back.
- **Abuse limits.** Login, registration, imports, cart and other sensitive actions are rate-limited.
- **Tested.** A large automated test suite and the WordPress coding standards run on every push (see `.github/workflows/tests.yml`).

## The theme

The **Nexora theme** (`wp-content/themes/nexora-theme`) gives the site its navy-and-blue look. Its header, footer and 404 page can be replaced by Elementor Pro Theme Builder templates, and it can create a full set of sample pages for you.

1. Activate Elementor, Elementor Pro and the **Nexora** theme.
2. Go to **Appearance → Sample Content** and press **Install sample content**.

It creates the Home (front page), About, Contact, FAQs, Privacy Policy, Terms of Use and Community Guidelines pages, plus Login, Registration and Profile if missing. It also creates Elementor library templates for each page layout, the Header, Footer and 404 templates, and the menus. It is safe to run again: pages you made yourself are never changed, and pages it created are replaced only if you tick "Replace".

The logo, tagline, email, phone, address and social links come from **Appearance → Nexora Settings**, and the live numbers (members, connections, posts, conversations) are calculated from your data, so the templates never need editing when the site grows. Images use one sample image; replace it in the Elementor editor.

## Shortcodes

| Shortcode | Use on page |
| --- | --- |
| `[nexora_home]` | Landing page (live numbers, text editable under Nexora > Settings) |
| `[profile_registration]` | Registration page |
| `[profile_login]` | `/login-page` |
| `[profile_dashboard]` | `/profile-page` (also served at `/profile-page/<username>` via a rewrite rule) |
| `[nexora_stat type="members\|connections\|posts\|chats"]` | Any Elementor Shortcode widget: live platform number |
| `[nexora_auth_buttons]` | Header: Log in / Sign up, or profile / Log out when logged in |
| `[nexora_contact_form]` | Contact page: spam-protected form that emails the admin |

## Get it running

**Requirements:** WordPress 6.4 or newer (the repository ships 7.1), PHP 8.0 or newer (tested on 8.3), MySQL or MariaDB, Apache with `mod_rewrite` (the `.htaccess` assumes the site is served from `/nexora/`; change `RewriteBase` if you host it elsewhere), and **WooCommerce** (both of its order-storage modes work). The free **Advanced Custom Fields** plugin is needed for the administrator edit screens, and **Elementor Pro** for the Theme Builder templates. Plan for working outgoing e-mail (WP Mail SMTP): one-time codes and order e-mails depend on it.

1. Place the project in your web root (for example `/var/www/html/83/nexora`).
2. Create a database, copy `wp-config-sample.php` to `wp-config.php`, and fill in the credentials and salts.
3. Run the WordPress installer at `/nexora/wp-admin/install.php`, or import a backup.
4. Activate WooCommerce and the **Nexora** plugin (activation creates the notification and chat tables).
5. Create the pages `login-page`, `registration-page` and `profile-page` with the matching shortcodes, or use *Appearance → Sample Content*, which creates the missing ones. Then save *Settings → Permalinks* once.
6. Under *Nexora System → Settings*, set the default images, admin e-mail, commission, categories and (optionally) reCAPTCHA keys.
7. Turn WooCommerce "Coming soon" mode **off**; while it is on, customers cannot reach the cart or checkout.

Prefer containers? `docker compose up -d --build` starts the site at http://localhost:8080/nexora/ (details in [`docker/README.md`](docker/README.md)). Before you go live, work through [`docs/LAUNCH-CHECKLIST.md`](docs/LAUNCH-CHECKLIST.md).

## For developers

The plugin is plain PHP with a PSR-4 layout (`wp-content/plugins/nexora/src`, namespace `Nexora\`), HTML in `templates/`, and CSS/JS in `assets/`. There is no build step.

| Path | Purpose |
| --- | --- |
| `wp-content/plugins/nexora/` | The custom plugin: social features, marketplace, orders and reports. Notes in its own [README](wp-content/plugins/nexora/README.md). |
| `wp-content/themes/nexora-theme/` | The custom theme and the Elementor sample-content installer. |
| `tests/` | WP-CLI test suite, golden markup snapshots and CI helpers. |
| `docs/` | Specification, architecture and reference documents (below). |
| Everything else | WordPress core and third-party plugins and themes: install and update them through wp-admin. |

- **Tests** run only on a scratch copy of the database: `NX_TEST_DB=<scratch db> tests/run.sh`, or add a single file such as `tests/php/test-foo.php`.
- **Coding standards:** `cd wp-content/plugins/nexora && composer install && vendor/bin/phpcs` (auto-fix with `vendor/bin/phpcbf`).
- **Strings** go through `__()` with the `nexora` text domain; regenerate `languages/nexora.pot` after changing them.
- **Cache busting:** bump `NEXORA_VERSION` in `nexora.php` when plugin PHP, CSS or JS ships.
- Set `WP_DEBUG` to `true` locally to surface PHP notices.

Start with [`docs/SPEC.md`](docs/SPEC.md) (the marketplace rules and which order statuses count as earnings) and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The rest of [`docs/`](docs/) covers the AJAX API, authentication, the database, the theme, the development workflow, the security matrix, the design system, the roadmap and troubleshooting.

## What is not in git

`.gitignore` excludes `wp-config.php`, `.env`, `wp-content/uploads/`, caches, and backup folders (`updraft/`, `ai1wm-backups/`, `*.sql`, `*.wpress`). Obtain database dumps and uploads separately; never commit them, since they contain user data.

## License

WordPress core is licensed under GPLv2 or later (see `license.txt`). Third-party plugins keep their own licenses. The Nexora plugin was written by Sahil Singla.
