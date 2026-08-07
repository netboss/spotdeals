#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

echo "========================================"
echo " SpotDeals URL Alias Update"
echo " Started: $(date)"
echo "========================================"

ddev drush pathauto:aliases-generate update canonical_entities:node -y
ddev drush cr

echo
echo "Finished: $(date)"
echo "Done."
