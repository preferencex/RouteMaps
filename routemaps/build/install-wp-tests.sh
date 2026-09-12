#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-6.8.3}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WC_DIR="${WC_DIR:-$WP_CORE_DIR/wp-content/plugins/woocommerce}"

for cmd in curl svn tar unzip mysqladmin; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing required command: $cmd" >&2; exit 1; }
done

rm -rf "$WP_TESTS_DIR" "$WP_CORE_DIR" /tmp/woocommerce.zip
mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR/wp-content/plugins"

curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wordpress.tar.gz
tar -xzf /tmp/wordpress.tar.gz -C /tmp
if [[ -d /tmp/wordpress && /tmp/wordpress != "$WP_CORE_DIR" ]]; then
  rm -rf "$WP_CORE_DIR"
  mv /tmp/wordpress "$WP_CORE_DIR"
fi

svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data" "$WP_TESTS_DIR/data"
curl -fsSL "https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php" -o "$WP_TESTS_DIR/wp-tests-config.php"

sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "$WP_TESTS_DIR/wp-tests-config.php"

curl -fsSL 'https://downloads.wordpress.org/plugin/woocommerce.10.3.5.zip' -o /tmp/woocommerce.zip
unzip -q /tmp/woocommerce.zip -d "$WP_CORE_DIR/wp-content/plugins"

for i in {1..30}; do
  if mysqladmin ping -h"${DB_HOST%%:*}" -u"$DB_USER" -p"$DB_PASS" --silent; then
    break
  fi
  sleep 1
done
mysqladmin ping -h"${DB_HOST%%:*}" -u"$DB_USER" -p"$DB_PASS" --silent

printf 'WP_TESTS_DIR=%s\n' "$WP_TESTS_DIR"
printf 'WC_PLUGIN_FILE=%s\n' "$WC_DIR/woocommerce.php"
