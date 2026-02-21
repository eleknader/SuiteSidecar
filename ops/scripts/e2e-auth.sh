#!/usr/bin/env bash
set -euo pipefail

SCHEME="${E2E_SCHEME:-https}"
HOST="${E2E_HOST:-suitesidecar.example.com}"
IP="${E2E_IP:-127.0.0.1}"
DEFAULT_PORT="443"
if [[ "${SCHEME}" == "http" ]]; then
  DEFAULT_PORT="80"
fi
PORT="${E2E_PORT:-${DEFAULT_PORT}}"
BASE_URL="${SCHEME}://${HOST}:${PORT}"

PROFILE_ID="${E2E_PROFILE_ID:-example-dev}"
USERNAME="${E2E_USERNAME:-}"
PASSWORD="${E2E_PASSWORD:-}"
LOOKUP_EMAIL="${E2E_LOOKUP_EMAIL:-known.user@example.com}"
NOW_TS="$(date +%s)"

if [[ -z "${USERNAME}" || -z "${PASSWORD}" ]]; then
  echo "Usage: set E2E_USERNAME and E2E_PASSWORD environment variables."
  echo "Example: E2E_USERNAME=admin E2E_PASSWORD='...' ops/scripts/e2e-auth.sh"
  exit 2
fi

PASS_COUNT=0
FAIL_COUNT=0

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

extract_request_id() {
  local body_file="$1"
  local headers_file="$2"
  local rid

  rid="$(php -r '
$body = @file_get_contents($argv[1]);
$d = json_decode((string)$body, true);
if (is_array($d)) {
  if (isset($d["requestId"])) { echo $d["requestId"]; exit(0); }
  if (isset($d["error"]["requestId"])) { echo $d["error"]["requestId"]; exit(0); }
}
' "$body_file" 2>/dev/null || true)"

  if [[ -n "${rid}" ]]; then
    printf '%s' "${rid}"
    return
  fi

  rid="$(awk 'tolower($1)=="x-request-id:" {print $2}' "$headers_file" | tr -d '\r' | tail -n1)"
  printf '%s' "${rid}"
}

request() {
  local name="$1"
  local method="$2"
  local path="$3"
  local data="$4"
  local auth_header="$5"

  local body_file="${TMP_DIR}/${name}.body"
  local headers_file="${TMP_DIR}/${name}.headers"

  local args=(
    --silent --show-error
    -D "${headers_file}"
    -o "${body_file}"
    -X "${method}"
  )

  if [[ -n "${IP}" ]]; then
    args+=(--resolve "${HOST}:${PORT}:${IP}")
  fi

  if [[ -n "${data}" ]]; then
    args+=(-H "Content-Type: application/json" -d "${data}")
  fi

  if [[ -n "${auth_header}" ]]; then
    args+=(-H "Authorization: Bearer ${auth_header}")
  fi

  local code
  code="$(curl "${args[@]}" "${BASE_URL}${path}" -w "%{http_code}")"
  printf '%s\n' "${code}|${body_file}|${headers_file}"
}

mark_pass() {
  local msg="$1"
  PASS_COUNT=$((PASS_COUNT + 1))
  echo "PASS: ${msg}"
}

mark_fail() {
  local msg="$1"
  local body_file="$2"
  local headers_file="$3"
  FAIL_COUNT=$((FAIL_COUNT + 1))
  local rid
  rid="$(extract_request_id "$body_file" "$headers_file")"
  if [[ -n "${rid}" ]]; then
    echo "FAIL: ${msg} (requestId=${rid})"
  else
    echo "FAIL: ${msg}"
  fi
}

parse_json_field() {
  local body_file="$1"
  local php_expr="$2"
  php -r "\$d=json_decode((string)file_get_contents(\$argv[1]),true); ${php_expr}" "$body_file" 2>/dev/null || true
}

# /health
IFS='|' read -r code body headers < <(request "health" "GET" "/health" "" "")
if [[ "${code}" == "200" ]]; then
  mark_pass "GET /health -> 200"
else
  mark_fail "GET /health -> ${code}" "$body" "$headers"
fi

# /profiles
IFS='|' read -r code body headers < <(request "profiles" "GET" "/profiles" "" "")
if [[ "${code}" == "200" ]]; then
  mark_pass "GET /profiles -> 200"
else
  mark_fail "GET /profiles -> ${code}" "$body" "$headers"
fi

# login
login_payload="$(php -r 'echo json_encode([
  "profileId" => $argv[1],
  "username" => $argv[2],
  "password" => $argv[3],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "${PROFILE_ID}" "${USERNAME}" "${PASSWORD}")"
IFS='|' read -r code body headers < <(request "login" "POST" "/auth/login" "${login_payload}" "")
if [[ "${code}" != "200" ]]; then
  mark_fail "POST /auth/login -> ${code}" "$body" "$headers"
  echo "---"
  echo "Summary: PASS=${PASS_COUNT} FAIL=${FAIL_COUNT}"
  exit 1
