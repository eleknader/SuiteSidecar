# SuiteSidecar Connector PHP: Deployment and Verification

## Composer clean-state verification

Run from `connector-php`:

```bash
cd connector-php
rm -rf vendor
composer install
composer dump-autoload
```

## Local secrets setup

Install server-local secrets file and permissions:

```bash
sudo ops/scripts/env-install.sh
```

For local `php -S` development (without Apache envvars), use a gitignored local env file:

```bash
cp connector-php/.env.example connector-php/.env
chmod 600 connector-php/.env
# then fill SUITESIDECAR_* values in connector-php/.env
```

The connector bootstrap loads `connector-php/.env` automatically if present.

If connector runs behind Apache as `www-data`, make sure `.env` is readable by that user:

```bash
chgrp www-data connector-php/.env
chmod 640 connector-php/.env
```

Run one-command smoke checks against local Apache (with `--resolve`):

```bash
ops/scripts/smoke.sh
```

Override target in development (for example local PHP server):

```bash
SMOKE_SCHEME=http SMOKE_HOST=suitesidecar.local SMOKE_IP=127.0.0.1 SMOKE_PORT=18080 ops/scripts/smoke.sh
```

Run authenticated end-to-end checks (login + lookup + create + email log duplicate conflict):

```bash
E2E_USERNAME="admin" E2E_PASSWORD="your-password" ops/scripts/e2e-auth.sh
```

Optional overrides:

```bash
E2E_PROFILE_ID=example-dev \
E2E_LOOKUP_EMAIL=known.user@example.com \
E2E_HOST=suitesidecar.example.com \
E2E_IP=127.0.0.1 \
ops/scripts/e2e-auth.sh
```

## Runtime storage (`connector-php/var`)

`connector-php/var` is runtime-only storage (tokens, sessions, temporary runtime data).
It is intentionally excluded from git and must not contain committed files.

Create and secure it on the target host:

```bash
mkdir -p connector-php/var/tokens connector-php/var/sessions
sudo chown -R www-data:www-data connector-php/var
sudo chmod -R 750 connector-php/var
```

## Profile OAuth secrets via environment variables

Profile id `example-dev` is normalized to `EXAMPLE_DEV` for env keys.

```bash
export SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID="your-client-id"
export SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET="your-client-secret"
```

General pattern:

```bash
SUITESIDECAR_<PROFILEID_NORMALIZED>_CLIENT_ID
SUITESIDECAR_<PROFILEID_NORMALIZED>_CLIENT_SECRET
```

Credential precedence:

1. environment variables (`SUITESIDECAR_*`)
2. fallback values in `config/profiles.php`

Recommended: keep `oauth.clientId` and `oauth.clientSecret` empty in `config/profiles.php`
and store real credentials only in env files.

Profile URL rule for SuiteCRM v8:

- `suitecrmBaseUrl` must be CRM root URL (example: `https://crmdev.tapsa.duckdns.org`)
- `tokenUrl` should be `https://<host>/legacy/Api/access_token`
- do not set `suitecrmBaseUrl` to `/legacy`

## OAuth troubleshooting (invalid_client / unauthorized)

If SuiteCRM token calls fail with `invalid_client` or connector login returns unauthorized:

1. Confirm profile id normalization:
   - `example-dev` -> `EXAMPLE_DEV`
   - expected env keys:
     - `SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID`
     - `SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET`
2. Verify secrets are loaded in the runtime:
   - Apache mode: check `/etc/suitesidecar/suitesidecar.env`, then reload Apache.
   - local `php -S` mode: check `connector-php/.env`.
3. Test token endpoint directly with current credentials:

```bash
source /etc/suitesidecar/suitesidecar.env
curl -sS -i -X POST "https://crm.example.com/legacy/Api/access_token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "grant_type=password" \
  --data-urlencode "client_id=${SUITESIDECAR_EXAMPLE_DEV_CLIENT_ID}" \
  --data-urlencode "client_secret=${SUITESIDECAR_EXAMPLE_DEV_CLIENT_SECRET}" \
  --data-urlencode "username=<suitecrm-user>" \
  --data-urlencode "password=<suitecrm-password>"
```

