# Nexora Marketplace: SPEC

Status: the Phase 0 decisions (2026-10-14) are below. Phases 1 to 6 are built; sections 8a to 8a4 describe what is built. Sections 11 and 12 (Phases 7a and 7b) are specified, not built.

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
- Suspension keeps the role: the status, not the role, controls what a seller may do (section 11). `can_sell()` is true only for an approved, not suspended seller. Suspended vendors read earnings through `can_view_earnings()`. Uninstall (when "delete data" is on) removes the role, status meta and version option.
- Status lives in user meta `_nexora_vendor_status`: `none | pending | approved | suspended`. Allowed transitions: none→pending, pending→approved|rejected, approved→suspended, suspended→approved. Reject is for pending applications only: a suspended vendor can only be reactivated (keeps the GSTIN locked and read-only earnings). Today a rejected application returns to `none`; Phase 7a stores `rejected` and lets the person apply again (section 11).
- The client sends only an intent (`customer` | `seller`). The server decides; any `role` parameter is ignored.
- `Accounts\Account_Types`: `vendor_status()`, `can_sell()`, `tabs_for()`. Phase 7a adds `has_seller_record()` (any status) next to `can_sell()` (approved and not suspended) and replaces `is_vendor()` (section 11).

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
- **Order-item snapshot** (Woo order item meta, CRUD API only): `_nx_product_id`, `_nx_name`, `_nx_sku`, `_nx_price`, `_nx_qty`, `_nx_vendor_id`, `_nx_vendor_name`, `_nx_rate`, `_nx_gross`, `_nx_commission`, `_nx_earning`, and the counters `_nx_stock_taken`, `_nx_stock_back`, `_nx_refunded`, `_nx_refunded_qty`, `_nx_earning_reversed`, `_nx_commission_reversed`; the order carries `_nx_order`, `_nx_placed` and one `_nx_vendor` row per vendor. `Orders\Lines` is the only place that knows these keys (section 8a4).
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

### 6b. Product API sources and the Nexora format (built)

**Sources** (Nexora System > Settings > "Product API sources"). Each source has a name, an https address, an optional authentication (none, header key, or bearer token), an enabled flag and a format: **Nexora format**, the **DummyJSON adapter** or the **Fake Store adapter**. The list is seeded with DummyJSON (`https://dummyjson.com/products`, DummyJSON adapter, no auth, enabled) and a **disabled** Fake Store source (`https://fakestoreapi.com/products`, Fake Store adapter); nothing is hard-coded in `Imports\Api_Source` any more. Keys are encrypted with the site salts (`Core\Secrets`, libsodium secretbox), are never shown back (the field is always empty; a blank field keeps the saved key; "Remove the saved key" clears it) and never appear in logs, history, errors, script data or the database in clear. Two more settings: a **master switch** "Let vendors import from their own API" (off by default) and an optional **host allow-list** for vendors' own APIs (one host per line; a host matches itself and its subdomains; empty = any public https host; it does not restrict the sources the administrator configured).

