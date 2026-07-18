#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
HOST_PO_FILE="${PROJECT_ROOT}/translations/es/es_updated.po"

if [[ ! -s "${HOST_PO_FILE}" ]]; then
  echo "ERROR: Translation file does not exist or is empty:"
  echo "${HOST_PO_FILE}"
  exit 1
fi

cd "${PROJECT_ROOT}"

if command -v ddev >/dev/null 2>&1 && [[ -f "${PROJECT_ROOT}/.ddev/config.yaml" ]]; then
  ENVIRONMENT="Local DDEV"
  DRUSH=(ddev drush)
  PO_FILE="/var/www/html/translations/es/es_updated.po"
else
  ENVIRONMENT="Production"
  DRUSH=(vendor/bin/drush)
  PO_FILE="${HOST_PO_FILE}"
fi

if [[ "${ENVIRONMENT}" == "Production" && ! -x "${PROJECT_ROOT}/vendor/bin/drush" ]]; then
  echo "ERROR: Drush executable was not found:"
  echo "${PROJECT_ROOT}/vendor/bin/drush"
  exit 1
fi

echo "Environment: ${ENVIRONMENT}"
echo "Project root: ${PROJECT_ROOT}"
echo "PO file: ${PO_FILE}"
echo
echo "Importing Spanish translations..."

"${DRUSH[@]}" locale:import es "${PO_FILE}" \
  --type=customized \
  --override=all \
  -y

echo
echo "Rebuilding Drupal caches..."

"${DRUSH[@]}" cr

echo
echo "Spanish translation import completed successfully."
