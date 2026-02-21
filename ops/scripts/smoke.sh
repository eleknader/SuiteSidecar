#!/usr/bin/env bash
set -euo pipefail

SCHEME="${SMOKE_SCHEME:-https}"
HOST="${SMOKE_HOST:-suitesidecar.example.com}"
IP="${SMOKE_IP:-127.0.0.1}"
DEFAULT_PORT="443"
if [[ "${SCHEME}" == "http" ]]; then
  DEFAULT_PORT="80"
fi
PORT="${SMOKE_PORT:-${DEFAULT_PORT}}"
BASE_URL="${SCHEME}://${HOST}:${PORT}"
DEFAULT_EMAIL="known.user@example.com"
EMAIL="${1:-$DEFAULT_EMAIL}"

PASS_COUNT=0
FAIL_COUNT=0
SKIP_COUNT=0

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
  local profile_header="$6"

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

  if [[ -n "${profile_header}" ]]; then
    args+=(-H "X-SuiteSidecar-Profile: ${profile_header}")
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

mark_skip() {
  local msg="$1"
  SKIP_COUNT=$((SKIP_COUNT + 1))
  echo "SKIP: ${msg}"
}

# /health
IFS='|' read -r code body headers < <(request "health" "GET" "/health" "" "" "")
if [[ "${code}" == "200" ]]; then
  mark_pass "GET /health -> 200"
else
  mark_fail "GET /health -> ${code}" "$body" "$headers"
fi

# /profiles
IFS='|' read -r code body headers < <(request "profiles" "GET" "/profiles" "" "" "")
if [[ "${code}" == "200" ]]; then
  mark_pass "GET /profiles -> 200"
else
  mark_fail "GET /profiles -> ${code}" "$body" "$headers"
fi

# /lookup/by-email (auth required in current connector flow)
lookup_path="/lookup/by-email?profileId=example-dev&email=${EMAIL}"
IFS='|' read -r code body headers < <(request "lookup" "GET" "${lookup_path}" "" "" "")
if [[ "${code}" == "200" || "${code}" == "401" ]]; then
  mark_pass "GET ${lookup_path} -> ${code}"
else
  mark_fail "GET ${lookup_path} -> ${code}" "$body" "$headers"
fi

# /email/log (auth required in current connector flow)
email_log_payload='{"message":{"internetMessageId":"<smoke@example.com>","subject":"Smoke test","from":{"email":"sender@example.com"},"to":[{"email":"known.user@example.com"}],"sentAt":"2026-01-01T12:00:00Z"},"linkTo":{"module":"Contacts","id":"smoke-contact"}}'
IFS='|' read -r code body headers < <(request "email-log" "POST" "/email/log?profileId=example-dev" "${email_log_payload}" "" "")
if [[ "${code}" == "401" ]]; then
  mark_pass "POST /email/log?profileId=example-dev -> 401"
else
  mark_fail "POST /email/log?profileId=example-dev -> ${code}" "$body" "$headers"
fi

# /entities/contacts (auth required in current connector flow)
contact_payload='{"firstName":"Smoke","lastName":"Contact","email":"smoke.contact@example.com"}'
IFS='|' read -r code body headers < <(request "contact-create" "POST" "/entities/contacts?profileId=example-dev" "${contact_payload}" "" "")
if [[ "${code}" == "401" ]]; then
  mark_pass "POST /entities/contacts?profileId=example-dev -> 401"
else
  mark_fail "POST /entities/contacts?profileId=example-dev -> ${code}" "$body" "$headers"
fi

# /entities/leads (auth required in current connector flow)
lead_payload='{"firstName":"Smoke","lastName":"Lead","email":"smoke.lead@example.com"}'
IFS='|' read -r code body headers < <(request "lead-create" "POST" "/entities/leads?profileId=example-dev" "${lead_payload}" "" "")
if [[ "${code}" == "401" ]]; then
  mark_pass "POST /entities/leads?profileId=example-dev -> 401"
else
  mark_fail "POST /entities/leads?profileId=example-dev -> ${code}" "$body" "$headers"
fi

# /auth/login only if endpoint exists
login_payload='{"profileId":"example-dev","username":"smoke-user","password":"smoke-pass"}'
IFS='|' read -r code body headers < <(request "login" "POST" "/auth/login" "${login_payload}" "" "")
if [[ "${code}" == "404" ]]; then
  error_code="$(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); echo $d["error"]["code"] ?? "";' "$body" 2>/dev/null || true)"
  if [[ "${error_code}" == "not_found" ]]; then
    mark_skip "POST /auth/login endpoint not implemented"
  else
    mark_fail "POST /auth/login -> 404" "$body" "$headers"
  fi
elif [[ "${code}" == "200" || "${code}" == "400" || "${code}" == "401" || "${code}" == "502" ]]; then
  mark_pass "POST /auth/login endpoint reachable -> ${code}"
else
  mark_fail "POST /auth/login -> ${code}" "$body" "$headers"
fi

# /auth/logout (auth required)
IFS='|' read -r code body headers < <(request "logout" "POST" "/auth/logout" "" "" "")
if [[ "${code}" == "401" ]]; then
  mark_pass "POST /auth/logout -> 401"
elif [[ "${code}" == "404" ]]; then
  error_code="$(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); echo $d["error"]["code"] ?? "";' "$body" 2>/dev/null || true)"
  if [[ "${error_code}" == "not_found" ]]; then
    mark_skip "POST /auth/logout endpoint not implemented"
  else
    mark_fail "POST /auth/logout -> 404" "$body" "$headers"
  fi
else
  mark_fail "POST /auth/logout -> ${code}" "$body" "$headers"
fi

echo "---"
echo "Summary: PASS=${PASS_COUNT} FAIL=${FAIL_COUNT} SKIP=${SKIP_COUNT}"

if [[ ${FAIL_COUNT} -gt 0 ]]; then
  exit 1
fi
