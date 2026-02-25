# SuiteSidecar Support Playbook

Use this for first-line troubleshooting. Always capture `requestId` from API errors (`X-Request-Id` header or JSON error body).

## What to collect

1. `requestId` from response header (`X-Request-Id`) or JSON error response.
2. Tenant-routing diagnostics when available:
   - `X-SuiteSidecar-Resolved-Host`
   - `X-SuiteSidecar-Resolved-Profile`
   - Add-in `Copy Debug Info` payload fields `resolvedHost` and `resolvedProfileId`.
3. Endpoint and method (`/auth/login`, `/lookup/by-email`, `/email/log`, `/tasks/from-email`, `/opportunities/by-context`).
4. HTTP status code.
5. Timestamp (UTC) and profile id.

## Login failures (`POST /auth/login`)

1. If `401 unauthorized`:
   - verify username/password in SuiteCRM.
   - verify profile id exists in `/profiles`.
2. If `401 suitecrm_auth_failed`:
   - verify `SUITESIDECAR_<PROFILE>_CLIENT_ID` and `_CLIENT_SECRET`.
   - test SuiteCRM token endpoint directly with `grant_type=password`.
3. If `500 server_error`:
   - verify `SUITESIDECAR_JWT_SECRET` is loaded for connector runtime.

## Lookup failures (`GET /lookup/by-email`)

1. If `401 unauthorized`:
   - login again and retry with fresh connector JWT.
2. If `400 bad_request`:
   - verify `email` and profile selection (`profileId` query or `X-SuiteSidecar-Profile`).
3. If `502 suitecrm_unreachable` or `suitecrm_bad_response`:
   - verify SuiteCRM URL in active profile.
   - verify connector can reach SuiteCRM over HTTPS.

## Log-email failures (`POST /email/log`)

1. If `400 bad_request`:
   - verify payload contains `message.internetMessageId`, sender/recipients, and `linkTo`.
2. If `409 conflict`:
   - duplicate message detected by dedup key.
   - treat as expected idempotency protection.
3. If `502 suitecrm_bad_response`:
   - verify target module relationship and required fields in SuiteCRM.
4. If `413 payload_too_large`:
   - compare response `error.details.maxRequestBytes` vs request size.
   - reduce attachment count/size and retry.
   - if needed, tune connector/PHP limits (`post_max_size`, `upload_max_filesize`, optional `SUITESIDECAR_MAX_REQUEST_BYTES`).

## Create-task failures (`POST /tasks/from-email`)

1. If `400 bad_request`:
   - verify payload contains valid `message.from.email`, `message.subject`, `message.receivedDateTime`.
   - verify at least one message id exists (`graphMessageId` or `internetMessageId`).
2. If `200` with `deduplicated=true`:
   - existing task was reused (expected idempotency behavior).
3. If `502 suitecrm_unreachable`:
   - verify SuiteCRM API availability and profile connectivity.

## Opportunities failures (`GET /opportunities/by-context`)

1. If `400 bad_request`:
   - verify `personId` or `accountId` is present.
   - verify `personModule` is one of `Contacts|Leads` when provided.
2. If `200` with empty `items`:
   - expected when no related opportunities are accessible to the current user.

## Known behavior (current)

1. Note body formatting is plain text:
   - `Notes.description` stores plain text.
   - Outlook HTML formatting (tags/styles) is not preserved in current v0.2 behavior.
2. Attachment skip behavior is expected with size guardrails:
   - add-in status shows `Attachments sent=<n>, skipped=<n>`.
   - skipped files are commonly due to `maxAttachmentBytes` or host API read limitations.
3. Timeline links use SuiteCRM route style:
   - expected format: `https://<crm>/#/module/record/<id>` (for example `#/notes/record/<id>`).

## RequestId usage

- RequestId is the correlation key across connector logs and API responses.
- Never include credentials or tokens in support tickets.
