#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PACKAGE_SCRIPT="${ROOT_DIR}/ops/scripts/package-addin.sh"

SRC_STATIC_DIR="${ROOT_DIR}/dist/addin/stage/static/addin"
SRC_MANIFEST="${ROOT_DIR}/dist/addin/stage/sideload/suitesidecar.xml"

DEST_ADDIN_DIR="${ROOT_DIR}/connector-php/public/addin"
DEST_MANIFEST_DIR="${DEST_ADDIN_DIR}/manifest"
LOCAL_MANIFEST="${ROOT_DIR}/addin/manifest/suitesidecar.local.xml"

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

MANIFEST_BASE_URL="$(grep -oE 'https://[^"]+/addin/taskpane\.html[^"]*' "${SRC_MANIFEST}" | head -n1 | sed 's|/addin/taskpane\.html.*$||')"
if [[ -z "${MANIFEST_BASE_URL}" ]]; then
  MANIFEST_BASE_URL="https://suitesidecar.example.com"
fi

LOCAL_MANIFEST_BASE_URL=""
if [[ -f "${LOCAL_MANIFEST}" ]]; then
  LOCAL_MANIFEST_BASE_URL="$(grep -oE 'https://[^"]+/addin/taskpane\.html[^"]*' "${LOCAL_MANIFEST}" | head -n1 | sed 's|/addin/taskpane\.html.*$||')"
fi

echo "Published add-in files to:"
echo "  ${DEST_ADDIN_DIR}"
echo "  ${DEST_MANIFEST_DIR}/suitesidecar.xml"
echo
echo "Validation URLs:"
echo "  ${MANIFEST_BASE_URL}/addin/taskpane.html"
echo "  ${MANIFEST_BASE_URL}/addin/manifest/suitesidecar.xml"

if [[ -n "${LOCAL_MANIFEST_BASE_URL}" ]]; then
  echo
  echo "Local sideload manifest URLs:"
  echo "  ${LOCAL_MANIFEST_BASE_URL}/addin/taskpane.html"
  echo "  ${LOCAL_MANIFEST}"
fi
