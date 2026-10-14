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
- Status lives in user meta `_nexora_vendor_status`: `none | pending | approved | suspended`. Allowed transitions: none→pending, pending→approved|rejected(none), approved→suspended, suspended→approved.
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