Expected result: HTTP `200` with `access_token`.  
If HTTP `401 invalid_client`: client id/secret pair is wrong or not loaded by runtime.
If HTTP `404`: token URL/host is wrong (common cause: wrong host or wrong base path).

## Connector JWT secret

`/auth/login` and authenticated endpoints require:

```bash
export SUITESIDECAR_JWT_SECRET="replace-with-long-random-secret"
```

Optional token lifetime override (default: 8h):

```bash
export SUITESIDECAR_JWT_TTL_SECONDS="28800"
```

## API smoke tests (normal DNS path)

```bash
BASE_URL="https://connector.example.com"
curl -sS "${BASE_URL}/health"
curl -sS "${BASE_URL}/version"
curl -sS "${BASE_URL}/profiles"

LOGIN_RESPONSE=$(curl -sS -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "profileId":"example-dev",
    "username":"user@example.com",
    "password":"your-password"
  }')

TOKEN=$(printf "%s" "${LOGIN_RESPONSE}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["token"] ?? "";')

curl -sS "${BASE_URL}/lookup/by-email?profileId=example-dev&email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}"

curl -sS "${BASE_URL}/lookup/by-email?email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-SuiteSidecar-Profile: example-dev"

curl -sS -X POST "${BASE_URL}/entities/contacts?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "firstName":"Matti",
    "lastName":"Meikäläinen",
    "email":"matti.meikalainen@example.com",
    "title":"Sales Manager"
  }'

curl -sS -X POST "${BASE_URL}/entities/leads?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "firstName":"Laura",
    "lastName":"Lead",
    "email":"laura.lead@example.com",
    "company":"Example Oy"
  }'

curl -sS -X POST "${BASE_URL}/email/log?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "message": {
      "internetMessageId": "<abc123@mail.example.com>",
      "subject": "Follow-up call",
      "from": {"email": "sender@example.com"},
      "to": [{"email": "known.user@example.com"}],
      "sentAt": "2026-01-01T12:00:00Z"
    },
    "linkTo": {
      "module": "Contacts",
      "id": "contact-id"
    }
  }'

curl -sS -o /dev/null -w "%{http_code}\n" \
  -X POST "${BASE_URL}/auth/logout" \
  -H "Authorization: Bearer ${TOKEN}"
```

For `suitecrm_v8_jsonapi` profiles, the connector creates a SuiteCRM `Notes` record and links it to `linkTo`.
If SuiteCRM is unreachable, the connector returns a structured error response.

Conflict mapping examples (returns `409` when dedup condition is met):

```bash
curl -sS -X POST "${BASE_URL}/entities/contacts?profileId=mock-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"firstName":"Dup","lastName":"Contact","email":"dup+conflict@example.com"}'

curl -sS -X POST "${BASE_URL}/email/log?profileId=mock-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "message": {
      "internetMessageId": "<conflict@mail.example.com>",
      "subject": "Duplicate log",
      "from": {"email": "sender@example.com"},
      "to": [{"email": "known.user@example.com"}],
      "sentAt": "2026-01-01T12:00:00Z"
    },
    "linkTo": {"module":"Contacts","id":"contact-id"}
  }'
```

## Verified pre-DNS flow example

```bash
TOKEN=$(
curl -sS --resolve suitesidecar.example.com:443:127.0.0.1 \
  -X POST https://suitesidecar.example.com/auth/login \
  -H "Content-Type: application/json" \
  -d '{"profileId":"example-dev","username":"admin","password":"<password>"}' \
  | jq -r '.token'
)

curl -sS --resolve suitesidecar.example.com:443:127.0.0.1 \
  "https://suitesidecar.example.com/lookup/by-email?profileId=example-dev&email=<known-email>" \
  -H "Authorization: Bearer ${TOKEN}" | jq
```

Expected login: token returned.  
Expected lookup: normalized JSON response (for missing email, `notFound: true` is valid).
Expected duplicate email log with same `internetMessageId`: HTTP `409 conflict`.

