#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROTECTION_SCRIPT="scripts/spotdeals_protect_geoapify_migration_rows.php"
PROTECTION_ACTIVE=0

cd "$APP_ROOT"

restore_protected_rows() {
  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Restoring protected Geoapify non-food migration rows..."
    ddev drush php:script "$PROTECTION_SCRIPT" -- restore non-food
    PROTECTION_ACTIVE=0
  fi
}

cleanup() {
  local exit_code=$?

  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Migration interrupted. Restoring protected Geoapify non-food migration rows..."
    ddev drush php:script "$PROTECTION_SCRIPT" -- restore non-food || true
  fi

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Non-Food Full Migration"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Protecting Geoapify-backed non-food venue and deal migration rows..."
ddev drush php:script "$PROTECTION_SCRIPT" -- protect non-food
PROTECTION_ACTIVE=1

echo
echo "Rolling back only the non-food migrations..."
ddev drush migrate:rollback spotdeals_non_food_deals
ddev drush migrate:rollback spotdeals_non_food_venues

restore_protected_rows

echo
echo "Importing non-food venues and deals..."
ddev drush migrate:import spotdeals_non_food_venues -vvv
ddev drush migrate:import spotdeals_non_food_deals -vvv

echo
echo "Creating Spanish translations for imported non-food nodes..."
ddev drush php:script scripts/spotdeals_create_non_food_es_translations.php

echo
echo "Updating English and Spanish URL aliases..."
"$APP_ROOT/scripts/local/update-url-alias.sh"

echo
echo "Reindexing deals_solr..."
ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr

trap - EXIT INT TERM

echo
echo "Done. Non-food English and Spanish nodes were created."
echo "The original spotdeals_venues and spotdeals_deals migrations were not touched."
