# Nexora Marketplace: TEST PLAN

Every test is written first and shown RED, then GREEN. Files go in `tests/php/test-<name>.php`, run with `tests/run.sh`, with self-cleaning fixtures from `tests/bootstrap.php`. Every phase ends with the full suite, `vendor/bin/phpcs`, and a report.

**Baseline (2026-10-14, Woo 11.2.1 active):** the original run was 1,142 passed, 4 failed, all in `test-golden-output.php` (logout nonce differs per environment). After normalising `_wpnonce=` URL args in `tests/bootstrap.php` (harness only, no markup change; stored goldens untouched) the suite is **1,146 passed, 0 failed, 20 files**. phpcs clean. Jetpack, Google Listings & Ads, Reddit and Snapchat are deactivated.

Note: an earlier report said "1,084 of 1,088"; that was a miscount.

## Test isolation (rules for every test)
The suite runs against the site's own database, so a test must never depend on, or damage, what the site has saved. Rules, enforced by `test-isolation-lint.php` and proved by `tests/guard.sh`:
- **Known settings.** `tests/bootstrap.php` puts every test on the same default settings (no API sources saved, vendor APIs off, no host allow-list, default product categories, delete-on-uninstall off) with `nx_test_default_settings()`. A test that needs other values sets them with `nx_test_options( [ name => value ] )` (null = the option does not exist).
- **Exact restore.** `nx_test_options()`, `nx_test_options_keep()` (edit it yourself, it is restored) and the Marketplace Item scope remember the RAW database rows (value, autoload flag, or "absent") and put them back byte for byte at `nx_test_finish()` and from a shutdown function, so also after a failed assertion, an exception, a fatal error or `exit`. Restores bypass sanitize callbacks.
- **Only what the test created.** A test may only delete a user, post, option or table it made itself (fixtures from `nx_test_user()` / `nx_test_vendor()`, tracked ids, scratch names `nx_test_*` / `nxtest_*` / `nx_iso_*`, the `nxtest_` scratch table prefix). Anything else it needs to change goes through the helpers above. No `TRUNCATE`; `DROP TABLE` only on the scratch prefix.
- **The Marketplace Item** (real orders point at it) is never edited or deleted: `nx_test_marketplace_item_scope()` makes the test work on a scratch item and puts the real item's flag and option back.
- **Ephemeral rows** (rate-limit counters, API page caches, import previews, locks, OTP references, the home-stats cache): the first `nx_test_reset_limits()` sets the site's own aside, the test sees a clean slate, the end removes what the run made and puts the originals back. `tests/purge.php` removes only `nxtest_*` fixtures.
- **Golden snapshots** render with the fixed default settings and give the auto-increment counters back exactly as the site had them.
- **Commands.** `tests/run.sh` (all) or `tests/run.sh tests/php/test-foo.php`; `NX_TEST_DB=<copy of the database>` runs everything (child processes included) on a copy; `NX_SKIP_LIVE=1` skips the tests that make real web requests (the web server only sees the live database); `tests/guard.sh` sets hostile non-default values (Fake Store enabled, category creation off, vendor APIs on, host allow-list, custom categories, "coming soon" and guest checkout on), runs the whole suite, expects 0 failures and checks that the originals are exactly back. While a test runs against the live database the site briefly uses the test's known values: run it on a development site or on a copy.
- **New tests:** `test-isolation-helper.php` (exact restore incl. crash modes, the Marketplace Item scope, ephemera), `test-isolation-lint.php` (the rules above, read from the test files).

## Phase 0
Done: baseline, environment check, SPEC and TEST_PLAN. No code.

## Phase 1: Account types (`test-account-types.php`, DONE, 79 assertions)
- `is_vendor` / `vendor_status` / `can_sell` for none, pending, approved, suspended and a guest.
- Role/caps registration is idempotent, runs on activation and on upgrade, and survives running twice.
- Additive: a vendor keeps their original role and still has every customer ability (`read`, upload rule from `Upload_Policy`).
- The role is never taken from the client: `role=administrator|editor|nexora_vendor` is ignored.
- Status transitions: allowed ones pass; illegal ones (none→approved, suspended→pending) are rejected.
- `tabs_for()` matches the map for each state.
- **Regression:** golden snapshots unchanged (after the nonce normalisation), `test-endpoint-matrix` and `test-compat-contract` unchanged.

