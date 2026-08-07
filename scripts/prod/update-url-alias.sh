#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"

cd "$APP_ROOT"

echo "========================================"
echo " SpotDeals URL Alias Update"
echo " Started: $(date)"
echo "========================================"

"$DRUSH" pathauto:aliases-generate update canonical_entities:node -y
"$DRUSH" cr

echo
echo "Finished: $(date)"
echo "Done."
