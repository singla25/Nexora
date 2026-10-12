---
name: nexora-frontend-style
description: Conventions for Nexora plugin and theme CSS/JS (design tokens, per-page asset enqueueing, localized data, brand look) plus how to check a UI change in the browser. Use when editing any CSS, JS or front-end markup.
---

# Front-end conventions

- **Tokens first.** Colors, radii, shadows and the font come from `assets/css/tokens.css` (`--nx-navy`, `--nx-blue`, `--nx-radius-*`, `--nx-font`, ...), matching the theme's FST design (navy/blue, Plus Jakarta Sans). Use `var(--nx-*)`; don't add new hex values or fonts. Every plugin stylesheet depends on handle `nexora-tokens`; call `Core\Assets::enqueue_tokens()` for a new one.
- **Per-feature files.** Each feature enqueues its own CSS/JS from its own class, only on its own page (`Core\Assets::is_page_for()`): `profile-login`, `profile-registration`, `profile-page`, and chat (`assets/css/chat.css`, `assets/js/chat.js`, logged-in members only). Markup is in `templates/`. Add new assets there, not to `assets/css/style.css`, which is global on every page.
- **Localized data** is the only bridge to PHP: `profileData` (login/registration), `profilePageData` (profile), `nexoraChat` (chat). Escape/sanitize anything put in them; never include secrets.
- **Elementor sections** in the theme are styled via `nxe-*` classes in `themes/nexora-theme/assets/css/elementor.css`; keep layout editable in Elementor rather than hard-coding markup.
- Profile page JS uses jQuery + SweetAlert2 (CDN); chat has its own JS. Build HTML from AJAX data with escaping (`textContent` / escaped strings), not raw concatenation.
- Bump `NEXORA_VERSION` (plugin) after any CSS/JS change (see `nexora-release-assets`).

## Checking a UI change
No test suite, so measure in the browser under `/nexora/` (use `verify-like-a-user` for the method): check at about 1440, 1000, 640 and 400px wide; reach the page by clicking through from the header; reload mid-flow; try empty states (no connections, no content); and hard-refresh to rule out stale cache. Report what you actually drove versus only read.
