#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$APP_ROOT"

echo "========================================"
echo " SpotDeals CSV Validation"
echo " Environment: production"
echo "========================================"

echo
echo "Validating food/drink CSVs..."
php scripts/spotdeals_csv_validate.php --dataset=food --strict-format

echo
echo "Validating non-food/drink CSVs..."
php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format

echo
echo "========================================"
echo " All SpotDeals CSV validation passed."
echo "========================================"
