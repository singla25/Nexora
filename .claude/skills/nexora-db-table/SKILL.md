---
name: nexora-db-table
description: Create or change a custom database table for the Nexora plugin (notifications or chat schema). Use for any schema change; `Database\Migrations` applies it on the next request once `DB_VERSION` is bumped.
---

# Nexora custom tables

Existing tables (prefix = `$wpdb->prefix`): `nexora_notifications` (`Notifications\Repository`), `nexora_threads`, `nexora_thread_participants`, `nexora_messages`, `nexora_message_meta` (`Chat\Repository`). Each class has `create_table()` using `dbDelta()`.

`Database\Migrations::sync_schema()` calls them (on activation, and on `plugins_loaded` whenever the stored `nexora_db_version` is older than `Migrations::DB_VERSION`). **Editing a `CREATE TABLE` takes effect only after you bump `DB_VERSION`.** A new table or data location also goes in `Database\Uninstaller::defaults()`.

## Steps
1. Edit the `$sql` in the relevant `create_table()`. Follow `dbDelta` rules: two spaces after `PRIMARY KEY`, one column per line, `KEY` not `INDEX`, use `$wpdb->get_charset_collate()`.
2. A brand-new table: add a Repository class (or method) and call it from `Database\Migrations::sync_schema()`.
3. Bump `Migrations::DB_VERSION`; the site upgrades itself on the next request (`test-lifecycle.php` checks idempotence). `dbDelta` adds columns/indexes but never drops or renames; destructive changes need a manual SQL migration. Warn the user and mention that data lives in the DB, which is not in git.
4. Always query through `$wpdb->prepare()`. Keep queries inside the Repository that owns the table.
5. Tests run against the dev DB: tracked fixtures only (`nx_test_thread()` etc.), never edit real rows.
6. Update the table list in `README.md` and `CLAUDE.md`.
