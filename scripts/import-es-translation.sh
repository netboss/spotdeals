#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
HOST_PO_FILE="${PROJECT_ROOT}/translations/es/es_updated.po"
DDEV_PO_FILE="/var/www/html/translations/es/es_updated.po"

if [[ ! -s "${HOST_PO_FILE}" ]]; then
  echo "ERROR: Translation file does not exist or is empty:"
  echo "${HOST_PO_FILE}"
  exit 1
fi

cd "${PROJECT_ROOT}"

if command -v ddev >/dev/null 2>&1 && [[ -f "${PROJECT_ROOT}/.ddev/config.yaml" ]]; then
  echo "Environment: Local DDEV"
  echo "Project root: ${PROJECT_ROOT}"
  echo "Host PO file: ${HOST_PO_FILE}"
  echo "DDEV PO file: ${DDEV_PO_FILE}"
  echo
  echo "Importing Spanish translations..."

  ddev drush locale:import es "${DDEV_PO_FILE}" \
    --type=customized \
    --override=all \
    -y

  echo
  echo "Rebuilding Drupal caches..."

  ddev drush cr
else
  PO_FILE="${PROJECT_ROOT}/translations/es/es_updated.po"

  echo "Environment: Production"
  echo "Project root: ${PROJECT_ROOT}"
  echo "PO file: ${PO_FILE}"
  echo
  echo "Importing Spanish translations..."

  drush locale:import es "${PO_FILE}" \
    --type=customized \
    --override=all \
    -y

  echo
  echo "Rebuilding Drupal caches..."

  drush cr
fi

echo
echo "Spanish translation import completed successfully."