## Phase 2: Vendor application (DONE: `test-vendor-validator.php`, `test-vendor-application.php`, `test-vendor-admin.php`, `test-vendor-assets.php`, extended `test-vendor-self-approval.php`)
- Valid application creates the `nexora_vendor` post, user meta and `pending` status.
- Missing, over-long and invalid fields (email, phone, pincode, GSTIN format).
- Duplicate GSTIN (case-insensitive) is rejected; a second application by the same user is rejected.
- Partial failure rolls back (no orphan post or role), like `create_member`.
- Sign-up "Seller" intent works. The "Customer" intent and the default path produce the same markup as before.
- Privacy: business data is absent for guests, viewers and `Privacy::script_data()` of non-owners; present for the owner.
- Admin: list columns, meta box, approve and suspend (nonce + `manage_options`), emails sent to admin and applicant.
- Rate limit `vendor_apply`; nonce missing; guest rejected.
- Guard (`test-vendor-guard.php`): no Publish box, no bulk or row Trash/Restore, no Add New, wp_trash_post / wp_delete_post / wp_untrash_post / wp_update_post(status,title) refused for live applications, Service and member deletion still work. Reject is pending-only (suspended can only be reactivated: GSTIN stays locked, earnings read-only access kept).
- UI fix round: decision box (side/high, admins only, buttons per status, confirm on Reject, no links for others, details box read-only) in `test-vendor-admin.php`; profile popups and the Business sub-tab in `test-vendor-profile-ui.php`; suspended>none transition in `test-account-types.php`.
- Self-approval: the applicant's own form can only create `pending` (a posted `status=approved`, `role`, `account_type` is ignored); a pending applicant re-posting the form stays pending; only the admin approve action (nonce + `manage_options`) reaches `approved`. `test-vendor-self-approval.php` (exists since Phase 1) must keep passing, and Phase 2 adds each new allowed caller to its file whitelist deliberately.
- Earnings by status: suspended vendor keeps read-only history without the role (`test-account-types.php`).

