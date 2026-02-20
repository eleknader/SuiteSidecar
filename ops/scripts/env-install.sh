#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="/etc/suitesidecar"
TARGET_FILE="${TARGET_DIR}/suitesidecar.env"

if [[ "${EUID}" -ne 0 ]]; then
  echo "This script must be run as root (use: sudo ops/scripts/env-install.sh)." >&2
  exit 1
fi

read -r -p "SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID: " client_id
read -r -s -p "SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET: " client_secret
printf "\n"
read -r -s -p "SUITESIDECAR_JWT_SECRET: " jwt_secret
printf "\n"

if [[ -z "${client_id}" || -z "${client_secret}" || -z "${jwt_secret}" ]]; then
  echo "All values are required." >&2
  exit 1
fi

install -d -m 750 -o root -g root "${TARGET_DIR}"

tmp_file="$(mktemp)"
chmod 600 "${tmp_file}"
cat > "${tmp_file}" <<ENVVARS
# SuiteSidecar environment variables (server-local).
# Fill/update values using this installer. Do NOT commit secrets.
SUITESIDECAR_JWT_SECRET=${jwt_secret}
SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID=${client_id}
SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET=${client_secret}
ENVVARS

install -m 640 -o root -g root "${tmp_file}" "${TARGET_FILE}"
rm -f "${tmp_file}"

echo "Wrote ${TARGET_FILE} (owner root:root, mode 640)."
