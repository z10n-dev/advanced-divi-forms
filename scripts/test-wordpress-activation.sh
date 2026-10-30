#!/usr/bin/env bash
set -euo pipefail

# Use only a disposable database: WordPress will create tables in it.
: "${WP_DB_HOST:?Set WP_DB_HOST to the temporary database host}"
: "${WP_DB_NAME:?Set WP_DB_NAME to the temporary database name}"
: "${WP_DB_USER:?Set WP_DB_USER to the temporary database user}"
: "${WP_DB_PASSWORD:?Set WP_DB_PASSWORD to the temporary database password}"

PLUGIN_ZIP=$(realpath "${1:-dist/advanced-divi-forms.zip}")
test -f "$PLUGIN_ZIP"
WP_DIR=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/adf-wordpress.XXXXXX")

cleanup() {
  local status=$?
  if [[ -f "$WP_DIR/wp-content/debug.log" ]]; then
    cat "$WP_DIR/wp-content/debug.log"
  fi
  rm -rf -- "$WP_DIR"
  exit "$status"
}
trap cleanup EXIT

wp_test() {
  wp --path="$WP_DIR" "$@"
}

wp_test core download --version="${WP_VERSION:-latest}" --locale=en_US
wp_test config create \
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

wp_test core install \
  --url=http://wordpress.test \
  --title='Advanced Divi Forms CI' \
  --admin_user=ci-admin \
  --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" \
  --admin_email=ci@example.org \
  --skip-email

wp_test plugin install "$PLUGIN_ZIP" --activate
wp_test plugin is-active advanced-divi-forms

# A separate process must also be able to load the now-active plugin.
wp_test eval '
if ( ! defined( "ADF_PLUGIN_VERSION" ) || ! function_exists( "adf_check_github_update" ) ) {
    WP_CLI::error( "The active plugin did not load." );
}
WP_CLI::success( "Plugin activation and subsequent WordPress bootstrap passed." );
'

# Exercise the installed release ZIP against real WordPress APIs.
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/admin-integration.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/mail-summary.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/template-preview.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/template-preview-controller.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/github-update-integration.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-actions.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-controller.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-page.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-filters.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-trash.php"
wp_test --exec='define("EMPTY_TRASH_DAYS",0);' eval-file "$(dirname "$(realpath "$0")")/../tests/submission-trash.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/submission-export.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/divi-integration.php"
wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/divi-discovery.php"

# Optional local vendor test. The licensed source is never bundled or uploaded.
if [[ -n "${ADF_DIVI_THEME_DIR:-}" ]]; then
  test -f "$ADF_DIVI_THEME_DIR/style.css"
  cp -r "$ADF_DIVI_THEME_DIR" "$WP_DIR/wp-content/themes/Divi"
  wp_test theme activate Divi
  wp_test eval-file "$(dirname "$(realpath "$0")")/../tests/divi-live.php"
fi

if [[ -f "$WP_DIR/wp-content/debug.log" ]] &&
   grep -Eiq 'PHP (Fatal error|Parse error|Recoverable fatal error)|Uncaught (Error|Exception)' "$WP_DIR/wp-content/debug.log"; then
  echo "::error::Fatal PHP error found in the WordPress debug log"
  exit 1
fi
