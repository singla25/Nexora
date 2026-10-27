# Nexora Design System Audit

Date: 2026-10-18. Scope: all CSS in `wp-content/plugins/nexora/assets/css/` (9 files, 3,614 lines) and `wp-content/themes/nexora-theme/assets/css/` (2 files, 1,905 lines), plus how they are enqueued. Static analysis only (grep and contrast arithmetic); nothing was rendered in a browser, so visual findings still need a `verify-like-a-user` pass.

## 1. Summary

Nexora has a real but thin design system: one brand palette (navy/blue), one font (Plus Jakarta Sans), three radii, two shadows, defined as `--nx-*` tokens in the plugin and duplicated as `--nxt-*` in the theme. Core surfaces (profile, login, registration, chat) use the tokens heavily. The newer marketplace work (orders, vendor, marketplace CSS) drifts from them: it references tokens that do not exist and hard-codes its own colours.

| Area | Rating | One-line verdict |
|---|---|---|
| Colour tokens | Needs work | Brand colours tokenised; status/semantic colours and neutrals are not |
| Typography | Needs work | One family, no type scale: 52 distinct font-size values |
| Spacing | Poor | No spacing tokens; 32 distinct literal values |
| Radius / shadow / motion | Fair | Radius and shadow tokenised, motion is not |
| Components | Poor | No shared button, badge, form or modal base; each screen re-styles its own |
| Naming / scoping | Needs work | Three prefixes done well; ~120 unprefixed global selectors in `profile-page.css` |
| Accessibility | Needs work | Several contrast failures from tokens themselves; no reduced-motion support |
| Responsive | Fair | Max-width only, eight different breakpoints, no shared set |
| Governance | Fair | Convention documented in a skill, nothing enforces it |

Top priorities: fix the five undefined tokens (bug, section 3.1), fix the contrast failures (3.2), add semantic status tokens, then consolidate the plugin and theme token sets.

## 2. What exists

- **Tokens** (`plugins/nexora/assets/css/tokens.css`, 28 lines): 12 colours, 3 radii, 2 shadows, 1 gradient, 1 font stack. Enqueued as handle `nexora-tokens`, a dependency of every plugin stylesheet (verified in each `Assets`/`Login`/`Registration`/`Page`/`Module` class).
- **Theme tokens** (`themes/nexora-theme/assets/css/style.css:1-40`, `--nxt-*`): the same palette again plus blue-bg/blue-text, amber-bg/amber-text, a fourth radius (`xl` 22px), 4 shadows, 2 gradients, a transition, a container width and a heading font.
- **Prefixes**: plugin `nx-*` (256 uses), theme chrome `nxt-*` (188), Elementor sections `nxe-*` (133). Documented in `CLAUDE.md` and the `nexora-frontend-style` skill.
- **Font loading**: Google Fonts, weights 400-800, requested from three places (`Core/Assets.php:50`, `theme/inc/elementor/support.php:31`, `theme/inc/theme-setup.php:39`).
- **Third-party UI**: SweetAlert2 (vendored in `assets/lib`) is restyled by global `.swal2-*` overrides.
- **Convention doc**: `.claude/skills/nexora-frontend-style/SKILL.md` ("tokens first, don't add new hex values or fonts"). No standalone design-system document exists in `docs/`; `THEME.md` is the closest.

## 3. Findings

### 3.1 Undefined tokens (bug, high)

Five custom properties are used but defined nowhere in the repo's CSS. They only work because of the fallback value (or silently fail where there is none):

| Token | Used in | Effect |
|---|---|---|
| `--nx-radius` | chat, marketplace, orders, profile-login, profile-page, profile-registration, style, vendor | Always resolves to its inline fallback (6px or 10px). The tokens file has `-sm/-md/-lg` only. The fallbacks disagree with each other. |
| `--nx-primary` | marketplace, orders | Falls back to `#2563eb` (a generic Tailwind blue), not brand blue `#2c8fd1`. Primary buttons and focus rings in orders/marketplace are off-brand. |
| `--nx-muted` | orders | Falls back to grays `#4b5563`/`#6b7280`, not `--nx-text-muted` `#5b5f6b`. |
| `--nx-surface` | orders | Falls back to `#fff`. |
| `--nx-space-2` | marketplace | No spacing scale exists, so there is no fallback to land on. Check the rule for a collapsed gap. |

Fix: either define these or (better) replace them with the real tokens (`--nx-blue`, `--nx-text-muted`, `--nx-white`, `--nx-radius-sm`).

### 3.2 Colour

