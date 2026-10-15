# Nexora Marketplace: SPEC (Phase 0 draft)

Status: **All Phase 0 decisions resolved (2026-10-14). Awaiting your review before Phase 1.** No feature code exists yet.

## 0. Decisions log

Vendor sees everything a customer sees plus Marketplace and vendor tabs. Customer/Seller at sign-up plus "Become a seller". Manual approval. Approved vendors publish directly. Existing uploader for images. Fixed category list in a setting. Global 10% commission; store-wide shipping/tax; one shipping charge per order. Vendors may buy but not their own products. COD + BACS. Refunded and cancelled orders excluded and stock restored. GSTIN mandatory, format-checked, unique. No GST certificate for now. Business type from a fixed list. Business email may differ from the account email. DummyJSON API. CSV spec as below. Dry run first, upsert by sku, skip and report invalid rows. Docs are markdown in `docs/`. One branch per phase (`feature/vendor-phase-N`); no commits until you say go. A Nexora "customer" is any member who is not a vendor and is never a check on the WordPress role (Woo's `customer`/`shop_manager` roles are ignored).

Terminology: "account type" means customer or vendor. Never call it "role"; `Profile\Privacy::role()` (guest/viewer/owner) is a different concept.

## 1. Environment (verified 2026-10-14)

WordPress 7.1, PHP 8.3.30, WooCommerce 11.2.1, HPOS on (sync off), default role `subscriber`, currency INR, tax off, guest checkout off, gateways COD + BACS, one India zone (₹50 flat, free above ₹1000). Pages Shop/Cart/Checkout/My account exist.

## 2. Account types

| | Customer | Vendor (pending) | Vendor (approved) | Vendor (suspended) | Admin |
|---|---|---|---|---|---|
| How obtained | sign-up | apply (sign-up "Seller" or "Become a seller") | admin approves | admin suspends | existing |
| Existing Nexora features | yes | yes | yes | yes | yes |
| Marketplace browse and buy | yes | yes | yes (not own items) | yes (not own items) | n/a |
| List products, import | no | no | yes | no | n/a |
| Analytics and earnings | no | no | yes | read-only history | all |
| Products visible in marketplace | n/a | n/a | yes | no | n/a |

- **Vendor = customer + extra** (additive). A user keeps their existing WP role and gains `nexora_vendor` through `add_role()`. No migration for existing users.
- Role is held only while approved (added on approval, removed on suspension or rejection) so the caps gate themselves; `can_sell()` still requires both status and role. Suspended vendors read earnings through `can_view_earnings()`. Uninstall (when "delete data" is on) removes the role, status meta and version option.
- Status lives in user meta `_nexora_vendor_status`: `none | pending | approved | suspended`. Allowed transitions: none→pending, pending→approved|rejected(none), approved→suspended, suspended→approved. Reject is for pending applications only: a suspended vendor can only be reactivated (keeps the GSTIN locked, so no re-applying, and keeps read-only earnings).
- The client sends only an intent (`customer` | `seller`). The server decides; any `role` parameter is ignored.
- `Accounts\Account_Types`: `is_vendor()`, `vendor_status()`, `can_sell()`, `tabs_for()`.

### Tab-visibility map (single source: `Account_Types::tabs_for()`)

| Tab | Customer | Vendor pending | Vendor approved | Vendor suspended |
|---|---|---|---|---|
| User Information | ✔ | ✔ | ✔ | ✔ |
| Connections | ✔ | ✔ | ✔ | ✔ |
| Content (owner) | ✔ | ✔ | ✔ | ✔ |
| Notifications (owner) | ✔ | ✔ | ✔ | ✔ |
| Marketplace | ✔ | ✔ | ✔ | ✔ |
| My Products | | | ✔ | |
| Sales & Earnings | | | ✔ | ✔ read-only |
| My Orders (Nexora tab) | ✔ | ✔ | ✔ | ✔ |
| Vendor application / status | "Become a seller" | status notice | | status notice |

Hiding a tab is never the security; every endpoint re-checks on the server.

## 3. Data model

- **`nexora_vendor` CPT** (private, under "Nexora System", same pattern as `PostTypes\Registrar`). Linked by `_wp_user_id` post meta and `_vendor_id` user meta. Fields: business name, business type, GSTIN, business email, business phone, address, city, state, pincode, status. All business data is **owner-only**; it is added to `Fields::owner_only()`/`Privacy` and never sent for non-owners.
  - GSTIN: regex `^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$`, uppercase-normalised, unique per vendor. Mandatory. No live lookup. No GST certificate upload for now.
- **Platform vendor**: a built-in vendor that owns API-imported items. It cannot be applied for or suspended from the front end.
- **`nexora_product` CPT**: meta `price`, `stock`, `sku`, `vendor_id`, `source` (manual|csv|api), `external_id`, `image_id`, `category`. SKU unique per vendor (the upsert key for CSV and API is always **vendor + sku**, never sku alone). Bounds: price 0.01 to 1,000,000; stock 0 to 100,000; title up to 120 characters; description up to 2,000 characters, plain text.
- **Import log**: stored as a custom post type or table, decided in Phase 5. A table would bump `Migrations::DB_VERSION`.
- **Order-item snapshot** (Woo order item meta, CRUD API only): `_nx_product_id`, `_nx_vendor_id`, `_nx_source`, `_nx_name`, `_nx_unit_price`, `_nx_qty`, `_nx_sku`, `_nx_commission_rate`, `_nx_commission_amount`, `_nx_stock_reduced` (idempotency flag).
- **Settings** (Nexora System > Settings): commission % (default 10), category list, import limits.

### 3b. Vendor application (built in Phase 2)

- **Classes** (`src/Vendors/`): `Fields` (nine business fields, fixed business types, labels), `Validator` (pure), `Repository` (the `nexora_vendor` posts), `Service` (apply / update / admin decisions; the only caller of `Account_Types::set_status()`), `Notifier` (mails), `Ajax` (`nexora_vendor_apply`, `nexora_vendor_update`), `Admin` (list columns, details box, decision links), `Assets` (script and style only on the registration page and a logged-in member's profile page).
- **Application**: always creates `pending`. Sign-up "Seller" validates the business details and the GSTIN BEFORE creating the account; if saving the application fails afterwards the new user and profile are deleted (rollback).
- **Duplicate GSTIN**: unique across live applications (trashed ones do not count); a short lock (`add_option`) closes the race between two simultaneous applications. The error never says who has it.
- **Rate limit** `vendor_apply`: 3 well-formed attempts a day per member (only attempts that reach the GSTIN lookup count, so typos are free but enumeration is not).
- **Reject**: pending to none; the application moves to the trash (kept for the record), the link is removed and the GSTIN is free; the member may apply again.
- **Locked after a decision**: business name, type and GSTIN; contact and address fields stay editable. A pending applicant may correct everything.
- **Deleting a member** deletes their application (`delete_with_user`) and frees the GSTIN.
- **Admin UI**: an "Application decision" box in the side column (administrators only): status, applicant, business and the buttons valid now (pending: Approve primary + Reject; approved: Suspend; suspended: Reactivate only). Reject asks for confirmation. The main-column details box is read-only. `Vendors\Guard` removes the Publish box, the bulk actions and the Trash/Restore/Quick Edit row links, withdraws the delete capability and the Add New capability, and refuses programmatic trash / delete / restore / status or title edits that do not come from `Repository` (deleting the member is still allowed): no trash path bypasses `Service`.
- **Profile UI**: Business Details is a read-only card with a Business sub-tab (between Documents and Security) once an application exists; Edit / Become a seller open a SweetAlert2 popup built from a server-rendered `<template>` (owner only). Locked fields are shown disabled with an explanation. No inline forms.
- **Admin**: decisions are `admin-post` links with a per-vendor, per-action nonce, `manage_options`, and a second `manage_options` check inside `Service::change_status()`. Idempotent. The edit form cannot change a status. List columns for products and earnings arrive with Phases 4 and 7.
- **Privacy**: business data is rendered on the server for the owner only; it is not in `Privacy::script_data()`, in any AJAX reply for others, or in another member's profile page (tested against every member AJAX action).

## 4. WooCommerce bridge

**Decision: the hidden "Marketplace Item" is a PHYSICAL product (`virtual = no`).**
Virtual products make Woo skip the shipping address and shipping rates, so physical goods would ship free. So it is non-virtual and its shipping is calculated per order using the existing zone: ₹50 flat, free above ₹1000.

Product configuration:
- Hidden catalog visibility, tax status none, manage-stock off (Nexora manages stock itself), sold-individually off, price 0, kept purchasable through a `woocommerce_is_purchasable` filter for this product only.
- ID kept in option `nexora_marketplace_item_id`. It is created idempotently, re-created if it is trashed or missing, and excluded from the Shop page and from search.
- No weight or shipping class, so flat rate and free shipping apply. The free-shipping threshold uses the cart subtotal after the server-side price override.
- A cart line is separated by item data `nexora_product_id`, so two different marketplace products never merge into one line.

Rules:
- Price is re-read from the DB in `woocommerce_before_calculate_totals`; the client price is ignored.
- Validate at add-to-cart **and** at checkout: product published, vendor approved, stock sufficient, user logged in, not the buyer's own product.
- Guests are sent to the Nexora login with a return URL. Woo-side holes are closed per section 8.
- Orders: snapshot on creation. Stock is decremented once on processing/completed (guarded by `_nx_stock_reduced`) and restored on cancelled/refunded.
- HPOS: declare compatibility via `FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`. Use `wc_get_orders` and CRUD only. Test with HPOS on and off.
- Shipping is one charge per order, not per vendor (out of scope: per-vendor shipping, payouts).

## 5. AJAX actions (all via `Http\Ajax::register`, nonce `profile_nonce`, handlers extend `Member_Ajax`)

| Action | Who | Validation | Rate-limit bucket |
|---|---|---|---|
| `vendor_apply` | logged-in customer without a vendor profile | business fields + GSTIN, unique | `vendor_apply` 3/day |
| `vendor_update` | pending/approved/suspended owner | same validator, own record | `product_save` |
| `product_save` | approved vendor | shared validator, own product only | `product_save` 60/h |
| `product_delete` | approved vendor | owns the product | `product_save` |
| `product_list_mine` | approved vendor | own products only | none |
| `marketplace_list` | logged-in member | search/category/page, whitelisted | none (cached) |
| `csv_preview` and `csv_import` | approved vendor | own handler: nonce, cap, extension + MIME, size/rows, UTF-8, formula neutralising | `csv_import` 10/h |
| `api_import` | approved vendor | server-side fetch, response validation, upsert by `external_id` | `api_import` 10/h |
| `add_to_cart` | logged-in member | published product, approved vendor, stock, not own | `add_to_cart` 60/10 min |
| `sales_report` | approved/suspended vendor | own data only, date range | none |

Every vendor action rejects with a JSON error for customers, pending/suspended vendors (where it writes), and other vendors' objects. Buckets are added through the `nexora_rate_limits` filter. New JS lives in its own file. Templates live under `templates/marketplace/` and `templates/vendor/`; new URLs go in `Core\Urls`.

## 6. Imports

- **CSV**: columns `sku,name,description,price,stock,category,image_url`; the upsert key is **vendor + sku** (vendor B uploading a sku that vendor A has creates B's own product and never touches A's); at most 2 MB and 500 rows; UTF-8; `.csv` only. `restrict_member_mimes` is NOT loosened: the handler reads the upload through `$_FILES` with its own checks and never stores it in the Media Library. A dry-run preview comes first and writes nothing. Row-level errors carry line numbers. Cells starting with `= + - @` are neutralised. A re-import is idempotent.
- **API**: server-side `wp_remote_get`, 10 s timeout, response schema validation, mapping layer, transient cache, upsert by `external_id`, partial-failure report. Credentials never reach the browser. Source is DummyJSON; mapping in 8b.
- One shared validator class is used by the manual, CSV and API paths.

### 6a. Phase 5 (built): imports

- **Who and whose products**: approved vendors only (`Vendor_Ajax::seller()`), and an import creates and updates **the importing vendor's own products** (upsert key: vendor + SKU for CSV, vendor + external id for the API). **Deviation from 8b, to confirm:** 8b said API items belong to a built-in "Platform" vendor. A vendor-triggered API import into a shared Platform inventory made little sense (every vendor could add to it, none would own the result) and conflicts with "vendor + key, never the key alone", so API items belong to the vendor who imports them (source `api`, `external_id` = the DummyJSON id). A Platform-owned catalog can be added later as an administrator-only import.
- **Two steps**: `csv_preview` / `api_preview` is a dry run (writes no product and no history; returns a report and a one-time 32-hex token valid 15 minutes, bound to the member and the source and not consumed by a refused use); `csv_import` / `api_import` applies exactly that previewed plan, after re-checking that the member may still sell. Rows are applied independently: invalid rows are skipped and reported, valid rows import.
- **CSV** (`Imports\Csv_Parser`): `.csv` extension, at most 2 MB and 500 rows, readable text (no NUL bytes, MIME from the content), UTF-8 (BOM allowed), header with `sku,name,price,stock,category` required and `description,image_url` optional (no other columns, no repeats). Line numbers are physical file lines (multi-line cells and blank lines counted). Cells starting with `= + - @` tab or CR get a leading apostrophe. The file is read from PHP's upload and never stored; the Media Library upload policy (`restrict_member_mimes`) is untouched and still refuses CSV. Categories may be given as slug or label; `image_url` must be an image in the vendor's own Media Library.
- **API** (`Imports\Api_Source`): fixed URL `https://dummyjson.com/products` (a filter exists for tests; the host must be on the allow-list, https only), `limit=100` pages up to 500 items, 10 s timeout, 2 redirects, `reject_unsafe_urls`, 2 MB response cap, schema checks, 10-minute cache of good pages, no credentials. Mapping as in 8b. A failure on a later page keeps the earlier pages and reports the failure; the upstream body and URL are never echoed. Images stay on `cdn.dummyjson.com` (`image_url` meta, https and host checked again when shown).
- **Upsert rules** (`Products\Service::import_row`): same validator and checks as the manual form; an unchanged row is not touched (so a re-import changes nothing); a blank image never removes an existing one; an imported item whose SKU belongs to a different product of the same vendor is an error row, never a takeover; the per-vendor limit counts products planned in the same run.
- **History**: private `nexora_import_log` posts (guarded like the others), one per real import: source, file name, created/updated/unchanged/skipped, up to 50 row errors with line numbers; the vendor sees their last 10 on My Products; administrators see all under Nexora System. Rate limits: `csv_import` and `api_import` 10 previews per hour per vendor.
- **Fix found on the way (Phase 4 bug)**: WordPress's kses filters rewrote product text into entities (`&` became `&amp;`, `<3` became `&lt;3`, `'` became `&#039;`) for every vendor save, so the edit popup showed encoded text and a re-import could never be "unchanged". The Repository's writes now run with those filters off (the text is already plain text and always escaped on output). Products saved earlier with `&`, `<`, `>` or `'` are still stored encoded and will read normally once edited and saved again.

## 7. Analytics

Aggregated from order-item snapshots. Only processing and completed orders count; refunds and cancels are excluded. Reported: total sales, commission, net, order count, unique buyers, top products, per-order list (buyer name, date, qty, amount only). Date filter. A vendor can only ever query their own `vendor_id`. Commission is rounded half-up to 2 decimals per line.

## 8. Woo route closing (finding F2)

Resolved decisions: Woo checkout account creation and checkout login are disabled (you are also unticking them in Accounts & Privacy; Nexora will force them off with `option_` filters so a settings change cannot reopen them). The extra Woo roles are ignored. Jetpack, Google Listings & Ads, Reddit and Snapchat are deactivated, not deleted.

| Route | Plan | Phase |
|---|---|---|
| `/my-account/` (and endpoints) for a guest | Redirect to the Nexora login with a return URL | 3 |
| `/my-account/` for a logged-in member | Redirect to their Nexora profile | 3 |
| Woo checkout signup/login options | Forced off by filter + test that asserts it | 3 |
| Guest `?add-to-cart=`, `wc-ajax=add_to_cart`, Store API `cart/add-item` | Guests rejected; members only through the validated Nexora bridge | 6 |
| Direct add of the Marketplace Item with a client price/ID | Rejected unless `nexora_product_id` passes validation | 6 |
| Guest `/checkout/` and Store API checkout | Redirect or 401 to the Nexora login | 6 |
| Woo lost-password and reset-password endpoints | Redirect to the Nexora reset flow | 6 |
| Woo registration hooks (`woocommerce_created_customer`) | Assert no user can be created through Woo | 6 |
| Members viewing their orders (`view-order`, `orders`) | Decided: a Nexora "My Orders" tab on the profile; Woo's endpoints redirect there | 6 |
| Shop/product pages, Woo REST `wc/v3` | Out of scope (not a purchase path; `wc/v3` is admin-auth only). Re-checked in Phase 8. | 8 |

### 8a. Phase 3 (built)

- **Tabs**: `Profile\Page::BUILT_TABS` (user-info, connections, content, notifications) intersected with `Account_Types::tabs_for()` for the owner; others see the two public tabs; the `nexora_profile_tabs` filter can adjust. Tabs in the map that are not built yet render nothing, so a customer's page is byte-identical. Each later phase adds its slug to `BUILT_TABS`.
- **Return URL after login**: `Urls::safe_return()` accepts only this site, http(s), no userinfo/backslashes/control characters, and a first path segment in `profile-page, cart, checkout, shop, marketplace` (filter `nexora_return_url_paths`); the login form passes `redirect_to`; everyone else lands on their own profile (admins on the bare profile page), whatever their vendor status.
- **Gating**: `Vendors\Vendor_Ajax` (`seller()`, `earnings_viewer()`, `require_owner()`): forbidden JSON error for guests, customers, pending, suspended (selling), admins, a role without the status or the status without the role, and other vendors' objects. Phase 4+ endpoints extend it.
- **Woo**: `Integrations\Woo_Routes` forces `woocommerce_enable_signup_and_login_from_checkout`, `..._checkout_login_reminder` and `..._myaccount_registration` to 'no' (via `pre_option_`), and sends `/my-account/` (all endpoints) to the Nexora login (guests) or the member's profile (admins left alone). Cart and checkout routes follow in Phase 6. It also tells WooCommerce's admin lock-down (`woocommerce_prevent_admin_access`) to leave AJAX requests alone: Woo exempts only `admin-ajax.php` and `admin-post.php` by file name, so the media modal's upload endpoint `async-upload.php` redirected every member to /my-account/ and uploads (profile images, documents, product images) failed with "An error occurred in the upload". Real admin pages are still closed to members (Woo's rule plus Nexora's `Access_Control`).

### 8a2. Phase 4 (built): products and the marketplace

- **`nexora_product`**: private post type under Nexora System, read-only in wp-admin. Author = the vendor's user; meta `vendor_id` (the vendor's application post), `price`, `stock`, `sku` (upper-cased, unique per vendor), `category`, `image_id`, `source`, `external_id`; the description is the post content. Approved vendors publish directly; delete is permanent (past orders will keep their own snapshot).
- **One validator** (`Products\Validator`) for manual, CSV and API: name 2-120, description up to 2,000 (plain text), price 0.01-1,000,000 (two decimals), stock 0-100,000 (whole), SKU 2-40 (`A-Z 0-9 . _ -`), category from the list, image optional.
- **Service rules** (`Products\Service`): approved vendor only (status AND role); the vendor and owner come from the session (a posted `vendor_id`, `user_id`, `source`, `external_id` or status is ignored); editing and deleting require both the author and the `vendor_id` to be the caller's, and every refusal is the same "forbidden"; images must be the caller's own uploads (the existing uploader); at most 500 products per vendor (`nexora_max_products`); a lock closes the duplicate-SKU race. A suspended vendor cannot edit or delete their products.
- **Guard**: `Core\Post_Guard` (generic, replaces `Vendors\Guard`) is applied to both post types: no Publish box, no bulk or row Trash/Restore, no create capability, and `wp_insert_post` / `wp_trash_post` / `wp_delete_post` / `wp_untrash_post` / status, title or content edits are refused unless the Repository (flagged with `Internal_Writes`) does them; deleting the member deletes their products.
- **Listing** (`Products\Listing`): published products whose vendor is approved (one join over the status meta, so suspending a vendor hides their products at once), newest first; search (name and description), category filter (unknown category = no filter), 12 per page (max 48), a page past the end shows the last. Endpoint `nexora_marketplace_list` (any logged-in member, rate limit `marketplace_list` 120/min) returns server-escaped HTML; the query count does not grow with the page size (caches primed).
- **Cards** show name, short description, price, stock label, category, image and the seller's **business name** (public by nature); never the GSTIN, email, phone or address. A vendor's own product is marked "Your product" (buying is Phase 6; vendors cannot buy their own).
- **UI**: Marketplace tab for every member on their own profile (products load when the tab opens), My Products tab for approved vendors only (read-only cards; Add/Edit in a SweetAlert2 popup built from a server-rendered `<template>`; Delete asks first). New files `marketplace.js`, `products.js`, `marketplace.css`.
- **Settings**: "Product categories" (one per line, "Other" always kept); empty = the nine defaults.
- **Rate limits**: `product_save` 60/hour per vendor (saves and deletes), `marketplace_list` 120/minute per member.

## 8b. DummyJSON field mapping (for your review)

Source: `GET https://dummyjson.com/products?limit=100&skip=N` (paged up to the row limit), response `{products:[...], total, skip, limit}`. No auth.

| DummyJSON | Nexora | Rule |
|---|---|---|
| `id` | `external_id` | cast to string; upsert key (with `source=api`, vendor = Platform) |
| `title` | title | text-sanitised, cut to 120 |
| `description` | description | plain text, cut to 2,000 |
| `price` | price | numeric, must be within 0.01 to 1,000,000. DummyJSON prices are USD; **imported as-is as INR, no conversion**: a learning stand-in |
| `stock` | stock | int, 0 to 100,000 |
| `sku` | sku | required; missing gets `DJ-{id}` |
| `category` (slug) | category | mapped to the fixed list, unmatched gives "Other" |
| `thumbnail` | `image_url` | stored as an external URL, https only, host must be `cdn.dummyjson.com`; **not sideloaded** into the Media Library |
| everything else | ignored | |

A product with an invalid field is skipped and reported with its `id`; the rest still import.

## 8c. Fixed lists (stored as settings, editable by admin)

- **Categories**: Electronics, Fashion, Home & Kitchen, Beauty, Groceries, Books, Toys, Sports, Other.
- **Business types**: Sole proprietorship, Partnership, LLP, Private limited, Other.

## 8d. Known issues (existing behaviour, deliberately not changed)

- **`user_profile` posts are orphaned when a user is deleted.** The `user_profile` post type does not support `author`, so `wp_delete_user()` leaves the profile post behind (and its `_wp_user_id` points at a user that no longer exists). This predates the marketplace. The vendor post type does not have the problem (`delete_with_user` is set and tested). It is why the seller sign-up rollback deletes the profile post explicitly before deleting the user. Fixing it for `user_profile` (and later `nexora_product`) is a separate decision: it changes what an admin deleting a user destroys.

## 9. Out of scope

Payouts, per-vendor shipping and tax, live GST lookup, multi-currency, product variations, reviews, returns workflow, vendor-to-buyer messaging beyond the existing chat.

## 10. Phase acceptance (summary; details in TEST_PLAN.md)

1. Existing customer markup and AJAX byte-identical. 2. Application works with rollback, role tampering ignored. 3. Gating and tabs per map. 4. Own-only product CRUD, marketplace shows only published + approved. 5. Imports pass the bad-input matrix. 6. Price from DB, snapshot complete, stock once. 7. Vendor sees only own earnings. 8. End-to-end test, security checklist, docs, `.pot`, versions.
