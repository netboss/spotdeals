#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals Historical Venue URL Cleanup"
echo " Started: $(date)"
echo "========================================"

echo
echo "NOTE: This command is DRY RUN unless --apply is passed."
echo

ddev drush spotdeals:cleanup-venue-url-aliases "$@"
ddev drush cr

echo
echo "Finished: $(date)"
echo "Done."
