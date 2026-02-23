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

# lookup with timeline include (expected 200 + timeline array)
lookup_timeline_path="/lookup/by-email?profileId=${PROFILE_ID}&email=${LOOKUP_EMAIL}&include=timeline"
IFS='|' read -r code body headers < <(request "lookup-timeline" "GET" "${lookup_timeline_path}" "" "${TOKEN}")
if [[ "${code}" == "200" ]]; then
  timeline_shape="$(parse_json_field "$body" 'echo (isset($d["match"]["timeline"]) && is_array($d["match"]["timeline"])) ? "array" : "missing";')"
  if [[ "${timeline_shape}" == "array" ]]; then
    mark_pass "GET ${lookup_timeline_path} -> 200 (timeline array)"
  else
    mark_fail "GET ${lookup_timeline_path} -> 200 (timeline missing)" "$body" "$headers"
  fi
else
  mark_fail "GET ${lookup_timeline_path} -> ${code}" "$body" "$headers"
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

# opportunities by context (expected 200)
if [[ -n "${contact_id}" ]]; then
  opp_path="/opportunities/by-context?profileId=${PROFILE_ID}&personModule=Contacts&personId=${contact_id}&limit=5"
  IFS='|' read -r code body headers < <(request "opportunities-context" "GET" "${opp_path}" "" "${TOKEN}")
  if [[ "${code}" == "200" ]]; then
    items_shape="$(parse_json_field "$body" 'echo (isset($d["items"]) && is_array($d["items"])) ? "array" : "missing";')"
    if [[ "${items_shape}" == "array" ]]; then
      mark_pass "GET ${opp_path} -> 200 (items array)"
    else
      mark_fail "GET ${opp_path} -> 200 (items missing)" "$body" "$headers"
    fi
  else
    mark_fail "GET ${opp_path} -> ${code}" "$body" "$headers"
  fi
fi

# create task from email + dedup
if [[ -n "${contact_id}" ]]; then
  task_graph_id="smoke-graph-${NOW_TS}"
  task_payload="$(php -r 'echo json_encode([
    "message" => [
      "graphMessageId" => $argv[1],
      "subject" => "Task smoke ".$argv[2],
      "from" => ["email" => "sender@example.com", "name" => "Smoke Sender"],
      "receivedDateTime" => "2026-01-01T12:00:00Z",
      "bodyPreview" => "Short preview"
    ],
    "context" => [
      "personModule" => "Contacts",
      "personId" => $argv[3]
    ]
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "${task_graph_id}" "${NOW_TS}" "${contact_id}")"
  IFS='|' read -r code body headers < <(request "task-create-1" "POST" "/tasks/from-email?profileId=${PROFILE_ID}" "${task_payload}" "${TOKEN}")
  if [[ "${code}" == "201" ]]; then
    mark_pass "POST /tasks/from-email?profileId=${PROFILE_ID} -> 201"
  else
    mark_fail "POST /tasks/from-email?profileId=${PROFILE_ID} -> ${code}" "$body" "$headers"
  fi

  IFS='|' read -r code body headers < <(request "task-create-2" "POST" "/tasks/from-email?profileId=${PROFILE_ID}" "${task_payload}" "${TOKEN}")
  if [[ "${code}" == "200" ]]; then
    dedup_flag="$(parse_json_field "$body" 'echo isset($d["deduplicated"]) ? ($d["deduplicated"] ? "true" : "false") : "";')"
    if [[ "${dedup_flag}" == "true" ]]; then
      mark_pass "POST /tasks/from-email duplicate -> 200 (deduplicated=true)"
    else
      mark_fail "POST /tasks/from-email duplicate -> 200 (deduplicated!=true)" "$body" "$headers"
    fi
  else
    mark_fail "POST /tasks/from-email duplicate -> ${code}" "$body" "$headers"
  fi
fi