fi
mark_pass "POST /auth/login -> 200"

TOKEN="$(parse_json_field "$body" 'echo $d["token"] ?? "";')"
if [[ -z "${TOKEN}" || "${TOKEN}" == "null" ]]; then
  mark_fail "POST /auth/login did not return token" "$body" "$headers"
  echo "---"
  echo "Summary: PASS=${PASS_COUNT} FAIL=${FAIL_COUNT}"
  exit 1
fi
mark_pass "Connector JWT received"

# lookup (expected 200)
lookup_path="/lookup/by-email?profileId=${PROFILE_ID}&email=${LOOKUP_EMAIL}"
IFS='|' read -r code body headers < <(request "lookup" "GET" "${lookup_path}" "" "${TOKEN}")
if [[ "${code}" == "200" ]]; then
  mark_pass "GET ${lookup_path} -> 200"
else
  mark_fail "GET ${lookup_path} -> ${code}" "$body" "$headers"
fi

# not found lookup (expected 200 with notFound=true)
missing_email="no-match-${NOW_TS}@example.com"
missing_lookup_path="/lookup/by-email?profileId=${PROFILE_ID}&email=${missing_email}"
IFS='|' read -r code body headers < <(request "lookup-missing" "GET" "${missing_lookup_path}" "" "${TOKEN}")
if [[ "${code}" == "200" ]]; then
  not_found_flag="$(parse_json_field "$body" 'echo isset($d["notFound"]) ? ($d["notFound"] ? "true" : "false") : "";')"
  if [[ "${not_found_flag}" == "true" ]]; then
    mark_pass "GET ${missing_lookup_path} -> 200 (notFound=true)"
  else
    mark_fail "GET ${missing_lookup_path} -> 200 (notFound!=true)" "$body" "$headers"
  fi
else
  mark_fail "GET ${missing_lookup_path} -> ${code}" "$body" "$headers"
fi

# create contact
contact_email="smoke-contact-${NOW_TS}@example.com"
contact_payload="$(printf '{"firstName":"Smoke","lastName":"Contact%s","email":"%s"}' "${NOW_TS}" "${contact_email}")"
IFS='|' read -r code body headers < <(request "create-contact" "POST" "/entities/contacts?profileId=${PROFILE_ID}" "${contact_payload}" "${TOKEN}")
if [[ "${code}" == "201" ]]; then
  mark_pass "POST /entities/contacts?profileId=${PROFILE_ID} -> 201"
else
  mark_fail "POST /entities/contacts?profileId=${PROFILE_ID} -> ${code}" "$body" "$headers"
fi

contact_id="$(parse_json_field "$body" 'echo $d["id"] ?? "";')"
if [[ -z "${contact_id}" ]]; then
  mark_fail "Contact create response missing id" "$body" "$headers"
fi

# create lead
lead_email="smoke-lead-${NOW_TS}@example.com"
lead_payload="$(printf '{"firstName":"Smoke","lastName":"Lead%s","email":"%s"}' "${NOW_TS}" "${lead_email}")"
IFS='|' read -r code body headers < <(request "create-lead" "POST" "/entities/leads?profileId=${PROFILE_ID}" "${lead_payload}" "${TOKEN}")
if [[ "${code}" == "201" ]]; then
  mark_pass "POST /entities/leads?profileId=${PROFILE_ID} -> 201"
else
  mark_fail "POST /entities/leads?profileId=${PROFILE_ID} -> ${code}" "$body" "$headers"
fi

# log email (first time expected 201)
message_id="<smoke-${NOW_TS}@suitesidecar.local>"
email_log_payload="$(printf '{"message":{"internetMessageId":"%s","subject":"Smoke log %s","from":{"email":"sender@example.com"},"to":[{"email":"%s"}],"sentAt":"2026-01-01T12:00:00Z"},"linkTo":{"module":"Contacts","id":"%s"}}' "${message_id}" "${NOW_TS}" "${contact_email}" "${contact_id}")"
IFS='|' read -r code body headers < <(request "email-log-1" "POST" "/email/log?profileId=${PROFILE_ID}" "${email_log_payload}" "${TOKEN}")
if [[ "${code}" == "201" ]]; then
  mark_pass "POST /email/log?profileId=${PROFILE_ID} -> 201"
else
  mark_fail "POST /email/log?profileId=${PROFILE_ID} -> ${code}" "$body" "$headers"
fi

# log email duplicate (expected 409)
IFS='|' read -r code body headers < <(request "email-log-2" "POST" "/email/log?profileId=${PROFILE_ID}" "${email_log_payload}" "${TOKEN}")
if [[ "${code}" == "409" ]]; then
  mark_pass "POST /email/log duplicate -> 409"
else
  mark_fail "POST /email/log duplicate -> ${code}" "$body" "$headers"
fi

echo "---"
echo "Summary: PASS=${PASS_COUNT} FAIL=${FAIL_COUNT}"

if [[ ${FAIL_COUNT} -gt 0 ]]; then
  exit 1
fi
