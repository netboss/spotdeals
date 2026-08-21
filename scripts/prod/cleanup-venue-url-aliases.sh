#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DRUSH="$APP_ROOT/vendor/bin/drush"

cd "$APP_ROOT"

echo "========================================"
echo " SpotDeals Historical Venue URL Cleanup"
echo " Started: $(date)"
echo "========================================"

echo
echo "NOTE: This command is DRY RUN unless --apply is passed."
echo

"$DRUSH" spotdeals:cleanup-venue-url-aliases "$@"
"$DRUSH" cr

echo
echo "Finished: $(date)"
echo "Done."
