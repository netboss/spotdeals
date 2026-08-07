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
    echo "Restoring protected Geoapify migration rows..."
    "$DRUSH" php:script "$PROTECTION_SCRIPT" -- restore
    PROTECTION_ACTIVE=0
  fi
}

cleanup() {
  local exit_code=$?

  if [ "$PROTECTION_ACTIVE" -eq 1 ]; then
    echo
    echo "Migration interrupted. Restoring protected Geoapify migration rows..."
    "$DRUSH" php:script "$PROTECTION_SCRIPT" -- restore || true
  fi

  exit "$exit_code"
}

trap cleanup EXIT INT TERM

echo "========================================"
echo " SpotDeals Production Full Migration"
echo " Started: $(date)"
echo "========================================"

echo
echo "Clearing cache..."
"$DRUSH" cr

echo
echo "Protecting Geoapify-backed venue and deal migration rows..."
"$DRUSH" php:script "$PROTECTION_SCRIPT" -- protect
PROTECTION_ACTIVE=1

echo
echo "Rolling back CSV-owned food/drink migrations..."
"$DRUSH" migrate:rollback spotdeals_deals -y
"$DRUSH" migrate:rollback spotdeals_venues -y

restore_protected_rows

echo
echo "Importing venues..."
"$DRUSH" migrate:import spotdeals_venues -vvv

echo
echo "Importing deals..."
"$DRUSH" migrate:import spotdeals_deals -vvv

echo
echo "Creating missing Spanish venue and deal translations..."
"$APP_ROOT/scripts/prod/translate-prod.sh"

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
echo "Production migration completed without removing Geoapify-backed venues or deals."
