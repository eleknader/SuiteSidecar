# SuiteSidecar Support Playbook

Use this for first-line troubleshooting. Always capture `requestId` from API/add-in errors.

## What to collect

1. `requestId` from add-in status or JSON error response.
2. Endpoint and method (`/auth/login`, `/lookup/by-email`, `/email/log`).
3. HTTP status code.
4. Timestamp (UTC) and profile id.

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

## RequestId usage

- RequestId is the correlation key across connector logs and API responses.
- Never include credentials or tokens in support tickets.