**Vendors** (Import from API popup): pick a platform source, a saved connection, or "My own API" (an address and an optional key, header or bearer). Up to **5 saved connections** per vendor, with edit and delete; "Test my API" reads the first page and explains in plain language, item by item, what would be refused; Preview then Import keep the one-time token and the vendor + external id upsert (a connection's products carry `c-<connection id>:<item id>` so two feeds never collide). A vendor sees and changes only their own connections and can never supply an address for a platform source (the request names a source id; any `url`/key posted with it is ignored). Administrators see all connections under Nexora System > API connections: vendor, address, kind of authentication, last import - never the key.

**Security.** Every outbound request goes through `Imports\Url_Guard`: https only (port 443 only), no credentials in the address, plain ASCII host names with a letters-only ending, no IP literals in any spelling, no names that mean "here" (`localhost`, `.local`, `.internal`, `.lan`, `.home.arpa` ...), the name is resolved and EVERY address (IPv4 and IPv6) must be public (loopback, private, CGNAT, link-local, reserved, documentation, multicast, broadcast, cloud metadata such as 169.254.169.254, 100.100.100.200, 168.63.129.16 and fd00:ec2::254, IPv4-mapped / NAT64 / 6to4 judged by the embedded address), and the request is pinned to the checked address with `CURLOPT_RESOLVE` so a second DNS answer cannot change it. No redirect is followed (a 3xx is an error). Each "next page" address is checked the same way and must be on the same host. Limits: 10 s timeout, 2 MB response, 500 items, 10 pages. Rate limits per vendor and hour: `api_test` 20, `api_import` (preview) 10, `api_apply` (import) 30, `api_connection` (save/delete) 30. Vendor-facing errors are generic: one message for every refused address ("This address cannot be used...") that never says why, and no upstream body, address or lookup detail.

**The Nexora format.** A vendor's API answers a JSON object with a `"products"` list and, when there are more pages, `next`: the full https address of the next page on the same host.

```json
{
  "products": [
    { "id": "KURTA-001", "name": "Cotton Kurta", "price": 1299.5, "stock": 25, "category": "fashion",
      "sku": "KURTA-001", "description": "Soft cotton, hand block printed.", "image": "https://shop.example.com/images/kurta-001.jpg" },
    { "id": "MUG-7", "name": "Ceramic Mug", "price": 249, "stock": 120, "category": "Home & Kitchen" }
  ],
  "next": "https://shop.example.com/api/products?page=2"
}
```

| Field | Required | Type | Rule |
|---|---|---|---|
| `id` | yes | text or whole number | your identifier: 1-64 of letters, numbers, `. _ : -`; importing again updates the product with the same id |
| `name` | yes | text | 2-120 characters |
| `price` | yes | number | 0.01 to 1,000,000, at most two decimals |
| `stock` | yes | whole number | 0 to 100,000 |
| `category` | yes | text | a marketplace category, by name or slug |
| `sku` | no | text | 2-40 of letters, numbers, `. _ -`; defaults to the `id` |
| `description` | no | text | plain text, up to 2,000 characters |
| `image` | no | https address | on the API's own host or a subdomain; stays on that server; anything else is ignored (reported as a note by "Test my API") |

Every item goes through the same shared product validator as the manual form and the CSV import; an item without a usable `id` is reported, any other problem is reported on its field in plain words and that item is skipped while the rest import. The DummyJSON adapter maps `title`, `description`, `category` (to the fixed list), `price`, `stock`, `sku` (`DJ-{id}` when missing) and `thumbnail` as in 8b.

### 6c. The Fake Store adapter (built)

A third format for platform sources (`Imports\Api_Source::map_fakestore`). The API (`https://fakestoreapi.com/products`) answers a **bare JSON list** (no `products` wrapper, no `next`), so it is one request; anything that is not a list is a plain fetch error. Each item has `id`, `title`, `price`, `description`, `category`, `image` and `rating`, and **no stock**.

| Fake Store | Nexora | Rule |
|---|---|---|
| `id` | external id `<source key>:<id>`, sku `FS-<id>` | prefixed so it can never meet a DummyJSON id; upsert by vendor + external id, so the same id updates |
| `title` | name | judged by the shared validator (a missing or blank title fails that item: "This field is required.") |
| `price`, `description` | price, description | as given; description cut to 2,000 |
| `category` | category | existing slug or label is used; otherwise see below |
| `image` | `image_url` | https and on the source's own host, else dropped (the product still imports) |
| `rating` | - | ignored |
| (none) | stock | the source's **Stock for items without stock** (whole number 0-100,000, default 10); a stock the API happens to send is not trusted |

**Two per-source settings**, shown only for this format: *Stock for items without stock* and *Create missing categories* (on by default for platform sources). With it **off**, an item whose category is not in the list fails in the preview: `The category "jewelery" is not in the category list...`. With it **on**, the category is created **only when the import is applied** (the preview writes nothing and counts the item as valid): the label is the API's name in title case, at most 40 characters, the slug is what `sanitize_title` makes of it; at most 10 new categories per import and never past 50 in the list (items beyond that fail with a message). The new names live in the server-held plan, never in the browser, and are appended to the category setting by `Products\Fields::add_categories()`.

**Security is unchanged.** The adapter goes through the same `Api_Source::page()` and `Url_Guard`: https only, address checks after DNS, pinned address, 10 s timeout, 2 MB cap, no redirects, 500 items, generic errors, keys never returned. Nothing new makes a request.

**Vendor-owned API vs platform source:** a vendor's own API is always the Nexora format (the Fake Store format is refused for it) and can never create categories, and it is limited by the host allow-list and not cached, while a platform source is chosen and configured by the administrator, may use either adapter, may create categories if allowed, and its pages are cached for 10 minutes.

### 6d. "How many products" (built)

The "Import from API" popup has a dropdown **How many products**: 20, 40, 100 or **All (up to 500)**, default 20. It applies to platform sources, saved connections and "My own API" alike (all go through `nexora_api_preview`).

- **Server-side allow-list.** `Importer::limit_of()` accepts only the plain digits `20`, `40`, `100` or `500`; anything else (missing, `25`, `-40`, `1e2`, ` 40`, an array, `all`...) reads as 20.
- **Stops early.** `Api_Source::fetch( $source, [ 'valid' => N ] )` counts **valid** items (the entry maps, and the shared product validator accepts it) and requests no further page once N are collected, even in the middle of a page. A count of 20 on a 500-item feed downloads one page. All existing hard limits still apply: 10 s timeout, 2 MB, 500 entries, 10 pages, no redirects, the preview rate limit.
- **Counting.** Invalid items (and entries that are not products) are still returned and shown in the preview as errors, but they do not use up the count: with 5 invalid items at the front and a count of 20 the preview has 20 valid and 5 invalid rows.
- **Preview line.** When the feed has more than was taken: `Showing the first N of M.` (the feed says its total, or the whole feed was read to its end); when the stop came early and the total is unknown: `Showing the first N of at least M.` (M = entries read so far). Nothing is shown when everything was taken. The report carries `feed` (taken, total or null, at_least, more, limit) and `feed_note`.
- **Token.** The one-time token stores the count it was made for. The import request sends the dropdown value again and `Importer::take()` refuses a different count ("The number of products was changed after the preview. Please preview again."); a refusal does not use the token up. A preview of 20 cannot import 100, and a request with no or an invalid count counts as 20. Changing the dropdown in the popup hides Import until a new preview is made.
- **Later larger imports.** Upsert is by vendor + external id as before, so importing 20 and later 100 leaves the first 20 unchanged (or updated if the feed changed) and creates the other 80, with no duplicates.

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
- **Cards** show name, short description, price, stock label, category, image and the seller's **business name** (public by nature); never the GSTIN, email, phone or address. A vendor's own product is marked "Your product" (vendors cannot buy their own).
- **UI**: Marketplace tab for every member on their own profile (products load when the tab opens), My Products tab for approved vendors only (read-only cards; Add/Edit in a SweetAlert2 popup built from a server-rendered `<template>`; Delete asks first). New files `marketplace.js`, `products.js`, `marketplace.css`.
- **Settings**: "Product categories" (one per line, "Other" always kept); empty = the nine defaults.
- **Rate limits**: `product_save` 60/hour per vendor (saves and deletes), `marketplace_list` 120/minute per member.

### 8a3. Phase 6a (built): the cart

A logged-in member (customer or vendor) can put another seller's product in the WooCommerce cart. Orders, stock decrement and commission are Phase 6b.

- **Marketplace Item** (`Marketplace\Marketplace_Item`): one hidden physical Woo product (price 0, tax none, catalog hidden, no stock management), id in option `nexora_marketplace_item_id`. Created on first use under a lock and on activation; a trashed copy is restored, a deleted one recreated; absent from the shop, search, Store API product lists and sitemaps (404 on its own URL); non-admins cannot edit or delete it.
- **One set of rules** (`Marketplace\Cart_Rules`): quantity a whole number 1-99 (including what is already in the cart); the product must be published, its vendor approved, stock enough, and the buyer not the owner. Unpublished / deleted / suspended-vendor products all give the same "not available" message. The product's name, price, image, vendor and stock are always read from the product post.
- **Add** (`Marketplace\Cart_Ajax`, `nexora_add_to_cart`, members only, rate limit 60 per 10 minutes): reads only `product_id` and `quantity`. The cart line is the Marketplace Item with cart-item data `nexora_product_id` and nothing else; only `Cart_Bridge::add()` can add that item (`?add-to-cart=`, `wc-ajax`, the Store API and direct `add_to_cart()` calls are refused).
- **Changes while in the cart** (`Marketplace\Cart_Bridge`): at cart load every line is re-read; new price used, quantity lowered to stock, unavailable lines removed, and the member is told once (in the "Your cart by seller" panel). Checkout (Store API `cart_errors` / `validate_cart_item`, and `woocommerce_check_cart_items`) re-checks fresh without fixing and blocks with a plain message.
- **Several sellers in one cart**: lines are ordered by vendor, each shows "Sold by <business>", and `Marketplace\Cart_Panel` adds a server-rendered grouped panel (with per-seller subtotals) above the Cart block.
- **Route hardening** (`Integrations\Woo_Routes`): guests get 401 (`nexora_sign_in`) on Store API cart / checkout / order / batch routes and on cart-related `wc-ajax` actions; guests on the cart, checkout and order-received pages go to the Nexora login (with a return address for cart and checkout); guest checkout is forced off; WooCommerce's login, registration and password-reset form handlers are removed and its lost-password link is the Nexora login.
- **Card**: "Add to cart" (only the product id in `data-product`), a disabled "Out of stock", "Sign in to buy" for guests, "Your product" for the owner.
- **Known limits**: permanently deleting the Marketplace Item makes WooCommerce drop marketplace lines from carts holding the old id (it is recreated; those carts start empty). External product images (API imports) show in the grouped panel but not as the Cart block thumbnail. Nothing is reserved: two buyers can both add the last unit; 6b re-checks stock when the order is created. **WooCommerce "Coming soon" mode hides the cart, checkout and shop from everyone but administrators; it must be off for customers to buy.**

### 8a4. Phase 6b (built): orders, stock, commission, earnings, My Orders and Sales

**Placing an order** (`Orders\Placement`, on `woocommerce_checkout_order_processed` for the classic checkout and `woocommerce_store_api_checkout_order_processed` for the block checkout, both before any payment). Checkout tags each marketplace line with the product post id (`woocommerce_checkout_create_order_line_item`). Placing then: (1) re-checks every line with the cart's own rules (`Cart_Rules`: published, vendor approved, not your own product, stock) and that the line total still equals the DB price x quantity; (2) takes the stock line by line with one atomic SQL statement (`UPDATE ... WHERE stock >= qty`), giving back what it already took if a later line fails; (3) freezes each line. If anything fails the order is cancelled and checkout shows a plain message (`"<name>" is no longer in stock in that quantity, so the order was not placed...`, or a price-changed / no-longer-available message). A flag on the order (`_nx_placed`) makes a second call do nothing.

**Frozen on each line** (hidden order-item meta, `Orders\Lines`): `_nx_product_id`, `_nx_name`, `_nx_sku`, `_nx_price`, `_nx_qty`, `_nx_vendor_id` (the vendor application post), `_nx_vendor_name`, `_nx_rate`, `_nx_gross`, `_nx_commission`, `_nx_earning`; counters `_nx_stock_taken` / `_nx_stock_back`, `_nx_refunded`, `_nx_refunded_qty`, `_nx_earning_reversed`, `_nx_commission_reversed`. The order carries `_nx_order` = 1, `_nx_placed` and one `_nx_vendor` row per vendor. Editing, repricing or deleting the product later changes nothing in an old order; changing the commission setting changes new orders only.

**Commission** (`Orders\Commission`): setting `nexora_commission_rate` (Nexora System > Settings > Marketplace commission, **10%** until changed, 0 to 100, two decimals, invalid input refused and the old value kept). Per line, in whole cents: gross = unit price x quantity, commission = round half up (gross x rate), earning = gross - commission. The rate is frozen on the line.

**Stock** (`Orders\Stock`, `Orders\Order_Events`, `Orders\Refunds`): taken once at placement. Given back on Cancelled, Failed and Refunded and for refunded units, **once only**: a line records what it took and what it gave back and can only give back what it still holds, so a hook that fires twice, a cancel after a refund, or a Refunded status after a refund changes nothing, and a stock number the vendor raised by hand is never pushed up. An order moved from Cancelled/Failed/Refunded back to Pending/On hold/Processing/Completed takes its stock again; a line that cannot get its units is named in an order note (the status is not blocked).

**Which statuses count as earnings** (`Orders\Earnings`, read from the order's current status every time, never stored as a running total): **only Processing and Completed** count. **On hold and Pending never count** (they are shown separately as "awaiting payment"), and neither do **Cancelled, Failed, Refunded** or drafts. A bank-transfer (BACS) order is On hold until the shop owner marks it paid (Processing); cash on delivery is Processing from the start, so it counts at once. Note: setting an order to Refunded makes WooCommerce itself record a full refund, so the vendor's share stays reversed even if the order is moved back to Processing.

**Refunds** (`Orders\Refunds`, `woocommerce_order_refunded`): the vendor's share of the refunded amount is reversed: for an amount A on a line of gross G and earning E, round(A x E / G); the rest of A is the commission that is reversed. A refund that names a line is applied to it; an amount that names no line is split across the order's marketplace lines by what each still has left to refund; the refund that completes a line reverses exactly what is left (no cent stays behind). A flag on the refund (`_nx_refund_applied`) makes the same refund hook a no-op the second time.

**My Orders** (every member; `nexora_my_orders`): the member's own placed marketplace orders as read-only cards (status in words, payment, total, lines with the frozen name, SKU, price and seller, and their own shipping address). **Sales** (approved vendors, suspended ones read-only; `nexora_sales`, `nexora_sale_order`): the orders that contain the vendor's products, **only the vendor's own lines**, with commission and earning per line, totals (counted, commission, earnings, awaiting payment) and a status note. The vendor is always the logged-in user; a bad, foreign or non-marketplace order id gets the same "forbidden" answer. A vendor sees what shipping needs (recipient name and shipping address) only while the order still ships (Pending, On hold, Processing, Completed), never the billing address, e-mail, phone or account name. Rate limit `orders_view` (120 a minute).

**High-performance order storage:** declared compatible; everything uses the WooCommerce order API.

**Known limits:** coupons are not supported on marketplace lines (a discounted line total no longer equals the price, so placing is refused with the price message); a vendor's totals are read from their orders on each request (fine for thousands of orders, a summary table is the next step if volume grows); orders edited by hand in wp-admin (lines added or removed) are not re-frozen.

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

## 11. Accounts and records (Phase 7a)

Status: **specified, not built.** Recorded from the owner's requirement and answers (2026-10-19).

**Roles.** Three: `subscriber` (customer), `nexora_seller` (registered as a seller) and `nexora_customer_seller` (a customer who became a seller). No code asks for a role slug.

**Two helpers, not one.**

- `Account_Types::has_seller_record( $user )`: the user has a seller record, in **any** status.
- `Account_Types::can_sell( $user )`: the seller is approved and not suspended.

Every selling gate (products, imports, the Sales and My Products tabs, the order and stock paths) uses `can_sell()`. Screens that show where a seller stands use the status (`pending`, `approved`, `rejected`, `suspended`).

**When roles change.** Only on approval (a customer is promoted to `nexora_customer_seller`) and when a seller record is deleted (the user is demoted to `subscriber`). **Suspension does not remove the role**: the status controls what the seller may do. A suspended customer-seller keeps `nexora_customer_seller`, can still buy and can still read their earnings. This replaces the "role held only while approved" rule of section 2.

**Post types** (at most 20 characters, the WordPress limit):

| Today | After 7a | Holds |
|---|---|---|
| `user_profile` | `nexora_customer` | a customer's profile |
| `nexora_vendor` | `nexora_seller` | a seller who registered directly as a seller: their personal details and business details |
| (new) | `nexora_cust_seller` | the business details of a customer who becomes a seller |
| `user_connections`, `user_content`, `nexora_product` | unchanged | |

`nexora_import_log` and `nexora_api_conn` keep their slugs. PHP namespaces, classes and product or order meta keys (`vendor_id` on products, `_nx_*` on order items) are **not** renamed. The user meta `_nexora_vendor_status` and `_vendor_id` are **kept as they are, with no aliases**; only user-facing text says "seller".

**A direct seller is a normal member.** Their `_profile_id` points to their primary profile, which is their `nexora_seller` post, and every shared guard accepts both profile post types. Connections, Content, Chat and Notifications therefore work for them, keyed on whichever profile post they have. Their profile page shows the personal section (as for a customer) plus a business section.

**Linking.** For a customer who became a seller: `customer_post_id` on the `nexora_cust_seller` record and `seller_post_id` on the `nexora_customer` post, kept in sync by **one** method. User meta: `_profile_id` (the primary profile: the customer post, or the seller post for a direct seller) and `_seller_post_id` (the seller record).

**Flows.**

1. *Register as a customer:* role `subscriber` and a `nexora_customer` post.
2. *Register as a seller:* role `nexora_seller`, status `pending`, and **only** a `nexora_seller` post holding their personal and business details (it is their primary profile). No separate customer post.
3. *A customer clicks Become a seller:* a `nexora_cust_seller` post with status `pending`, linked to the customer post. The role changes to `nexora_customer_seller` on approval, not before.

**Rules for every seller.** Pending, rejected and suspended sellers can still buy but cannot sell.

**Rejection.**

- A rejected *customer* stays `subscriber`. Their `nexora_cust_seller` record is kept with status `rejected`, and they can apply again.
- A rejected *direct seller* keeps the role `nexora_seller` with status `rejected`, can buy but not sell, and can apply again.

**Statuses** of a seller record: `pending`, `approved`, `rejected`, `suspended` (today `rejected` is not stored: a rejected application returns to `none`).

**Admin.** A customer's edit screen has a "Seller account" box showing whether this customer is also a seller and linking to that record.

**Existing data** is converted in place, so post IDs do not change, by an idempotent migration with a dry-run (the dry-run reports what would change and writes nothing; running the migration twice changes nothing the second time):

- every existing `nexora_vendor` post becomes a `nexora_cust_seller` post (`post_type` changed in place), linked to the owner's existing customer post (`customer_post_id` / `seller_post_id`), with its status kept;
- the users' role: approved and suspended sellers become `nexora_customer_seller` (suspension keeps the role); pending sellers and users with no seller record become `subscriber` (the role changes on approval); the `nexora_vendor` role is removed afterwards;
- `user_profile` posts become `nexora_customer` posts.

**Size of the change** (measured 2026-10-19): `user_profile` appears in 20 plugin or theme files, 14 test files (1 golden snapshot) and 8 docs; `nexora_vendor` in 9, 21 (3 golden) and 2; `_vendor_id` in 4 and 2; `_profile_id` in 20, 14 and 7. The live site has 7 `user_profile` posts, 2 `nexora_vendor` posts and 110 products.

**Owner's answers on the last points (2026-10-19):**

1. `_seller_post_id` **is also set for a direct seller**, pointing at the same post as `_profile_id`.
2. When a direct seller's seller record is deleted, the user is demoted to `subscriber` and a **plain `nexora_customer` post is created for them**, so they keep a profile.
3. Creating a seller-only account by hand is **deferred to Phase 7b** as an admin row action that uses the shared linking method.

Nothing in this section is open.

## 12. Admin editing (Phase 7b)

Status: **specified, not built.** Recorded from the owner's requirement and answers (2026-10-19). Depends on Phase 7a.

**Who and how.** Administrators (`manage_options`) can edit, trash and delete every Nexora post type through **ACF Pro** field groups registered in code, using the existing meta keys as the field names. *Prerequisite:* ACF Pro installed and licensed (through wp-admin, not committed); the site has the free ACF 6.8.10 today.

**Validation.** Admin saves go through the same validators as the front end.

**Read-only.** Order snapshots (the `_nx_*` meta on order items) and the links between posts (`customer_post_id`, `seller_post_id`, and the user-meta links) stay read-only.

**Notifications** are a custom table, not a post type, so they get their own edit and delete screen.

**Change history.** Every admin change is recorded in a read-only "Change history" box. The records live in a small **append-only custom table**: object type, object id, user, field, old value, new value, time. It survives the deletion of the object, so a deleted seller's history stays. It is a new table, so it follows the usual rules for tables (`Database\Migrations::DB_VERSION` bump, listed in the uninstaller).

**When `Core\Post_Guard` is lifted.** Today it refuses direct `wp_update_post` / `wp_delete_post` on products and seller records. It is lifted only when **all** of these are true: `current_user_can( 'manage_options' )`, the request is a wp-admin edit-screen save or an admin row action, and the nonce is valid. It is **not** lifted for REST or AJAX.

**Trash and delete rules.**

- *Trash* is allowed for a seller who has orders: it hides the seller and unpublishes their products; the orders are untouched.
- *Permanent delete* is refused while any order line refers to that seller.
- A seller with no orders can be deleted: this also removes their products and their API connections, and the WordPress user drops to `subscriber`. Order snapshots always stay.
- A customer post that has a linked seller record cannot be deleted first (the rule concerns customer posts that have a `nexora_cust_seller` record; a direct seller's only profile is their seller post, which follows the seller rules above).
- Deleting a WordPress user cascades to their Nexora posts.

**Deleting the WordPress user of a seller whose record has order lines is refused** (owner's answer, 2026-10-19): the cascade never runs for such a user, so the seller record the order lines refer to is never deleted.

**Row action for seller-only accounts** (decided for 7b): an administrator can create a seller-only account by hand through an admin row action that uses the shared linking method of section 11.

Nothing in this section is open.
