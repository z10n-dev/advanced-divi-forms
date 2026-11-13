#!/usr/bin/env bash
set -euo pipefail

# Audit the plugin's admin screens with axe-core through real Chromium.
# Requires Node modules playwright and axe-core to be resolvable, and a
# disposable database: the script installs WordPress and seeds synthetic answers.
#
# ADF_PHP_SERVER overrides the web server command (default: php -S). It must keep
# the same site URL the WordPress install uses.
: "${WP_DB_HOST:?Set WP_DB_HOST to the temporary database host}"
: "${WP_DB_NAME:?Set WP_DB_NAME to the temporary database name}"
: "${WP_DB_USER:?Set WP_DB_USER to the temporary database user}"
: "${WP_DB_PASSWORD:?Set WP_DB_PASSWORD to the temporary database password}"

PLUGIN_ZIP=$(realpath "${1:-dist/advanced-divi-forms.zip}")
PORT=${ADF_A11Y_PORT:-8767}
BASE_URL="http://127.0.0.1:$PORT"
REPO_DIR=$(dirname "$(realpath "$0")")/..
WP_DIR=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/adf-a11y.XXXXXX")
FIXTURE_DIR=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/adf-a11y-fixture.XXXXXX")
SERVER_PID=""

cleanup() {
  local status=$?
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [[ $status -ne 0 && -f "$FIXTURE_DIR/server.log" ]]; then
    tail -40 "$FIXTURE_DIR/server.log"
  fi
  rm -rf -- "$WP_DIR" "$FIXTURE_DIR"
  exit "$status"
}
trap cleanup EXIT

if ! node -e "require.resolve('playwright'); require.resolve('axe-core')" >/dev/null 2>&1; then
  echo "::error::Install the Node modules playwright and axe-core before running this audit"
  exit 1
fi
if curl -s -o /dev/null --max-time 2 "$BASE_URL/"; then
  echo "::error::Something already answers on $BASE_URL; stop it before running the audit"
  exit 1
fi

wp_a11y() {
  wp --path="$WP_DIR" "$@"
}

wp_a11y core download --version="${WP_VERSION:-latest}" --locale=en_US
wp_a11y config create \
  --dbname="$WP_DB_NAME" \
  --dbuser="$WP_DB_USER" \
  --dbpass="$WP_DB_PASSWORD" \
  --dbhost="$WP_DB_HOST" \
  --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISABLE_WP_CRON', true );
PHP

wp_a11y core install \
  --url="$BASE_URL" \
  --title='Advanced Divi Forms accessibility' \
  --admin_user=adf-admin \
  --admin_password="$(php -r 'echo bin2hex(random_bytes(24));')" \
  --admin_email=adf@example.org \
  --skip-email

wp_a11y plugin install "$PLUGIN_ZIP" --activate
wp_a11y plugin is-active advanced-divi-forms

SEED_OUTPUT=$(wp_a11y eval-file "$REPO_DIR/tests/fixtures/accessibility-seed.php")
SEED_JSON=$(printf '%s\n' "$SEED_OUTPUT" | awk '/^\{/{print; exit}')
if [[ -z "$SEED_JSON" ]]; then
  echo "::error::The accessibility fixtures could not be created"
  printf '%s\n' "$SEED_OUTPUT"
  exit 1
fi
COOKIE_OUTPUT=$(wp_a11y eval-file "$REPO_DIR/tests/fixtures/accessibility-cookies.php")
printf '%s\n' "$COOKIE_OUTPUT" | awk '/^\{/{print; exit}' >"$FIXTURE_DIR/cookies.json"

read -r FORM_ID SUBMISSION_ID TEMPLATE_ID <<<"$(SEED_JSON="$SEED_JSON" php -r '
$data = json_decode(getenv("SEED_JSON"), true);
printf("%d %d %d", $data["form"] ?? 0, $data["submissions"][0] ?? 0, $data["template"] ?? 0);
')"
if [[ "$FORM_ID" == "0" || "$SUBMISSION_ID" == "0" || "$TEMPLATE_ID" == "0" ]]; then
  echo "::error::The accessibility fixtures are incomplete: $SEED_JSON"
  exit 1
fi

SERVER_CMD=${ADF_PHP_SERVER:-}
if [[ -z "$SERVER_CMD" ]]; then
  SERVER_CMD='php -S 127.0.0.1:{{port}} -t {{dir}}'
fi
SERVER_CMD=${SERVER_CMD//\{\{port\}\}/$PORT}
SERVER_CMD=${SERVER_CMD//\{\{dir\}\}/$WP_DIR}
IFS=' ' read -r -a PHP_SERVER <<<"$SERVER_CMD"
"${PHP_SERVER[@]}" >"$FIXTURE_DIR/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  if curl -s -o /dev/null --max-time 2 "$BASE_URL/"; then
    break
  fi
  sleep 0.2
done

cd "$REPO_DIR"
node tests/admin-accessibility.js \
  --fixture "$FIXTURE_DIR/cookies.json" \
  --base-url "$BASE_URL" \
  --form "$FORM_ID" \
  --submission "$SUBMISSION_ID" \
  --template "$TEMPLATE_ID"
