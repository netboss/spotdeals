#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

php scripts/spotdeals_csv_validate.php --dataset=non-food --strict-format
