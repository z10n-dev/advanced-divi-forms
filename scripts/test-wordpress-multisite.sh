#!/usr/bin/env bash
set -euo pipefail

# Build a disposable multisite network from the tested ZIP and run the network
# suite. Never point this at a real installation: it creates sites and deletes
# plugin-owned content.
: "${WP_DB_HOST:?Set WP_DB_HOST to the temporary database host}"
: "${WP_DB_NAME:?Set WP_DB_NAME to the temporary database name}"
: "${WP_DB_USER:?Set WP_DB_USER to the temporary database user}"
: "${WP_DB_PASSWORD:?Set WP_DB_PASSWORD to the temporary database password}"

PLUGIN_ZIP=$(realpath "${1:-dist/advanced-divi-forms.zip}")
test -f "$PLUGIN_ZIP"
WP_DIR=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/adf-multisite.XXXXXX")

cleanup() {
  local status=$?
  if [[ -f "$WP_DIR/wp-content/debug.log" ]]; then
    cat "$WP_DIR/wp-content/debug.log"
  fi
  rm -rf -- "$WP_DIR"
  exit "$status"
}
trap cleanup EXIT

wp_ms() {
  wp --path="$WP_DIR" "$@"
}

wp_ms core download --version="${WP_VERSION:-latest}" --locale=en_US
wp_ms config create \
  --dbname="$WP_DB_NAME" \
  --dbuser="$WP_DB_USER" \
  --dbpass="$WP_DB_PASSWORD" \
  --dbhost="$WP_DB_HOST" \
  --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP

wp_ms core multisite-install \
  --url=http://wordpress.test \
  --title='Advanced Divi Forms multisite' \
  --admin_user=ci-admin \
  --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" \
  --admin_email=ci@example.org \
  --skip-email \
  --subdomains=false

wp_ms plugin install "$PLUGIN_ZIP" --activate-network
wp_ms eval 'if ( ! is_plugin_active_for_network( "advanced-divi-forms/advanced-divi-forms.php" ) ) { WP_CLI::error( "The plugin is not network-active." ); }'

wp_ms eval-file "$(dirname "$(realpath "$0")")/../tests/multisite.php"

if [[ -f "$WP_DIR/wp-content/debug.log" ]] &&
   grep -Eiq 'PHP (Fatal error|Parse error|Recoverable fatal error)|Uncaught (Error|Exception)' "$WP_DIR/wp-content/debug.log"; then
  echo "::error::Fatal PHP error found in the WordPress debug log"
  exit 1
fi