- **Hard-coded colour**: 54 hex literals outside `tokens.css` in the plugin and 22 in the theme. `orders.css` is the worst (19 hex, 11 `var()`): status pills, grays and the off-brand blue are all literal. Stray Tailwind-style values (`#e5e7eb`, `#111827`, `#9ca3af`, `#6b7280`, `#4b5563`, `#2563eb`, `#991b1b`, `#166534`) do not appear in the palette.
- **No semantic status palette**: only `--nx-danger` and `--nx-success` exist. Statuses are implemented four separate ways: orders (`.nx-status-*`, green/amber/red/gray literals), vendor (`.nx-vendor-status`), connection history (`.history-status.*`) and notifications (`.noti-status.*`). The theme has an amber pair (`--nxt-amber-*`) the plugin cannot see.
- **Duplicated palettes**: `--nx-*` and `--nxt-*` hold identical values under two names. A palette change must be made twice. No stylesheet crosses over (`var(--nxt-` is never used in the plugin or vice versa), so the two systems cannot share components.
- **Unused**: `--nxt-shadow-pop` is defined and never used.
- **Contrast** (WCAG 2.x, computed from the token values):

| Pair | Ratio | Result |
|---|---|---|
| `--nx-text-faint` `#9a9a95` on white | 2.83 | Fails AA (4.5) for text; fails 3.0 for UI |
| White on `--nx-success` `#16a34a` | 3.30 | Fails AA for normal text |
| White on `--nx-blue` `#2c8fd1` | 3.53 | Fails AA for normal text (passes only for large text) |
| `--nx-blue` `#2c8fd1` as link/text on white | 3.53 | Fails AA |
| `--nx-blue` on `--nx-bg-tint` | 3.16 | Fails AA |
| White on `#9ca3af` (disabled button, `orders.css:95`) | 2.54 | Fails |
| White on `--nx-danger` | 4.83 | Pass |
| White on `--nx-blue-dark` | 5.50 | Pass |
| `--nx-text-muted` on white / tint | 6.38 / 5.72 | Pass |
| Order status pills (3 checked) | 6.4-6.8 | Pass |

  The brand gradient runs navy to `#2c8fd1`, so white text on its light end is 3.53. Primary buttons that use the gradient or flat `--nx-blue` with small white text inherit the failure.
- **No dark mode** (no `prefers-color-scheme` anywhere). Reasonable for the current scope; noted so it is a decision, not an oversight.

### 3.3 Typography

- One family everywhere (good): 11 declarations use `var(--nx-font)`/`--nxt-font`, 2 use `inherit`. The heading font token exists only in the theme.
- **No type scale.** Font-size values in use include 0.68, 0.7, 0.72, 0.75, 0.78, 0.8, 0.82, 0.85, 0.88, 0.9, 0.92, 0.95, 0.98, 1.0, 1.05, 1.1, 1.2, 1.3, 1.7 rem plus 11, 12, 13, 14, 16 px and two `clamp()` headings. The most common (`0.85rem`, `0.9rem`, `0.95rem`, `0.92rem`) are almost identical sizes with no reason to differ. rem and px are mixed.
- Two sizes are below 12px: `marketplace.css:98` (11px) and `chat.css:370` (0.68rem, about 11px).
- Weights are used consistently (400-800 loaded); no weight tokens, none needed.

### 3.4 Spacing and layout

- No spacing tokens (`--nx-space-2` is a dangling reference). 32 distinct padding/margin/gap values. Frequent: `gap: 10px` (13), `8px` (10), `14px` (7), `20px` (5); paddings such as `10px 12px`, `12px 14px`, `12px 16px`, `10px 14px` differ by 2px for no visible reason.
- Container width exists only in the theme (`--nxt-container: 1200px`).
- **z-index**: `500`, `500`, `1`, `10000`, `99999` with no scale. The `99999` (chat/overlay) and `10000` values are arbitrary and will lose to any third-party widget that picks bigger.

### 3.5 Radius, shadow, motion

- Radius is the best-adopted token: 47 uses of `--nx-radius-sm`, 19 `-md`, 12 `-lg`. Remaining literals: `50%` (18, fine for circles), `999px` (8) and `50px` (2) for pills (two spellings of one idea), plus stray `2px`, `6px`, `10px`, `34px`. The theme adds an `xl` (22px) that the plugin lacks.
- Shadows: two plugin tokens (`card`, `hover`), four in the theme; some rules hard-code `rgba(20,39,92,…)` instead.
- **Motion**: no timing tokens in the plugin. Durations are `0.3s` (6), `0.2s`, `0.22s`, `220ms` in various spellings; the theme has `--nxt-transition` and uses it, the plugin does not. **No `prefers-reduced-motion` handling in any file**, although cards lift on hover with `transform` transitions.

