# Nexora Design System

Look: a calm, flat app UI inspired by modern project dashboards: neutral grey canvas, white bordered cards, near-black ink, one blue for actions, pastel status pills, small dense type (Inter), a left sidebar for navigation. No gradients on controls, no hover lifts.

One vocabulary for the plugin and the theme: custom properties named `--nx-*`, defined once in `wp-content/plugins/nexora/assets/css/tokens.css`. The theme keeps a byte-identical copy (`themes/nexora-theme/assets/css/tokens.css`) that it loads only when the plugin is not active. `tests/php/test-design-system.php` enforces everything below (it needs no WordPress: `php tests/php/test-design-system.php`).

## Rules

1. Raw colour values (hex, `rgb()`/`rgba()`) exist only inside `:root` in `tokens.css`. Everything else uses `var(--nx-*)`.
2. After changing `tokens.css`, copy it over the theme's copy (`cp wp-content/plugins/nexora/assets/css/tokens.css wp-content/themes/nexora-theme/assets/css/tokens.css`); the test fails if they differ.
3. Every `var(--x)` must be defined. No inline fallbacks: they hide missing tokens.
4. Font sizes, padding/margin/gap, border radius, z-index and transitions use the scales below, never literals (`0`, `auto`, `%`, `em`, `clamp()` and layout sizes like `width` are fine).
5. Media queries use only 600, 900 and 1200px (max-width, desktop-first).
6. No `!important` outside `themes/nexora-theme/assets/css/elementor.css` (it fights Elementor's own CSS).
7. A rule that removes an outline must provide `box-shadow: var(--nx-focus-ring)` and a visible border colour change.
8. Text on a coloured background must meet 4.5:1 (the test checks the token pairs). Primary actions are flat `--nx-primary` under white text; blue text on white or a soft surface is `--nx-primary-text`.
9. Bump `NEXORA_VERSION` (plugin sheets) or `NXT_VERSION` (theme sheets) after any change.

## Tokens

| Group | Tokens |
|---|---|
| Brand | `--nx-primary` (actions, active nav), `--nx-primary-dark` (hover), `--nx-primary-soft` (selected), `--nx-primary-text` (blue text), `--nx-ink` (headings) |
| Surfaces | `--nx-bg` (page canvas), `--nx-surface` (cards), `--nx-surface-muted` (table headers, stat bars), `--nx-white` |
| Neutral | `--nx-border`, `--nx-border-strong`, `--nx-text`, `--nx-text-muted`, `--nx-text-faint`, `--nx-disabled-bg` |
| Status | solid `--nx-danger/success/warning/info`; pastel pairs `--nx-{success,warning,danger,info,accent,neutral}-{bg,text}`; `--nx-danger-border` |
| Translucent | `--nx-on-dark`, `--nx-on-dark-border`, `--nx-glass`, `--nx-overlay`, `--nx-focus-halo` |
| Backgrounds | `--nx-gradient-wash`, `--nx-scrim-light`, `--nx-scrim-brand`, `--nx-glow` (public hero areas only) |
| Type | `--nx-font`, `--nx-font-heading`; sizes `--nx-text-xs` .75rem, `sm` .875, `base` 1, `md` 1.125, `lg` 1.25, `xl` 1.5, `2xl` 1.75, `3xl` 2.25, `4xl` 3 |
| Spacing | `--nx-space-0` 2px, `1` 4, `2` 8, `3` 12, `4` 16, `5` 20, `6` 24, `8` 32, `10` 40, `12` 48, `16` 64, `20` 80, `24` 96 (rem based) |
| Radius | `--nx-radius-xs` 4px, `sm` 8 (buttons, fields), `md` 12 (cards), `lg` 16 (app shell, dialogs), `xl` 20, `pill` |
| Elevation | `--nx-shadow-xs/card/hover/pop/button/button-hover/modal` (cards use a 1px border plus a faint shadow) |
| Motion | `--nx-duration` 160ms, `--nx-ease`; `prefers-reduced-motion` is handled in `tokens.css` |
| Focus | `--nx-focus-ring` (halo; pair with `border-color: var(--nx-primary)`) |
| Layers | `--nx-z-sticky` 500, `dropdown` 600, `chat` 99999, `modal` 100000 (SweetAlert), `toast` 100100 |
| Layout | `--nx-container` 1200px, `--nx-sidebar` 220px |

## Components (`assets/css/components.css`)

Loaded under the handle `nexora-tokens` (which is components.css depending on `nexora-token-vars` = tokens.css), so it always prints before any feature stylesheet. Feature sheets add layout only.

- **Button**: `.nx-btn` + `--primary` | `--secondary` | `--danger` | `--inverse`, sizes `--sm`, `--lg`, `--block`. `.nx-primary` and `.nx-outline` are the older names for primary and secondary. The older one-off button classes (`.submit-btn`, `.connect-btn`, `.nx-product-add`, ...) share the same transition, cursor, font and keyboard focus ring, listed in the component sheet.
- **Badge**: `.nx-badge` + `--success` | `--warning` | `--danger` | `--info` | `--accent` | `--neutral` (pastel pills).
- **Field**: `.nx-input`. **Card**: `.nx-card`. **Table**: `.nx-table` (shaded header row, hairline rows). **Stat bar**: `.nx-stat-bar` with `.nx-stat` items (inline figures in one rounded strip), used for the profile welcome strip.
- **Dialogs**: SweetAlert2 popups, buttons and title are styled globally under `.swal2-container` (no `!important`); the `.nx-swal` class applies the same popup style to custom markup.

## Profile dashboard layout

`.profile-wrapper` is a three-column grid (`--nx-sidebar` | main | `--nx-rail`): the `.profile-tabs` buttons become the left sidebar nav (icons come from `.tab-btn[data-tab=...]` CSS masks, active item solid `--nx-primary`), the content takes the middle, and the right rail is a profile card (banner, overlapping avatar, name, contact, "Welcome back" and three figure tiles: connections, unread notifications, products for sellers) because it is secondary information. Under 900px the sidebar becomes a horizontally scrolling tab row and the rail stacks above the content. The layout is CSS only; add a new tab by adding its `data-tab` icon rule in `profile-page.css`.

Use components for new UI; migrate a legacy class to one when you touch its markup.

## Class prefixes

Plugin `nx-*`, theme chrome `nxt-*`, Elementor sections `nxe-*`. Older plugin classes without a prefix (`.tab-btn`, `.form-group`, ...) live in `profile-page.css` and are scoped to that page; prefix new classes.
