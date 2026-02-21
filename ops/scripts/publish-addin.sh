#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PACKAGE_SCRIPT="${ROOT_DIR}/ops/scripts/package-addin.sh"

SRC_STATIC_DIR="${ROOT_DIR}/dist/addin/stage/static/addin"
SRC_MANIFEST="${ROOT_DIR}/dist/addin/stage/sideload/suitesidecar.xml"

DEST_ADDIN_DIR="${ROOT_DIR}/connector-php/public/addin"
DEST_MANIFEST_DIR="${DEST_ADDIN_DIR}/manifest"

if [[ ! -f "${PACKAGE_SCRIPT}" ]]; then
  echo "Missing package script: ${PACKAGE_SCRIPT}"
  exit 1
fi

bash "${PACKAGE_SCRIPT}"

if [[ ! -f "${SRC_MANIFEST}" ]]; then
  echo "Manifest not found after packaging: ${SRC_MANIFEST}"
  exit 1
fi

if [[ ! -d "${SRC_STATIC_DIR}" ]]; then
  echo "Static add-in payload not found after packaging: ${SRC_STATIC_DIR}"
  exit 1
fi

mkdir -p "${DEST_ADDIN_DIR}" "${DEST_MANIFEST_DIR}"
rsync -a --delete "${SRC_STATIC_DIR}/" "${DEST_ADDIN_DIR}/"
mkdir -p "${DEST_MANIFEST_DIR}"
cp "${SRC_MANIFEST}" "${DEST_MANIFEST_DIR}/suitesidecar.xml"

echo "Published add-in files to:"
echo "  ${DEST_ADDIN_DIR}"
echo "  ${DEST_MANIFEST_DIR}/suitesidecar.xml"
echo
echo "Validation URLs:"
echo "  https://suitesidecar.example.com/addin/taskpane.html"
echo "  https://suitesidecar.example.com/addin/manifest/suitesidecar.xml"
