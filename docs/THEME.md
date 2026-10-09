# The Nexora theme, and how it works with the plugin

`wp-content/themes/nexora-theme/` is a classic PHP theme (version 1.0.0, text domain `nexora-theme`, requires PHP 7.4 per its header). It supplies the site's look and chrome only. Back to the [developer guide](NEXORA-DEVELOPER-GUIDE.md).

> Scope note: at the time of writing, many theme files show as modified but uncommitted in the working tree. This document describes the files as they are on disk now.

Contents: [1 Plugin vs theme](#1-plugin-vs-theme) · [2 File tree](#2-file-tree) · [3 What each file does](#3-what-each-file-does) · [4 Elementor integration](#4-elementor-integration) · [5 Where plugin and theme meet](#5-where-plugin-and-theme-meet) · [6 If the theme is switched](#6-if-the-theme-is-switched)

---

## 1. Plugin vs theme

| | Plugin `nexora` | Theme `nexora-theme` |
|---|---|---|
| Answers | "what can members do, and who may do it?" | "what does the site look like around the content?" |
| Contains | registration, login, OTP, profiles, privacy, connections, chat, notifications, uploads, database, admin screens, AJAX | header, footer, 404, archive/single/search, branding settings, sample Elementor pages |
| Survives a theme switch | yes: everything above keeps working | no: header/footer branding is lost |
| Must never hold | layout, branding | data rules, queries on member data, security checks |

Authentication, user relationships, chat, notifications and profile data belong in the plugin because they are *the product*, not decoration: a theme is meant to be replaceable and a theme switch must not lose logins or messages, and security rules (ownership checks, nonces, private fields) must live where one audited place enforces them. The theme has **no dependency on plugin PHP classes**; it only reads the plugin's shortcodes and fixed page paths.

---

## 2. File tree

```text
nexora-theme/
├── style.css               theme header (name, version) + pointer comment; real styles are in assets/css
├── functions.php           defines NXT_VERSION / NXT_DIR / NXT_URI, requires the inc/ files
├── header.php  footer.php  site chrome (Elementor Theme Builder templates can replace both)
├── index.php  home.php  front-page.php  page.php  single.php  archive.php  search.php  searchform.php  404.php  comments.php
├── assets/
│   ├── css/style.css       theme styles (nxt-* classes), 1,182 lines
│   ├── css/elementor.css   styles for the Elementor sections (nxe-* classes), 723 lines
│   ├── js/main.js          mobile navigation toggle (26 lines)
│   └── images/sample.webp  placeholder image used by the sample-content installer
└── inc/
    ├── theme-setup.php     supports, menus, assets, widgets, fallback menus
    ├── helpers.php         escaping-safe output helpers and URL helpers
    ├── settings-page.php   Appearance → Nexora Settings
    └── elementor/
        ├── support.php     Elementor locations, CSS loading, small shortcodes
        ├── builder.php     NXT_EL: builds Elementor element arrays
        ├── templates.php   sample layouts (header, footer, 404, pages)
        └── installer.php   Appearance → Nexora Sample Content
```

---

## 3. What each file does

| File | Responsibility | Key functions / hooks |
|---|---|---|
| `functions.php` | bootstrap: constants `NXT_VERSION` (`1.0.0`, **bump to bust theme CSS/JS caches**), `NXT_DIR`, `NXT_URI`; requires the 7 `inc/` files | |
| `inc/theme-setup.php` | `after_setup_theme`: `title-tag`, `post-thumbnails`, HTML5, custom logo, feed links, responsive embeds, menus `primary` and `footer`, image size `nxt-card` (640×420); `wp_enqueue_scripts`: font `nxt-fonts` (Plus Jakarta Sans), `nxt-style`, `nxt-main` (+ `comment-reply`); resource hints; widget area; `init`: trims head clutter; excerpt length/more; fallback menus `nxt_default_primary_menu`, `nxt_default_footer_menu` | `nxt_setup`, `nxt_enqueue_assets`, `nxt_resource_hints`, `nxt_widgets_init`, `nxt_trim_head` |
| `inc/helpers.php` | output helpers (each escapes its own output) | `nxt_setting($key,$default)` reads option `nxt_settings`; `nxt_profile_url()`; `nxt_current_avatar()`; `nxt_button()`; `nxt_badge()`; `nxt_cta_banner()`; `nxt_site_cta()`; `nxt_page_header()`; **`nxt_is_app_page()`** |
| `inc/settings-page.php` | Appearance → Nexora Settings; option `nxt_settings` with fields `tagline email phone address cta_heading cta_button_label facebook twitter linkedin instagram` | `nxt_settings_fields`, `nxt_sanitize_settings`, `nxt_register_settings`, `nxt_add_settings_page`, `nxt_render_settings_page` |
| `header.php` | uses Elementor's `header` location if a Theme Builder header exists, otherwise the built-in header: logo, `primary` menu, and an account area that differs for logged-in visitors (avatar + name linking to the profile, "Log out" via `wp_logout_url`) and guests ("Log in", "Sign up") | |
| `footer.php` | closes `<main>`; Elementor `footer` location or the built-in footer plus `nxt_site_cta()` | |
| `front-page.php` | page shown as the site's front page: if the page has no content shows a built-in hero (buttons depend on login state), else renders content (Nexora app pages in a full-width `nxt-app-page` wrapper) | |
| `page.php` | **app pages** (those containing a Nexora shortcode) render full width without the title band; other pages get `nxt_page_header()` + a prose container | |
| `index.php`, `home.php`, `archive.php`, `search.php`, `searchform.php`, `single.php`, `comments.php`, `404.php` | standard template-hierarchy templates using the `nxt-*` classes. `404.php` offers "Log in" and links home | |
| `assets/js/main.js` | toggles `.nxt-nav__toggle` / `#nxt-primary-nav` (`is-open`, `aria-expanded`), closes on Escape. No AJAX | |
| `assets/css/*.css` | `style.css` for `nxt-*` chrome; `elementor.css` for `nxe-*` Elementor sections | |

Responsive structure: one stylesheet with `nxt-container` / grid classes and media queries; the mobile menu is the only JS. The theme does no AJAX of its own and runs no custom queries on member data.

---

## 4. Elementor integration

The theme is built to work with Elementor Pro's Theme Builder but does not require it.

| File | Detail |
|---|---|
| `inc/elementor/support.php` | registers Elementor theme locations (header, footer, 404) on `elementor/theme/register_locations`; enqueues `elementor.css` on the site (`wp_enqueue_scripts`, priority 20) and in the editor/preview; defines the shortcodes below |
| `inc/elementor/builder.php` | class `NXT_EL` with static helpers `container, widget, heading, text, button, image, shortcode, html, nav_menu, wrap, section` that return the same array structure Elementor stores in `_elementor_data` |
| `inc/elementor/templates.php` | functions `nxt_tpl_*` that assemble the header, footer, 404 and the pages; `nxt_sample_registry()` lists the pages `home about contact faqs privacy-policy terms-of-use community-guidelines` and the theme templates `header footer error-404` |
| `inc/elementor/installer.php` | Appearance → Nexora Sample Content: `admin_post_nxt_install_sample` runs `nxt_install_sample_content()`. It is idempotent: creates the pages (using Elementor's document API), the Theme Builder templates, the menus `nexora-main`, `nexora-footer-company`, `nexora-footer-legal` (bound to the Nav Menu widgets), and **the three Nexora plugin pages** `login-page` (`[profile_login]`), `registration-page` (`[profile_registration]`) and `profile-page` (`[profile_dashboard]`) |

Theme-defined shortcodes (the sample templates use them for dynamic values):

| Shortcode | Output |
|---|---|
| `[nxt_setting key="email" default="" link="mailto\|tel"]` | a value from `nxt_settings`, optionally as a link |
| `[nxt_logo]` | custom logo or a letter mark with the site name |
| `[nxt_year]` | current year |

If Elementor is not active, the installer reports "Elementor is not active. Nothing was created." and the theme's built-in header/footer/templates are used.

---

## 5. Where plugin and theme meet

Every integration point, found by searching the theme for plugin references:

| # | Boundary | Provider | Consumer | Data crossing | Security | If the theme changes |
|---|---|---|---|---|---|---|
| 1 | Shortcodes `[profile_login]`, `[profile_registration]`, `[profile_dashboard]`, `[nexora_home]` placed in page content | plugin (`Auth`, `Profile\Page`, `Shortcodes\Home`) | WordPress `the_content()` in any theme | rendered HTML | each shortcode checks login/ownership itself | still works; the plugin loads its own CSS |
| 2 | Shortcodes `[nexora_stat type=…]`, `[nexora_auth_buttons]`, `[nexora_contact_form]` used inside the sample Elementor layouts | plugin (`Shortcodes\Stats`, `Contact_Form`) | `inc/elementor/templates.php` | counts, buttons, a form | form has nonce, honeypot, limiter | works anywhere the shortcode is placed |
| 3 | Fixed page paths `/login-page/`, `/registration-page/`, `/profile-page/<login>` | plugin (`Core\Urls`, rewrite rule) | theme `header.php`, `front-page.php`, `404.php`, `inc/helpers.php` (`nxt_profile_url`), `inc/theme-setup.php` | URLs only | none needed | the theme's own links stop making sense; the plugin pages still exist |
| 4 | `nxt_is_app_page()` checks the page content for the shortcode tags `profile_login profile_registration profile_dashboard nexora_home` | theme | theme `page.php`, `front-page.php` | boolean | n/a | only affects layout |
| 5 | Logout link `wp_logout_url(home_url('/login-page/'))` | WordPress | theme header | URL with WordPress's logout nonce | nonce by WordPress | n/a |
| 6 | `get_template() !== 'nexora-theme'` in `Core\Assets::enqueue_tokens` | theme slug | plugin | decides whether the plugin loads the Plus Jakarta Sans font itself | n/a | the plugin loads the font when the theme is another one |
| 7 | Sample installer creates the plugin's three required pages | theme | plugin needs those slugs | page slugs + shortcodes | admin-post, admin only | after a switch the pages already exist |
| 8 | Elementor pages may contain plugin shortcodes inside `_elementor_data` | Elementor | plugin | `Assets::is_page_for()` also searches the `_elementor_data` meta for `[shortcode` so assets load on Elementor-built pages | n/a | n/a |

The browser path: `Plugin shortcode → HTML in the theme's page → plugin JS (enqueued by the plugin, only on those pages) → admin-ajax.php → plugin handler`. The theme's own JS (`main.js`) is not part of that path.

Style prefixes: plugin `nx-*`, theme chrome `nxt-*`, Elementor sections `nxe-*`.

---

## 6. If the theme is switched

* Works: registration, login, OTP reset, profiles, connections, chat popup, notifications, home stats, contact form, admin screens, uploads, access control, everything in AJAX.
* Changes: header, footer, 404 and archive layouts; the plugin pages render inside the new theme's `the_content()` area; navigation links to `/login-page/` etc. come from that theme's menu.
* Lost: `nxt_settings` branding values and the `[nxt_*]` shortcodes (shortcode tags would print as plain text in saved Elementor content).
* Required in any theme: a page with slug `login-page`, `registration-page`, `profile-page` containing the matching shortcode, and the permalink structure that allows the `profile-page/<username>` rewrite (re-save *Settings → Permalinks* after switching).