## Phase 3: Gating and UI (`test-vendor-gating.php`)
- Matrix: each vendor AJAX action × (guest, customer, pending, approved, suspended, other vendor's object). Expect a JSON error for every cell except the allowed one.
- Suspended vendor cannot list, save, delete, import or sell; the history is read-only.
- Rendered tabs match `tabs_for()` for each state.
- Login unchanged: username or email, generic error, reCAPTCHA rules, redirect through `Urls::profile_for()`.
- `Access_Control` still blocks non-admins from wp-admin/wp-login, with Woo active (guest and member).
- `/my-account/` (and `/my-account/orders/`, `/lost-password/`): guest goes to the Nexora login with return URL; member goes to their profile; admin untouched.
- Woo checkout signup and login options stay off even if the option is set to yes (filter test).
- A "customer" is any non-vendor member whatever the WP role (test with roles subscriber, customer, shop_manager).

**Phase 3 (DONE)**: `test-profile-tabs.php`, `test-login-redirect.php`, `test-vendor-gating.php`, `test-woo-routes.php`.

**Phase 4 (DONE)**: `test-product-validator.php`, `test-product-service.php` (add/edit/delete, ownership, other vendor's product, posted vendor_id, images, limit, rate limit, AJAX matrix), `test-product-guard.php`, `test-marketplace-list.php` (listing rules, search, category, pagination, query count, escaping, privacy), `test-product-ui.php` (tabs, cards, popup template, scripts); `test-vendor-guard.php` extended (no creation from outside).

## Phase 4 (original plan): Products and marketplace (`test-products.php`, `test-marketplace-list.php`)
- Own-only edit and delete; another vendor's ID gives an error.
- Price and stock bounds, SKU unique per vendor, XSS in title and description is stripped or escaped, category whitelist.
- Listing shows only published products from approved vendors (and Platform); suspended vendor products vanish; search, category filter and pagination are correct; query count stays flat as the list grows.
- Image: only attachment IDs owned by the vendor.
- Rate limit `product_save`.

**Phase 5b (DONE) - product API sources**: `test-url-guard.php` (SSRF: schemes, ports, credentials, IP literals in every spelling, local names, resolved addresses incl. IPv6/mapped/NAT64/6to4/metadata, allow-list, one generic message, DNS pin), `test-secrets.php` (encryption, tamper, salts, never in the DB), `test-api-sources.php` (seed, sanitising, keys kept/replaced/cleared, master switch, allow-list, the Settings screen never shows a key), `test-api-format.php` (Nexora format, pagination on the same host, auth headers, no redirects, limits, generic errors, cache, adapter, guide vs code vs SPEC), `test-api-connections.php` (switch, save/edit/delete, limit 5, ownership, keys, test/preview/import, platform address not controllable, rate limits, admin list), UI/matrix updates.

**Phase 5 (DONE)**: `test-csv-import.php` (parser, formula neutralising, plan/apply, idempotence, vendor+sku, images, limit), `test-api-import.php` (mocked HTTP: request, mapping, partial failure, cache, URL control, no leakage), `test-import-ajax.php` (gating, tokens, rate limits, history guard), `test-import-http.php` (real multipart uploads: wrong extension/MIME/size/rows/encoding, dry run, import, other vendor, customer, CSV still refused by the media uploader), `test-import-ui.php`; kses regression in `test-product-service.php`.

## Phase 5 (original plan): Imports (`test-csv-import.php`, `test-api-import.php`)
- CSV: wrong extension and wrong MIME rejected; oversize and over-row-limit; non-UTF-8; bad rows give line numbers; formula injection neutralised; dry run writes nothing; re-import is idempotent; the member MIME allow-list is unchanged (assert `restrict_member_mimes` still blocks CSV in the uploader).
- API mapping (SPEC 8b): each field mapped, price/stock out of bounds skipped, unknown category becomes Other, missing sku becomes `DJ-{id}`, image host other than `cdn.dummyjson.com` or non-https dropped, paging stops at the row limit.
- API: HTTP failure, timeout, non-JSON, wrong schema, partial failure report, upsert by `external_id`, transient cache hit, owner = Platform vendor, no credential in any response.
- Upsert scope: vendor B uploads (CSV) and imports (API) a sku/external_id that vendor A already has; A's product is byte-identical afterwards (price, stock, name, modified date) and B gets its own product. Re-importing B's file updates only B's.
- Both: customers, pending and suspended vendors blocked; rate limits.

## Phase 7a (BUILT, awaiting review): accounts and record types
`test-accounts-model.php` (the three record types and their two lists, helpers for every status, Become a seller, approve promotes, suspend keeps the role, reject keeps the record and re-apply reuses it, GSTIN across both seller types, direct seller = one post and no customer post, links both ways and repair by the one linking method, shared guards accept a direct seller, record deletion demotes and gives a direct seller a plain customer post, the read-only "Seller account" box, no cross-account edits), `test-accounts-migration.php` (fixtures put into the OLD shape; dry-run writes nothing; apply keeps IDs; roles per status; running twice changes nothing; orders, earnings, listing and product owner unchanged; the old role leaves only when nobody holds it). `test-account-types.php`, `test-vendor-*.php` and the golden snapshots were changed only for renamed identifiers and the decided behaviour (reject keeps the record; approve promotes; suspension keeps the role).

## Phase 6a (DONE): the cart
`test-marketplace-item.php` (hidden item: shape, idempotent, restored, recreated, hidden everywhere, not editable by vendors), `test-cart-rules.php` (every refusal code, quantity forms, snapshot from the DB), `test-cart-bridge.php` (add stores only the product id; hostile requests: tampered price/name/vendor/quantity/ids, direct add of the hidden item, forged cart data; deleted, unpublished, suspended-vendor, 0-stock and price-changed products already in the cart; own product; several vendors grouped with subtotals; fresh checkout errors; rate limit), `test-cart-routes.php` (guest 401 on every Store API cart/checkout/order route, guarded `wc-ajax`, `?add-to-cart=`, Woo handlers removed, guest checkout forced off, cart/checkout pages redirect guests), `test-cart-ui.php` (button states, tamper-free markup and script, flat query count), `test-cart-http.php` (real HTTP: add with a tampered request, Store API cart JSON, forged add-item, edited price, suspended vendor, guest). `test-endpoint-matrix.php` lists `add_to_cart`.

## Phase 6b (DONE): orders, stock, commission, earnings, My Orders and Sales
`test-orders-commission.php` (rate and Settings field, rounding table, the split), `test-orders-placement.php` (the frozen snapshot, price/name/SKU edit and product deletion after the order, several vendors, placing twice, stock gone, all-or-nothing, price changed, unpublished, suspended vendor, ordinary orders ignored, the checkout tag), `test-orders-stock.php` (taken once; cancel, fail and refund give back ONCE: every hook fired twice and in combination; partial refunds; revive; never above what was taken), `test-orders-concurrent.php` (two real processes racing for the last unit, three rounds, plus 3 buyers x 2 of 5), `test-orders-earnings.php` (the counted statuses, BACS On hold -> Processing starts counting, Processing -> Cancelled stops it, COD, vendor isolation, partial/amount-only/repeated refunds with the refund hook fired twice, no cent left behind), `test-orders-ajax.php` (My Orders only your own, Sales only your lines, object-level: a vendor cannot open another vendor's order, buyer privacy, suspended read-only, hostile ids, limits, tabs, script), `test-orders-http.php` (the block checkout over real HTTP). `test-profile-tabs.php`, `test-endpoint-matrix.php` and the goldens updated on purpose (new tabs, new Settings field). All follow the isolation rules above.

## Phase 6: Cart, checkout, orders (`test-wc-bridge.php`, `test-wc-stock.php`)
- Marketplace Item is created once, re-created if deleted, **physical (not virtual)**, hidden, tax none.
- Shipping: a ₹200 cart gets ₹50; a cart over ₹1000 gets free shipping; the shipping address is required.
- Price tampering: a client-sent price is ignored; the DB price is used even after the vendor edits it mid-cart.
- Blocked: out of stock, unpublished, suspended vendor, deleted product, own product, guest (sent to the Nexora login with a return URL).
- F2 hardening (SPEC section 8): guest `?add-to-cart=`, `wc-ajax=add_to_cart`, Store API add-item and checkout rejected; no user can be created through Woo; Woo lost-password goes to the Nexora flow; a forged `nexora_product_id` or client price is refused; "My Orders" decision implemented and tested.
- Snapshot meta complete; later edits and deletes do not change old orders.
- Stock decremented once (re-firing the status hook does nothing), restored on cancel and refund; race test with two concurrent orders for the last unit.
- Multi-vendor order carries each item's vendor id.
- HPOS on and off. `wc-ajax` and COD/BACS checkout complete end to end.

## Phase 8: Analytics and admin overview (`test-vendor-analytics.php`)
- Only processing and completed orders count; refunded, cancelled and pending are excluded.
- Commission rounding (e.g. 10% of ₹33.33 gives ₹3.33).
- Vendor A never sees vendor B's lines or buyers; the per-order list exposes the order number, date, the vendor's own products, quantities and earnings, and no buyer name (the shipping name and address only while the order still ships, as in 6b).
- Date filter edges; zero state; the admin overview totals equal the sum of the vendors.

## Phase 10: Hardening and end to end (`test-e2e-marketplace.php`)
- One flow: customer signs up, becomes a seller, admin approves, adds products manually, by CSV and by API, another customer buys with COD, the vendor sees the earning.
- Security checklist: nonces, capabilities, object-level checks, prepared SQL, escaped output (grep review).
- Performance: query counts on marketplace list, profile page and analytics.
- Accessibility pass on the new forms (labels, focus order, error association), checked in the browser with `verify-like-a-user`.
- `.pot` regenerated (`test-i18n`), `NEXORA_VERSION` + header bumped, `README.md` updated, `Migrations::DB_VERSION` bumped if a table was added.
