#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist/addin"
STAGE_DIR="${DIST_DIR}/stage"
MANIFEST_SRC="${ROOT_DIR}/addin/manifest/suitesidecar.xml"
PUBLIC_SRC="${ROOT_DIR}/addin/public"

if [[ ! -f "${MANIFEST_SRC}" ]]; then
  echo "Manifest not found: ${MANIFEST_SRC}"
  exit 1
fi

if [[ ! -d "${PUBLIC_SRC}" ]]; then
  echo "Add-in public directory not found: ${PUBLIC_SRC}"
  exit 1
fi

if rg -q "localhost:3000|connector.example.com|example.com/support" "${MANIFEST_SRC}"; then
  echo "Manifest still contains placeholder URLs. Update addin/manifest/suitesidecar.xml first."
  exit 1
fi

rm -rf "${DIST_DIR}"
mkdir -p "${STAGE_DIR}/sideload" "${STAGE_DIR}/static/addin"

cp "${MANIFEST_SRC}" "${STAGE_DIR}/sideload/suitesidecar.xml"
cp -R "${PUBLIC_SRC}/." "${STAGE_DIR}/static/addin/"

if command -v zip >/dev/null 2>&1; then
  (
    cd "${STAGE_DIR}/sideload"
    zip -qr "${DIST_DIR}/suitesidecar-manifest.zip" suitesidecar.xml
  )
  (
    cd "${STAGE_DIR}/static"
    zip -qr "${DIST_DIR}/suitesidecar-static.zip" addin
  )
else
  (
    cd "${STAGE_DIR}/sideload"
    tar -czf "${DIST_DIR}/suitesidecar-manifest.tar.gz" suitesidecar.xml
  )
  (
    cd "${STAGE_DIR}/static"
    tar -czf "${DIST_DIR}/suitesidecar-static.tar.gz" addin
  )
fi

echo "Add-in package created under: ${DIST_DIR}"
echo "Sideload manifest: ${STAGE_DIR}/sideload/suitesidecar.xml"
echo "Static payload: ${STAGE_DIR}/static/addin/"
