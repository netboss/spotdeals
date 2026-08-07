#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals Non-Food Full Migration"
echo "========================================"

echo
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "Rolling back only the non-food migrations..."
ddev drush migrate:rollback spotdeals_non_food_deals
ddev drush migrate:rollback spotdeals_non_food_venues

echo
echo "Importing non-food venues and deals..."
ddev drush migrate:import spotdeals_non_food_venues -vvv
ddev drush migrate:import spotdeals_non_food_deals -vvv

echo
echo "Creating Spanish translations for imported non-food nodes..."
ddev drush php:script scripts/spotdeals_create_non_food_es_translations.php

echo
echo "Reindexing deals_solr..."
ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr

echo
echo "Done. Non-food English and Spanish nodes were created."
echo "The original spotdeals_venues and spotdeals_deals migrations were not touched."
