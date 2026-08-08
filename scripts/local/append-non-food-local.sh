#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA_DIR="$APP_ROOT/web/modules/custom/spotdeals_import/data/non_food/.append"
VENUES_READY="$DATA_DIR/venues.ready"
DEALS_READY="$DATA_DIR/deals.ready"
VENUES_APPEND="$DATA_DIR/venues.append.csv"
DEALS_APPEND="$DATA_DIR/deals.append.csv"

cd "$APP_ROOT"

csv_data_row_count() {
  php -r '
    $handle = fopen($argv[1], "rb");
    if ($handle === false) {
      fwrite(STDERR, "Unable to open CSV: {$argv[1]}\n");
      exit(1);
    }
    fgetcsv($handle);
    $count = 0;
    while (fgetcsv($handle) !== false) {
      $count++;
    }
    fclose($handle);
    echo $count;
  ' "$1"
}

import_all_rows_in_process() {
  local migration_id="$1"
  local csv_file="$2"
  local label="$3"
  local total

  total="$(csv_data_row_count "$csv_file")"

  if [[ "$total" -eq 0 ]]; then
    echo "No $label rows to import."
    return
  fi

  echo "Importing $total $label row(s) in one migration process..."
  ddev drush migrate:reset-status "$migration_id" >/dev/null 2>&1 || true
  ddev drush migrate:import "$migration_id"
}

cleanup() {
  local exit_code=$?
  trap - EXIT INT TERM
  set +e

  ddev drush migrate:reset-status spotdeals_non_food_venues >/dev/null 2>&1
  ddev drush migrate:reset-status spotdeals_non_food_deals >/dev/null 2>&1
  ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=non-food --action=restore

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Non-Food Append Import"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Preparing append-only non-food CSVs..."
ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=non-food --action=prepare

if [[ -f "$VENUES_READY" ]]; then
  echo
  import_all_rows_in_process \
    "spotdeals_non_food_venues" \
    "$VENUES_APPEND" \
    "non-food venue"
else
  echo
  echo "No new non-food venues to import."
fi

if [[ -f "$DEALS_READY" ]]; then
  echo
  import_all_rows_in_process \
    "spotdeals_non_food_deals" \
    "$DEALS_APPEND" \
    "non-food deal"
else
  echo
  echo "No new non-food deals to import."
fi

echo
echo "Restoring canonical non-food migration source paths..."
ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=non-food --action=restore
trap - EXIT INT TERM

echo
echo "Creating Spanish translations for imported non-food nodes..."
ddev drush php:script scripts/spotdeals_create_non_food_es_translations.php

echo
echo "Indexing translated non-food content..."
ddev drush search-api:index deals_solr
ddev drush cr

echo
echo "Done. New non-food English and Spanish nodes were processed."
