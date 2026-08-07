#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
DRUSH="${DRUSH:-$APP_ROOT/vendor/bin/drush}"

TYPE="${TYPE:-all}"
BATCH_LIMIT="${BATCH_LIMIT:-500}"
CHUNK_SIZE="${CHUNK_SIZE:-10}"
PROGRESS_EVERY="${PROGRESS_EVERY:-25}"
MIN_FREE_GB="${MIN_FREE_GB:-10}"
MAX_RUNTIME="${MAX_RUNTIME:-5400}"
SLEEP_SECONDS="${SLEEP_SECONDS:-0}"

PURGE_SCRIPT="$APP_ROOT/scripts/prod/translate-prod-purge.sh"
PO_FILE="${PO_FILE:-$APP_ROOT/translations/es_updated.po}"

PURGE_PID=""

cd "$APP_ROOT"

if [ ! -x "$DRUSH" ]; then
  echo "ERROR: Drush executable not found or not executable: $DRUSH"
  exit 1
fi

cleanup() {
  echo
  echo "=== Cleaning up bulk translation state ==="

  if [ -n "${PURGE_PID:-}" ]; then
    kill "$PURGE_PID" 2>/dev/null || true
    wait "$PURGE_PID" 2>/dev/null || true
  fi

  "$DRUSH" state:delete spotdeals_bulk_operation_active || true
  "$DRUSH" state:delete spotdeals_suppress_admin_notifications || true
  "$DRUSH" state:delete spotdeals_suppress_owner_notifications || true
  "$DRUSH" state:delete spotdeals_disable_admin_notifications || true
}

trap cleanup EXIT INT TERM

echo "=== SpotDeals Spanish Translation Finalizer ==="
echo "App root: $APP_ROOT"
echo "Drush: $DRUSH"
echo "Type: $TYPE"
echo "Batch limit: $BATCH_LIMIT"
echo "Chunk size: $CHUNK_SIZE"
echo "Progress every: $PROGRESS_EVERY"
echo "Minimum free disk required: ${MIN_FREE_GB}G"
echo "Max runtime per translation pass: ${MAX_RUNTIME}s"
echo "PO file: $PO_FILE"
echo

echo "=== Enabling bulk translation suppression ==="
"$DRUSH" state:set spotdeals_bulk_operation_active 1
"$DRUSH" state:set spotdeals_suppress_admin_notifications 1
"$DRUSH" state:set spotdeals_suppress_owner_notifications 1
"$DRUSH" state:set spotdeals_disable_admin_notifications 1

check_disk() {
  local available_kb
  local available_gb

  available_kb="$(df --output=avail / | tail -1 | tr -d ' ')"
  available_gb="$((available_kb / 1024 / 1024))"

  echo "Free disk: ${available_gb}G"

  if [ "$available_gb" -lt "$MIN_FREE_GB" ]; then
    echo "ERROR: Free disk is below ${MIN_FREE_GB}G. Aborting."
    df -h /
    exit 1
  fi
}

missing_count() {
  if [ "$TYPE" = "all" ]; then
    "$DRUSH" sqlq "
SELECT COUNT(*)
FROM node_field_data n
WHERE n.langcode = 'en'
  AND n.type IN ('venue', 'deal')
  AND NOT EXISTS (
    SELECT 1
    FROM node_field_data e
    WHERE e.nid = n.nid
      AND e.langcode = 'es'
  );
"
  else
    "$DRUSH" sqlq "
SELECT COUNT(*)
FROM node_field_data n
WHERE n.langcode = 'en'
  AND n.type = '$TYPE'
  AND NOT EXISTS (
    SELECT 1
    FROM node_field_data e
    WHERE e.nid = n.nid
      AND e.langcode = 'es'
  );
"
  fi
}

start_purge_monitor() {
  if [ ! -x "$PURGE_SCRIPT" ]; then
    echo "ERROR: Purge script not found or not executable: $PURGE_SCRIPT"
    exit 1
  fi

  DRUSH="$DRUSH" WAIT_FOR_PROCESS=1 "$PURGE_SCRIPT" &
  PURGE_PID="$!"

  echo "Started purge monitor PID: $PURGE_PID"
}

wait_for_purge_monitor() {
  if [ -n "${PURGE_PID:-}" ]; then
    wait "$PURGE_PID" || true
    PURGE_PID=""
  fi
}

run_translation_pass() {
  local limit="$1"
  local type_args=()

  if [ "$TYPE" != "all" ]; then
    type_args+=(--type="$TYPE")
  fi

  start_purge_monitor

  "$DRUSH" php:script scripts/spotdeals_create_missing_es_node_translations.php -- \
    "${type_args[@]}" \
    --limit="$limit" \
    --chunk-size="$CHUNK_SIZE" \
    --progress-every="$PROGRESS_EVERY" \
    --min-free-gb="$MIN_FREE_GB" \
    --max-runtime="$MAX_RUNTIME" \
    --sleep="$SLEEP_SECONDS"

  wait_for_purge_monitor
}

generate_spanish_aliases() {
  echo
  echo "=== Generating Spanish node URL aliases ==="

  if "$DRUSH" list --format=list | grep -qx "pathauto:generate"; then
    "$DRUSH" pathauto:generate --language=es node
  else
    "$DRUSH" pathauto:aliases-generate update canonical_entities:node -y
  fi
}

import_po_file() {
  if [ -f "$PO_FILE" ]; then
    echo
    echo "=== Importing Spanish PO file ==="

    "$DRUSH" locale:import es "$PO_FILE" \
      --type=customized \
      --override=all \
      -y
  else
    echo
    echo "WARNING: PO file not found, skipping import: $PO_FILE"
  fi
}

check_disk

while true; do
  REMAINING="$(missing_count | tr -d '[:space:]')"

  echo
  echo "Missing Spanish translations: $REMAINING"

  if [ "$REMAINING" -eq 0 ]; then
    break
  fi

  LIMIT="$BATCH_LIMIT"

  if [ "$REMAINING" -lt "$LIMIT" ]; then
    LIMIT="$REMAINING"
  fi

  check_disk

  echo "=== Creating next Spanish translation batch: $LIMIT ==="
  run_translation_pass "$LIMIT"
done

echo
echo "=== Verifying Spanish translations ==="

FINAL_REMAINING="$(missing_count | tr -d '[:space:]')"

echo "Missing Spanish translations after run: $FINAL_REMAINING"

if [ "$FINAL_REMAINING" -ne 0 ]; then
  echo "ERROR: Some Spanish translations are still missing."
  exit 1
fi

generate_spanish_aliases
import_po_file

echo
echo "=== Clearing Drupal cache ==="
"$DRUSH" cr

echo
echo "=== Final disk check ==="
df -h /

echo
echo "=== Done: Spanish translations finalized successfully ==="
