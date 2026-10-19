# Nexora Marketplace: ROADMAP

Written 2026-10-19 from `docs/SPEC.md`, `docs/TEST_PLAN.md`, the code on `main` (`16cb22e5`, plugin version 1.0.16) and the original multi-phase plan as recorded in the SPEC (section 10) and the TEST_PLAN. The original plan text itself is not in the repository, so where this file says "the original plan" it means those two documents.

Where this file and the SPEC disagree about what is *built*, the code wins; where they disagree about what is *wanted*, the SPEC (sections 11 and 12 for the next two phases) wins.

## 1. Where things stand

- 66 test files; the last full run on a scratch copy of the database gave **3972 passed, 0 failed** (with hostile settings, via `tests/guard.sh`).
- `main` and `origin/main` are the same commit. The local tag `phase-6-complete` has not been pushed.
- Everything below marked **Done** has its tests in `tests/php/` and its rules in `docs/SPEC.md`.

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

Gaps between the plan documents and the code that are *not* missing features, only stale text: `docs/SPEC.md` section 3 still lists old order-item key names (the built keys are `_nx_price`, `_nx_qty`, ...) and its title says "Phase 0 draft"; `docs/TEST_PLAN.md` still carries "original plan" entries for phases 3 to 6 next to the "DONE" entries.

## 3. Remaining phases

