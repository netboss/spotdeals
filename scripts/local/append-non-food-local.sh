#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals Non-Food Append Import"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Appending new non-food venues and deals..."
ddev drush php:script scripts/spotdeals_append_new_csv_rows.php -- --dataset=non-food

echo
echo "Creating Spanish translations for imported non-food nodes..."
ddev drush php:script scripts/spotdeals_create_non_food_es_translations.php

echo
echo "Indexing translated non-food content..."
ddev drush search-api:index deals_solr
ddev drush cr

echo
echo "Done. New non-food English and Spanish nodes were processed."
