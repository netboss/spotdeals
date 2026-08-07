#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROTECTION_SCRIPT="scripts/spotdeals_protect_geoapify_migration_rows.php"
PROTECTION_ACTIVE=0

cd "$APP_ROOT"

restore_protected_rows() {
  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Restoring protected Geoapify migration rows..."
    ddev drush php:script "$PROTECTION_SCRIPT" -- restore
    PROTECTION_ACTIVE=0
  fi
}

cleanup() {
  local exit_code=$?

  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Migration interrupted. Restoring protected Geoapify migration rows..."
    ddev drush php:script "$PROTECTION_SCRIPT" -- restore || true
  fi

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Food/Drink Full Migration"
echo "========================================"

ddev drush cr

echo
echo "Protecting Geoapify-backed venue and deal migration rows..."
ddev drush php:script "$PROTECTION_SCRIPT" -- protect
PROTECTION_ACTIVE=1

echo
echo "Rolling back CSV-owned food/drink migrations..."
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

restore_protected_rows

echo
echo "Importing food/drink venues and deals..."
ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

echo
echo "Creating missing Spanish venue and deal translations..."
"$APP_ROOT/scripts/local/translate-local.sh"

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
echo "Done. Food/drink content was migrated without removing Geoapify-backed venues or deals."