# log email (first time expected 201)
if [[ -n "${contact_id}" ]]; then
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

  # log email with oversized attachment metadata + tiny maxAttachmentBytes (expected 201, attachment skipped)
  attachment_message_id="<smoke-attach-${NOW_TS}@suitesidecar.local>"
  attachment_payload="$(php -r 'echo json_encode([
    "message" => [
      "internetMessageId" => $argv[1],
      "subject" => "Smoke attachment skip ".$argv[2],
      "from" => ["email" => "sender@example.com"],
      "to" => [["email" => "known.user@example.com"]],
      "sentAt" => "2026-01-01T12:00:00Z",
      "attachments" => [[
        "name" => "big.txt",
        "sizeBytes" => 2048,
        "contentType" => "text/plain",
        "contentBase64" => "U21va2UgQXR0YWNobWVudA=="
      ]]
    ],
    "linkTo" => ["module" => "Contacts", "id" => $argv[3]],
    "options" => ["storeAttachments" => true, "maxAttachmentBytes" => 10]
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "${attachment_message_id}" "${NOW_TS}" "${contact_id}")"
  IFS='|' read -r code body headers < <(request "email-log-attach-skip" "POST" "/email/log?profileId=${PROFILE_ID}" "${attachment_payload}" "${TOKEN}")
  if [[ "${code}" == "201" ]]; then
    mark_pass "POST /email/log with tiny maxAttachmentBytes -> 201"
  else
    mark_fail "POST /email/log with tiny maxAttachmentBytes -> ${code}" "$body" "$headers"
  fi

  # payload-too-large behavior (expected 413 when maxRequestBytes is known)
  IFS='|' read -r code body headers < <(request "version" "GET" "/version" "" "${TOKEN}")
  max_request_bytes=""
  if [[ "${code}" == "200" ]]; then
    max_request_bytes="$(parse_json_field "$body" 'echo $d["limits"]["maxRequestBytes"] ?? "";')"
  fi

  if [[ -n "${max_request_bytes}" && "${max_request_bytes}" =~ ^[0-9]+$ && "${max_request_bytes}" -gt 0 ]]; then
    oversize_target=$((max_request_bytes + 4096))
    oversized_message_id="<smoke-oversize-${NOW_TS}@suitesidecar.local>"
    oversized_payload="$(php -r '$size=(int)$argv[1]; $messageId=$argv[2]; $contactId=$argv[3]; $body=str_repeat("A",$size); echo json_encode([
      "message" => [
        "internetMessageId" => $messageId,
        "subject" => "Smoke oversized payload",
        "from" => ["email" => "sender@example.com"],
        "to" => [["email" => "known.user@example.com"]],
        "sentAt" => "2026-01-01T12:00:00Z",
        "bodyText" => $body
      ],
      "linkTo" => ["module" => "Contacts", "id" => $contactId],
      "options" => ["storeBody" => true]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "${oversize_target}" "${oversized_message_id}" "${contact_id}")"

    IFS='|' read -r code body headers < <(request "email-log-oversize" "POST" "/email/log?profileId=${PROFILE_ID}" "${oversized_payload}" "${TOKEN}")
    if [[ "${code}" == "413" ]]; then
      err_code="$(parse_json_field "$body" 'echo $d["error"]["code"] ?? "";')"
      if [[ "${err_code}" == "payload_too_large" ]]; then
        mark_pass "POST /email/log oversized payload -> 413 payload_too_large"
      else
        mark_fail "POST /email/log oversized payload -> 413 (unexpected error code)" "$body" "$headers"
      fi
    else
      mark_fail "POST /email/log oversized payload -> ${code}" "$body" "$headers"
    fi
  else
    echo "SKIP: /email/log oversized payload check skipped because /version did not expose maxRequestBytes."
  fi
else
  echo "SKIP: /email/log checks skipped because contact creation did not return an id."
fi

echo "---"
echo "Summary: PASS=${PASS_COUNT} FAIL=${FAIL_COUNT}"

if [[ ${FAIL_COUNT} -gt 0 ]]; then
  exit 1
fi
