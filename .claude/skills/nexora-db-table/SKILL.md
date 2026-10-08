---
name: nexora-db-table
description: Create or change a custom database table for the Nexora plugin (notifications or chat schema). Use for any schema change, since tables are only created on plugin activation.
---

# Nexora custom tables

Existing tables (prefix = `$wpdb->prefix`): `nexora_notifications` (`includes/class-notification.php`), `nexora_threads`, `nexora_thread_participants`, `nexora_messages`, `nexora_message_meta` (`chat/class-chat-db.php`). Each class has `create_table()` using `dbDelta()`.

`NEXORA_System::create_new_tables()` in `nexora.php` calls them, but it is hooked to `register_activation_hook` only. **Editing a `CREATE TABLE` does nothing on an already-activated site.**

## Steps
1. Edit the `$sql` in the relevant `create_table()`. Follow `dbDelta` rules: two spaces after `PRIMARY KEY`, one column per line, `KEY` not `INDEX`, use `$wpdb->get_charset_collate()`.
2. A brand-new table: add a class (or method) and call it from `create_new_tables()` in `nexora.php`.
3. Apply to the running site: deactivate and reactivate the Nexora plugin (or run `dbDelta` through a one-off script). `dbDelta` adds columns/indexes but never drops or renames; destructive changes need a manual SQL migration. Warn the user and mention that data lives in the DB, which is not in git.
4. Always query through `$wpdb->prepare()`. Keep queries inside the class that owns the table.
5. Update the table list in `README.md` and `CLAUDE.md`.