## How to test before DNS propagation

Use `--resolve` to force hostname + TLS SNI to this server IP:

```bash
SERVER_IP=127.0.0.1
HOSTNAME="connector.example.com"

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/health

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/version

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  https://${HOSTNAME}/profiles

LOGIN_RESPONSE=$(curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -X POST "https://${HOSTNAME}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "profileId":"example-dev",
    "username":"user@example.com",
    "password":"your-password"
  }')

TOKEN=$(printf "%s" "${LOGIN_RESPONSE}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["token"] ?? "";')

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  "https://${HOSTNAME}/lookup/by-email?profileId=example-dev&email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}"

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  "https://${HOSTNAME}/lookup/by-email?email=known.user@example.com&include=account" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-SuiteSidecar-Profile: example-dev"

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -X POST "https://${HOSTNAME}/entities/contacts?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "firstName":"Matti",
    "lastName":"Meikäläinen",
    "email":"matti.meikalainen@example.com",
    "title":"Sales Manager"
  }'

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -X POST "https://${HOSTNAME}/entities/leads?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "firstName":"Laura",
    "lastName":"Lead",
    "email":"laura.lead@example.com",
    "company":"Example Oy"
  }'

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -X POST "https://${HOSTNAME}/email/log?profileId=example-dev" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "message": {
      "internetMessageId": "<abc123@mail.example.com>",
      "subject": "Follow-up call",
      "from": {"email": "sender@example.com"},
      "to": [{"email": "known.user@example.com"}],
      "sentAt": "2026-01-01T12:00:00Z"
    },
    "linkTo": {
      "module": "Contacts",
      "id": "contact-id"
    }
  }'

curl -sS --resolve ${HOSTNAME}:443:${SERVER_IP} \
  -o /dev/null -w "%{http_code}\n" \
  -X POST "https://${HOSTNAME}/auth/logout" \
  -H "Authorization: Bearer ${TOKEN}"
```

## SuiteCRM Dedup Prerequisites

For SuiteCRM-side dedup field requirements and DEV QRR/cache refresh steps, see:

- `docs/REQUIREMENTS.md`

## Outlook add-in packaging (repeatable)

Run from repo root:

```bash
bash ops/scripts/package-addin.sh
bash ops/scripts/publish-addin.sh
```

If successful, artifacts are created under `dist/addin/`:

- `dist/addin/stage/sideload/suitesidecar.xml`
- `dist/addin/stage/static/addin/`
- `dist/addin/suitesidecar-manifest.zip` (or `.tar.gz`)
- `dist/addin/suitesidecar-static.zip` (or `.tar.gz`)

The script fails if the manifest still contains placeholder URLs.
`publish-addin.sh` also deploys files into `connector-php/public/addin/`.

## Outlook sideload (OWA/Desktop)

Prerequisites:

1. `https://suitesidecar.example.com/addin/taskpane.html` is reachable from the Outlook client.
2. Connector API is reachable at `https://suitesidecar.example.com`.
3. `dist/addin/stage/sideload/suitesidecar.xml` exists.
4. Add-in static files are published under `connector-php/public/addin/`.

Sideload using Outlook on the web:

1. Open Outlook on the web with the target mailbox.
2. Go to `Get Add-ins` -> `My add-ins`.
3. Select `Add a custom add-in` -> `Add from file`.
4. Upload `dist/addin/stage/sideload/suitesidecar.xml`.
5. Open any email in read mode and launch `SuiteSidecar` from the ribbon.

Validation after sideload:

1. Click `Load Profiles`.
2. Login with SuiteCRM user credentials.
3. Open a known sender email and confirm automatic lookup runs.
4. Test `Create Contact`, `Create Lead`, and `Log Email`.
5. Add-in default for `maxAttachmentBytes` is `5242880` (5 MB). Ensure PHP `upload_max_filesize` and `post_max_size` are aligned if attachment limits are changed.
6. For failures, capture `requestId` shown in the add-in status box.

For requestId-based incident handling, see `docs/SUPPORT.md`.