Order (the owner's): **7a, 7b, 8, 9**. Each phase is test-first, stops for review, and runs its settings-touching tests on a scratch copy of the database (see `docs/TEST_PLAN.md`, "Test isolation").

### Phase 7a: Accounts and record types

- **Goal.** Three roles (`subscriber`, `nexora_seller`, `nexora_customer_seller`), record types named for what they hold (`nexora_customer`, `nexora_seller`, `nexora_cust_seller`), and a rejected seller who can apply again. Specified in `docs/SPEC.md` section 11.
- **Main pieces.**
  - An `Account_Types::is_seller()` family of helpers so no code asks for a role slug.
  - Rename `user_profile` to `nexora_customer` and `nexora_vendor` to `nexora_seller`, add `nexora_cust_seller`.
  - One method that links a seller record and a customer post (`customer_post_id`, `seller_post_id`), plus user meta `_profile_id` and `_seller_post_id`.
  - The three flows: register as customer, register as seller (seller post only, no customer post), Become a seller (pending post; role changes on approval).
  - A stored `rejected` status and re-apply.
  - A "Seller account" box on the customer's admin screen.
  - An idempotent in-place migration with a dry-run: existing sellers become `nexora_cust_seller`, linked to their existing customer post, role `nexora_customer_seller`.
  - Update everything that names the old slugs: the post-type registrar, `Core\Post_Guard`, the uninstaller, queries, templates, tests, golden snapshots, docs.
- **Depends on.** Nothing new; it must come before 7b and 8. The five open points in SPEC section 11 need an owner decision first.
- **Tested by.** Dry-run versus apply versus a second run (post IDs unchanged, nothing changes twice); a role and flow matrix; "pending, rejected and suspended can buy but not sell"; link-sync; the existing suites green with the new slugs; golden diffs reviewed; the whole migration rehearsed on a scratch copy of the live database first.
- **Risk.** The widest change so far (SPEC section 11 gives the numbers). The seller-only account (no customer post) changes an assumption the social features rely on (see open point 1).

### Phase 7b: Admin editing through ACF Pro

- **Goal.** Administrators (`manage_options`) can edit, trash and delete every Nexora post type from wp-admin. Specified in `docs/SPEC.md` section 12.
- **Main pieces.**
  - Field groups registered in code, using the existing meta keys as field names.
  - Admin saves through the same validators as the front end.
  - Order snapshots and post links read-only.
  - A screen for the notifications table.
  - A read-only "Change history" box.
  - The delete rules (seller removal removes products and API connections; order snapshots stay; the user drops to `subscriber`; a linked customer post can't be deleted first; deleting a WordPress user cascades).
- **Depends on.** 7a (the new slugs and links) and **ACF Pro installed and licensed** (the site has the free ACF 6.8.10 today; the owner will install Pro through wp-admin).
- **Tested by.** A capability matrix (only `manage_options`); validators applied on admin save; read-only fields unchangeable by a crafted request; one test per delete rule; a history entry per edit; the cascade on user deletion; `Core\Post_Guard` still blocking everyone else.

### Phase 8: Vendor analytics

- **Goal.** Each vendor sees revenue, orders, top products and a date filter. Only Processing and Completed orders count, as for earnings today.
- **Main pieces.** A read model on top of `Orders\Earnings` and `Orders\Lines` (the per-line figures are already frozen and refund-adjusted); a date-range filter; top products by quantity and by earning; a zero state; admin and vendor access rules the same as the Sales tab.
- **Depends on.** 6b (done). Nothing in 7a or 7b is needed technically; it comes after them by the owner's order.
- **Tested by.** `test-vendor-analytics.php` as planned in `docs/TEST_PLAN.md`: only the counted statuses, refunds reduce the figures, commission rounding, a vendor never sees another vendor's lines, date-range edges, empty state. Query counts stay flat as orders grow.

### Phase 9: Hardening

- **Goal.** Make the whole marketplace safe and sturdy before it is used for real.
- **Main pieces.** A security review (nonces, capabilities, object-level access, SQL, escaping); performance (query counts on the Marketplace, profile and Sales screens; `Orders\Earnings` at volume; an index strategy for the `_nx_vendor` order meta); error pages (checkout errors, 403 and 404); mail failures (see the backlog); an accessibility pass on the new forms; the end-to-end test; README, version and `.pot` updated.
- **Depends on.** 7a, 7b and 8 (so it reviews the final shape).
- **Tested by.** `test-e2e-marketplace.php` (customer signs up, becomes a seller, admin approves, products added by hand, CSV and API, another customer buys with cash on delivery and with bank transfer, the vendor earns); the checklist in `docs/TEST_PLAN.md` Phase 8; a recorded query-count budget.

## 4. Backlog (not scheduled)

- **Coupons on marketplace lines.** Refused today: a discounted line no longer equals the product price, so placing the order is refused with the price message. Needs a rule for who pays the discount (platform or seller) and how commission is worked out.
- **Admin-only platform catalog import.** The "Platform" vendor that owns catalog items was dropped from Phase 5 (imported items belong to the importing vendor); an administrator-only import into a platform-owned catalog can be added later.
- **Deferred CSS fixes.** *OWNER TO SUPPLY:* the list of fixes (pages, screenshots) has not been written down yet.
- **Email sending failures shown by WP Mail SMTP.** *OWNER TO SUPPLY:* the error text and when it happens. What the code does today: the plugin itself sends the registration mails, the password-reset code, the contact-form mail and the vendor application and decision mails; it sends no order mail, so failures seen in WP Mail SMTP come from those, from WooCommerce's own emails, or from WordPress itself.
- **Vendor payouts and settlements.** Out of scope for now (decided 2026-10-19; `docs/SPEC.md` section 9). Parked here so it is not forgotten: it would need a ledger of what is owed, a "mark paid" flow, settlement periods and a refund hold.
- **Clean-repo push.** Create a fresh GitHub repository from the clean tree at some later point; the existing repository stays as it is until then.
- **Housekeeping found during this review.**
  - Push the local tag `phase-6-complete` (the owner does the pushing).
  - The 833 third-party files committed in `16cb22e5` ("marketplace"); decide whether third-party code belongs in the repository at all.
  - Remove the leftover `Integrations\Better_Messages` class, its alias and test.
  - Tidy the stale text listed in section 2.

## 5. Possibly missing from the plan

Flags only: each was checked against the code, none is decided or scheduled.

1. **Vendors cannot act on an order.** Only an administrator can change an order's status; there is no "mark as shipped" for a vendor, so a Processing order never becomes Completed without an admin.
2. **No order emails and no vendor notification.** WooCommerce's own order emails are not configured or tested by this project, a vendor is not told about a new order, and the Nexora notification table is not used for sales.
3. **No admin marketplace overview.** There is no screen with total sales and commission across vendors, although `docs/TEST_PLAN.md` Phase 7 mentions an admin overview.
4. **Earnings are computed on each request** by reading a vendor's orders. Fine for thousands of orders; a summary table would be needed for much more.
5. **No customer cancel or return request**, and no low-stock alert for vendors.
6. **GSTIN is collected but unused.** There is no GST invoice and no tax line on marketplace orders (the Marketplace Item is tax-free by design).
7. **No public seller storefront page** (a page listing one seller's products).
8. **Data retention and erasure.** Order lines keep the buyer's and seller's names as frozen text; there is no policy for personal-data erasure requests, nor for deleting a seller who has orders (7b covers the post side only).
9. **No continuous integration.** Tests are run by hand (about 7 minutes); nothing runs them on a push.
10. **Production readiness.** WooCommerce "Coming soon" must be off, mail must work (see the backlog), HTTPS and backups need an owner, and ACF Pro must be installed before 7b.

## 6. Decisions made and questions still open

**Decided by the owner (2026-10-19):**

- Existing sellers all become `nexora_cust_seller`, linked to their existing customer post, with role `nexora_customer_seller`.
- Someone who registers as a seller gets only a `nexora_seller` post (personal and business details) and no separate customer post.
- A rejected customer stays `subscriber` and keeps a `nexora_cust_seller` record with status `rejected`; a rejected direct seller keeps role `nexora_seller` with status `rejected`, can buy but not sell; both can apply again.
- Payouts are out of scope for now (backlog).
- Phase 7b uses ACF Pro; the owner installs it.
- The clean-repo push is wanted, later.

**Still open** (the roadmap does not guess):

1. The five open points in `docs/SPEC.md` section 11 (what a seller-only account sees and which social features apply to it; what `is_seller()` answers for each status; the role of a suspended seller; the fate of `_nexora_vendor_status` and `_vendor_id`; how an administrator handles seller-only accounts).
2. The four open points in section 12 (where the change history is stored; lifting `Core\Post_Guard` for administrators only; what "trash" means for a seller with orders; the delete rule for a seller without a customer post).
3. Should vendors' analytics show buyer names? Phase 6b deliberately hides them from vendors; the original plan listed "buyer name" in the per-order list.
4. Does an admin marketplace overview belong in Phase 8?
5. Which of the items in section 5 should be scheduled, and in which phase.
6. The two *OWNER TO SUPPLY* backlog items (deferred CSS fixes, email-sending failures).
