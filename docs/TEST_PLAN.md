# Nexora Marketplace: TEST PLAN

Every test is written first and shown RED, then GREEN. Files go in `tests/php/test-<name>.php`, run with `tests/run.sh`, with self-cleaning fixtures from `tests/bootstrap.php`. Every phase ends with the full suite, `vendor/bin/phpcs`, and a report.

**Baseline (2026-10-14, Woo 11.2.1 active):** the original run was 1,142 passed, 4 failed, all in `test-golden-output.php` (logout nonce differs per environment). After normalising `_wpnonce=` URL args in `tests/bootstrap.php` (harness only, no markup change; stored goldens untouched) the suite is **1,146 passed, 0 failed, 20 files**. phpcs clean. Jetpack, Google Listings & Ads, Reddit and Snapchat are deactivated.

Note: an earlier report said "1,084 of 1,088"; that was a miscount.

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

## Phase 2: Vendor application (`test-vendor-application.php`, `test-vendor-validator.php`)
- Valid application creates the `nexora_vendor` post, user meta and `pending` status.
- Missing, over-long and invalid fields (email, phone, pincode, GSTIN format).
- Duplicate GSTIN (case-insensitive) is rejected; a second application by the same user is rejected.
- Partial failure rolls back (no orphan post or role), like `create_member`.
- Sign-up "Seller" intent works. The "Customer" intent and the default path produce the same markup as before.
- Privacy: business data is absent for guests, viewers and `Privacy::script_data()` of non-owners; present for the owner.
- Admin: list columns, meta box, approve and suspend (nonce + `manage_options`), emails sent to admin and applicant.
- Rate limit `vendor_apply`; nonce missing; guest rejected.
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

## Phase 4: Products and marketplace (`test-products.php`, `test-marketplace-list.php`)
- Own-only edit and delete; another vendor's ID gives an error.
- Price and stock bounds, SKU unique per vendor, XSS in title and description is stripped or escaped, category whitelist.
- Listing shows only published products from approved vendors (and Platform); suspended vendor products vanish; search, category filter and pagination are correct; query count stays flat as the list grows.
- Image: only attachment IDs owned by the vendor.
- Rate limit `product_save`.

## Phase 5: Imports (`test-csv-import.php`, `test-api-import.php`)
- CSV: wrong extension and wrong MIME rejected; oversize and over-row-limit; non-UTF-8; bad rows give line numbers; formula injection neutralised; dry run writes nothing; re-import is idempotent; the member MIME allow-list is unchanged (assert `restrict_member_mimes` still blocks CSV in the uploader).
- API mapping (SPEC 8b): each field mapped, price/stock out of bounds skipped, unknown category becomes Other, missing sku becomes `DJ-{id}`, image host other than `cdn.dummyjson.com` or non-https dropped, paging stops at the row limit.
- API: HTTP failure, timeout, non-JSON, wrong schema, partial failure report, upsert by `external_id`, transient cache hit, owner = Platform vendor, no credential in any response.
- Upsert scope: vendor B uploads (CSV) and imports (API) a sku/external_id that vendor A already has; A's product is byte-identical afterwards (price, stock, name, modified date) and B gets its own product. Re-importing B's file updates only B's.
- Both: customers, pending and suspended vendors blocked; rate limits.

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

## Phase 7: Analytics (`test-vendor-analytics.php`)
- Only processing and completed orders count; refunded, cancelled and pending are excluded.
- Commission rounding (e.g. 10% of ₹33.33 gives ₹3.33).
- Vendor A never sees vendor B's lines or buyers; the per-order list exposes only buyer name, date, qty and amount.
- Date filter edges; zero state; the admin overview totals equal the sum of the vendors.

## Phase 8: Hardening and end to end (`test-e2e-marketplace.php`)
- One flow: customer signs up, becomes a seller, admin approves, adds products manually, by CSV and by API, another customer buys with COD, the vendor sees the earning.
- Security checklist: nonces, capabilities, object-level checks, prepared SQL, escaped output (grep review).
- Performance: query counts on marketplace list, profile page and analytics.
- Accessibility pass on the new forms (labels, focus order, error association), checked in the browser with `verify-like-a-user`.
- `.pot` regenerated (`test-i18n`), `NEXORA_VERSION` + header bumped, `README.md` updated, `Migrations::DB_VERSION` bumped if a table was added.
