# Dashboard Redesign: Report

Date: 2026-10-18. Goal: make the whole UI follow the reference dashboard (flat app shell, left sidebar, white bordered cards on a grey canvas, one blue, pastel status pills, dense Inter type) and rebuild the design system around it.

## What the reference gave us

| Reference trait | Design system answer |
|---|---|
| Left sidebar with icons, solid blue active item | Profile tabs become the sidebar nav (`--nx-sidebar` 220px), icons per tab, active item `--nx-primary` |
| Grey canvas, white cards with a hairline border | `--nx-bg`, `--nx-surface`, `--nx-border`; shadows reduced to a faint 1px-style lift |
| One blue for actions, near-black text | `--nx-primary` #2563eb (5.2:1 under white), `--nx-ink` #111113; navy and gradients removed |
| Flat buttons: blue primary, white outlined secondary | `.nx-btn--primary` / `--secondary`; no gradient fills, no hover lift |
| Pastel status pills (green, pink, blue) | `.nx-badge` with success, accent (pink), info, warning, danger, neutral |
| Shaded table header, hairline rows | `.nx-table`, applied to order lines |
| Rounded stat strip | `.nx-stat-bar` component |
| Small dense sans-serif | Inter, dashboard text at 14px, scale unchanged |

## Changes

- **Tokens rewritten** (`tokens.css`, copied to the theme): new `--nx-primary*`, `--nx-ink`, `--nx-bg`, `--nx-surface-muted`, accent status pair, softer shadows, 160ms motion, radii 4/8/12/16/20. The old navy/blue/tint/gradient tokens are gone and every stylesheet was migrated (property-aware: navy text became ink, navy fills became primary, blue text became `--nx-primary-text`).
- **Profile page is an app shell, CSS only**: two-column grid, sidebar tabs with icons (CSS masks keyed on `data-tab`), compact header (avatar left, name beside it), cover hidden when no image is set, mobile layout turns the sidebar into a scrolling tab row. Section titles are left-aligned like the reference.
- **Components**: flat buttons; new `.nx-table`, `.nx-stat-bar`, `.nx-badge--accent`, `.nx-btn--lg`; SweetAlert dialogs restyled (16px radius, flat blue confirm). The "Business" sub-tab no longer looks like an active tab.
- **Sales and Orders**: totals are one summary card with rows, order status pills use the new palette (processing blue, completed green, pending pink, on hold amber, cancelled red), line-item table has a shaded header.
- **Public site**: header and sections lose the dark-to-blue gradient rules for 1px hairlines; Elementor's green menu highlight is replaced by the primary blue; buttons, cards and the hero use the new tokens.
- **Logout button**: its inline red style became `nx-btn nx-btn--danger` in a right-aligned `.profile-logout` row. This is the one markup change; the two golden snapshots that contain it (`profile-owner`, `profile-own-url`) were regenerated and only that block differs.
- **Font**: Inter replaces Plus Jakarta Sans in the plugin and both theme enqueue sites.
- **Docs and tests**: `docs/DESIGN-SYSTEM.md` rewritten for the new tokens and the dashboard layout; `tests/php/test-design-system.php` updated (111 checks, all pass), including 23 text-contrast pairs.
- **Versions**: plugin 1.0.20, theme 1.0.4.

## How it was checked

Playwright with headless Chrome against `http://localhost/nexora`, using session cookies for the existing `nexora` admin and the `vendor1` member:

- All 8 profile tabs at 1440px and 400px, home, login, registration (guest and member), the Add product dialog and the chat window: no horizontal overflow and no JavaScript errors from Nexora code. Dialog z-index 100000 above chat 99999.
- Full PHP suite via `tests/run.sh` with a small stand-in for WP-CLI (WP-CLI is not installed here). Baseline before the redesign: 3,670 passed, 77 failed. The failures are unrelated to styling: `test-api-import` (38) and `test-api-limit` (25) depend on the DummyJSON feed, `test-i18n` (2) needs `wp i18n make-pot`, three golden admin snapshots differ by environment details, and a few single checks. After the redesign: 3,843 passed, 91 failed. The extra 14 failures come from fixing the stand-in runner (the golden test used to stop halfway, now it runs all 48 snapshots): every one of the 23 golden failures is the snapshot host (`localhost83.local.com` in the repo versus `localhost` here) except the same three admin snapshots that already differed by environment. The design-system test went from 9 failures (baseline, old tokens) to 0 with 111 checks. No failure points at the new styling or markup. On a machine with the original host name, `tests/run.sh` should show only the feed-dependent, `make-pot` and environment cases.

## Follow-up: welcome line and stat strip (added after the first pass)

The owner's dashboard now opens with "Welcome back, {first name}" and a `.nx-stat-bar` strip: Connections, Unread notifications and, for an approved seller, Products. It is shown to the owner only (other members and guests never get it). Built test-first: `tests/php/test-profile-stats.php` (7 checks) failed first for the missing behaviour, then passed. Data comes from `Profile\Page::owner_stats()`; markup is in `templates/profile/page.php`. The two owner golden snapshots were refreshed (only the new block differs), the `.pot` has the one new string (`Unread notifications`) added by hand because `wp i18n make-pot` is not available here, so regenerate it where WP-CLI exists. Plugin version 1.0.22.

## Follow-up: identity moved to a right rail

The cover image and the avatar/name/contact block moved from the top of the main area into a right-hand panel (`--nx-rail` 280px), so the main column starts with the welcome line and the work. CSS only (grid areas), no markup change; plugin 1.0.23, theme 1.0.5. Checked at 1440, 1000 and 400px.

## Follow-up: the right rail becomes a profile card

- The welcome line and the stat strip moved into the right rail, inside the identity block (`.profile-header`), so the main column is only the work. The rail is now a card: tinted banner (or the cover image), 88px avatar overlapping it, centred name and contact, a divider, "Welcome back, {name}", and three tiles (icon chip, big number, label) for Connections, Unread notifications and Products (sellers only).
- Removed the bottom Logout button (the site header has Log out) and the Edit button on the Business Details card (the Business sub-tab of User Information opens the same edit popup; its template is still on the page). The unused `.logout-btn` click handler was removed from `profile-page.js`.
- Test-first: `test-profile-stats.php` (welcome and strip inside the panel, no logout button) and `test-vendor-profile-ui.php` (no Edit button on the card, popup template still present) were changed to fail first, then the templates were changed. The two owner golden snapshots were refreshed again. Plugin 1.0.25.

## Not done / caveats

- The reference's top bar (search box, "New Project" button, notification bell), schedule and notes cards have no equivalent data in Nexora. They would need new markup and features, so they were not invented. `.nx-stat-bar` is ready if you want a stat strip on the profile (for example connections, products, orders).
- Sidebar items for Settings and Help are not present for the same reason.
- Not verified in a browser: widths between 900 and 1440px, the OTP and forgot-password dialogs, the wp-admin chat screen, the customer (non-vendor) view, and Elementor-edited pages that override colours inside their own saved settings. If a saved Elementor template pins a colour, it will keep it until edited in Elementor.
- `tests/golden/profile-owner.html` and `profile-own-url.html` were regenerated for the logout markup, with the snapshot host kept as `localhost83.local.com`; no other snapshot was changed.
- `phpcs` was not run (Composer dependencies are not installed here).
- Nothing is committed (git has no author identity on this machine) and nothing was pushed.