### 3.6 Components

There is no component layer. `nx-btn` (`style.css:103`) is the only reusable button and is used for the home/contact screens only. Everywhere else a button is a one-off class:

`.tab-btn`, `.submit-btn`, `.upload-btn`, `.connect-btn`, `.accept-btn`, `.reject-btn`, `.remove-btn`, `.remove-connection-btn`, `.view-all-btn`, `.view-content-btn`, `.profile-registration-form-btn`, `.nx-product-add`, `.nx-product-edit`, `.nx-import-*`, `.nx-api-*`, `.nx-add-to-cart`, `.nx-sale-view` plus the theme's `.nxt-btn` family (`--primary/--secondary/--sm/--nav/--ghost-inverse`) and `.nxt-btn-home--primary`.

That is roughly 25 button implementations across two vocabularies. The same holds for badges/pills (section 3.2) and form fields (`.form-group`, `.content-form`, `.profile-login-card input`, `.profile-registration-form-grid input`, `.nx-contact-form input` each restyle inputs and repeat the identical focus ring `border-color: blue; box-shadow: 0 0 0 3px rgba(44,143,209,.18)`).

**Modals**: SweetAlert2 is restyled in `profile-page.css:344-366` using 15 `!important` declarations, and only loads where that file loads. `marketplace.css` and `vendor.css` then add `.swal2-popup .nx-…` rules relying on those overrides being present. A popup opened on a page where `profile-page.css` is not enqueued would be unstyled.

### 3.7 Naming and specificity

- `profile-page.css` (1,342 lines, the largest file) has about 120 selectors with no prefix at the top level (`.tab-btn`, `.form-group`, `.info-*`, `.content-*`, `.doc-*`, `.history-*`, `.conn-*`, `.noti-*`). The file loads only on the profile page, which limits blast radius, but any theme or plugin using `.form-group` or `.tab-btn` on that page collides. The documented `nx-*` convention is followed by marketplace/vendor/orders but not by the older files.
- Chat styles are keyed on ids (`#chat-search`, `#chat-input`, `#chat-send`, `#nexora-chat-modal`, …), which are high-specificity and make overrides awkward.
- `!important`: 15 in `profile-page.css` (all SweetAlert), 2 in `elementor.css` (Elementor override, acceptable).
- BEM-style modifiers (`nx-btn--inverse`, `nxt-btn--sm`) are used in the newer code; the older code uses compound classes (`.history-status.pending`).

### 3.8 Accessibility (CSS-level)

- Focus: the marketplace and orders files add `:focus-visible` rings consistently (good). The theme has a global `:focus-visible` rule (`style.css:120`) and a skip link. The five older files (chat, login, registration, profile form, contact form) set `outline: none` on inputs and replace it with a border-colour change plus a 3px 18%-opacity ring; the ring is faint (the blue at 18% on white has well under 3:1 contrast against the background), so keyboard focus on those inputs relies on the border colour change alone. Buttons in these files have no focus style of their own and fall back to the browser default, which is fine unless the theme's global rule is not loaded.
- No `prefers-reduced-motion` (section 3.5).
- Contrast failures (section 3.2), including the most common secondary text colour `--nx-text-faint`.
- Touch targets, tab order and screen-reader behaviour were not assessed (need a browser).

### 3.9 Responsive

- Eight distinct `max-width` breakpoints across the files: 480, 500, 600 (6x), 640 (2x), 760 (2x), 860 (2x), 900, 1024. Only `chat`, `marketplace`, `profile-page`, `vendor` and the theme `style.css` use any `min-width` query (one each). Mobile-last (desktop-first) throughout.
- 600px is the de facto breakpoint; the 640/760/860 values are near-duplicates that make pages break at slightly different widths.
- The project's own check list (1440, 1000, 640, 400) does not match any actual breakpoint.

### 3.10 Governance and tooling

- Convention lives in prose only (`nexora-frontend-style` skill, `CLAUDE.md`). Nothing fails when a hex value or an undefined `var()` is added; the five undefined tokens above shipped through the existing test suite.
- No stylelint, no token documentation page, no component gallery. `nexora-release-assets` covers version bumps, not design conformance.
- Asset versioning is handled (`NEXORA_VERSION`/`NXT_VERSION`), which keeps token changes from being cached.
- Google Fonts is requested from three enqueue sites (the plugin and two theme files); handles differ (`nxt-fonts` vs the theme-setup call vs the plugin), so the font CSS can be requested twice on one page. Not verified in the browser.

