#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."

ddev drush php:script scripts/spotdeals_create_missing_es_node_translations.php
ddev drush cr
