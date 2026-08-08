#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA_DIR="$APP_ROOT/web/modules/custom/spotdeals_import/data/.append"
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

  ddev drush migrate:reset-status spotdeals_venues >/dev/null 2>&1
  ddev drush migrate:reset-status spotdeals_deals >/dev/null 2>&1
  ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=food --action=restore

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Food/Drink Append Import"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=food --strict-format

echo
echo "Preparing append-only food/drink CSVs..."
ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=food --action=prepare

if [[ -f "$VENUES_READY" ]]; then
  echo
  import_all_rows_in_process \
    "spotdeals_venues" \
    "$VENUES_APPEND" \
    "food/drink venue"
else
  echo
  echo "No new food/drink venues to import."
fi

if [[ -f "$DEALS_READY" ]]; then
  echo
  import_all_rows_in_process \
    "spotdeals_deals" \
    "$DEALS_APPEND" \
    "food/drink deal"
else
  echo
  echo "No new food/drink deals to import."
fi

echo
echo "Restoring canonical food/drink migration source paths..."
ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=food --action=restore
trap - EXIT INT TERM

echo
echo "Creating missing Spanish venue and deal translations..."
./scripts/local/translate-local.sh

echo
echo "Updating English and Spanish URL aliases..."
./scripts/local/update-url-alias.sh

echo
echo "Reindexing deals_solr..."
ddev drush search-api:index deals_solr

ddev drush cr

echo
echo "Done. New food/drink English and Spanish nodes were processed, URL aliases were updated, and search was reindexed."
