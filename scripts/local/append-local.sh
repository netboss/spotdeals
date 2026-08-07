#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals Food/Drink Append Import"
echo "========================================"

ddev drush php:script scripts/spotdeals_append_new_csv_rows.php

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
