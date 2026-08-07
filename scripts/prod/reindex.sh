#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"

cd "$APP_ROOT"

echo "Clearing Solr index..."
"$DRUSH" search-api:clear deals_solr

echo "Reindexing Solr..."
"$DRUSH" search-api:index deals_solr

echo "Done."
