#!/usr/bin/env bash
set -euo pipefail

PROCESS_PATTERN="${PROCESS_PATTERN:-spotdeals_create_missing_es_node_translations.php}"
SLEEP_SECONDS="${SLEEP_SECONDS:-120}"
WAIT_FOR_PROCESS="${WAIT_FOR_PROCESS:-1}"
WAIT_TIMEOUT_SECONDS="${WAIT_TIMEOUT_SECONDS:-300}"

echo "=== SpotDeals translation binlog purge monitor ==="
echo "Process pattern: $PROCESS_PATTERN"
echo "Sleep seconds: $SLEEP_SECONDS"
echo

if [ "$WAIT_FOR_PROCESS" = "1" ]; then
  waited=0

  while ! pgrep -f "$PROCESS_PATTERN" >/dev/null; do
    if [ "$waited" -ge "$WAIT_TIMEOUT_SECONDS" ]; then
      echo "Translation process did not start within ${WAIT_TIMEOUT_SECONDS}s. Exiting."
      df -h /
      exit 0
    fi

    echo "Waiting for translation process..."
    sleep 5
    waited=$((waited + 5))
  done
fi

while pgrep -f "$PROCESS_PATTERN" >/dev/null; do
  echo "----- $(date) -----"
  df -h /

  CURRENT_BINLOG="$(mysql -N -e "SHOW MASTER STATUS;" | awk '{print $1}')"
  echo "Current binlog: $CURRENT_BINLOG"

  if [ -n "$CURRENT_BINLOG" ]; then
    mysql -e "PURGE BINARY LOGS TO '$CURRENT_BINLOG';"
  fi

  df -h /
  sleep "$SLEEP_SECONDS"
done

echo "Translation process finished. Purge loop stopped automatically."
df -h /
