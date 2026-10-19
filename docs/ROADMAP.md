# Nexora Marketplace: ROADMAP

Written 2026-10-19 (updated the same day with the owner's answers) from `docs/SPEC.md`, `docs/TEST_PLAN.md`, the code on `main` (`16cb22e5`, plugin version 1.0.16) and the original multi-phase plan as recorded in the SPEC (section 10) and the TEST_PLAN. The original plan text itself is not in the repository, so where this file says "the original plan" it means those two documents.

Where this file and the SPEC disagree about what is *built*, the code wins; where they disagree about what is *wanted*, the SPEC (sections 11 and 12 for the next two phases) wins.

## 1. Where things stand

- 66 test files; the last full run on a scratch copy of the database gave **3972 passed, 0 failed** (with hostile settings, via `tests/guard.sh`).
- `main` and `origin/main` are the same commit. The local tag `phase-6-complete` has not been pushed.
- Everything marked **Done** below has its tests in `tests/php/` and its rules in `docs/SPEC.md`.

## 2. Done

| Phase | What exists | Proof |
|---|---|---|
| 0 | Specification, environment check (WooCommerce 11.2.1, HPOS), decisions log | `docs/SPEC.md`, `docs/TEST_PLAN.md` |
| 1 | Account types: vendor status (none, pending, approved, suspended), role `nexora_vendor`, `tabs_for()` | `test-account-types.php` |
| 2 | Vendor application (business details, GSTIN rules), admin approve / reject / suspend / reactivate, mails | `test-vendor-*.php` |
| 3 | Server-side gating and tab visibility, post-login redirects, Woo account pages closed (`/my-account/`, sign-up and login at checkout forced off) | `test-vendor-gating.php`, `test-profile-tabs.php`, `test-login-redirect.php`, `test-woo-routes.php` |
| 4 | Products (own-only CRUD, one shared validator, images), the Marketplace tab and listing | `test-product-*.php`, `test-marketplace-list.php` |
| 5 | CSV import and API import with dry run, one-time token, history | `test-csv-import.php`, `test-api-import.php`, `test-import-*.php` |
| 5b | Product API sources in Settings, the published Nexora format, vendors' own API connections, SSRF rules, encrypted keys | `test-api-sources.php`, `test-api-format.php`, `test-api-connections.php`, `test-url-guard.php`, `test-secrets.php` |
| 5c | Fake Store adapter (stock default, create-missing-categories) | `test-fakestore-adapter.php` |
| 5d | "How many products" for API imports (20, 40, 100, All up to 500), early stop, token bound to the count | `test-api-limit.php` |
| - | Test isolation: known settings for every test, exact restore, scratch Marketplace Item, guard script, lint | `test-isolation-*.php`, `tests/guard.sh` |
| 6a | The cart: hidden Marketplace Item, server-side prices, cart by seller, guest routes closed, Add to cart button | `test-marketplace-item.php`, `test-cart-*.php` |
| 6b | Orders: frozen order lines, atomic stock, commission (setting, default 10%), earnings (only Processing and Completed), refunds, My Orders and Sales tabs, HPOS declared | `test-orders-*.php` (incl. a two-process race and a real block-checkout run) |
| - | `CLAUDE.md` refreshed for the marketplace, tests and git habits | `CLAUDE.md` |

Gaps between the plan documents and the code that are *not* missing features, only stale text: `docs/SPEC.md` section 3 still lists old order-item key names (the built keys are `_nx_price`, `_nx_qty`, ...) and its title says "Phase 0 draft"; `docs/TEST_PLAN.md` still carries "original plan" entries for phases 3 to 6 next to the "DONE" entries, and numbers analytics and hardening as its Phases 7 and 8 (they are Phases 8 and 10 below).

## 3. Remaining phases

Order (the owner's): **7a, 7b, 8, 9, 10**, then the pre-launch checklist (section 5). Each phase is test-first, stops for review, and runs its settings-touching tests on a scratch copy of the database (`docs/TEST_PLAN.md`, "Test isolation").

### Phase 7a: Accounts and record types

- **Goal.** Three roles (`subscriber`, `nexora_seller`, `nexora_customer_seller`), record types named for what they hold (`nexora_customer`, `nexora_seller`, `nexora_cust_seller`), and a rejected seller who can apply again. Specified in `docs/SPEC.md` section 11 (decided there; three small open points remain).
- **Main pieces.**
  - Two helpers instead of a fuzzy `is_seller()`: `has_seller_record( $user )` (any status) and `can_sell( $user )` (approved and not suspended). Every selling gate uses `can_sell()`; screens show the status.
  - Roles change only on approval (promote to `nexora_customer_seller`) and when a seller record is deleted (demote to `subscriber`); suspension keeps the role, the status controls it.
  - Rename `user_profile` to `nexora_customer` and `nexora_vendor` to `nexora_seller`, add `nexora_cust_seller`. `_nexora_vendor_status` and `_vendor_id` stay as they are, with no aliases.
  - A direct seller is a normal member: `_profile_id` points at their seller post and every shared guard accepts both profile post types, so Connections, Content, Chat and Notifications work for them; their profile page shows the personal section plus a business section.
  - One method that links a seller record and a customer post (`customer_post_id`, `seller_post_id`), plus user meta `_profile_id` and `_seller_post_id`.
  - The three flows: register as a customer, register as a seller (seller post only), Become a seller (pending post; the role changes on approval); a stored `rejected` status and re-apply.
  - A "Seller account" box on the customer's admin screen.
  - An idempotent in-place migration with a dry-run: existing sellers become `nexora_cust_seller`, linked to their existing customer post, role `nexora_customer_seller`.
  - Update everything that names the old slugs: the post-type registrar, `Core\Post_Guard`, the uninstaller, queries, templates, tests, golden snapshots, docs.
- **Depends on.** Nothing new; it must come before 7b.
- **Tested by.** Dry-run versus apply versus a second run (post IDs unchanged, nothing changes twice); a role and flow matrix; `can_sell()` for every status ("pending, rejected and suspended can buy but not sell", and a suspended customer-seller keeps the role and can read earnings); social features for a direct seller; link-sync; the existing suites green with the new slugs; golden diffs reviewed; the whole migration rehearsed on a scratch copy of the live database first.
- **Risk.** The widest change so far (SPEC section 11 gives the numbers), and the seller-only profile changes an assumption the social features rely on.

### Phase 7b: Admin editing through ACF Pro

- **Goal.** Administrators (`manage_options`) can edit, trash and delete every Nexora post type from wp-admin. Specified in `docs/SPEC.md` section 12 (decided there; one open point remains).
- **Main pieces.**
  - Field groups registered in code, using the existing meta keys as field names.
  - Admin saves through the same validators as the front end; order snapshots and post links read-only.
  - A screen for the notifications table.
  - A read-only "Change history" box fed by a small append-only custom table (object type, object id, user, field, old value, new value, time) that survives post deletion; this is a new table, so `Migrations::DB_VERSION` and the uninstaller list change.
  - `Core\Post_Guard` lifted only when the user has `manage_options`, the request is a wp-admin edit-screen save or an admin row action, and the nonce is valid; never for REST or AJAX.
  - Trash of a seller with orders is allowed (hides the seller, unpublishes their products, orders untouched); permanent delete is refused while any order line refers to the seller; a seller with no orders can be deleted (products and API connections go, the user drops to `subscriber`); a customer post with a linked seller record cannot be deleted first; deleting a WordPress user cascades to their Nexora posts.
- **Depends on.** 7a (the new slugs and links) and **ACF Pro installed and licensed** (the site has the free ACF 6.8.10 today; the owner will install Pro through wp-admin).
- **Tested by.** A capability matrix (only `manage_options`); validators applied on admin save; read-only fields unchangeable by a crafted request; the Post_Guard lift only with all three conditions (and not via REST or AJAX, not with a bad nonce); one test per trash and delete rule, including refusal while an order line refers to the seller; a history row per edit that survives deletion; the cascade on user deletion; `Core\Post_Guard` still blocking everyone else.

### Phase 8: Vendor analytics and the admin overview

- **Goal.** Each vendor sees how their shop is doing; the administrator sees the whole marketplace. Only Processing and Completed orders count, as for earnings today.
- **Main pieces.**
  - *Vendor analytics:* per-vendor revenue, orders and top products, with a date filter, on top of `Orders\Earnings` and `Orders\Lines` (the per-line figures are already frozen and refund-adjusted). **No buyer names.** A vendor sees the order number, the date, their own products, quantities and earnings, and the buyer's shipping name and address only while the order still ships, as in 6b.
  - *Admin overview:* total sales, commission and orders, and a per-seller table.
  - A zero state; vendor access rules the same as the Sales tab.
- **Depends on.** 6b (done). Technically independent of 7a and 7b; it comes after them by the owner's order.
- **Tested by.** `test-vendor-analytics.php`: only the counted statuses; refunds reduce the figures; commission rounding; a vendor never sees another vendor's lines or any buyer name; the address only while shipping; date-range edges; empty state; the admin overview totals equal the sum of the per-seller rows; query counts stay flat as orders grow.

### Phase 9: Fulfilment, order emails and notifications, low-stock alerts

- **Goal.** Close the loop after an order is placed.
- **Main pieces.**
  - *Vendor fulfilment:* a vendor marks their own order lines as shipped.
  - *Order emails and notifications:* the emails and Nexora notifications for order events (for example a new order for a seller).
  - *Low-stock alerts* for vendors.
- **Depends on.** 6b, and 8 for the shared read model. The mail setup (see the backlog) should work first.
- **Tested by.** A vendor can mark only their own lines (object-level); marking twice changes nothing; each email or notification is sent once per event, even if a hook fires twice; a mail failure never blocks an order; a low-stock alert fires once when the threshold is crossed; privacy rules for what a vendor sees stay as in 6b.
- **Open before it starts (needs the owner's decision, not guessed):** how a vendor marking lines as shipped relates to the order status (today the status is changed only by an administrator, and earnings depend on the status); which emails and notifications are wanted, to whom, and the low-stock threshold.

### Phase 10: Hardening and automated tests on every push

- **Goal.** Make the whole marketplace safe and sturdy, and make sure the tests run by themselves.
- **Main pieces.**
  - A security review (nonces, capabilities, object-level access, SQL, escaping).
  - Performance (query counts on the Marketplace, profile and Sales screens; `Orders\Earnings` at volume; an index strategy for the `_nx_vendor` order meta).
  - Error pages (checkout errors, 403 and 404) and mail failures (see the backlog).
  - An accessibility pass on the new forms; the end-to-end test; README, version and `.pot` updated.
  - **Automated tests on every push.** The suite needs WordPress, WooCommerce and MySQL, so this means a scripted fixture site in CI.
- **Depends on.** 7a to 9 (so it reviews the final shape).
- **Tested by.** `test-e2e-marketplace.php` (customer signs up, becomes a seller, admin approves, products added by hand, CSV and API, another customer buys with cash on delivery and with bank transfer, the vendor earns); the hardening checklist in `docs/TEST_PLAN.md`; a recorded query-count budget; the CI job itself must go green on a clean checkout.

## 4. Backlog (not scheduled)

- **GST invoice and tax line.** GSTIN is collected but unused; marketplace orders have no tax line (the Marketplace Item is tax-free by design).
- **A public seller storefront page** (a page listing one seller's products).
- **Customer cancel and return requests.**
- **Data retention and erasure.** Order lines keep buyer and seller names as frozen text; a policy for personal-data erasure requests is needed.
- **The earnings summary table.** Earnings are read from each vendor's orders on every request. Fine for thousands of orders; a summary table is the next step if volume grows.
- **Coupons on marketplace lines.** Refused today: a discounted line no longer equals the product price, so placing the order is refused with the price message. Needs a rule for who pays the discount and how commission is worked out.
- **Admin-only platform catalog import.** The "Platform" vendor that owns catalog items was dropped from Phase 5 (imported items belong to the importing vendor); an administrator-only import into a platform-owned catalog can be added later.
- **Deferred CSS fixes.** *OWNER TO SUPPLY:* the list of fixes (pages, screenshots) has not been written down yet.
- **Email sending failures shown by WP Mail SMTP.** *OWNER TO SUPPLY:* the error text (without passwords or keys) and when it happens. What the code does today: the plugin itself sends the registration mails, the password-reset code, the contact-form mail and the vendor application and decision mails; it sends no order mail, so failures seen in WP Mail SMTP come from those, from WooCommerce's own emails, or from WordPress itself.
- **Vendor payouts and settlements.** Out of scope for now (decided 2026-10-19; `docs/SPEC.md` section 9). Parked here so it is not forgotten: it would need a ledger of what is owed, a "mark paid" flow, settlement periods and a refund hold.
- **Clean-repo push.** Create a fresh GitHub repository from the clean tree at some later point; the existing repository stays as it is until then.
- **Housekeeping.**
  - Push the local tag `phase-6-complete` (the owner does the pushing).
  - The 833 third-party files committed in `16cb22e5` ("marketplace"): decide whether third-party code belongs in the repository at all.
  - Remove the leftover `Integrations\Better_Messages` class, its alias and test.
  - Tidy the stale text listed in section 2, including the phase numbering in `docs/TEST_PLAN.md`.

## 5. Pre-launch checklist (at the very end)

Nothing here is a phase; it is done once, just before real customers use the site.

- WooCommerce **"Coming soon" mode off**.
- **Working mail** (the WP Mail SMTP failures resolved; test registration, password reset, vendor decision and order mails).
- **HTTPS** on the live address.
- **Backups** that are tested and kept off the site.
- **ACF Pro installed** and licensed (it is already needed from Phase 7b on).

## 6. Where the flagged items went

The items flagged as "possibly missing" in the first version of this roadmap now all have a place:

| Flagged item | Now |
|---|---|
| Vendors cannot mark an order shipped | Phase 9 |
| No order emails, no vendor notification | Phase 9 |
| No admin marketplace overview | Phase 8 |
| Low-stock alert | Phase 9 |
| No automated tests on push | Phase 10 |
| Production readiness | Pre-launch checklist (section 5) |
| GST invoice and tax line; public storefront; customer cancel and return; data retention and erasure; earnings summary table | Backlog (section 4) |

## 7. Decisions made and questions still open

**Decided by the owner (2026-10-19):**

- **Accounts (Phase 7a).** Two helpers, `has_seller_record( $user )` and `can_sell( $user )`; roles change only on approval and on deletion of a seller record, suspension keeps the role; `_nexora_vendor_status` and `_vendor_id` are kept with no aliases; only user-facing text says "seller".
- **Existing sellers** all become `nexora_cust_seller`, linked to their existing customer post, with role `nexora_customer_seller`.
- **A direct seller** is a normal member whose primary profile (`_profile_id`) is their `nexora_seller` post; Connections, Content, Chat and Notifications work for them; their profile page shows the personal section plus a business section; they have no separate customer post.
- **Rejected sellers** can apply again (a rejected customer stays `subscriber`; a rejected direct seller keeps `nexora_seller` with status `rejected`).
- **A suspended customer-seller** keeps `nexora_customer_seller`, can still buy and can read their earnings.
- **Analytics** show no buyer names; the shipping name and address only while the order still ships, as in 6b. The **admin overview** (total sales, commission, orders, a per-seller table) is part of Phase 8.
- **Change history** is an append-only custom table that survives deletion; **Post_Guard** is lifted only for `manage_options` on a wp-admin save or row action with a valid nonce (never REST or AJAX); **trash** of a seller with orders is allowed, permanent delete is refused while an order line refers to the seller.
- **Schedule:** Phase 8 analytics and admin overview; Phase 9 fulfilment, order emails and notifications, low-stock alerts; Phase 10 hardening and automated tests on every push; backlog as in section 4; the pre-launch checklist at the end.
- **Payouts** out of scope for now (backlog). Phase 7b uses **ACF Pro**, installed by the owner. The **clean-repo push** is wanted, later.

**Still open** (the roadmap does not guess):

1. `docs/SPEC.md` section 11: whether `_seller_post_id` is also set for a direct seller; what profile a direct seller has if their seller record is deleted (they become a `subscriber` with no profile post); how an administrator creates a seller-only account by hand.
2. `docs/SPEC.md` section 12: when an administrator deletes the WordPress user of a seller whose record order lines refer to, does the user deletion get refused or does the seller record stay?
3. Phase 9, before it starts: how "shipped" relates to the order status and to earnings; which emails and notifications, to whom; the low-stock threshold.
4. The two *OWNER TO SUPPLY* backlog items (deferred CSS fixes, email-sending failures): the answer message still contained the template placeholders, so nothing was recorded.
