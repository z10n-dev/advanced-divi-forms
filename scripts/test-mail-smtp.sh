#!/usr/bin/env bash
# Run the real SMTP delivery suite against a local sink. Disposable site only.
#
#   WP=/path/to/wp-wrapper ADF_TEST_PATH=tests/mail-smtp.php \
#     bash scripts/test-mail-smtp.sh [extra wp arguments]
#
# In CI, WP defaults to `wp` and the site path is passed as an argument. Set
# ADF_SMTP_CAPTURE_ROOT when WordPress runs in a container that must see the
# capture directory at the same absolute path.
set -euo pipefail

WP_CMD=${WP:-wp}
TEST_PATH=${ADF_TEST_PATH:-tests/mail-smtp.php}
PORT=${ADF_SMTP_PORT:-2525}
SINK_BASE=${ADF_SMTP_CAPTURE_ROOT:-$(mktemp -d "${TMPDIR:-/tmp}/adf-smtp.XXXXXX")}
CAPTURE="$SINK_BASE/capture"
mkdir -p "$CAPTURE"
SINK_LOG="$SINK_BASE/sink.log"
RUN_LOG="$SINK_BASE/run.log"

cleanup() {
  local status=$?
  if [[ -n "${SINK_PID:-}" ]]; then
    kill "$SINK_PID" 2>/dev/null || true
    wait "$SINK_PID" 2>/dev/null || true
  fi
  if [[ $status -ne 0 && -f "$RUN_LOG" ]]; then
    echo "--- suite output ---"
    cat "$RUN_LOG"
    echo "--- sink log ---"
    cat "$SINK_LOG" 2>/dev/null || true
    if compgen -G "$CAPTURE/msg-*.eml" >/dev/null; then
      echo "--- captured messages ---"
      for message in "$CAPTURE"/msg-*.eml; do
        echo "[$message]"
        head -40 "$message"
      done
    fi
  fi
  rm -rf -- "$SINK_BASE"
  exit "$status"
}
trap cleanup EXIT

python3 "$(dirname "$0")/../tests/smtp-sink.py" --port "$PORT" --out "$CAPTURE" >"$SINK_LOG" 2>&1 &
SINK_PID=$!

for _ in $(seq 1 50); do
  if grep -q 'LISTENING' "$SINK_LOG" 2>/dev/null; then
    break
  fi
  sleep 0.2
done
if ! grep -q 'LISTENING' "$SINK_LOG" 2>/dev/null; then
  echo "::error::The local SMTP sink did not start"
  exit 1
fi

ADF_SMTP_PORT="$PORT" ADF_SMTP_CAPTURE="$CAPTURE" \
  $WP_CMD "$@" eval-file "$TEST_PATH" 2>&1 | tee "$RUN_LOG"

if ! grep -q '^Success:' "$RUN_LOG"; then
  echo "::error::The SMTP suite did not report success"
  exit 1
fi

# The WordPress fatal handler exits 0 even on a crash, so also fail on its text.
if grep -Eiq 'Fatal error|Uncaught|There has been a critical error' "$RUN_LOG"; then
  echo "::error::The SMTP suite reported a PHP error"
  exit 1
fi
