#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"
PROTECTION_SCRIPT="$APP_ROOT/scripts/spotdeals_protect_geoapify_migration_rows.php"
PROTECTION_ACTIVE=0

cd "$APP_ROOT"

restore_protected_rows() {
  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Restoring protected Geoapify non-food migration rows..."
    "$DRUSH" php:script "$PROTECTION_SCRIPT" -- restore non-food
    PROTECTION_ACTIVE=0
  fi
}

cleanup() {
  local exit_code=$?

  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Migration interrupted. Restoring protected Geoapify non-food migration rows..."
    "$DRUSH" php:script "$PROTECTION_SCRIPT" -- restore non-food || true
  fi

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Non-Food Production Full Migration"
echo " Started: $(date)"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Clearing cache..."
"$DRUSH" cr

echo
echo "Protecting Geoapify-backed non-food venue and deal migration rows..."
"$DRUSH" php:script "$PROTECTION_SCRIPT" -- protect non-food
PROTECTION_ACTIVE=1

echo
echo "Rolling back only the non-food migrations..."
"$DRUSH" migrate:rollback spotdeals_non_food_deals -y
"$DRUSH" migrate:rollback spotdeals_non_food_venues -y

restore_protected_rows

echo
echo "Importing non-food venues..."
"$DRUSH" migrate:import spotdeals_non_food_venues -vvv

echo
echo "Importing non-food deals..."
"$DRUSH" migrate:import spotdeals_non_food_deals -vvv

echo
echo "Creating Spanish translations for imported non-food nodes..."
"$DRUSH" php:script scripts/spotdeals_create_non_food_es_translations.php

echo
echo "Updating English and Spanish URL aliases..."
"$APP_ROOT/scripts/prod/update-url-alias.sh"

echo
echo "Reindexing Solr..."
"$APP_ROOT/scripts/prod/reindex.sh"

"$DRUSH" cr

trap - EXIT INT TERM

echo
echo "Finished: $(date)"
echo "Done. Non-food content was migrated without touching the food/drink migrations or removing Geoapify-backed non-food content."
