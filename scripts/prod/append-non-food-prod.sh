#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"

cd "$APP_ROOT"

echo "========================================"
echo " SpotDeals Non-Food Production Append Import"
echo " Started: $(date)"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Appending new non-food venues and deals..."
"$DRUSH" php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=non-food

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

echo
echo "Finished: $(date)"
echo "Done. New non-food English and Spanish nodes were processed."
