#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"

cd "$APP_ROOT"

echo "========================================"
echo " SpotDeals Production Append Import"
echo " Started: $(date)"
echo "========================================"

"$DRUSH" php:script scripts/spotdeals_append_new_csv_rows.php

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

echo
echo "Finished: $(date)"
echo "Done."
