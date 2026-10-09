---
name: tdd-workflow
description: Test-driven workflow for this repo: write a failing test first, watch it fail for the right reason, implement the minimum, refactor. Uses the in-repo WP-CLI test runner (tests/run.sh) for PHP, node for pure JS logic, and a browser check for UI. Use before building or fixing anything in the nexora plugin or theme.
---

# TDD workflow

**Rule: no production code until a test has failed for the right reason.** This applies to features and bug fixes (a bug fix starts with a test that reproduces the bug).

There is no PHPUnit or Composer here. Tests are plain PHP files run inside WordPress by WP-CLI:

```
tests/run.sh                          # all tests/php/test-*.php
tests/run.sh tests/php/test-foo.php   # one file
```

Helpers live in `tests/bootstrap.php`:
- Fixtures (all self-cleaning via `nx_test_finish()`): `nx_test_user($name)`, `nx_test_connection($from, $to, $status)`, `nx_test_thread($a, $b, $conn_id)`, `nx_test_attachment($user_id)` (real 1x1 PNG), `nx_test_track_post($id)`.
- Calling code: `nx_call_ajax($action, $post, $guest = false)` runs a `wp_ajax_*` (or `wp_ajax_nopriv_*` with `$guest`) handler in-process as the current user; `nx_profile_nonce()` / `nx_chat_nonce()` give nonce payloads.
- Assertions: `nx_assert`, `nx_assert_same`, `nx_rejected($reply)`, and `nx_assert_guard_matrix($action, $valid_post, $member, $nonce_fn)` (no nonce / wrong nonce / logged out).
- Environment: `nx_test_capture_mail()` (stops real mail), `nx_test_reset_limits()` (clears rate-limit transients).
- Code that ends in `exit` (redirects, `admin_post_*`) cannot run in-process: run it in a child via `shell_exec('wp eval-file tests/child/<x>.php')` with inputs in env vars (see `tests/child/contact.php`, `tests/child/redirect.php`).

Existing suites double as the **compatibility contract** for the restructure: `test-compat-contract.php` (action/shortcode/CPT/table/option/rewrite names), `test-profile-ajax.php`, `test-chat-ajax.php`, `test-auth-ajax.php`, `test-shortcodes-privacy.php`, `test-access-control.php`. Assertions tagged `[PHASE1]` or `[DECISION]` describe known weaknesses or open product decisions; change them deliberately, in the same commit as the fix, and list the change as user-visible.

## The loop
1. **Pin the behaviour** in one sentence ("a user cannot send a request to someone already connected"). Ask the user if it is ambiguous; don't invent requirements.
2. **Red.** Write the smallest test for it in `tests/php/test-<area>.php`. Run it. Confirm it fails because the *behaviour is missing* (assertion fail), not because of a typo, fatal error or missing fixture. Show the failing output.
3. **Green.** Write the least code that passes. No extra features, no refactors yet.
4. **Run all** (`tests/run.sh`) to catch regressions.
5. **Refactor** with the tests green; re-run after each step.
6. Repeat per behaviour. One behaviour per test, named for the behaviour.
7. Finish with `nexora-release-assets` (`php -l`, version bump, docs) and report which tests were added and their results.

## What to test, and how
| Change | Test with |
| --- | --- |
| AJAX handler (guards, validation, ownership, state change) | `nx_call_ajax()` as different users: owner, other member, logged out, bad nonce. Assert `success`, the stored meta/rows, and that foreign IDs are rejected. |
| Data/model helpers (`Connections\Repository`/`Service`, `Notifications\Repository`, `Chat\Repository`) | Call the method with `nx_test_user()` fixtures; assert returned IDs/rows. Chat/notification tables must already exist (created on activation). |
| Settings, shortcodes | `do_shortcode()` output contains/omits expected text; `get_option` after `update_option` + sanitizer. |
| Pure JS logic (formatting, validation) | Put it in a function with no DOM, test with `node` (`node tests/js/<file>.test.js`, `assert` module). |
| Layout, CSS, DOM interaction | Not unit-testable here: write the acceptance check first as a short list ("at 400px the Send button is inside the viewport"), then verify it in the browser per `verify-like-a-user`. Say it was a manual check. |

## Rules
- Tests run against the **local dev database**. Only use fixtures from the helpers (users named `nxtest_*`), track every extra post/row for cleanup, and never read or edit real members' data. Don't write tests that send real email or call external services (stub with the `pre_wp_mail` filter or skip).
- Never weaken or delete a test to get green; fix the code or ask.
- A test you did not see fail is not evidence. Do not claim TDD was followed unless you ran it red first.
- Schema/rewrite changes still need the manual steps in `nexora-db-table` / `nexora-release-assets` before tests that depend on them.
- Keep tests deterministic: no sleeping, no dependence on existing site content, no reliance on test order.

## Reporting
State for each behaviour: test file, red output summary, green output, and anything untested (browser-only, email, third-party). If the harness itself can't run (e.g. `wp` missing), stop and say so rather than skipping tests.
