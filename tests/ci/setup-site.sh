#!/usr/bin/env bash
# Builds the fixture site the test suite runs on, from a clean checkout (used by .github/workflows/tests.yml and by hand to prove it):
#  - two empty databases: the SITE database (named in wp-config.php) and a scratch TEST database (named in NX_TEST_DB), which the
#    test-runner guard requires to be a different one;
#  - wp-config.php, written here and never committed (it is git-ignored);
#  - WordPress itself is already in the repository; WooCommerce and the free ACF are installed from wordpress.org at pinned versions;
#  - the Nexora plugin is activated and the pages it needs are created; then the finished site database is copied into the test database.
# Elementor, Elementor Pro and the other third-party plugins that need a vendor/ folder are NOT installed or activated: the suite does
# not need them. No credential is printed (nothing here runs with `set -x`).
#
# Environment (all required except the versions): CI_DB_HOST, CI_DB_USER, CI_DB_PASSWORD, CI_SITE_DB, CI_TEST_DB, CI_SITE_URL.
set -euo pipefail
cd "$(dirname "$0")/../.."

WOOCOMMERCE_VERSION="${WOOCOMMERCE_VERSION:-11.2.1}"
ACF_VERSION="${ACF_VERSION:-6.8.10}"

for v in CI_DB_HOST CI_DB_USER CI_DB_PASSWORD CI_SITE_DB CI_TEST_DB CI_SITE_URL; do
  if [ -z "${!v:-}" ]; then echo "setup-site.sh: $v is not set" >&2; exit 2; fi
done
if [ "${CI_SITE_DB,,}" = "${CI_TEST_DB,,}" ]; then echo "setup-site.sh: the site and the test database must be different" >&2; exit 2; fi
if [ -e wp-config.php ]; then echo "setup-site.sh: wp-config.php already exists here; refusing to overwrite a real site's configuration" >&2; exit 2; fi

sql() { MYSQL_PWD="$CI_DB_PASSWORD" mysql --host="$CI_DB_HOST" --user="$CI_DB_USER" "$@"; }

echo "== databases"
sql -e "CREATE DATABASE IF NOT EXISTS \`$CI_SITE_DB\` CHARACTER SET utf8mb4; CREATE DATABASE IF NOT EXISTS \`$CI_TEST_DB\` CHARACTER SET utf8mb4;"

echo "== wp-config.php (not committed)"
wp config create --path=. --dbname="$CI_SITE_DB" --dbuser="$CI_DB_USER" --dbpass="$CI_DB_PASSWORD" --dbhost="$CI_DB_HOST" --skip-check --quiet --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
PHP

echo "== WordPress"
ADMIN_PASSWORD="$(openssl rand -hex 16)"
wp core install --path=. --url="$CI_SITE_URL" --title="Nexora CI" --admin_user=ci_admin --admin_password="$ADMIN_PASSWORD" --admin_email=ci-admin@example.test --skip-email --quiet
wp rewrite structure '/%postname%/' --path=. --quiet

echo "== WooCommerce $WOOCOMMERCE_VERSION and ACF $ACF_VERSION (wordpress.org)"
wp plugin install woocommerce --version="$WOOCOMMERCE_VERSION" --force --activate --path=. --quiet
wp plugin install advanced-custom-fields --version="$ACF_VERSION" --force --activate --path=. --quiet

echo "== Nexora"
wp plugin activate nexora --path=. --quiet
for slug in login-page registration-page profile-page; do
  if [ -z "$(wp post list --post_type=page --name="$slug" --field=ID --path=.)" ]; then
    case "$slug" in
      login-page) content='[profile_login]' ;;
      registration-page) content='[profile_registration]' ;;
      *) content='[profile_dashboard]' ;;
    esac
    wp post create --post_type=page --post_status=publish --post_title="$slug" --post_name="$slug" --post_content="$content" --path=. --porcelain >/dev/null
  fi
done
wp rewrite flush --path=. --quiet

echo "== settings the tests assume (the same as the development site)"
# High-performance order storage is on (WooCommerce's default for new stores is not reliable under WP-CLI), the shop currency is the rupee,
# and the captcha has placeholder keys so the captcha tests have something to switch. These are made-up values, not credentials.
wp wc hpos enable --path=. --quiet
wp option update woocommerce_currency INR --path=. --quiet
wp option update woocommerce_default_country 'IN:DL' --path=. --quiet
wp option update recaptcha_enabled 1 --path=. --quiet
wp option update recaptcha_site_key ci-placeholder-site-key --path=. --quiet
wp option update recaptcha_secret_key ci-placeholder-secret-key --path=. --quiet
wp option update default_admin_mail ci-admin@example.test --path=. --quiet

echo "== the scratch test database (a copy of the finished site)"
dump="$(mktemp)"
wp db export "$dump" --path=. --quiet
sql "$CI_TEST_DB" < "$dump"
rm -f "$dump"
echo "== done: site database and test database are ready"
