# Docker setup (alternative to XAMPP)

Apache + PHP 8.3 + MariaDB 10.11, with WP-CLI and Composer in the web image. The repository is bind-mounted, so edits show up at once.
The host's `wp-config.php` (XAMPP) is not touched: `docker/wp-config.docker.php` is mounted over it inside the container only.

Site: http://localhost:8080/nexora/ (change with `NEXORA_PORT`). Database from the host: `127.0.0.1:3307`, user/password/db `nexora`.

## First start

```
docker compose up -d --build
```

The database is created from every `.sql` file in `docker/db-init/` (git-ignored, put a `mysqldump` of your site there as `10-nexora.sql`):

```
mysqldump -uroot --single-transaction nexora > docker/db-init/10-nexora.sql
```

An imported dump holds `http://localhost/nexora` URLs. Rewrite them once:

```
docker compose exec -u www-data web wp search-replace 'http://localhost/nexora' 'http://localhost:8080/nexora' --all-tables --skip-columns=guid
docker compose exec -u www-data web wp search-replace 'http:\/\/localhost\/nexora' 'http:\/\/localhost:8080\/nexora' --all-tables --skip-columns=guid
```

The import runs only when the `nexora-db` volume is new. To start over: `docker compose down -v` (deletes the Docker database only).

## Everyday commands

Run these as `www-data` so files keep the right owner.

| Task | Command |
|---|---|
| WP-CLI | `docker compose exec -u www-data web wp ...` |
| Coding standards | `docker compose exec -u www-data web bash -c 'cd wp-content/plugins/nexora && composer install && vendor/bin/phpcs'` |
| Tests (scratch DB) | see below |
| Logs | `docker compose logs -f web` |
| Stop | `docker compose down` |

## Tests

Tests must never run on the site database. Make a scratch copy (the `nexora` user may create databases whose names start with `nexora`):

```
docker compose exec db bash -c 'mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS nexora_test" && mariadb-dump -uroot -proot nexora | mariadb -uroot -proot nexora_test'
docker compose exec -u www-data -e NX_TEST_DB=nexora_test web tests/run.sh
```

Tests that make real web requests only see the live database, so add `-e NX_SKIP_LIVE=1` for a copy.

## XAMPP and Docker together

XAMPP uses port 80 and its own database; Docker uses 8080 and its own volume. They do not share data. Run `docker compose down` before editing the same files through both if you want to be sure caches match.
