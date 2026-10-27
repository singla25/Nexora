# Design System Fixes: Report

Date: 2026-10-18. Follow-up to the design system audit. Everything below is CSS/token work plus two PHP enqueue changes; no markup or user-facing strings changed.

## Result

| Audit finding | Before | After |
|---|---|---|
| Undefined tokens in use | 5 | 0 |
| Raw hex colours outside tokens | 54 plugin, 22 theme | 0 |
| Raw rgb()/rgba() outside tokens | about 40 | 0 |
| Distinct font-size values | 52 | 9 scale steps |
| Spacing | 32 px literals, no scale | 13-step `--nx-space-*` scale, no px literals |
| Radius literals | about 12 stray values | radius tokens only (xs, sm, md, lg, xl, pill) |
| z-index values | 500, 10000, 99999 | 5 layer tokens |
| Transition spellings | 0.2s, 0.22s, 0.25s, 0.3s, 220ms | one `--nx-duration` / `--nx-ease` |
| Breakpoints | 8 (480 to 1024) | 3 (600, 900, 1200) |
| `!important` outside Elementor sheet | 15 | 0 |
| `prefers-reduced-motion` | none | handled globally |
| Plugin vs theme palettes | two vocabularies (`--nx-*`, `--nxt-*`) | one (`--nx-*`), theme keeps an identical copy for plugin-off use |
| Contrast failures | 6 token pairs | 0 (14 pairs checked) |

## What changed

- **`tokens.css` rewritten** as the single source of truth: brand, neutral, status (solid and bg/text pairs), translucent, gradient, type, spacing, radius, elevation, motion, focus, layer and layout tokens.
- **New `components.css`**: `.nx-btn` (primary, secondary, danger, inverse, sm, block), `.nx-badge` (five statuses), `.nx-input`, `.nx-card`, and global SweetAlert2 dialog styling that replaced 15 `!important` declarations. All older button classes share one transition, cursor, font and keyboard focus ring.
- **Contrast fixed at the source**: `--nx-success` is now `#15803d` (5.0:1), `--nx-text-faint` `#6e727c` (4.9:1), blue text uses the new `--nx-blue-text` (6.9:1), and every white-on-blue surface uses `--nx-blue-dark` or the new `--nx-gradient-action` instead of the lighter brand blue (3.5:1).
- **Bugs fixed**: the five undefined tokens are gone; orders and marketplace buttons now use the brand colour instead of a stray `#2563eb`.
- **Theme**: all `--nxt-*` tokens removed in favour of `--nx-*`. The theme enqueues the plugin's tokens when the plugin is active, otherwise its own `tokens.css` copy (new `nxt_enqueue_tokens()`).
- **Layering**: SweetAlert dialogs (100000) now sit above the chat window (99999). Before, a dialog opened from inside chat could be hidden behind it.
- **Keyboard focus**: every button and link gets a visible 2px outline; input focus now uses a dark-blue border plus the halo.
- **Versions**: `NEXORA_VERSION` 1.0.16 to 1.0.17 (plugin header, constant and `.pot`), `NXT_VERSION` 1.0.0 to 1.0.1.
- **Docs and guard**: new `docs/DESIGN-SYSTEM.md`; `CLAUDE.md` and the `nexora-frontend-style` skill point to it; new `tests/php/test-design-system.php` (93 checks, passes) fails on any regression.

## Visible differences to expect

Mapping to scales moves some values by a few pixels (for example 10px spacing became 12px, 0.95rem text became 1rem, 13px text became 14px), primary buttons end in a darker blue, placeholder and caption text is slightly darker, success green is deeper, and the three-column grids now collapse to one column at 900px instead of 760px.

## Verified and not verified

Verified with Playwright driving headless Chrome against `http://localhost/nexora`, using a session cookie for the existing `nexora` admin and for the `vendor1` member (an approved vendor, so every tab is visible); no passwords were used and nothing was changed in the database except those sessions:

- Design test: 93 passed, 0 failed. `php -l` clean on the changed PHP files.
- Pages: home, registration, wp-admin Nexora System settings, admin-mode profile, and all 8 profile tabs of `vendor1` (User Information, Connections, Content, Notifications, Marketplace, My Orders, My Products, Sales) at 1440px and 400px: no horizontal overflow, no JavaScript errors from Nexora code, no stylesheet 404s.
- Add product dialog: SweetAlert popup at z-index 100000 with a 20px radius, the chat window at 99999, and the new shared button styles rendering; the first field shows the new focus ring.
- Found and fixed during this pass: the page body text had rounded from 17px up to 18px (17px sits exactly between two scale steps). It is now the 16px base, and the My Products, import, API and vendor buttons use the same 14px size as the tab buttons instead of inheriting body size. Versions bumped again (plugin 1.0.18, theme 1.0.2).

Not verified:

- `tests/run.sh` (golden-output, i18n, compat suites) and `phpcs`: `wp` is not installed in this shell. No markup or translatable strings changed.
- Widths between 900 and 1440px, the Sales and My Orders tabs with real order data, the wp-admin Chat screen, the OTP / forgot-password dialogs, and the customer (non-vendor) view.
- Not caused by this work, but seen in the logs: `react.min.js` and `react-dom.min.js` return 404 from `wp-includes/js/dist/vendor/` (Elementor then reports `React is not defined`), and several uploaded images under `wp-content/uploads/2026/10/` are missing (broken image icons on the settings page).
- Nothing is committed (git has no author identity on this machine) and nothing was pushed.
