#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals Local Reindex"
echo "========================================"

ddev drush cr
ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr
ddev drush cr

echo
echo "Done."