## 4. Recommendations

Ordered by value for effort.

1. **Fix the undefined tokens** (hour). Replace `--nx-primary`, `--nx-muted`, `--nx-surface`, `--nx-radius`, `--nx-space-2` with real tokens in `orders.css`, `marketplace.css` and the rest. Visible result: orders/marketplace primary buttons become brand blue.
2. **Add a test for it** (hour). A PHP test (same pattern as `test-isolation-lint.php`) that scans plugin and theme CSS and fails on any `var(--x)` with no definition. Prevents recurrence.
3. **Fix contrast** (small). Add `--nx-blue-text` (the theme already has `#1a5f8c`) for blue text and links; darken `--nx-success` (e.g. `#15803d`, about 5.0:1); use `--nx-blue-dark` for white-on-blue buttons or increase button weight/size to qualify as large text; replace `--nx-text-faint` for text with `--nx-text-muted` and keep faint only for decoration; set the disabled button to a darker gray or a dimmed navy.
4. **Add semantic tokens** (small). `--nx-success-bg/-text`, `--nx-warning-bg/-text`, `--nx-danger-bg/-text`, `--nx-info-bg/-text`, and one `.nx-badge` + modifier set to replace the four status implementations.
5. **Add scales** (small). Spacing (`--nx-space-1…8`: 4, 8, 12, 16, 20, 24, 32, 48), font sizes (`--nx-text-xs/sm/base/lg/xl/2xl`, about six steps), z-index (`--nx-z-dropdown/modal/chat/toast`), motion (`--nx-ease`, `--nx-duration`), and a `--nx-radius-pill`. Migrate opportunistically, file by file, rather than all at once.
6. **Add `prefers-reduced-motion`** (small) in `tokens.css` or `style.css` to neutralise transforms and transitions.
7. **Unify plugin and theme tokens** (medium). Make the theme's `--nxt-*` aliases of the plugin's `--nx-*` (or the reverse), keep one source of truth and delete duplicated hex. Needs a decision on which side owns the palette since the theme can run without the plugin.
8. **Build a minimal component layer** (medium). `.nx-btn` (primary/secondary/ghost/danger, sm/md), `.nx-input`, `.nx-badge`, `.nx-card`, `.nx-modal` (a single place to style SweetAlert2 instead of inside `profile-page.css`). Then migrate the ~25 one-off buttons gradually. Load this layer as part of `nexora-tokens` or a sibling handle so every page has it.
9. **Standardise breakpoints** (small). Pick three (suggest 600, 900, 1200), document them, and collapse 480/500/640/760/860.
10. **Rename legacy selectors** (large, low urgency). Prefix `profile-page.css` classes with `nx-` only when touching a section; `test-golden-output.php` snapshots markup, so renames need `NX_UPDATE_GOLDEN=1` and review.
11. **Consolidate Google Fonts** into one enqueue with a single handle (small); consider self-hosting for privacy and performance.
12. **Governance**: add stylelint (`color-no-hex`, `declaration-property-value-disallowed-list`, `no-unknown-custom-properties`) or the test from item 2; add `docs/DESIGN-SYSTEM.md` documenting tokens, scales, components and the rules; add a design-conformance line to `nexora-release-assets`.

## 5. What is working

- A single tokens file loaded as a declared dependency of every plugin stylesheet.
- Radius tokens are widely adopted.
- One font family, loaded with `display=swap` and preconnect hints.
- Per-page asset loading, so most CSS never reaches pages that do not need it.
- Clear prefix ownership between plugin, theme chrome and Elementor sections.
- Newer marketplace CSS uses `:focus-visible` and BEM-style modifiers.
- Cache busting via version constants.

## 6. Not covered

Rendered appearance, real contrast over the gradient and images, JS-built markup (`products.js`, `chat.js`, etc. inject classes and inline styles not checked here), the Elementor Pro/Elementor-generated CSS, wp-admin screens (Nexora admin pages, meta boxes), email templates and print styles. A browser pass using `verify-like-a-user` at 1440/1000/640/400px is the natural next step.

## Appendix: method

Counts come from `grep` over the 11 CSS files listed in the scope line. Contrast ratios use the WCAG 2.x relative-luminance formula on the token hex values. Undefined and unused token lists compare every `var(--x)` reference with every `--x:` declaration across both folders (declared inline fallbacks were not counted as definitions). "Distinct values" counts exclude `0` and `auto`.
