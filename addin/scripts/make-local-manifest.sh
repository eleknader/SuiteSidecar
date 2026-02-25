#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_MANIFEST="${ROOT_DIR}/manifest/suitesidecar.xml"
TARGET_MANIFEST="${ROOT_DIR}/manifest/suitesidecar.local.xml"

if [[ ! -f "${SOURCE_MANIFEST}" ]]; then
  echo "Source manifest not found: ${SOURCE_MANIFEST}" >&2
  exit 1
fi

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 <base-url> [connector-base-url]"
  echo "Example: $0 https://suitesidecar.yourdomain.tld"
  echo "Example: $0 https://addin.example.com https://api.example.com"
  exit 1
fi

BASE_URL="$1"
BASE_URL="${BASE_URL%/}"
CONNECTOR_BASE_URL="${2:-$BASE_URL}"
CONNECTOR_BASE_URL="${CONNECTOR_BASE_URL%/}"

if [[ ! "${BASE_URL}" =~ ^https:// ]]; then
  echo "Base URL must start with https:// for Outlook add-in hosting." >&2
  exit 1
fi

if [[ ! "${CONNECTOR_BASE_URL}" =~ ^https:// ]]; then
  echo "Connector base URL must start with https://." >&2
  exit 1
fi

ENCODED_CONNECTOR_BASE_URL="$(
  python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "${CONNECTOR_BASE_URL}"
)"

sed "s|https://suitesidecar.example.com|${BASE_URL}|g" "${SOURCE_MANIFEST}" \
  | sed "s|taskpane.html?v=0.5.0|taskpane.html?v=0.5.0\\&amp;connectorBaseUrl=${ENCODED_CONNECTOR_BASE_URL}|g" \
  > "${TARGET_MANIFEST}"

echo "Wrote local manifest: ${TARGET_MANIFEST}"
echo "Taskpane host URL: ${BASE_URL}"
echo "Connector base URL param: ${CONNECTOR_BASE_URL}"
echo "This file is gitignored."
