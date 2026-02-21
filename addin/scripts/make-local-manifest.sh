#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_MANIFEST="${ROOT_DIR}/manifest/suitesidecar.xml"
TARGET_MANIFEST="${ROOT_DIR}/manifest/suitesidecar.local.xml"

if [[ ! -f "${SOURCE_MANIFEST}" ]]; then
  echo "Source manifest not found: ${SOURCE_MANIFEST}" >&2
  exit 1
fi

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <base-url>"
  echo "Example: $0 https://suitesidecar.yourdomain.tld"
  exit 1
fi

BASE_URL="$1"
BASE_URL="${BASE_URL%/}"

if [[ ! "${BASE_URL}" =~ ^https:// ]]; then
  echo "Base URL must start with https:// for Outlook add-in hosting." >&2
  exit 1
fi

sed "s|https://suitesidecar.example.com|${BASE_URL}|g" "${SOURCE_MANIFEST}" > "${TARGET_MANIFEST}"

echo "Wrote local manifest: ${TARGET_MANIFEST}"
echo "This file is gitignored."
