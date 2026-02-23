#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ADDIN_PACKAGE_SCRIPT="${ROOT_DIR}/ops/scripts/package-addin.sh"
RELEASE_DIR="${ROOT_DIR}/dist/release"
STAGE_DIR="${RELEASE_DIR}/stage"
CONNECTOR_STAGE_DIR="${STAGE_DIR}/connector-php"
ADDIN_STAGE_DIR="${STAGE_DIR}/addin"

if [[ ! -x "${ADDIN_PACKAGE_SCRIPT}" ]]; then
  echo "Missing add-in packaging script: ${ADDIN_PACKAGE_SCRIPT}" >&2
  exit 1
fi

bash "${ADDIN_PACKAGE_SCRIPT}"

rm -rf "${RELEASE_DIR}"
mkdir -p "${CONNECTOR_STAGE_DIR}" "${ADDIN_STAGE_DIR}"

rsync -a \
  --exclude "vendor/" \
  --exclude "var/" \
  --exclude ".env" \
  --exclude ".env.local" \
  --exclude "config/profiles.php" \
  --exclude "public/addin/" \
  "${ROOT_DIR}/connector-php/" "${CONNECTOR_STAGE_DIR}/"

cp -R "${ROOT_DIR}/dist/addin/." "${ADDIN_STAGE_DIR}/"

BUILD_TIME_UTC="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
GIT_SHA="$(git -C "${ROOT_DIR}" rev-parse --short HEAD 2>/dev/null || true)"

cat > "${STAGE_DIR}/release-metadata.txt" <<EOF
build_time_utc=${BUILD_TIME_UTC}
git_sha=${GIT_SHA}
connector_src=connector-php
addin_dist=dist/addin
EOF

if command -v zip >/dev/null 2>&1; then
  (
    cd "${STAGE_DIR}"
    zip -qr "${RELEASE_DIR}/suitesidecar-release.zip" .
  )
else
  (
    cd "${STAGE_DIR}"
    tar -czf "${RELEASE_DIR}/suitesidecar-release.tar.gz" .
  )
fi

echo "Release package created under: ${RELEASE_DIR}"
echo "Contents:"
echo "  - connector-php/ (without runtime/secrets)"
echo "  - addin/ (packaged add-in artifacts)"
echo "  - release-metadata.txt"
